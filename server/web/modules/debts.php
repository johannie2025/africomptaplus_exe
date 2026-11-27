<?php
function mod_debts(): void {
    if (($_GET['a'] ?? '') === 'receipt') {
        $l0 = lid(); $q = db()->prepare('SELECT p.*, s.customer_name, s.reference sref, s.total, s.paid sp, s.id sid FROM w_payments p JOIN w_sales s ON s.id=p.sale_id WHERE p.id=? AND p.license_id=?'); $q->execute([(int)($_GET['pid'] ?? 0), $l0]); $p = $q->fetch();
        if (!$p) { http_response_code(404); exit; }
        $after = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM w_payments WHERE sale_id=? AND id<=?'); $after->execute([$p['sid'], $p['id']]);
        $acc = ['shop_name' => setting('shop_name', (string)me()['lshop']), 'currency' => setting('currency', 'XAF'), 'tax_label' => setting('tax_label', 'TVA'), 'momo_info' => ''];
        $paidInit = (float)$p['sp'] - (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM w_payments WHERE sale_id=' . (int)$p['sid'])->fetchColumn();
        $rest = max(0, (float)$p['total'] - $paidInit - (float)$after->fetchColumn());
        $r = ['reference' => 'REC-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT), 'created_at' => $p['created_at'], 'customer_name' => $p['customer_name'], 'sale_reference' => $p['sref'], 'amount' => (float)$p['amount'], 'method' => $p['method'], 'remaining' => $rest];
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $r['reference'] . '.pdf"'); echo receipt_pdf($acc, $r); exit;
    }
    $l = lid(); $pdo = db(); $a = (string)($_GET['a'] ?? ''); $id = (int)($_GET['id'] ?? 0);
    if ($a === 'pay') {
        post_only(); $sid = (int)$_POST['id'];
        $pdo->beginTransaction();
        try {
            $s = $pdo->prepare('SELECT * FROM w_sales WHERE id=? AND license_id=? FOR UPDATE'); $s->execute([$sid, $l]); $sale = $s->fetch();
            if (!$sale) throw new RuntimeException(t('Montant invalide'));
            $amt = min(max(0, f_num('amount')), (float)$sale['total'] - (float)$sale['paid']);
            if ($amt <= 0) throw new RuntimeException(t('Montant invalide'));
            $m = in_array($_POST['method'] ?? '', ['cash', 'mobile_money'], true) ? $_POST['method'] : 'cash';
            $pdo->prepare('INSERT INTO w_payments (license_id,sale_id,amount,method,created_at) VALUES (?,?,?,?,?)')->execute([$l, $sid, $amt, $m, now()]);
            $pdo->prepare('UPDATE w_sales SET paid=paid+? WHERE id=?')->execute([$amt, $sid]); $pdo->commit();
            flash(t('Paiement enregistré ✓'));
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'err'); }
        go('debts', ['id' => $sid]);
    }
    if ($id) {
        $s = $pdo->prepare('SELECT * FROM w_sales WHERE id=? AND license_id=?'); $s->execute([$id, $l]); $sale = $s->fetch();
        if ($sale) {
            $p = $pdo->prepare('SELECT * FROM w_payments WHERE sale_id=? AND license_id=? ORDER BY id'); $p->execute([$id, $l]); $pays = $p->fetchAll();
            render_head(t('Détail de la dette'), 'debts'); $rest = (float)$sale['total'] - (float)$sale['paid']; ?>
<h1><?= e(t('Détail de la dette')) ?> — <?= e($sale['customer_name']) ?></h1>
<div class="kpis"><div class="kpi"><small><?= e(t('Montant de la dette')) ?></small><b><?= e(money_fmt((float)$sale['total'])) ?></b></div><div class="kpi"><small><?= e(t('Total déjà payé')) ?></small><b><?= e(money_fmt((float)$sale['paid'])) ?></b></div><div class="kpi warn"><small><?= e(t('Reste à payer')) ?></small><b><?= e(money_fmt($rest)) ?></b></div></div>
<?php if ($rest > 0): ?><form method="post" action="<?= e(url('debts', ['a' => 'pay'])) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><h2><?= e(t('Enregistrer un paiement')) ?></h2>
<div class="two"><input name="amount" type="number" min="1" max="<?= $rest ?>" step="any" value="<?= (int)$rest ?>" required><select name="method"><option value="cash"><?= e(t('Espèces')) ?></option><option value="mobile_money">Mobile Money</option></select></div><button class="btn cta"><?= e(t('Enregistrer')) ?></button></form><?php endif; ?>
<div class="card"><h2><?= e(t('Historique des paiements')) ?></h2>
<?php foreach ($pays as $x) echo '<div class="li"><span>' . e($x['created_at']) . ' · ' . e($x['method']) . ' · <a href="' . e(url('debts', ['a' => 'receipt', 'pid' => $x['id']])) . '" target="_blank">🧾 REC-' . str_pad((string)$x['id'], 5, '0', STR_PAD_LEFT) . '</a></span><b>' . e(money_fmt((float)$x['amount'])) . '</b></div>'; if (!$pays) echo '<p class="mu">' . e(t('Aucun paiement enregistré.')) . '</p>'; ?>
<a class="btn" href="<?= e(url('sales', ['a' => 'view', 'id' => $id])) ?>"><?= e(t('Voir le détail')) ?></a></div>
<?php render_foot(); return; }
    }
    $s = $pdo->prepare('SELECT id,reference,customer_name,customer_phone,total,paid,due_date,created_at FROM w_sales WHERE license_id=? AND total>paid ORDER BY created_at DESC LIMIT 300'); $s->execute([$l]); $rows = $s->fetchAll();
    $sum = array_sum(array_map(fn($r) => (float)$r['total'] - (float)$r['paid'], $rows));
    render_head(t('Suivi des dettes'), 'debts'); ?>
<h1><?= e(t('Suivi des dettes')) ?></h1><div class="kpis"><div class="kpi warn"><small><?= e(t('Total dû')) ?></small><b><?= e(money_fmt($sum)) ?></b></div></div>
<div class="card tw"><table><tr><th><?= e(t('Client')) ?></th><th><?= e(t('Réf. : ')) ?></th><th><?= e(t('Échéance : ')) ?></th><th class="r"><?= e(t('Reste dû')) ?></th></tr>
<?php foreach ($rows as $r): ?><tr class="click" onclick="location='<?= e(url('debts', ['id' => $r['id']])) ?>'"><td><b><?= e($r['customer_name']) ?></b><br><small><?= e($r['customer_phone']) ?></small></td><td><?= e($r['reference']) ?></td><td><?= e((string)$r['due_date']) ?></td><td class="r neg"><?= e(money_fmt((float)$r['total'] - (float)$r['paid'])) ?></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="mu">' . e(t('Aucune dette en cours 🎉')) . '</td></tr>'; ?></table></div>
<?php render_foot(); }
