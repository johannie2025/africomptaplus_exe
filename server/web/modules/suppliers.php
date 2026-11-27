<?php
function sup_get(int $id): ?array { $s = db()->prepare('SELECT * FROM w_suppliers WHERE id=? AND license_id=?'); $s->execute([$id, lid()]); return $s->fetch() ?: null; }
function mod_suppliers(): void {
    sync_migrate();
    $l = lid(); $pdo = db(); $a = (string)($_GET['a'] ?? ''); $id = (int)($_GET['id'] ?? 0);
    if ($a === 'save') {
        post_only(); $name = f_str('name', 120); if ($name === '') { flash(t('Nom requis'), 'err'); go('suppliers'); }
        $v = [$name, f_str('phone', 30), f_str('email', 120), f_str('address', 200), f_str('notes', 300)];
        if ($id && sup_get($id)) { $pdo->prepare('UPDATE w_suppliers SET name=?,phone=?,email=?,address=?,notes=? WHERE id=? AND license_id=?')->execute([...$v, $id, $l]); }
        else { $pdo->prepare('INSERT INTO w_suppliers (name,phone,email,address,notes,license_id,created_at) VALUES (?,?,?,?,?,?,?)')->execute([...$v, $l, now()]); $id = (int)$pdo->lastInsertId(); }
        flash(t('Enregistré ✓')); go('suppliers', ['a' => 'view', 'id' => $id]);
    }
    if ($a === 'delete') {
        post_only(); sync_migrate(); $n = $pdo->prepare('SELECT COUNT(*) FROM w_purchases WHERE license_id=? AND supplier_id=?'); $n->execute([$l, $id]);
        if ((int)$n->fetchColumn() > 0) { $pdo->prepare('UPDATE w_suppliers SET archived=1 WHERE id=? AND license_id=?')->execute([$id, $l]); flash(t('Archivé (historique conservé) ✓')); }
        else { sync_tomb_web('suppliers', 'w_suppliers', $id); $pdo->prepare('DELETE FROM w_suppliers WHERE id=? AND license_id=?')->execute([$id, $l]); flash(t('Supprimé ✓')); }
        go('suppliers');
    }
    if ($a === 'purchase') {            // enregistre un achat
        post_only(); $sup = sup_get((int)($_POST['supplier_id'] ?? 0));
        $pdo->beginTransaction();
        try {
            if (!$sup) throw new RuntimeException(t('Fournisseur introuvable'));
            $pid = (int)($_POST['product_id'] ?? 0); $qty = (int)f_num('qty'); $cost = f_num('unit_cost');
            $ps = $pdo->prepare('SELECT * FROM w_products WHERE id=? AND license_id=? FOR UPDATE'); $ps->execute([$pid, $l]); $p = $ps->fetch();
            if (!$p || $qty < 1 || $cost < 0) throw new RuntimeException(t('Montant invalide'));
            $total = $qty * $cost; $paid = max(0, min($total, f_num('paid'))); $m = ($_POST['method'] ?? '') === 'mobile_money' ? 'mobile_money' : 'cash';
            $ref = 'ACH-' . gmdate('Ymd-His'); $k = 0;
            while (true) { $x = $pdo->prepare('SELECT 1 FROM w_purchases WHERE license_id=? AND reference=?'); $x->execute([$l, $ref . ($k ? "-$k" : '')]); if (!$x->fetch()) break; $k++; }
            if ($k) $ref .= "-$k";
            $pdo->prepare('INSERT INTO w_purchases (license_id,reference,supplier_id,supplier_name,total,initial_paid,paid,payment,notes,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$l, $ref, $sup['id'], $sup['name'], $total, $paid, $paid, $m, f_str('notes', 300), now()]);
            $uid = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO w_purchase_items (purchase_id,product_id,name,qty,unit_cost) VALUES (?,?,?,?,?)')->execute([$uid, $p['id'], $p['name'], $qty, $cost]);
            $pdo->prepare('UPDATE w_products SET stock=stock+?' . (isset($_POST['upd_price']) ? ',buy_price=?' : '') . ' WHERE id=? AND license_id=?')->execute(isset($_POST['upd_price']) ? [$qty, $cost, $p['id'], $l] : [$qty, $p['id'], $l]);
            $pdo->commit(); flash(t('Achat enregistré ✓') . ' ' . $ref);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'err'); }
        go('suppliers', ['a' => 'view', 'id' => (int)($_POST['supplier_id'] ?? 0)]);
    }
    if ($a === 'delpurchase') {      // annule un achat : stock retiré, paiements supprimés
        post_only(); $uid = (int)($_POST['purchase_id'] ?? 0); $pdo->beginTransaction();
        try {
            $it = $pdo->prepare('SELECT i.* FROM w_purchase_items i JOIN w_purchases p ON p.id=i.purchase_id WHERE p.id=? AND p.license_id=?'); $it->execute([$uid, $l]);
            $dn = $pdo->prepare('UPDATE w_products SET stock=GREATEST(0, stock-?) WHERE id=? AND license_id=?');
            foreach ($it as $i) if ($i['product_id']) $dn->execute([(int)$i['qty'], $i['product_id'], $l]);
            sync_tomb_web('purchases', 'w_purchases', $uid);
            $pdo->prepare('DELETE FROM w_purchases WHERE id=? AND license_id=?')->execute([$uid, $l]); $pdo->commit(); flash(t('Supprimé ✓'));
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'err'); }
        go('suppliers', ['a' => 'view', 'id' => $id]);
    }
    if ($a === 'pay') {
        post_only(); $uid = (int)($_POST['purchase_id'] ?? 0); $pdo->beginTransaction();
        try {
            $s = $pdo->prepare('SELECT * FROM w_purchases WHERE id=? AND license_id=? FOR UPDATE'); $s->execute([$uid, $l]); $u = $s->fetch();
            if (!$u) throw new RuntimeException(t('Montant invalide'));
            $amt = min(max(0, f_num('amount')), (float)$u['total'] - (float)$u['paid']); if ($amt <= 0) throw new RuntimeException(t('Montant invalide'));
            $m = ($_POST['method'] ?? '') === 'mobile_money' ? 'mobile_money' : 'cash';
            $pdo->prepare('INSERT INTO w_supplier_payments (license_id,purchase_id,amount,method,created_at) VALUES (?,?,?,?,?)')->execute([$l, $uid, $amt, $m, now()]);
            $pdo->prepare('UPDATE w_purchases SET paid=paid+? WHERE id=?')->execute([$amt, $uid]); $pdo->commit(); flash(t('Paiement enregistré ✓'));
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'err'); }
        go('suppliers', ['a' => 'view', 'id' => $id]);
    }
    if ($a === 'view' || $a === 'edit') {
        $sup = $id ? sup_get($id) : null;
        if ($a === 'view' && !$sup) go('suppliers');
        if ($a === 'edit' || !$sup) { render_head(t('Fournisseur'), 'suppliers'); $sup = $sup ?: ['name' => '', 'phone' => '', 'email' => '', 'address' => '', 'notes' => '']; ?>
<h1><?= e(t('Fournisseur')) ?></h1><form method="post" action="<?= e(url('suppliers', ['a' => 'save', 'id' => $id])) ?>" class="card"><?= csrf_field() ?>
<input name="name" value="<?= e($sup['name']) ?>" placeholder="<?= e(t('Nom *')) ?>" required><div class="two"><input name="phone" value="<?= e($sup['phone']) ?>" placeholder="<?= e(t('Téléphone')) ?>"><input name="email" value="<?= e($sup['email']) ?>" placeholder="Email"></div>
<input name="address" value="<?= e($sup['address']) ?>" placeholder="<?= e(t('Adresse')) ?>"><input name="notes" value="<?= e($sup['notes']) ?>" placeholder="Notes"><button class="btn cta"><?= e(t('Enregistrer')) ?></button></form>
<?php render_foot(); return; }
        $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? ''); $w = 'license_id=? AND supplier_id=?'; $q = [$l, $id];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w .= ' AND DATE(created_at)>=?'; $q[] = $from; } else $from = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w .= ' AND DATE(created_at)<=?'; $q[] = $to; } else $to = '';
        $s = $pdo->prepare("SELECT * FROM w_purchases WHERE $w ORDER BY created_at DESC,id DESC LIMIT 500"); $s->execute($q); $rows = $s->fetchAll();
        $due = array_sum(array_map(fn($r) => (float)$r['total'] - (float)$r['paid'], $rows)); $prods = $pdo->prepare('SELECT id,name,buy_price FROM w_products WHERE license_id=? ORDER BY name LIMIT 3000'); $prods->execute([$l]); $prods = $prods->fetchAll();
        render_head($sup['name'], 'suppliers'); ?>
