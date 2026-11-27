<?php
function mod_products(): void {
    $l = lid(); $pdo = db(); stk_migrate($l); $a = (string)($_GET['a'] ?? ''); $id = (int)($_GET['id'] ?? 0);
    if ($a === 'save') {
        post_only(); $pid = (int)($_POST['id'] ?? 0);
        $name = f_str('name', 160); if ($name === '') { flash(t('Nom requis'), 'err'); go('products', ['a' => 'edit', 'id' => $pid]); }
        $bc = f_str('barcode', 64);
        if ($bc !== '') { $d = $pdo->prepare('SELECT name FROM w_products WHERE license_id=? AND barcode=? AND id<>?'); $d->execute([$l, $bc, $pid]);
            if ($n = $d->fetchColumn()) { flash(t('Ce code existe déjà : ') . $n, 'err'); go('products', ['a' => 'edit', 'id' => $pid]); } }
        $v = [$name, $bc, f_str('category', 80), max(0, f_num('buy_price')), max(0, f_num('sell_price')), (int)f_num('stock'), max(0, (int)f_num('min_stock'))];
        if ($pid) {
            $o = $pdo->prepare('SELECT stock FROM w_products WHERE id=? AND license_id=?'); $o->execute([$pid, $l]); $old = $o->fetchColumn(); $cm = stk_cmp_map($l);
            $pdo->prepare('UPDATE w_products SET name=?,barcode=?,category=?,buy_price=?,sell_price=?,stock=?,min_stock=? WHERE id=? AND license_id=?')->execute([...$v, $pid, $l]);
            if ($old !== false && (int)$old !== (int)$v[5]) { $dq = (int)$v[5] - (int)$old; stk_move($l, $pid, $name, $dq > 0 ? 'in' : 'out', abs($dq), $cm[$pid] ?? $v[3], t('Correction de stock')); }
        } else {
            $pdo->prepare('INSERT INTO w_products (name,barcode,category,buy_price,sell_price,stock,min_stock,license_id,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([...$v, $l, now()]);
            if ((int)$v[5] > 0) stk_move($l, (int)$pdo->lastInsertId(), $name, 'opening', (int)$v[5], $v[3], t('Stock initial'));
        }
        flash(t('Produit enregistré')); go('products');
    }
    if ($a === 'delete') { post_only(); sync_tomb_web('products', 'w_products', (int)$_POST['id']); $pdo->prepare('DELETE FROM w_products WHERE id=? AND license_id=?')->execute([(int)$_POST['id'], $l]); flash(t('Produit supprimé')); go('products'); }
    if ($a === 'edit') {
        $p = ['id' => 0, 'name' => '', 'barcode' => (string)($_GET['barcode'] ?? ''), 'category' => '', 'buy_price' => 0, 'sell_price' => 0, 'stock' => 0, 'min_stock' => 5];
        if ($id) { $s = $pdo->prepare('SELECT * FROM w_products WHERE id=? AND license_id=?'); $s->execute([$id, $l]); $p = $s->fetch() ?: $p; }
        render_head($p['id'] ? t('Modifier le produit') : t('Nouveau produit'), 'products'); ?>
<h1><?= e($p['id'] ? t('Modifier le produit') : t('Nouveau produit')) ?></h1>
<form method="post" action="<?= e(url('products', ['a' => 'save'])) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
<label><?= e(t('Nom du produit *')) ?></label><input name="name" required value="<?= e($p['name']) ?>" placeholder="<?= e(t('Ex : Riz parfumé 25 kg')) ?>">
<label><?= e(t('Code-barres / SKU (optionnel)')) ?></label><input name="barcode" id="barcode" value="<?= e($p['barcode']) ?>" inputmode="numeric"> <button type="button" class="btn sm" data-scan="#barcode">📷 <?= e(t('Scanner')) ?></button>
<label><?= e(t('Catégorie')) ?></label><input name="category" value="<?= e($p['category']) ?>">
<div class="two"><div><label><?= e(t("Prix d'achat *")) ?></label><input name="buy_price" type="number" step="any" min="0" required value="<?= e((string)(float)$p['buy_price']) ?>"></div>
<div><label><?= e(t('Prix de vente *')) ?></label><input name="sell_price" type="number" step="any" min="0" required value="<?= e((string)(float)$p['sell_price']) ?>"></div></div>
<div class="two"><div><label><?= e(t('Quantité en stock *')) ?></label><input name="stock" type="number" required value="<?= (int)$p['stock'] ?>"></div>
<div><label><?= e(t("Seuil d'alerte")) ?></label><input name="min_stock" type="number" min="0" value="<?= (int)$p['min_stock'] ?>"></div></div>
<button class="btn cta"><?= e(t('Enregistrer le produit')) ?></button></form>
<?php if ($p['id']): ?><form method="post" action="<?= e(url('products', ['a' => 'delete'])) ?>" onsubmit="return confirm('<?= e(t('Supprimer ?')) ?>')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn danger"><?= e(t('Supprimer')) ?></button></form><?php endif;
        render_foot(); return;
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $s = $pdo->prepare('SELECT * FROM w_products WHERE license_id=? AND (?=\'\' OR name LIKE ? OR barcode=? OR category LIKE ?) ORDER BY name LIMIT 500');
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; $s->execute([$l, $q, $like, $q, $like]); $rows = $s->fetchAll();
    render_head(t('Produits & Stock'), 'products'); ?>
<div class="bar"><h1><?= e(t('Produits & Stock')) ?></h1><a class="btn cta" href="<?= e(url('products', ['a' => 'edit'])) ?>">+ <?= e(t('Nouveau')) ?></a></div>
<form class="search" method="get"><input type="hidden" name="m" value="products"><input name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Rechercher un produit…')) ?>"></form>
<div class="card tw"><table><tr><th><?= e(t('Article')) ?></th><th><?= e(t('Catégorie')) ?></th><th class="r"><?= e(t('Prix de vente')) ?></th><th class="r">Stock</th></tr>
<?php foreach ($rows as $p): ?><tr onclick="location='<?= e(url('products', ['a' => 'edit', 'id' => $p['id']])) ?>'" class="click"><td><b><?= e($p['name']) ?></b><br><small><?= e($p['barcode']) ?></small></td><td><?= e($p['category']) ?></td>
<td class="r"><?= e(money_fmt((float)$p['sell_price'])) ?></td><td class="r"><span class="badge <?= $p['stock'] <= $p['min_stock'] ? 'ko' : 'ok' ?>"><?= (int)$p['stock'] ?></span></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="4" class="mu">' . e(t('Aucun produit.')) . '</td></tr>'; ?></table></div>
<?php render_foot(); }
