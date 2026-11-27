<?php
function mod_customers(): void {
    $l = lid(); $pdo = db(); $a = (string)($_GET['a'] ?? ''); $id = (int)($_GET['id'] ?? 0);
    if ($a === 'save') {
        post_only(); $cid = (int)($_POST['id'] ?? 0); $name = f_str('name', 120);
        if ($name === '') { flash(t('Nom requis'), 'err'); go('customers', ['a' => 'edit', 'id' => $cid]); }
        $v = [$name, f_str('phone', 30), f_str('email', 120), f_str('address', 200)];
        if ($cid) $pdo->prepare('UPDATE w_customers SET name=?,phone=?,email=?,address=? WHERE id=? AND license_id=?')->execute([...$v, $cid, $l]);
        else $pdo->prepare('INSERT INTO w_customers (name,phone,email,address,license_id,created_at) VALUES (?,?,?,?,?,?)')->execute([...$v, $l, now()]);
        flash(t('Enregistré')); go('customers');
    }
    if ($a === 'delete') {
        post_only(); $cid = (int)$_POST['id'];
        $n = $pdo->prepare('SELECT COUNT(*) FROM w_sales WHERE license_id=? AND customer_id=?'); $n->execute([$l, $cid]);
        if ((int)$n->fetchColumn() > 0) flash(t('Suppression impossible : ce client a des ventes'), 'err');
        else { sync_tomb_web('customers', 'w_customers', $cid); $pdo->prepare('DELETE FROM w_customers WHERE id=? AND license_id=?')->execute([$cid, $l]); flash(t('Supprimer')); }
        go('customers');
    }
    if ($a === 'edit') {
        $c = ['id' => 0, 'name' => '', 'phone' => '', 'email' => '', 'address' => ''];
        if ($id) { $s = $pdo->prepare('SELECT * FROM w_customers WHERE id=? AND license_id=?'); $s->execute([$id, $l]); $c = $s->fetch() ?: $c; }
        render_head($c['id'] ? t('Modifier') : t('Nouveau client'), 'customers'); ?>
<h1><?= e($c['id'] ? t('Modifier') : t('Nouveau client')) ?></h1>
<form method="post" action="<?= e(url('customers', ['a' => 'save'])) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
<label><?= e(t('Nom *')) ?></label><input name="name" required value="<?= e($c['name']) ?>">
<label><?= e(t('Téléphone')) ?></label><input name="phone" value="<?= e($c['phone']) ?>" inputmode="tel" placeholder="+240…">
<label><?= e(t('Email')) ?></label><input name="email" type="email" value="<?= e($c['email']) ?>">
<label><?= e(t('Adresse / quartier')) ?></label><input name="address" value="<?= e($c['address']) ?>">
<button class="btn cta"><?= e(t('Enregistrer')) ?></button></form>
<?php if ($c['id']): ?><form method="post" action="<?= e(url('customers', ['a' => 'delete'])) ?>" onsubmit="return confirm('<?= e(t('Supprimer ?')) ?>')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn danger"><?= e(t('Supprimer')) ?></button></form><?php endif;
        render_foot(); return;
    }
    $q = trim((string)($_GET['q'] ?? '')); $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $s = $pdo->prepare('SELECT c.*, (SELECT COALESCE(SUM(total-paid),0) FROM w_sales s WHERE s.license_id=c.license_id AND s.customer_id=c.id AND s.total>s.paid) AS due
        FROM w_customers c WHERE c.license_id=? AND (?=\'\' OR c.name LIKE ? OR c.phone LIKE ?) ORDER BY c.name LIMIT 500');
    $s->execute([$l, $q, $like, $like]); $rows = $s->fetchAll();
    render_head(t('Clients'), 'customers'); ?>
<div class="bar"><h1><?= e(t('Clients')) ?></h1><a class="btn cta" href="<?= e(url('customers', ['a' => 'edit'])) ?>">+ <?= e(t('Nouveau')) ?></a></div>
<form class="search" method="get"><input type="hidden" name="m" value="customers"><input name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Rechercher un client…')) ?>"></form>
<div class="card tw"><table><tr><th><?= e(t('Client')) ?></th><th><?= e(t('Téléphone')) ?></th><th class="r"><?= e(t('Dette')) ?></th></tr>
<?php foreach ($rows as $c): ?><tr class="click" onclick="location='<?= e(url('customers', ['a' => 'edit', 'id' => $c['id']])) ?>'"><td><b><?= e($c['name']) ?></b></td><td><?= e($c['phone']) ?></td>
<td class="r"><?= $c['due'] > 0 ? '<b class="neg">' . e(money_fmt((float)$c['due'])) . '</b>' : '<span class="badge ok">' . e(t('À jour')) . '</span>' ?></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="3" class="mu">' . e(t('Aucun client trouvé.')) . '</td></tr>'; ?></table></div>
<?php render_foot(); }
