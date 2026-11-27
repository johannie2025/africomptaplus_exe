<?php
const DOC_TYPES = ['quote' => ['DEVIS', 'Arrêté le présent devis', 'DEV'], 'proforma' => ['FACTURE PROFORMA', 'Arrêtée la présente facture proforma', 'PRO']];
function doc_load(int $id): ?array {
    $s = db()->prepare('SELECT * FROM w_documents WHERE id=? AND license_id=?'); $s->execute([$id, lid()]); $d = $s->fetch();
    if (!$d) return null;
    $i = db()->prepare('SELECT * FROM w_doc_items WHERE doc_id=? ORDER BY id'); $i->execute([$id]); $d['items'] = $i->fetchAll(); return $d;
}
function doc_pdf(array $d): string {
    [$title, $words] = DOC_TYPES[$d['doc_type']];
    $acc = ['shop_name' => setting('shop_name', (string)me()['lshop']), 'currency' => setting('currency', 'XAF'), 'tax_label' => setting('tax_label', 'TVA'), 'momo_info' => (string)(bot_account(lid(), false)['momo_info'] ?? '')];
    $o = ['reference' => $d['reference'], 'created_at' => $d['created_at'], 'customer_name' => $d['customer_name'], 'phone' => preg_replace('/\D/', '', $d['customer_phone']),
          'subtotal' => $d['subtotal'], 'tax_amount' => $d['tax_amount'], 'total' => $d['total'], 'title' => $title, 'words_label' => $words, 'valid_until' => $d['valid_until']];
    return bot_invoice_pdf($acc, $o, array_map(fn($i) => ['name' => $i['name'], 'qty' => (int)$i['qty'], 'price' => (float)$i['unit_price']], $d['items']));
}
function mod_documents(): void {
    $l = lid(); $pdo = db(); $a = (string)($_GET['a'] ?? ''); $id = (int)($_GET['id'] ?? 0);
    if ($a === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) json_out(['ok' => false, 'error' => 'csrf'], 403);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in) || empty($in['items'])) json_out(['ok' => false, 'error' => t('Panier vide')]);
        $type = isset(DOC_TYPES[$in['doc'] ?? '']) ? $in['doc'] : 'quote';
        $cid = (int)($in['customer_id'] ?? 0); $cname = mb_substr(trim((string)($in['customer_name'] ?? '')), 0, 120); $cphone = mb_substr(trim((string)($in['customer_phone'] ?? '')), 0, 30);
        $pdo->beginTransaction();
        try {
            if ($cid) { $c = $pdo->prepare('SELECT * FROM w_customers WHERE id=? AND license_id=?'); $c->execute([$cid, $l]); $cu = $c->fetch(); if (!$cu) throw new RuntimeException('client'); $cname = $cu['name']; $cphone = $cu['phone']; }
            elseif ($cname !== '') { $pdo->prepare('INSERT INTO w_customers (license_id,name,phone,created_at) VALUES (?,?,?,?)')->execute([$l, $cname, $cphone, now()]); $cid = (int)$pdo->lastInsertId(); }
            $sub = 0; $lines = []; $sel = $pdo->prepare('SELECT * FROM w_products WHERE id=? AND license_id=?');
            foreach ($in['items'] as $it) {
                $q = max(1, min(99999, (int)($it['qty'] ?? 1))); $sel->execute([(int)($it['id'] ?? 0), $l]); $p = $sel->fetch();
                if (!$p) throw new RuntimeException(t('Produit introuvable')); $lines[] = [$p, $q]; $sub += (float)$p['sell_price'] * $q;
            }
            $tax = round($sub * tax_rate() / 100.0); $ref = DOC_TYPES[$type][2] . '-' . gmdate('Ymd-His'); $k = 0;
            while (true) { $x = $pdo->prepare('SELECT 1 FROM w_documents WHERE license_id=? AND reference=?'); $x->execute([$l, $ref . ($k ? "-$k" : '')]); if (!$x->fetch()) break; $k++; }
            if ($k) $ref .= "-$k";
            $pdo->prepare('INSERT INTO w_documents (license_id,doc_type,reference,customer_id,customer_name,customer_phone,subtotal,tax_amount,total,valid_until,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$l, $type, $ref, $cid ?: null, $cname, $cphone, $sub, $tax, $sub + $tax, gmdate('Y-m-d', time() + 30 * 86400), 'open', now()]);
            $did = (int)$pdo->lastInsertId(); $ins = $pdo->prepare('INSERT INTO w_doc_items (doc_id,product_id,name,qty,unit_price) VALUES (?,?,?,?,?)');
            foreach ($lines as [$p, $q]) $ins->execute([$did, $p['id'], $p['name'], $q, $p['sell_price']]);
            $pdo->commit(); json_out(['ok' => true, 'id' => $did]);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_out(['ok' => false, 'error' => $e->getMessage()]); }
    }
    if (!$id) {
        $s = $pdo->prepare('SELECT * FROM w_documents WHERE license_id=? ORDER BY created_at DESC,id DESC LIMIT 300'); $s->execute([$l]); $rows = $s->fetchAll();
        render_head(t('Devis & Proforma'), 'documents'); ?>
<div class="bar"><h1><?= e(t('Devis & Proforma')) ?></h1><div class="actions"><a class="btn cta" href="<?= e(url('pos', ['doc' => 'quote'])) ?>">+ <?= e(t('Devis')) ?></a><a class="btn" href="<?= e(url('pos', ['doc' => 'proforma'])) ?>">+ Proforma</a></div></div>
<div class="card tw"><table><tr><th><?= e(t('Date')) ?></th><th><?= e(t('Client')) ?></th><th><?= e(t('Statut')) ?></th><th class="r"><?= e(t('Total')) ?></th></tr>
<?php foreach ($rows as $r): ?><tr class="click" onclick="location='<?= e(url('documents', ['a' => 'view', 'id' => $r['id']])) ?>'"><td><?= e(substr($r['created_at'], 0, 10)) ?><br><small><?= e($r['reference']) ?></small></td><td><?= e($r['customer_name'] ?: t('Client Passager')) ?></td><td><?= e($r['status'] === 'converted' ? t('Converti en facture') : t('Ouvert')) ?></td><td class="r"><?= e(money_fmt((float)$r['total'])) ?></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="mu">' . e(t('Aucun document')) . '</td></tr>'; ?></table></div>
<?php render_foot(); return; }
    $d = doc_load($id);
    if (!$d) { flash(t('Document introuvable'), 'err'); go('documents'); }
    if ($a === 'pdf') { header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', $d['reference']) . '.pdf"'); echo doc_pdf($d); exit; }
    if ($a === 'wa') {
        post_only();
        try {
            $acc = bot_account($l, false); $ph = preg_replace('/\D/', '', $d['customer_phone']);
            if (!$acc || !$acc['enabled'] || $acc['wa_state'] !== 'open') throw new RuntimeException(t("Liez d'abord votre WhatsApp (menu WhatsApp)."));
            if (strlen($ph) < 8) throw new RuntimeException(t('Numéro WhatsApp du client manquant.'));
            evo_send_pdf($acc, $ph, doc_pdf($d), $d['reference'] . '.pdf', $d['reference'] . ' — ' . money_fmt((float)$d['total']));
            flash(t('Facture envoyée sur WhatsApp ✓'));
        } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
        go('documents', ['a' => 'view', 'id' => $id]);
    }
    if ($a === 'delete') { post_only(); if ($d['status'] === 'open') { sync_tomb_web('documents', 'w_documents', $id); } if ($d['status'] === 'open') $pdo->prepare('DELETE FROM w_documents WHERE id=? AND license_id=?')->execute([$id, $l]); go('documents'); }
    if ($a === 'convert') {
        post_only(); $method = in_array($_POST['method'] ?? '', ['cash', 'mobile_money', 'credit'], true) ? $_POST['method'] : 'cash';
        $pdo->beginTransaction();
        try {
            $cur = $pdo->prepare('SELECT status FROM w_documents WHERE id=? AND license_id=? FOR UPDATE'); $cur->execute([$id, $l]);
            if ($cur->fetchColumn() !== 'open') throw new RuntimeException(t('Déjà converti'));
            if ($method === 'credit' && !$d['customer_id']) throw new RuntimeException(t('Sélectionnez un client pour une vente à crédit'));
            $sel = $pdo->prepare('SELECT * FROM w_products WHERE id=? AND license_id=? FOR UPDATE'); $lines = []; $sub = 0;
            foreach ($d['items'] as $it) {
                $sel->execute([$it['product_id'], $l]); $p = $sel->fetch(); if (!$p) throw new RuntimeException(t('Produit introuvable') . ' : ' . $it['name']);
                if ((int)$p['stock'] < (int)$it['qty']) throw new RuntimeException(t('Stock insuffisant : ') . $p['name'] . ' (' . (int)$p['stock'] . ')');
                $lines[] = [$p, (int)$it['qty']]; $sub += (float)$p['sell_price'] * (int)$it['qty'];
            }
            $tax = round($sub * tax_rate() / 100.0); $tot = $sub + $tax; $paid = $method === 'credit' ? 0 : $tot; $ref = 'INV-' . gmdate('Ymd-His'); $k = 0;
            while (true) { $x = $pdo->prepare('SELECT 1 FROM w_sales WHERE license_id=? AND reference=?'); $x->execute([$l, $ref . ($k ? "-$k" : '')]); if (!$x->fetch()) break; $k++; }
            if ($k) $ref .= "-$k";
            $pdo->prepare('INSERT INTO w_sales (license_id,reference,customer_id,customer_name,customer_phone,subtotal,tax_amount,total,paid,payment,source,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$l, $ref, $d['customer_id'], $d['customer_name'], $d['customer_phone'], $sub, $tax, $tot, $paid, $method, 'web', now()]);
            $sid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO w_sale_items (sale_id,product_id,name,qty,unit_price,buy_price) VALUES (?,?,?,?,?,?)'); $dec = $pdo->prepare('UPDATE w_products SET stock=stock-? WHERE id=? AND license_id=?'); $cm = stk_cmp_map($l);
            foreach ($lines as [$p, $q]) { $ins->execute([$sid, $p['id'], $p['name'], $q, $p['sell_price'], $cm[$p['id']] ?? $p['buy_price']]); $dec->execute([$q, $p['id'], $l]); }
            $pdo->prepare("UPDATE w_documents SET status='converted', sale_id=? WHERE id=?")->execute([$sid, $id]); $pdo->commit();
            flash(t('Converti en facture') . ' ✓'); go('sales', ['a' => 'view', 'id' => $sid]);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'err'); go('documents', ['a' => 'view', 'id' => $id]); }
    }
    render_head($d['reference'], 'documents'); ?>
<div class="bar noprint"><h1><?= e($d['reference']) ?></h1><div class="actions"><button class="btn" onclick="print()">🖨 <?= e(t('Imprimer')) ?></button><a class="btn" href="<?= e(url('documents', ['a' => 'pdf', 'id' => $id])) ?>" target="_blank">PDF</a>
<form method="post" action="<?= e(url('documents', ['a' => 'wa', 'id' => $id])) ?>" class="inline"><?= csrf_field() ?><button class="btn cta">💬 WhatsApp</button></form></div></div>
<div class="card invoice"><div class="ih"><b><?= e(DOC_TYPES[$d['doc_type']][0]) ?></b><span><?= e($d['created_at']) ?> UTC<br><small><?= e(t('Validité : ')) ?><?= e((string)$d['valid_until']) ?></small></span></div>
<p><b><?= e(t('Client')) ?> :</b> <?= e($d['customer_name'] ?: t('Client Passager')) ?> <?= e($d['customer_phone']) ?></p>
<table><tr><th><?= e(t('Article')) ?></th><th class="r"><?= e(t('Qté')) ?></th><th class="r">P.U.</th><th class="r">Total</th></tr>
<?php foreach ($d['items'] as $i) echo '<tr><td>' . e($i['name']) . '</td><td class="r">' . (int)$i['qty'] . '</td><td class="r">' . e(money_fmt((float)$i['unit_price'])) . '</td><td class="r">' . e(money_fmt((float)$i['unit_price'] * (int)$i['qty'])) . '</td></tr>'; ?></table>
<div class="totals"><div><?= e(t('Sous-total')) ?><b><?= e(money_fmt((float)$d['subtotal'])) ?></b></div><?php if ($d['tax_amount'] > 0) echo '<div>' . e(setting('tax_label', 'TVA')) . '<b>' . e(money_fmt((float)$d['tax_amount'])) . '</b></div>'; ?>
<div class="grand"><?= e(t('Total')) ?><b><?= e(money_fmt((float)$d['total'])) ?></b></div></div></div>
<?php if ($d['status'] === 'open'): ?><form method="post" action="<?= e(url('documents', ['a' => 'convert', 'id' => $id])) ?>" class="card noprint"><?= csrf_field() ?><h2><?= e(t('Convertir en facture')) ?></h2>
<select name="method"><option value="cash"><?= e(t('Espèces')) ?></option><option value="mobile_money">Mobile Money</option><option value="credit"><?= e(t('Crédit')) ?></option></select><button class="btn cta"><?= e(t('Convertir en facture')) ?></button></form>
<form method="post" action="<?= e(url('documents', ['a' => 'delete', 'id' => $id])) ?>" class="noprint" onsubmit="return confirm('?')"><?= csrf_field() ?><button class="btn">🗑 <?= e(t('Supprimer')) ?></button></form>
<?php else: ?><p class="mu"><?= e(t('Converti en facture')) ?> ✓ <a href="<?= e(url('sales', ['a' => 'view', 'id' => (int)$d['sale_id']])) ?>"><?= e(t('Voir le détail')) ?></a></p><?php endif;
    render_foot(); }
