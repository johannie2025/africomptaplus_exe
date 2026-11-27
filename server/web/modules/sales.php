<?php
function sale_load(int $id): ?array {
    $s = db()->prepare('SELECT * FROM w_sales WHERE id=? AND license_id=?'); $s->execute([$id, lid()]); $sale = $s->fetch();
    if (!$sale) return null;
    $i = db()->prepare('SELECT * FROM w_sale_items WHERE sale_id=? ORDER BY id'); $i->execute([$id]); $sale['items'] = $i->fetchAll();
    return $sale;
}
function sale_pdf(array $sale): string {
    $acc = ['shop_name' => setting('shop_name', (string)me()['lshop']), 'currency' => setting('currency', 'XAF'), 'tax_label' => setting('tax_label', 'TVA'), 'momo_info' => (string)(bot_account(lid(), false)['momo_info'] ?? '')];
    $o = ['reference' => $sale['reference'], 'created_at' => $sale['created_at'], 'customer_name' => $sale['customer_name'], 'phone' => preg_replace('/\D/', '', $sale['customer_phone']),
          'subtotal' => $sale['subtotal'], 'tax_amount' => $sale['tax_amount'], 'total' => $sale['total'], 'payment' => $sale['payment']];
    $items = array_map(fn($i) => ['name' => $i['name'], 'qty' => (int)$i['qty'], 'price' => (float)$i['unit_price']], $sale['items']);
    return bot_invoice_pdf($acc, $o, $items);
}
function mod_sales(): void {
    $l = lid(); $pdo = db(); $a = (string)($_GET['a'] ?? ''); $id = (int)($_GET['id'] ?? 0);
    if ($a === 'create') {            // POST JSON depuis le point de vente
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) json_out(['ok' => false, 'error' => 'csrf'], 403);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in) || empty($in['items'])) json_out(['ok' => false, 'error' => t('Panier vide')]);
        $method = in_array($in['payment'] ?? '', ['cash', 'mobile_money', 'credit'], true) ? $in['payment'] : 'cash';
        $cid = (int)($in['customer_id'] ?? 0); $cname = mb_substr(trim((string)($in['customer_name'] ?? '')), 0, 120); $cphone = mb_substr(trim((string)($in['customer_phone'] ?? '')), 0, 30);
        $pdo->beginTransaction();
        try {
            if ($cid) { $c = $pdo->prepare('SELECT * FROM w_customers WHERE id=? AND license_id=?'); $c->execute([$cid, $l]); $cu = $c->fetch(); if (!$cu) throw new RuntimeException('client'); $cname = $cu['name']; $cphone = $cu['phone']; }
            elseif ($cname !== '') { $pdo->prepare('INSERT INTO w_customers (license_id,name,phone,created_at) VALUES (?,?,?,?)')->execute([$l, $cname, $cphone, now()]); $cid = (int)$pdo->lastInsertId(); }
            elseif ($method === 'credit') throw new RuntimeException(t('Sélectionnez un client pour une vente à crédit'));
            $sub = 0; $lines = [];
            $sel = $pdo->prepare('SELECT * FROM w_products WHERE id=? AND license_id=? FOR UPDATE');
            foreach ($in['items'] as $it) {
                $q = max(1, min(99999, (int)($it['qty'] ?? 1))); $sel->execute([(int)($it['id'] ?? 0), $l]); $p = $sel->fetch();
                if (!$p) throw new RuntimeException(t('Produit introuvable'));
                if ((int)$p['stock'] < $q) throw new RuntimeException(t('Stock insuffisant : ') . $p['name'] . ' (' . (int)$p['stock'] . ')');
                $lines[] = [$p, $q]; $sub += (float)$p['sell_price'] * $q;
            }
            $tax = round($sub * tax_rate() / 100.0); $tot = $sub + $tax;
            $paid = $method === 'credit' ? max(0, min($tot, (float)($in['paid'] ?? 0))) : $tot;
            $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['due_date'] ?? '')) ? $in['due_date'] : null;
            $ref = 'INV-' . gmdate('Ymd-His'); $k = 0;
            while (true) { $x = $pdo->prepare('SELECT 1 FROM w_sales WHERE license_id=? AND reference=?'); $x->execute([$l, $ref . ($k ? "-$k" : '')]); if (!$x->fetch()) break; $k++; }
            if ($k) $ref .= "-$k";
            $pdo->prepare('INSERT INTO w_sales (license_id,reference,customer_id,customer_name,customer_phone,subtotal,tax_amount,total,paid,payment,due_date,source,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$l, $ref, $cid ?: null, $cname, $cphone, $sub, $tax, $tot, $paid, $method, $due, 'web', now()]);
            $sid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO w_sale_items (sale_id,product_id,name,qty,unit_price,buy_price) VALUES (?,?,?,?,?,?)'); $cm = stk_cmp_map($l);
            $dec = $pdo->prepare('UPDATE w_products SET stock=stock-? WHERE id=? AND license_id=?');
            foreach ($lines as [$p, $q]) { $ins->execute([$sid, $p['id'], $p['name'], $q, $p['sell_price'], $cm[$p['id']] ?? $p['buy_price']]); $dec->execute([$q, $p['id'], $l]); }
            if ($method === 'credit' && $paid > 0) $pdo->prepare('INSERT INTO w_payments (license_id,sale_id,amount,method,created_at) VALUES (?,?,?,?,?)')->execute([$l, $sid, $paid, 'cash', now()]);
            $pdo->commit();
            json_out(['ok' => true, 'id' => $sid]);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_out(['ok' => false, 'error' => $e->getMessage()]); }
    }
    if (!$id && $a === '') { sales_list(); return; }
    $sale = $id ? sale_load($id) : null;
    if (!$sale) { flash(t('Aucune vente pour le moment.'), 'err'); go('dashboard'); }
    if ($a === 'delete') {        // annule la vente : stock remis, paiements supprimés
        post_only(); $pdo->beginTransaction();
        try {
            $up = $pdo->prepare('UPDATE w_products SET stock=stock+? WHERE id=? AND license_id=?');
            foreach ($sale['items'] as $i) if ($i['product_id']) $up->execute([(int)$i['qty'], $i['product_id'], $l]);
            sync_tomb_web('sales', 'w_sales', $id);
            $pdo->prepare('DELETE FROM w_sales WHERE id=? AND license_id=?')->execute([$id, $l]); $pdo->commit(); flash(t('Supprimé ✓'));
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'err'); }
        go('sales');
    }
    if ($a === 'pdf') {
        $pdf = sale_pdf($sale);
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Facture-' . preg_replace('/[^A-Za-z0-9_-]/', '', $sale['reference']) . '.pdf"'); echo $pdf; exit;
    }
    if ($a === 'wa') {
        post_only();
        try {
            $acc = bot_account($l, false); $ph = preg_replace('/\D/', '', $sale['customer_phone']);
            if (!$acc || !$acc['enabled'] || $acc['wa_state'] !== 'open') throw new RuntimeException(t("Liez d'abord votre WhatsApp (menu WhatsApp)."));
            if (strlen($ph) < 8) throw new RuntimeException(t('Numéro WhatsApp du client manquant.'));
            evo_send_pdf($acc, $ph, sale_pdf($sale), 'Facture-' . $sale['reference'] . '.pdf', t('Facture') . ' ' . $sale['reference'] . ' — ' . money_fmt((float)$sale['total']));
            flash(t('Facture envoyée sur WhatsApp ✓'));
        } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
        go('sales', ['a' => 'view', 'id' => $id]);
    }
    $pm = ['cash' => t('Espèces'), 'mobile_money' => 'Mobile Money', 'credit' => t('Crédit')][$sale['payment']] ?? $sale['payment'];
    render_head($sale['reference'], 'pos'); ?>
