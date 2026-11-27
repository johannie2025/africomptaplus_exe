<?php
/**
 * Synchronisation APK ⇄ panel web (hub = tables w_*).
 * Le stock circule par deltas ; les ventes/achats/paiements sont idempotents (uid) ; produits/clients/fournisseurs : dernier à synchroniser gagne.
 */
require_once __DIR__ . '/sync_schema.php';
const SYNC_ACTIONS = ['sync'];

function sync_fail(string $m): never { out(200, ['valid' => true, 'status' => 'active', 'ok' => false, 'message' => $m, 'license' => null]); }

function sync_dispatch(array $lic, array $b): never {
    $pdo = db(); $l = (int)$lic['id'];
    try {
        sync_migrate();
        $rid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['req_id'] ?? ''));
        if ($rid !== '') {      // requête déjà traitée (réponse perdue côté appareil) : on renvoie la réponse mémorisée sans rien rejouer
            $g = $pdo->prepare('SELECT response FROM w_sync_log WHERE license_id=? AND req_id=?'); $g->execute([$l, $rid]);
            if ($old = $g->fetchColumn()) { header('Content-Type: application/json; charset=utf-8'); echo $old; exit; }
        }
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 20)'); $lock->execute(['acsync' . $l]);
        if (!(int)$lock->fetchColumn()) sync_fail('Synchronisation déjà en cours.');
        $tok = (string)($b['token'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $tok)) $tok = '2000-01-01 00:00:00';
        $newTok = (string)$pdo->query('SELECT DATE_SUB(NOW(), INTERVAL 3 SECOND)')->fetchColumn();
        foreach (['w_products', 'w_customers', 'w_sales', 'w_payments', 'w_expenses', 'w_suppliers', 'w_purchases', 'w_supplier_payments', 'w_documents', 'w_acct_entries', 'w_inventories'] as $t)
            $pdo->prepare("UPDATE $t SET uid=CONCAT('w',id)" . (in_array($t, ['w_payments', 'w_supplier_payments']) ? '' : ', updated_at=updated_at') . " WHERE license_id=? AND uid IS NULL")->execute([$l]);
        $remap = [];
        $pdo->beginTransaction();       // application + lecture + journal de requête : tout ou rien (anti-doublon si la réponse se perd)
        try {
            $info = [];
            sync_apply($l, $b, $remap, $info);
            $out = sync_pull($l, $tok, $info['touched']) + ['token' => $newTok, 'remap' => $remap, 'conflicts' => $info['conflicts'], 'merged' => $info['merged']];
            $resp = ['valid' => true, 'status' => 'active', 'ok' => true, 'message' => 'OK', 'license' => null] + $out;
            if ($rid !== '') {
                $pdo->prepare('INSERT IGNORE INTO w_sync_log (license_id,req_id,response,created_at) VALUES (?,?,?,?)')->execute([$l, $rid, json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), now()]);
                $pdo->prepare('DELETE FROM w_sync_log WHERE created_at<?')->execute([gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['acsync' . $l]);
        out(200, $resp);
    } catch (Throwable $e) {
        error_log('[sync] ' . $e->getMessage() . ' @' . $e->getLine());
        sync_fail('Erreur de synchronisation : ' . mb_substr($e->getMessage(), 0, 160));
    }
}

function sy_find(int $l, string $table, string $uid): ?array { $s = db()->prepare("SELECT * FROM $table WHERE license_id=? AND uid=?"); $s->execute([$l, $uid]); return $s->fetch() ?: null; }
function sy_s($v, int $n): string { return mb_substr(trim((string)$v), 0, $n); }
function sy_d($v): string { $v = (string)$v; return preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v) ? (strlen($v) === 10 ? $v . ' 00:00:00' : $v) : now(); }
function sy_uid($v): string { return preg_replace('/[^A-Za-z0-9_-]/', '', mb_substr((string)$v, 0, 40)); }

