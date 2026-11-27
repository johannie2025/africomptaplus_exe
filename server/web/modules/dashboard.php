<?php
function mod_dashboard(): void {
    $l = lid(); $pdo = db();
    $q = function (string $sql, array $a = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($a); return $s; };
    $today = gmdate('Y-m-d'); $month = gmdate('Y-m-01');
    $ca = (float)$q('SELECT COALESCE(SUM(subtotal),0) FROM w_sales WHERE license_id=? AND DATE(created_at)=?', [$l, $today])->fetchColumn();
    $n = (int)$q('SELECT COUNT(*) FROM w_sales WHERE license_id=? AND DATE(created_at)=?', [$l, $today])->fetchColumn();
    $cam = (float)$q('SELECT COALESCE(SUM(subtotal),0) FROM w_sales WHERE license_id=? AND created_at>=?', [$l, $month])->fetchColumn();
    $due = (float)$q('SELECT COALESCE(SUM(total-paid),0) FROM w_sales WHERE license_id=? AND total>paid', [$l])->fetchColumn();
    $low = $q('SELECT name, stock FROM w_products WHERE license_id=? AND stock<=min_stock ORDER BY stock LIMIT 8', [$l])->fetchAll();
    $last = $q('SELECT id,reference,customer_name,total,payment,source,created_at FROM w_sales WHERE license_id=? ORDER BY id DESC LIMIT 6', [$l])->fetchAll();
    render_head(t('Accueil'), 'dashboard'); ?>
<h1><?= e(t('Bonjour,')) ?> <?= e(setting('shop_name', (string)me()['lshop'])) ?></h1>
<div class="kpis">
<div class="kpi"><small><?= e(t("CA aujourd'hui")) ?></small><b><?= e(money_fmt($ca)) ?></b></div>
<div class="kpi"><small><?= e(t('Nombre de ventes')) ?></small><b><?= $n ?></b></div>
<div class="kpi"><small><?= e(t('Ventes du mois')) ?></small><b><?= e(money_fmt($cam)) ?></b></div>
<div class="kpi warn"><small><?= e(t('Dettes en cours')) ?></small><b><?= e(money_fmt($due)) ?></b></div></div>
<div class="actions"><a class="btn cta" href="<?= e(url('pos')) ?>">🛒 <?= e(t('Nouvelle vente')) ?></a>
<a class="btn" href="<?= e(url('products', ['a' => 'edit'])) ?>">📦 <?= e(t('Nouveau produit')) ?></a>
<a class="btn" href="<?= e(url('whatsapp')) ?>">💬 <?= e(t('Bot WhatsApp')) ?></a></div>
<div class="cols"><div class="card"><h2><?= e(t('Stock critique')) ?></h2>
<?php foreach ($low as $p) echo '<div class="li"><span>' . e($p['name']) . '</span><b class="neg">' . (int)$p['stock'] . '</b></div>'; if (!$low) echo '<p class="mu">—</p>'; ?></div>
<div class="card"><h2><?= e(t('Historique des ventes')) ?></h2>
<?php foreach ($last as $s) echo '<a class="li" href="' . e(url('sales', ['a' => 'view', 'id' => $s['id']])) . '"><span>' . e($s['reference']) . ($s['source'] === 'bot' ? ' 🤖' : '') . '<br><small>' . e($s['customer_name'] ?: t('Client Passager')) . '</small></span><b>' . e(money_fmt((float)$s['total'])) . '</b></a>'; if (!$last) echo '<p class="mu">' . e(t('Aucune vente pour le moment.')) . '</p>'; ?></div></div>
<?php render_foot(); }