<div class="bar noprint"><h1><?= e($sale['reference']) ?></h1><div class="actions"><button class="btn" onclick="print()">🖨 <?= e(t('Imprimer')) ?></button>
<a class="btn" href="<?= e(url('sales', ['a' => 'pdf', 'id' => $id])) ?>" target="_blank">PDF</a>
<form method="post" action="<?= e(url('sales', ['a' => 'wa', 'id' => $id])) ?>" class="inline"><?= csrf_field() ?><button class="btn cta">💬 <?= e(t('Envoyer par WhatsApp')) ?></button></form>
<a class="btn" href="<?= e(url('pos')) ?>">+ <?= e(t('Nouvelle vente')) ?></a>
<form method="post" action="<?= e(url('sales', ['a' => 'delete', 'id' => $id])) ?>" class="inline" onsubmit="return confirm('<?= e(t('Supprimer cette vente ? Le stock sera remis.')) ?>')"><?= csrf_field() ?><button class="btn">🗑</button></form></div></div>
<div class="card invoice"><div class="ih"><b><?= e(setting('shop_name', (string)me()['lshop'])) ?></b><span><?= e($sale['reference']) ?><br><small><?= e($sale['created_at']) ?> UTC</small></span></div>
<p><b><?= e(t('Client')) ?> :</b> <?= e($sale['customer_name'] ?: t('Client Passager')) ?> <?= e($sale['customer_phone']) ?></p>
<table><tr><th><?= e(t('Article')) ?></th><th class="r"><?= e(t('Qté')) ?></th><th class="r">P.U.</th><th class="r">Total</th></tr>
<?php foreach ($sale['items'] as $i) echo '<tr><td>' . e($i['name']) . '</td><td class="r">' . (int)$i['qty'] . '</td><td class="r">' . e(money_fmt((float)$i['unit_price'])) . '</td><td class="r">' . e(money_fmt((float)$i['unit_price'] * (int)$i['qty'])) . '</td></tr>'; ?></table>
<div class="totals"><div><?= e(t('Sous-total')) ?><b><?= e(money_fmt((float)$sale['subtotal'])) ?></b></div>
<?php if ($sale['tax_amount'] > 0) echo '<div>' . e(setting('tax_label', 'TVA')) . '<b>' . e(money_fmt((float)$sale['tax_amount'])) . '</b></div>'; ?>
<div class="grand"><?= e(t('Total')) ?><b><?= e(money_fmt((float)$sale['total'])) ?></b></div>
<div><?= e(t('Paiement : ')) ?><b><?= e($pm) ?></b></div>
<?php if ($sale['total'] > $sale['paid']) echo '<div>' . e(t('Reste à payer')) . '<b class="neg">' . e(money_fmt((float)($sale['total'] - $sale['paid']))) . '</b></div>'; ?></div></div>
<?php render_foot(); }