const SY_FIELDS = [
    'w_products' => ['name' => 'name', 'barcode' => 'barcode', 'category' => 'category', 'buy' => 'buy_price', 'sell' => 'sell_price', 'min_stock' => 'min_stock'],
    'w_customers' => ['name' => 'name', 'phone' => 'phone', 'email' => 'email', 'address' => 'address'],
    'w_suppliers' => ['name' => 'name', 'phone' => 'phone', 'email' => 'email', 'address' => 'address', 'notes' => 'notes'],
    'w_expenses' => ['category' => 'category', 'description' => 'description', 'amount' => 'amount'],
    'w_acct_entries' => ['date' => 'entry_date', 'journal' => 'journal', 'label' => 'label', 'debit' => 'debit_account', 'credit' => 'credit_account', 'amount' => 'amount'],
];
function sy_norm($v): string { return is_numeric($v) ? rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.') : trim((string)$v); }
function sy_conflict(int $l, string $table, string $uid, string $label, string $field, $dev, $web, array &$info): void {
    db()->prepare('INSERT INTO w_sync_conflicts (license_id,tbl,uid,label,field,device_value,web_value,created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$l, $table, $uid, mb_substr($label, 0, 160), $field, mb_substr((string)$dev, 0, 300), mb_substr((string)$web, 0, 300), now()]);
    $info['conflicts']++;
}
/** Fusion champ par champ à 3 voies (base = valeurs à la dernière synchro de l'appareil). Retourne les colonnes à mettre à jour. */
function sy_merge(int $l, string $table, array $row, array $in, ?array $base, string $uid, array &$info): array {
    $upd = []; $label = (string)($row['name'] ?? $row['label'] ?? $row['category'] ?? $uid);
    foreach (SY_FIELDS[$table] as $f => $col) {
        if (!array_key_exists($f, $in)) continue;
        $c = sy_norm($in[$f]); $s = sy_norm($row[$col] ?? '');
        if ($c === $s) continue;
        if ($base === null || !array_key_exists($f, $base)) { sy_conflict($l, $table, $uid, $label, $f, $in[$f], $row[$col] ?? '', $info); continue; }   // pas de base : le web est conservé, la différence est tracée
        $bs = sy_norm($base[$f]);
        if ($c === $bs) continue;                        // l'appareil n'a pas touché ce champ : on garde la valeur du web
        if ($s === $bs) { $upd[$col] = $in[$f]; continue; }   // seul l'appareil l'a modifié
        sy_conflict($l, $table, $uid, $label, $f, $in[$f], $row[$col] ?? '', $info);                 // modifié des deux côtés
    }
    return $upd;
}
function sy_apply_cols(string $table, int $id, array $upd): void {
    if (!$upd) return; $set = []; $a = [];
    foreach ($upd as $c => $v) { $set[] = "`$c`=?"; $a[] = $v; } $a[] = $id;
    db()->prepare("UPDATE $table SET " . implode(',', $set) . ' WHERE id=?')->execute($a);
}

function sync_apply(int $l, array $b, array &$remap, array &$info): void {
    $pdo = db(); $alias = ['products' => [], 'customers' => [], 'suppliers' => []]; $info = ['touched' => [], 'conflicts' => 0, 'merged' => 0];
    $pol = in_array($b['merge_stock'] ?? '', ['web', 'app', 'sum'], true) ? $b['merge_stock'] : 'web';
    $res = function (string $e, string $uid) use (&$alias) { return $alias[$e][$uid] ?? $uid; };
    $touch = function (string $t, string $uid) use (&$info) { $info['touched'][$t][] = $uid; };
    $baseOf = fn($o) => isset($o['base']) && is_array($o['base']) ? $o['base'] : null;
    // suppressions
    $tmap = ['products' => 'w_products', 'customers' => 'w_customers', 'suppliers' => 'w_suppliers', 'expenses' => 'w_expenses', 'sales' => 'w_sales', 'purchases' => 'w_purchases', 'documents' => 'w_documents', 'entries' => 'w_acct_entries'];
    foreach (array_slice((array)($b['deleted'] ?? []), 0, 2000) as $d) {
        $e = (string)($d['e'] ?? ''); $uid = sy_uid($d['uid'] ?? ''); $t = $tmap[$e] ?? null;
        if (!$t || $uid === '') continue;
        if ($e === 'suppliers') {      // fournisseur avec achats : archivé (historique conservé), l'archivage est diffusé aux autres appareils
            $c = $pdo->prepare('SELECT COUNT(*) FROM w_purchases p JOIN w_suppliers s ON s.id=p.supplier_id WHERE s.license_id=? AND s.uid=?'); $c->execute([$l, $uid]);
            if ((int)$c->fetchColumn() > 0) { $pdo->prepare('UPDATE w_suppliers SET archived=1 WHERE license_id=? AND uid=?')->execute([$l, $uid]); continue; }
        }
        $pdo->prepare("DELETE FROM $t WHERE license_id=? AND uid=?")->execute([$l, $uid]); sy_tomb($l, $e, $uid);
    }
    // clients
    foreach (array_slice((array)($b['customers'] ?? []), 0, 5000) as $c) {
        $uid = sy_uid($c['uid'] ?? ''); $name = sy_s($c['name'] ?? '', 120); if ($uid === '' || $name === '') continue;
        $in = ['name' => $name, 'phone' => sy_s($c['phone'] ?? '', 30), 'email' => sy_s($c['email'] ?? '', 120), 'address' => sy_s($c['address'] ?? '', 200)];
        if ($row = sy_find($l, 'w_customers', $uid)) { sy_apply_cols('w_customers', (int)$row['id'], sy_merge($l, 'w_customers', $row, $in, $baseOf($c), $uid, $info)); $touch('w_customers', $uid); continue; }
        $m = $pdo->prepare("SELECT * FROM w_customers WHERE license_id=? AND ((?<>'' AND phone=?) OR LOWER(name)=LOWER(?)) LIMIT 1"); $m->execute([$l, $in['phone'], $in['phone'], $name]);
        if ($x = $m->fetch()) {
            $alias['customers'][$uid] = $x['uid']; $remap[] = ['e' => 'customers', 'from' => $uid, 'to' => $x['uid']]; $info['merged']++;
            foreach (sy_merge($l, 'w_customers', $x, $in, null, $x['uid'], $info) as $_) {} $touch('w_customers', $x['uid']); continue;
        }
        $pdo->prepare('INSERT INTO w_customers (license_id,name,phone,email,address,created_at,uid) VALUES (?,?,?,?,?,?,?)')->execute([$l, $in['name'], $in['phone'], $in['email'], $in['address'], now(), $uid]); $touch('w_customers', $uid);
    }
    foreach (array_slice((array)($b['suppliers'] ?? []), 0, 2000) as $c) {
        $uid = sy_uid($c['uid'] ?? ''); $name = sy_s($c['name'] ?? '', 120); if ($uid === '' || $name === '') continue;
        $in = ['name' => $name, 'phone' => sy_s($c['phone'] ?? '', 30), 'email' => sy_s($c['email'] ?? '', 120), 'address' => sy_s($c['address'] ?? '', 200), 'notes' => sy_s($c['notes'] ?? '', 300)];
        if ($row = sy_find($l, 'w_suppliers', $uid)) { sy_apply_cols('w_suppliers', (int)$row['id'], sy_merge($l, 'w_suppliers', $row, $in, $baseOf($c), $uid, $info)); $touch('w_suppliers', $uid); continue; }
        $m = $pdo->prepare('SELECT * FROM w_suppliers WHERE license_id=? AND LOWER(name)=LOWER(?) LIMIT 1'); $m->execute([$l, $name]);
        if ($x = $m->fetch()) {
            $alias['suppliers'][$uid] = $x['uid']; $remap[] = ['e' => 'suppliers', 'from' => $uid, 'to' => $x['uid']]; $info['merged']++;
            foreach (sy_merge($l, 'w_suppliers', $x, $in, null, $x['uid'], $info) as $_) {} $touch('w_suppliers', $x['uid']); continue;
        }
        $pdo->prepare('INSERT INTO w_suppliers (license_id,name,phone,email,address,notes,created_at,uid) VALUES (?,?,?,?,?,?,?,?)')->execute([$l, $in['name'], $in['phone'], $in['email'], $in['address'], $in['notes'], now(), $uid]); $touch('w_suppliers', $uid);
    }
    // produits : champs fusionnés à 3 voies ; stock par delta
    foreach (array_slice((array)($b['products'] ?? []), 0, 5000) as $p) {
        $uid = sy_uid($p['uid'] ?? ''); $name = sy_s($p['name'] ?? '', 160); if ($uid === '' || $name === '') continue;
        $bc = sy_s($p['barcode'] ?? '', 64);
        $in = ['name' => $name, 'barcode' => $bc, 'category' => sy_s($p['category'] ?? '', 80), 'buy' => max(0, (float)($p['buy'] ?? 0)), 'sell' => max(0, (float)($p['sell'] ?? 0)), 'min_stock' => max(0, (int)($p['min_stock'] ?? 5))];
        if ($row = sy_find($l, 'w_products', $uid)) {
            $upd = sy_merge($l, 'w_products', $row, $in, $baseOf($p), $uid, $info);
            sy_apply_cols('w_products', (int)$row['id'], $upd);
            if ((int)($p['delta'] ?? 0) !== 0) $pdo->prepare('UPDATE w_products SET stock=stock+? WHERE id=?')->execute([(int)$p['delta'], $row['id']]);
            $touch('w_products', $uid); continue;
        }
        $m = $pdo->prepare("SELECT * FROM w_products WHERE license_id=? AND ((?<>'' AND barcode=?) OR LOWER(name)=LOWER(?)) LIMIT 1"); $m->execute([$l, $bc, $bc, $name]);
        if ($x = $m->fetch()) {      // même produit des deux côtés : fusion ; stock selon le choix de l'utilisateur (web / appli / somme)
            $alias['products'][$uid] = $x['uid']; $remap[] = ['e' => 'products', 'from' => $uid, 'to' => $x['uid']]; $info['merged']++;
            foreach (sy_merge($l, 'w_products', $x, $in, null, $x['uid'], $info) as $_) {}
            $st = (int)($p['stock'] ?? 0);
            if ($pol === 'app') $pdo->prepare('UPDATE w_products SET stock=? WHERE id=?')->execute([$st, $x['id']]);
            elseif ($pol === 'sum') $pdo->prepare('UPDATE w_products SET stock=stock+? WHERE id=?')->execute([$st, $x['id']]);
            $touch('w_products', $x['uid']); continue;
        }
        $pdo->prepare('INSERT INTO w_products (license_id,name,barcode,category,buy_price,sell_price,min_stock,stock,created_at,uid) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$l, $name, $bc, $in['category'], $in['buy'], $in['sell'], $in['min_stock'], (int)($p['stock'] ?? 0), now(), $uid]); $touch('w_products', $uid);
    }
    // dépenses
    foreach (array_slice((array)($b['expenses'] ?? []), 0, 5000) as $x) {
        $uid = sy_uid($x['uid'] ?? ''); if ($uid === '') continue;
        $in = ['category' => sy_s($x['category'] ?? '', 60), 'description' => sy_s($x['description'] ?? '', 200), 'amount' => max(0, (float)($x['amount'] ?? 0))];
        if ($row = sy_find($l, 'w_expenses', $uid)) { sy_apply_cols('w_expenses', (int)$row['id'], sy_merge($l, 'w_expenses', $row, $in, $baseOf($x), $uid, $info)); $touch('w_expenses', $uid); continue; }
        $pdo->prepare('INSERT INTO w_expenses (license_id,category,description,amount,created_at,uid) VALUES (?,?,?,?,?,?)')->execute([$l, $in['category'], $in['description'], $in['amount'], sy_d($x['created_at'] ?? ''), $uid]); $touch('w_expenses', $uid);
    }
    // ventes
    $pid = function (string $uid) use ($l, $res) { $r = sy_find($l, 'w_products', $res('products', $uid)); return $r; };
    foreach (array_slice((array)($b['sales'] ?? []), 0, 3000) as $s) {
        $uid = sy_uid($s['uid'] ?? ''); if ($uid === '') continue; $row = sy_find($l, 'w_sales', $uid);
        $method = in_array($s['payment'] ?? '', ['cash', 'mobile_money', 'credit'], true) ? $s['payment'] : 'cash';
        if (!$row) {
            $cu = ($s['client_uid'] ?? '') !== '' ? sy_find($l, 'w_customers', $res('customers', sy_uid($s['client_uid']))) : null;
            $ref = sy_s($s['reference'] ?? '', 40) ?: 'INV-' . $uid; $k = 0;
            while (true) { $q = $pdo->prepare('SELECT 1 FROM w_sales WHERE license_id=? AND reference=?'); $q->execute([$l, $ref . ($k ? "-A$k" : '')]); if (!$q->fetch()) break; $k++; }
            if ($k) $ref .= "-A$k";
            $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($s['due'] ?? '')) ? $s['due'] : null;
            $pdo->prepare('INSERT INTO w_sales (license_id,reference,customer_id,customer_name,customer_phone,subtotal,tax_amount,total,paid,payment,due_date,source,created_at,uid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$l, $ref, $cu['id'] ?? null, sy_s($s['name'] ?? '', 120), sy_s($s['phone'] ?? '', 30), (float)($s['subtotal'] ?? 0), (float)($s['tax'] ?? 0), (float)($s['total'] ?? 0), 0, $method, $due, 'web', sy_d($s['created_at'] ?? ''), $uid]);
            $sid = (int)$pdo->lastInsertId(); $ins = $pdo->prepare('INSERT INTO w_sale_items (sale_id,product_id,name,qty,unit_price,buy_price) VALUES (?,?,?,?,?,?)');
            foreach (array_slice((array)($s['items'] ?? []), 0, 300) as $i) { $p = $pid(sy_uid($i['product_uid'] ?? '')); $ins->execute([$sid, $p['id'] ?? null, sy_s($i['name'] ?? '', 160), max(1, (int)($i['qty'] ?? 1)), (float)($i['price'] ?? 0), (float)($i['buy'] ?? 0)]); }
            $row = sy_find($l, 'w_sales', $uid);
        }
        foreach (array_slice((array)($s['payments'] ?? []), 0, 200) as $p) {
            $pu = sy_uid($p['uid'] ?? ''); if ($pu === '' || sy_find($l, 'w_payments', $pu)) continue;
            $pdo->prepare('INSERT INTO w_payments (license_id,sale_id,amount,method,created_at,uid) VALUES (?,?,?,?,?,?)')->execute([$l, $row['id'], max(0, (float)($p['amount'] ?? 0)), ($p['method'] ?? '') === 'mobile_money' ? 'mobile_money' : 'cash', sy_d($p['created_at'] ?? ''), $pu]);
        }
        $sum = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM w_payments WHERE sale_id=?'); $sum->execute([$row['id']]);
        $paid = $row['payment'] === 'credit' ? min((float)$row['total'], (float)$sum->fetchColumn()) : (float)$row['total'];
        $pdo->prepare('UPDATE w_sales SET paid=? WHERE id=?')->execute([$paid, $row['id']]);
    }
    // achats
    foreach (array_slice((array)($b['purchases'] ?? []), 0, 3000) as $u) {
        $uid = sy_uid($u['uid'] ?? ''); if ($uid === '') continue; $row = sy_find($l, 'w_purchases', $uid);
        if (!$row) {
            $su = sy_find($l, 'w_suppliers', $res('suppliers', sy_uid($u['supplier_uid'] ?? ''))); if (!$su) continue;
            $ref = sy_s($u['reference'] ?? '', 40) ?: 'ACH-' . $uid; $k = 0;
            while (true) { $q = $pdo->prepare('SELECT 1 FROM w_purchases WHERE license_id=? AND reference=?'); $q->execute([$l, $ref . ($k ? "-A$k" : '')]); if (!$q->fetch()) break; $k++; }
            if ($k) $ref .= "-A$k"; $ip = max(0, (float)($u['initial_paid'] ?? 0));
            $pdo->prepare('INSERT INTO w_purchases (license_id,reference,supplier_id,supplier_name,total,initial_paid,paid,payment,notes,created_at,uid) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$l, $ref, $su['id'], $su['name'], (float)($u['total'] ?? 0), $ip, $ip, ($u['payment'] ?? '') === 'mobile_money' ? 'mobile_money' : 'cash', sy_s($u['notes'] ?? '', 300), sy_d($u['created_at'] ?? ''), $uid]);
            $xid = (int)$pdo->lastInsertId(); $ins = $pdo->prepare('INSERT INTO w_purchase_items (purchase_id,product_id,name,qty,unit_cost) VALUES (?,?,?,?,?)');
            foreach (array_slice((array)($u['items'] ?? []), 0, 300) as $i) { $p = $pid(sy_uid($i['product_uid'] ?? '')); $ins->execute([$xid, $p['id'] ?? null, sy_s($i['name'] ?? '', 160), max(1, (int)($i['qty'] ?? 1)), (float)($i['cost'] ?? 0)]); }
            $row = sy_find($l, 'w_purchases', $uid);
        }
        foreach (array_slice((array)($u['payments'] ?? []), 0, 200) as $p) {
            $pu = sy_uid($p['uid'] ?? ''); if ($pu === '' || sy_find($l, 'w_supplier_payments', $pu)) continue;
            $pdo->prepare('INSERT INTO w_supplier_payments (license_id,purchase_id,amount,method,created_at,uid) VALUES (?,?,?,?,?,?)')->execute([$l, $row['id'], max(0, (float)($p['amount'] ?? 0)), ($p['method'] ?? '') === 'mobile_money' ? 'mobile_money' : 'cash', sy_d($p['created_at'] ?? ''), $pu]);
        }
        $sum = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM w_supplier_payments WHERE purchase_id=?'); $sum->execute([$row['id']]);
        $pdo->prepare('UPDATE w_purchases SET paid=LEAST(total, initial_paid+?) WHERE id=?')->execute([(float)$sum->fetchColumn(), $row['id']]);
    }
    // devis / proforma
    foreach (array_slice((array)($b['documents'] ?? []), 0, 2000) as $o) {
        $uid = sy_uid($o['uid'] ?? ''); if ($uid === '') continue; $row = sy_find($l, 'w_documents', $uid);
        $sale = ($o['sale_uid'] ?? '') !== '' ? sy_find($l, 'w_sales', sy_uid($o['sale_uid'])) : null; $st = ($o['status'] ?? '') === 'converted' ? 'converted' : 'open';
        if ($row) { $pdo->prepare('UPDATE w_documents SET status=?, sale_id=? WHERE id=?')->execute([$st, $sale['id'] ?? $row['sale_id'], $row['id']]); continue; }
        $type = ($o['doc_type'] ?? '') === 'proforma' ? 'proforma' : 'quote'; $cu = ($o['client_uid'] ?? '') !== '' ? sy_find($l, 'w_customers', $res('customers', sy_uid($o['client_uid']))) : null;
        $ref = sy_s($o['reference'] ?? '', 40) ?: 'DOC-' . $uid; $k = 0;
        while (true) { $q = $pdo->prepare('SELECT 1 FROM w_documents WHERE license_id=? AND reference=?'); $q->execute([$l, $ref . ($k ? "-A$k" : '')]); if (!$q->fetch()) break; $k++; }
        if ($k) $ref .= "-A$k"; $vu = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($o['valid_until'] ?? '')) ? $o['valid_until'] : null;
        $pdo->prepare('INSERT INTO w_documents (license_id,doc_type,reference,customer_id,customer_name,customer_phone,subtotal,tax_amount,total,valid_until,status,sale_id,created_at,uid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$l, $type, $ref, $cu['id'] ?? null, sy_s($o['name'] ?? '', 120), sy_s($o['phone'] ?? '', 30), (float)($o['subtotal'] ?? 0), (float)($o['tax'] ?? 0), (float)($o['total'] ?? 0), $vu, $st, $sale['id'] ?? null, sy_d($o['created_at'] ?? ''), $uid]);
        $did = (int)$pdo->lastInsertId(); $ins = $pdo->prepare('INSERT INTO w_doc_items (doc_id,product_id,name,qty,unit_price) VALUES (?,?,?,?,?)');
        foreach (array_slice((array)($o['items'] ?? []), 0, 300) as $i) { $p = $pid(sy_uid($i['product_uid'] ?? '')); $ins->execute([$did, $p['id'] ?? null, sy_s($i['name'] ?? '', 160), max(1, (int)($i['qty'] ?? 1)), (float)($i['price'] ?? 0)]); }
    }
    // mouvements de stock (registre : insérés une fois ; le stock voyage par deltas de produits)
    foreach (array_slice((array)($b['stock_moves'] ?? []), 0, 5000) as $o) {
        $uid = sy_uid($o['uid'] ?? ''); if ($uid === '' || sy_find($l, 'w_stock_moves', $uid)) continue;
        $p = $pid(sy_uid($o['product_uid'] ?? '')); if (!$p) continue; $kind = in_array($o['kind'] ?? '', ['opening', 'in', 'out'], true) ? $o['kind'] : 'in'; $qty = (int)($o['qty'] ?? 0); if ($qty < 1) continue;
        if ($kind === 'opening') { $x = $pdo->prepare("SELECT 1 FROM w_stock_moves WHERE license_id=? AND product_id=? AND kind='opening'"); $x->execute([$l, $p['id']]); if ($x->fetch()) continue; }
        stk_move($l, (int)$p['id'], sy_s($o['name'] ?? '', 160), $kind, $qty, max(0, (float)($o['cost'] ?? 0)), sy_s($o['reason'] ?? '', 60), sy_s($o['note'] ?? '', 200), sy_d($o['date'] ?? ''), $uid);
    }
    // inventaires physiques (insérés une fois ; le stock voyage par deltas de produits)
    foreach (array_slice((array)($b['inventories'] ?? []), 0, 500) as $o) {
        $uid = sy_uid($o['uid'] ?? ''); if ($uid === '' || sy_find($l, 'w_inventories', $uid)) continue;
        $ref = sy_s($o['reference'] ?? '', 40) ?: 'INVT-' . $uid; $k = 0;
        while (true) { $q = $pdo->prepare('SELECT 1 FROM w_inventories WHERE license_id=? AND reference=?'); $q->execute([$l, $ref . ($k ? "-A$k" : '')]); if (!$q->fetch()) break; $k++; }
        if ($k) $ref .= "-A$k"; $dt = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($o['date'] ?? '')) ? substr($o['date'], 0, 10) : gmdate('Y-m-d');
        $pdo->prepare('INSERT INTO w_inventories (license_id,reference,inv_date,note,created_at,uid) VALUES (?,?,?,?,?,?)')->execute([$l, $ref, $dt, sy_s($o['note'] ?? '', 300), sy_d($o['created_at'] ?? ''), $uid]);
        $iid = (int)$pdo->lastInsertId(); $ins = $pdo->prepare('INSERT INTO w_inventory_items (inventory_id,product_id,name,theoretical,counted,cmp) VALUES (?,?,?,?,?,?)');
        foreach (array_slice((array)($o['items'] ?? []), 0, 5000) as $i) { $p = $pid(sy_uid($i['product_uid'] ?? '')); $ins->execute([$iid, $p['id'] ?? null, sy_s($i['name'] ?? '', 160), (int)($i['theoretical'] ?? 0), max(0, (int)($i['counted'] ?? 0)), max(0, (float)($i['cmp'] ?? 0))]); }
    }
    // écritures manuelles + comptes personnalisés
    foreach (array_slice((array)($b['entries'] ?? []), 0, 5000) as $o) {
        $uid = sy_uid($o['uid'] ?? ''); if ($uid === '') continue;
        $dt = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)($o['date'] ?? '')) ? substr($o['date'], 0, 10) : gmdate('Y-m-d');
        $in = ['date' => $dt, 'journal' => strtoupper(sy_s($o['journal'] ?? 'OD', 4)) ?: 'OD', 'label' => sy_s($o['label'] ?? '', 200), 'debit' => preg_replace('/\D/', '', (string)($o['debit'] ?? '')), 'credit' => preg_replace('/\D/', '', (string)($o['credit'] ?? '')), 'amount' => max(0, (float)($o['amount'] ?? 0))];
        if ($row = sy_find($l, 'w_acct_entries', $uid)) { sy_apply_cols('w_acct_entries', (int)$row['id'], sy_merge($l, 'w_acct_entries', $row, $in, $baseOf($o), $uid, $info)); $touch('w_acct_entries', $uid); }
        else { $pdo->prepare('INSERT INTO w_acct_entries (license_id,entry_date,journal,label,debit_account,credit_account,amount,created_at,uid) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$l, $in['date'], $in['journal'], $in['label'], $in['debit'], $in['credit'], $in['amount'], now(), $uid]); $touch('w_acct_entries', $uid); }
    }
    foreach (array_slice((array)($b['accounts'] ?? []), 0, 2000) as $o) {
        $c = preg_replace('/\D/', '', (string)($o['code'] ?? '')); $lb = sy_s($o['label'] ?? '', 160); if ($c === '' || $lb === '') continue;
        $pdo->prepare('INSERT INTO w_acct_accounts (license_id,code,label) VALUES (?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label)')->execute([$l, $c, $lb]);
    }
    // config : n'écrase jamais celle du web, ne remplit que les clés absentes
    $cfg = $b['config'] ?? null;
    if (is_array($cfg)) foreach (['shop_name' => 'shop_name', 'currency' => 'currency', 'tax_rate' => 'tax_rate', 'tax_label' => 'tax_label', 'tva_enabled' => 'tva_enabled'] as $k => $sk) {
        if (!isset($cfg[$k]) || $cfg[$k] === '') continue;
        $pdo->prepare('INSERT IGNORE INTO w_settings (license_id,skey,svalue) VALUES (?,?,?)')->execute([$l, $sk, mb_substr((string)$cfg[$k], 0, 120)]);
    }
}
function sy_tomb(int $l, string $e, string $uid): void {
    db()->prepare('INSERT INTO w_tombstones (license_id,entity,uid,deleted_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE deleted_at=NOW()')->execute([$l, $e, $uid]);
}

function sync_pull(int $l, string $tok, array $touched = []): array {
    $pdo = db(); $o = [];
    $force = function (string $t) use ($touched) { $u = array_values(array_unique($touched[$t] ?? [])); return $u ? ' OR uid IN (' . implode(',', array_map(fn($x) => db()->quote($x), $u)) . ')' : ''; };
    $all = function (string $sql, array $a = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchAll(); };
    $o['products'] = array_map(fn($r) => ['uid' => $r['uid'], 'name' => $r['name'], 'barcode' => $r['barcode'], 'category' => $r['category'], 'buy' => (float)$r['buy_price'], 'sell' => (float)$r['sell_price'], 'min_stock' => (int)$r['min_stock'], 'stock' => (int)$r['stock']],
        $all('SELECT * FROM w_products WHERE license_id=? AND (updated_at>?' . $force('w_products') . ') LIMIT 5000', [$l, $tok]));
    $o['customers'] = array_map(fn($r) => ['uid' => $r['uid'], 'name' => $r['name'], 'phone' => $r['phone'], 'email' => $r['email'], 'address' => $r['address']], $all('SELECT * FROM w_customers WHERE license_id=? AND (updated_at>?' . $force('w_customers') . ') LIMIT 5000', [$l, $tok]));
    $o['suppliers'] = array_map(fn($r) => ['uid' => $r['uid'], 'name' => $r['name'], 'phone' => $r['phone'], 'email' => $r['email'], 'address' => $r['address'], 'notes' => $r['notes'], 'archived' => (int)$r['archived']], $all('SELECT * FROM w_suppliers WHERE license_id=? AND (updated_at>?' . $force('w_suppliers') . ') LIMIT 2000', [$l, $tok]));
    $o['expenses'] = array_map(fn($r) => ['uid' => $r['uid'], 'category' => $r['category'], 'description' => $r['description'], 'amount' => (float)$r['amount'], 'created_at' => $r['created_at']], $all('SELECT * FROM w_expenses WHERE license_id=? AND (updated_at>?' . $force('w_expenses') . ') LIMIT 5000', [$l, $tok]));
    $o['sales'] = [];
    foreach ($all('SELECT s.*, c.uid cuid FROM w_sales s LEFT JOIN w_customers c ON c.id=s.customer_id WHERE s.license_id=? AND s.updated_at>? ORDER BY s.id LIMIT 1500', [$l, $tok]) as $s) {
        $it = $all('SELECT i.*, p.uid puid FROM w_sale_items i LEFT JOIN w_products p ON p.id=i.product_id WHERE i.sale_id=?', [$s['id']]);
        $py = $all('SELECT * FROM w_payments WHERE sale_id=? ORDER BY id', [$s['id']]);
        $o['sales'][] = ['uid' => $s['uid'], 'reference' => $s['reference'], 'client_uid' => $s['cuid'] ?? '', 'name' => $s['customer_name'], 'phone' => $s['customer_phone'], 'subtotal' => (float)$s['subtotal'], 'tax' => (float)$s['tax_amount'], 'total' => (float)$s['total'],
            'payment' => $s['payment'], 'due' => $s['due_date'], 'created_at' => $s['created_at'],
            'items' => array_map(fn($i) => ['product_uid' => $i['puid'] ?? '', 'name' => $i['name'], 'qty' => (int)$i['qty'], 'price' => (float)$i['unit_price'], 'buy' => (float)$i['buy_price']], $it),
            'payments' => array_map(fn($p) => ['uid' => $p['uid'], 'amount' => (float)$p['amount'], 'method' => $p['method'], 'created_at' => $p['created_at']], $py)];
    }
    $o['purchases'] = [];
    foreach ($all('SELECT u.*, s.uid suid FROM w_purchases u JOIN w_suppliers s ON s.id=u.supplier_id WHERE u.license_id=? AND u.updated_at>? ORDER BY u.id LIMIT 1500', [$l, $tok]) as $u) {
        $it = $all('SELECT i.*, p.uid puid FROM w_purchase_items i LEFT JOIN w_products p ON p.id=i.product_id WHERE i.purchase_id=?', [$u['id']]);
        $py = $all('SELECT * FROM w_supplier_payments WHERE purchase_id=? ORDER BY id', [$u['id']]);
        $o['purchases'][] = ['uid' => $u['uid'], 'reference' => $u['reference'], 'supplier_uid' => $u['suid'], 'supplier_name' => $u['supplier_name'], 'total' => (float)$u['total'], 'initial_paid' => (float)$u['initial_paid'], 'payment' => $u['payment'], 'notes' => $u['notes'], 'created_at' => $u['created_at'],
            'items' => array_map(fn($i) => ['product_uid' => $i['puid'] ?? '', 'name' => $i['name'], 'qty' => (int)$i['qty'], 'cost' => (float)$i['unit_cost']], $it),
            'payments' => array_map(fn($p) => ['uid' => $p['uid'], 'amount' => (float)$p['amount'], 'method' => $p['method'], 'created_at' => $p['created_at']], $py)];
    }
    $o['documents'] = [];
    foreach ($all('SELECT d.*, c.uid cuid, s.uid suid FROM w_documents d LEFT JOIN w_customers c ON c.id=d.customer_id LEFT JOIN w_sales s ON s.id=d.sale_id WHERE d.license_id=? AND d.updated_at>? ORDER BY d.id LIMIT 1500', [$l, $tok]) as $x) {
        $it = $all('SELECT i.*, p.uid puid FROM w_doc_items i LEFT JOIN w_products p ON p.id=i.product_id WHERE i.doc_id=?', [$x['id']]);
        $o['documents'][] = ['uid' => $x['uid'], 'doc_type' => $x['doc_type'], 'reference' => $x['reference'], 'client_uid' => $x['cuid'] ?? '', 'name' => $x['customer_name'], 'phone' => $x['customer_phone'], 'subtotal' => (float)$x['subtotal'], 'tax' => (float)$x['tax_amount'], 'total' => (float)$x['total'],
            'valid_until' => $x['valid_until'] ?? '', 'status' => $x['status'], 'sale_uid' => $x['suid'] ?? '', 'created_at' => $x['created_at'],
            'items' => array_map(fn($i) => ['product_uid' => $i['puid'] ?? '', 'name' => $i['name'], 'qty' => (int)$i['qty'], 'price' => (float)$i['unit_price']], $it)];
    }
    $o['stock_moves'] = array_map(fn($r) => ['uid' => $r['uid'], 'product_uid' => $r['puid'] ?? '', 'name' => $r['name'], 'kind' => $r['kind'], 'qty' => (int)$r['qty'], 'cost' => (float)$r['unit_cost'], 'date' => $r['move_date'], 'reason' => $r['reason'], 'note' => $r['note']],
        $all('SELECT m.*, p.uid puid FROM w_stock_moves m LEFT JOIN w_products p ON p.id=m.product_id WHERE m.license_id=? AND m.updated_at>? ORDER BY m.id LIMIT 3000', [$l, $tok]));
    $o['inventories'] = [];
    foreach ($all('SELECT * FROM w_inventories WHERE license_id=? AND updated_at>? ORDER BY id LIMIT 300', [$l, $tok]) as $v) {
        $it = $all('SELECT i.*, p.uid puid FROM w_inventory_items i LEFT JOIN w_products p ON p.id=i.product_id WHERE i.inventory_id=?', [$v['id']]);
        $o['inventories'][] = ['uid' => $v['uid'], 'reference' => $v['reference'], 'date' => $v['inv_date'], 'note' => $v['note'], 'created_at' => $v['created_at'],
            'items' => array_map(fn($i) => ['product_uid' => $i['puid'] ?? '', 'name' => $i['name'], 'theoretical' => (int)$i['theoretical'], 'counted' => (int)$i['counted'], 'cmp' => (float)$i['cmp']], $it)];
    }
    $o['entries'] = array_map(fn($r) => ['uid' => $r['uid'], 'date' => $r['entry_date'], 'journal' => $r['journal'], 'label' => $r['label'], 'debit' => $r['debit_account'], 'credit' => $r['credit_account'], 'amount' => (float)$r['amount']], $all('SELECT * FROM w_acct_entries WHERE license_id=? AND (updated_at>?' . $force('w_acct_entries') . ') LIMIT 5000', [$l, $tok]));
    $o['accounts'] = array_map(fn($r) => ['code' => $r['code'], 'label' => $r['label']], $all('SELECT code,label FROM w_acct_accounts WHERE license_id=? AND updated_at>? LIMIT 2000', [$l, $tok]));
    $o['deleted'] = array_map(fn($r) => ['e' => $r['entity'], 'uid' => $r['uid']], $all('SELECT entity,uid FROM w_tombstones WHERE license_id=? AND deleted_at>? LIMIT 5000', [$l, $tok]));
    return $o;
}
