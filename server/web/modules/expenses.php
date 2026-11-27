<?php
function mod_expenses(): void {
    $l = lid(); $pdo = db(); $a = (string)($_GET['a'] ?? '');
    if ($a === 'add') {
        post_only(); $amt = f_num('amount');
        if ($amt <= 0) { flash(t('Montant requis'), 'err'); go('expenses'); }
        $pdo->prepare('INSERT INTO w_expenses (license_id,category,description,amount,created_at) VALUES (?,?,?,?,?)')->execute([$l, f_str('category', 60), f_str('description', 200), $amt, now()]);
        flash(t('Dépense enregistrée')); go('expenses');
    }
    if ($a === 'delete') { post_only(); sync_tomb_web('expenses', 'w_expenses', (int)$_POST['id']); $pdo->prepare('DELETE FROM w_expenses WHERE id=? AND license_id=?')->execute([(int)$_POST['id'], $l]); flash(t('Supprimer')); go('expenses'); }
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : gmdate('Y-m-01'); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : gmdate('Y-m-d');
    $s = $pdo->prepare('SELECT * FROM w_expenses WHERE license_id=? AND DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC LIMIT 500'); $s->execute([$l, $from, $to]); $rows = $s->fetchAll();
    $tot = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
    render_head(t('Dépenses'), 'expenses'); ?>
<h1><?= e(t('Dépenses')) ?></h1>
<form method="post" action="<?= e(url('expenses', ['a' => 'add'])) ?>" class="card"><?= csrf_field() ?><h2><?= e(t('Nouvelle dépense')) ?></h2>
<div class="two"><input name="category" placeholder="<?= e(t('Catégorie')) ?> (Loyer, Transport, Stock…)" list="cats"><input name="amount" type="number" min="1" step="any" required placeholder="<?= e(t('Montant')) ?>"></div>
<input name="description" placeholder="<?= e(t('Description')) ?>"><datalist id="cats"><option>Loyer</option><option>Transport</option><option>Électricité</option><option>Salaires</option><option>Stock</option></datalist>
<button class="btn cta"><?= e(t('Enregistrer')) ?></button></form>
<form class="search two" method="get"><input type="hidden" name="m" value="expenses"><input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"><button class="btn"><?= e(t('Charger')) ?></button></form>
<div class="card tw"><table><tr><th><?= e(t('Date')) ?></th><th><?= e(t('Catégorie')) ?></th><th><?= e(t('Description')) ?></th><th class="r"><?= e(t('Montant')) ?></th><th></th></tr>
<?php foreach ($rows as $r): ?><tr><td><small><?= e(substr($r['created_at'], 0, 10)) ?></small></td><td><?= e($r['category']) ?></td><td><?= e($r['description']) ?></td><td class="r"><?= e(money_fmt((float)$r['amount'])) ?></td>
<td><form method="post" action="<?= e(url('expenses', ['a' => 'delete'])) ?>" onsubmit="return confirm('<?= e(t('Supprimer cette dépense ?')) ?>')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">✕</button></form></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="5" class="mu">' . e(t('Aucune dépense enregistrée.')) . '</td></tr>'; ?>
<tr><th colspan="3"><?= e(t('Total des dépenses')) ?></th><th class="r"><?= e(money_fmt($tot)) ?></th><th></th></tr></table></div>
<?php render_foot(); }