function sales_list(): void {
    $l = lid(); $q = trim((string)($_GET['q'] ?? '')); $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? ''); $pay = (string)($_GET['pay'] ?? '');
    $ok = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
    $w = 'license_id=?'; $a = [$l];
    if ($q !== '') { $w .= ' AND (reference LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ?)'; $k = '%' . addcslashes($q, '%_') . '%'; array_push($a, $k, $k, $k); }
    if ($ok($from)) { $w .= ' AND DATE(created_at)>=?'; $a[] = $from; }
    if ($ok($to)) { $w .= ' AND DATE(created_at)<=?'; $a[] = $to; }
    if (in_array($pay, ['cash', 'mobile_money', 'credit'], true)) { $w .= ' AND payment=?'; $a[] = $pay; }
    $s = db()->prepare("SELECT id,reference,customer_name,total,paid,payment,created_at FROM w_sales WHERE $w ORDER BY created_at DESC,id DESC LIMIT 1000"); $s->execute($a); $rows = $s->fetchAll();
    $tot = array_sum(array_map(fn($r) => (float)$r['total'], $rows));
    $pm = ['cash' => t('Espèces'), 'mobile_money' => 'Mobile Money', 'credit' => t('Crédit')];
    if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="ventes.csv"'); echo "\xEF\xBB\xBF"; $f = fopen('php://output', 'w');
        fputcsv($f, ['Reference', 'Date', 'Client', 'Paiement', 'Total', 'Paye'], ';');
        foreach ($rows as $r) fputcsv($f, [$r['reference'], $r['created_at'], $r['customer_name'], $r['payment'], $r['total'], $r['paid']], ';'); exit;
    }
    render_head(t('Historique des ventes'), 'sales'); ?>
<div class="bar"><h1><?= e(t('Historique des ventes')) ?></h1><a class="btn" href="<?= e(url('sales', ['csv' => 1, 'q' => $q, 'from' => $from, 'to' => $to, 'pay' => $pay])) ?>">⬇ CSV</a></div>
<form method="get" class="card"><input type="hidden" name="m" value="sales"><input name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Rechercher (réf., client, téléphone)')) ?>">
<div class="two"><input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"></div>
<select name="pay"><option value=""><?= e(t('Tous les paiements')) ?></option><?php foreach ($pm as $k => $v) echo '<option value="' . $k . '"' . ($pay === $k ? ' selected' : '') . '>' . e($v) . '</option>'; ?></select>
<button class="btn cta"><?= e(t('Filtrer')) ?></button></form>
<div class="kpis"><div class="kpi"><small><?= count($rows) ?> <?= e(t('ventes')) ?></small><b><?= e(money_fmt($tot)) ?></b></div></div>
<div class="card tw"><table><tr><th><?= e(t('Date')) ?></th><th><?= e(t('Client')) ?></th><th><?= e(t('Paiement')) ?></th><th class="r"><?= e(t('Total')) ?></th></tr>
<?php foreach ($rows as $r): ?><tr class="click" onclick="location='<?= e(url('sales', ['a' => 'view', 'id' => $r['id']])) ?>'"><td><?= e(substr($r['created_at'], 0, 10)) ?><br><small><?= e($r['reference']) ?></small></td><td><?= e($r['customer_name'] ?: t('Client Passager')) ?></td><td><?= e($pm[$r['payment']] ?? $r['payment']) ?><?= $r['total'] > $r['paid'] ? ' ⚠' : '' ?></td><td class="r"><?= e(money_fmt((float)$r['total'])) ?></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="mu">' . e(t('Aucune vente pour le moment.')) . '</td></tr>'; ?></table></div>
<?php render_foot(); }