<div class="bar"><h1><?= e($sup['name']) ?></h1><a class="btn" href="<?= e(url('suppliers', ['a' => 'edit', 'id' => $id])) ?>">✏️ <?= e(t('Modifier')) ?></a></div>
<p class="mu"><?= e($sup['phone']) ?> <?= e($sup['email']) ?> <?= e($sup['address']) ?></p>
<div class="kpis"><div class="kpi warn"><small><?= e(t('Reste à payer')) ?></small><b><?= e(money_fmt($due)) ?></b></div></div>
<form method="post" action="<?= e(url('suppliers', ['a' => 'purchase', 'id' => $id])) ?>" class="card"><?= csrf_field() ?><input type="hidden" name="supplier_id" value="<?= $id ?>"><h2><?= e(t('Nouvel achat')) ?></h2>
<select name="product_id" required><?php foreach ($prods as $p) echo '<option value="' . $p['id'] . '">' . e($p['name']) . '</option>'; ?></select>
<div class="two"><input name="qty" type="number" min="1" placeholder="<?= e(t('Quantité')) ?>" required><input name="unit_cost" type="number" min="0" step="any" placeholder="<?= e(t("Prix d'achat unitaire")) ?>" required></div>
<div class="two"><input name="paid" type="number" min="0" step="any" value="0" placeholder="<?= e(t('Montant payé maintenant')) ?>"><select name="method"><option value="cash"><?= e(t('Espèces')) ?></option><option value="mobile_money">Mobile Money</option></select></div>
<label><input type="checkbox" name="upd_price" value="1" checked> <?= e(t("Mettre à jour le prix d'achat du produit")) ?></label><button class="btn cta"><?= e(t('Enregistrer')) ?></button></form>
<form method="get" class="card"><input type="hidden" name="m" value="suppliers"><input type="hidden" name="a" value="view"><input type="hidden" name="id" value="<?= $id ?>"><div class="two"><input type="date" name="from" value="<?= e($from) ?>"><input type="date" name="to" value="<?= e($to) ?>"></div><button class="btn"><?= e(t('Filtrer')) ?></button></form>
<div class="card"><h2><?= e(t('Achats')) ?></h2>
<?php foreach ($rows as $r): $rest = (float)$r['total'] - (float)$r['paid']; ?><div class="li"><span><?= e(substr($r['created_at'], 0, 10)) ?> · <?= e($r['reference']) ?><br><small><?= e(t('Total')) ?> <?= e(money_fmt((float)$r['total'])) ?> · <?= e(t('Reste à payer')) ?> <?= e(money_fmt($rest)) ?></small></span>
<?php if ($rest > 0): ?><form method="post" action="<?= e(url('suppliers', ['a' => 'pay', 'id' => $id])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="purchase_id" value="<?= $r['id'] ?>"><input name="amount" type="number" min="1" max="<?= $rest ?>" step="any" value="<?= (int)$rest ?>" style="width:90px"><select name="method"><option value="cash">💵</option><option value="mobile_money">📱</option></select><button class="btn sm"><?= e(t('Payer')) ?></button></form><?php else: ?><b>✓</b><?php endif; ?>
<form method="post" action="<?= e(url('suppliers', ['a' => 'delpurchase', 'id' => $id])) ?>" class="inline" onsubmit="return confirm('<?= e(t('Supprimer cet achat ? Le stock sera retiré.')) ?>')"><?= csrf_field() ?><input type="hidden" name="purchase_id" value="<?= $r['id'] ?>"><button class="btn sm">🗑</button></form></div>
<?php endforeach; if (!$rows) echo '<p class="mu">' . e(t('Aucun achat')) . '</p>'; ?></div>
<form method="post" action="<?= e(url('suppliers', ['a' => 'delete', 'id' => $id])) ?>" onsubmit="return confirm('?')"><?= csrf_field() ?><button class="btn">🗑 <?= e(t('Supprimer')) ?></button></form>
<?php render_foot(); return;
    }
    $q = trim((string)($_GET['q'] ?? '')); $k = '%' . addcslashes($q, '%_') . '%';
    $s = $pdo->prepare('SELECT s.*, COALESCE((SELECT SUM(total-paid) FROM w_purchases p WHERE p.supplier_id=s.id AND p.license_id=s.license_id),0) due FROM w_suppliers s WHERE s.license_id=? AND s.archived=0 AND (s.name LIKE ? OR s.phone LIKE ?) ORDER BY s.name LIMIT 500'); $s->execute([$l, $k, $k]); $rows = $s->fetchAll();
    $sum = array_sum(array_map(fn($r) => (float)$r['due'], $rows)); render_head(t('Fournisseurs'), 'suppliers'); ?>
<div class="bar"><h1><?= e(t('Fournisseurs')) ?></h1><a class="btn cta" href="<?= e(url('suppliers', ['a' => 'edit'])) ?>">+ <?= e(t('Ajouter')) ?></a></div>
<form method="get" class="card"><input type="hidden" name="m" value="suppliers"><input name="q" value="<?= e($q) ?>" placeholder="<?= e(t('Rechercher')) ?>"></form>
<div class="kpis"><div class="kpi warn"><small><?= e(t('Total dû aux fournisseurs')) ?></small><b><?= e(money_fmt($sum)) ?></b></div></div>
<div class="card tw"><table><?php foreach ($rows as $r): ?><tr class="click" onclick="location='<?= e(url('suppliers', ['a' => 'view', 'id' => $r['id']])) ?>'"><td><b><?= e($r['name']) ?></b><br><small><?= e($r['phone']) ?></small></td><td class="r <?= $r['due'] > 0 ? 'neg' : '' ?>"><?= $r['due'] > 0 ? e(money_fmt((float)$r['due'])) : '' ?></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td class="mu">' . e(t('Aucun fournisseur')) . '</td></tr>'; ?></table></div>
<?php render_foot(); }
