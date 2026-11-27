<?php
function mod_reports(): void {
    $l = lid(); $pdo = db();
    $p = (string)($_GET['p'] ?? 'month'); $today = gmdate('Y-m-d');
    $map = ['day' => [$today, $today], 'week' => [gmdate('Y-m-d', strtotime('monday this week')), $today], 'month' => [gmdate('Y-m-01'), $today], 'year' => [gmdate('Y-01-01'), $today]];
    [$from, $to] = $map[$p] ?? [preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : gmdate('Y-m-01'), preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : $today];
    $one = function (string $sql, array $a) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($a); return (float)$s->fetchColumn(); };
    $ca = $one('SELECT COALESCE(SUM(subtotal),0) FROM w_sales WHERE license_id=? AND DATE(created_at) BETWEEN ? AND ?', [$l, $from, $to]);
    $n = (int)$one('SELECT COUNT(*) FROM w_sales WHERE license_id=? AND DATE(created_at) BETWEEN ? AND ?', [$l, $from, $to]);
    $cogs = $one('SELECT COALESCE(SUM(i.qty*i.buy_price),0) FROM w_sale_items i JOIN w_sales s ON s.id=i.sale_id WHERE s.license_id=? AND DATE(s.created_at) BETWEEN ? AND ?', [$l, $from, $to]);
    $exp = $one('SELECT COALESCE(SUM(amount),0) FROM w_expenses WHERE license_id=? AND DATE(created_at) BETWEEN ? AND ?', [$l, $from, $to]);
    $gross = $ca - $cogs; $net = $gross - $exp;
    $d = $pdo->prepare('SELECT DATE(created_at) d, SUM(subtotal) v FROM w_sales WHERE license_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY d ORDER BY d'); $d->execute([$l, $from, $to]); $days = $d->fetchAll();
    $t = $pdo->prepare('SELECT i.name, SUM(i.qty) q, SUM(i.qty*i.unit_price) r FROM w_sale_items i JOIN w_sales s ON s.id=i.sale_id WHERE s.license_id=? AND DATE(s.created_at) BETWEEN ? AND ? GROUP BY i.name ORDER BY r DESC LIMIT 10'); $t->execute([$l, $from, $to]); $top = $t->fetchAll();
    $max = max(1, ...array_map(fn($x) => (float)$x['v'], $days ?: [['v' => 1]]));
    render_head(t('Rapports financiers'), 'reports'); ?>
<h1><?= e(t('Rapports financiers')) ?></h1>
<div class="seg2"><?php foreach (['day' => t('JOUR'), 'week' => t('SEM.'), 'month' => t('MOIS'), 'year' => t('ANNÉE')] as $k => $v) echo '<a class="' . ($p === $k ? 'on' : '') . '" href="' . e(url('reports', ['p' => $k])) . '">' . e($v) . '</a>'; ?></div>
<form class="search two" method="get"><input type="hidden" name="m" value="reports"><input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"><button class="btn"><?= e(t('Charger')) ?></button></form>
<div class="kpis"><div class="kpi"><small><?= e(t("Chiffre d'affaires")) ?></small><b><?= e(money_fmt($ca)) ?></b></div><div class="kpi"><small><?= e(t('Nombre de ventes')) ?></small><b><?= $n ?></b></div>
<div class="kpi"><small><?= e(t('Bénéfice brut')) ?></small><b><?= e(money_fmt($gross)) ?></b></div><div class="kpi"><small><?= e(t('Dépenses')) ?></small><b><?= e(money_fmt($exp)) ?></b></div>
<div class="kpi <?= $net < 0 ? 'warn' : '' ?>"><small><?= e(t('Bénéfice net')) ?></small><b><?= e(money_fmt($net)) ?></b></div></div>
<div class="card"><h2><?= e(t("Évolution du chiffre d'affaires")) ?></h2><div class="bars">
<?php foreach ($days as $x) echo '<div class="bw"><div class="bar1" style="height:' . max(2, round((float)$x['v'] / $max * 100)) . '%" title="' . e(money_fmt((float)$x['v'])) . '"></div><small>' . e(substr($x['d'], 8)) . '</small></div>'; if (!$days) echo '<p class="mu">' . e(t('Aucune vente sur la période')) . '</p>'; ?></div></div>
<div class="card tw"><h2><?= e(t('Meilleures ventes par produit')) ?></h2><table><?php foreach ($top as $x) echo '<tr><td>' . e($x['name']) . '</td><td class="r">' . (int)$x['q'] . '</td><td class="r">' . e(money_fmt((float)$x['r'])) . '</td></tr>'; ?></table></div>
<?php render_foot(); }
