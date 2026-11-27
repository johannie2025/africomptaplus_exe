<?php
require_once __DIR__ . '/../inc/acct.php';
function mod_accounting(): void {
    $l = lid(); $pdo = db(); $mode = setting('acct_mode', 'off');
    if ($mode === 'off') { flash(t("Activez la comptabilité dans Paramètres."), 'err'); go('settings'); }
    $a = (string)($_GET['a'] ?? '');
    stk_migrate($l);
    if ($a === 'move_save') {        // mouvement de stock manuel : entrée (coût saisi) ou sortie (valorisée au CMP)
        post_only(); $pid = (int)($_POST['product_id'] ?? 0); $kind = ($_POST['kind'] ?? '') === 'out' ? 'out' : 'in'; $qty = (int)f_num('qty'); $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date'] ?? '')) ? $_POST['date'] : gmdate('Y-m-d');
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare('SELECT * FROM w_products WHERE id=? AND license_id=? FOR UPDATE'); $sel->execute([$pid, $l]); $p = $sel->fetch(); if (!$p || $qty < 1) throw new RuntimeException(t('Quantité invalide'));
            $cmp = ac_cmp_map(); $c = $kind === 'out' ? ($cmp[$pid] ?? (float)$p['buy_price']) : max(0, f_num('cost'));
            if ($kind === 'out' && $qty > (int)$p['stock']) throw new RuntimeException(t('Stock insuffisant : ') . $p['name'] . ' (' . (int)$p['stock'] . ')');
            $pdo->prepare('UPDATE w_products SET stock=stock' . ($kind === 'out' ? '-' : '+') . '? WHERE id=?')->execute([$qty, $pid]);
            stk_move($l, $pid, $p['name'], $kind, $qty, $c, f_str('reason', 60), f_str('note', 200), $dt . ' ' . gmdate('H:i:s'));
            $pdo->commit(); flash(t('Mouvement enregistré ✓'));
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'err'); }
        go('accounting', ['v' => 'stock']);
    }
    if ($a === 'move_new') {
        $s = $pdo->prepare('SELECT id,name,stock,buy_price FROM w_products WHERE license_id=? ORDER BY name'); $s->execute([$l]); $ps = $s->fetchAll(); $cmp = ac_cmp_map();
        render_head(t('Mouvement de stock'), 'accounting'); ?>
<h1><?= e(t('Mouvement de stock')) ?></h1><p class="mu"><?= e(t("Pour un réapprovisionnement auprès d'un fournisseur, utilisez plutôt un achat (Fournisseurs) : stock, CMP, dette et écritures sont mis à jour.")) ?> <a href="<?= e(url('suppliers')) ?>"><?= e(t('Fournisseurs')) ?> →</a></p>
<form method="post" action="<?= e(url('accounting', ['a' => 'move_save'])) ?>" class="card"><?= csrf_field() ?>
<select name="product_id" id="mp" required><?php foreach ($ps as $p) echo '<option value="' . (int)$p['id'] . '" data-c="' . e((string)round($cmp[$p['id']] ?? (float)$p['buy_price'])) . '">' . e($p['name']) . ' (' . (int)$p['stock'] . ')</option>'; ?></select>
<div class="two"><select name="kind" id="mk"><option value="in"><?= e(t('Entrée')) ?></option><option value="out"><?= e(t('Sortie')) ?></option></select><input type="number" name="qty" min="1" required placeholder="<?= e(t('Qté')) ?>"></div>
<div class="two"><input type="number" step="any" min="0" name="cost" id="mc" placeholder="<?= e(t('Coût unitaire (entrée)')) ?>"><input type="date" name="date" value="<?= gmdate('Y-m-d') ?>"></div>
<select name="reason"><?php foreach (['Retour client', 'Don reçu', 'Correction de stock', 'Casse / perte', 'Vol', 'Usage personnel', 'Don', 'Péremption', 'Retour fournisseur'] as $r) echo '<option value="' . e($r) . '">' . e(t($r)) . '</option>'; ?></select>
<input name="note" placeholder="<?= e(t('Note (facultatif)')) ?>"><button class="btn cta"><?= e(t('Enregistrer')) ?></button></form>
<script>const mp=document.getElementById('mp'),mc=document.getElementById('mc');const f=()=>mc.value=mp.selectedOptions[0].dataset.c;mp.onchange=f;f();document.getElementById('mk').onchange=e=>mc.disabled=e.target.value==='out';</script>
<?php render_foot(); return;
    }
    if ($a === 'add') {
        post_only(); $d = (string)($_POST['date'] ?? ''); $deb = preg_replace('/\D/', '', (string)($_POST['debit'] ?? '')); $cre = preg_replace('/\D/', '', (string)($_POST['credit'] ?? '')); $amt = f_num('amount');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $deb !== '' && $cre !== '' && $deb !== $cre && $amt > 0 && f_str('label') !== '') {
            $j = strtoupper(preg_replace('/[^A-Za-z]/', '', f_str('journal', 4))) ?: 'OD';
            $pdo->prepare('INSERT INTO w_acct_entries (license_id,entry_date,journal,label,debit_account,credit_account,amount,created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$l, $d, $j, f_str('label'), $deb, $cre, $amt, now()]); flash(t('Enregistré ✓'));
        } else flash(t('Écriture invalide'), 'err');
        go('accounting', ['v' => 'manual']);
    }
    if ($a === 'del') { post_only(); sync_tomb_web('entries', 'w_acct_entries', (int)$_POST['id']); $pdo->prepare('DELETE FROM w_acct_entries WHERE id=? AND license_id=?')->execute([(int)$_POST['id'], $l]); go('accounting', ['v' => 'manual']); }
    if ($a === 'account') {
        post_only(); $c = preg_replace('/\D/', '', f_str('code', 10)); $lb = f_str('label', 160);
        if ($c !== '' && $lb !== '') { $pdo->prepare('REPLACE INTO w_acct_accounts (license_id,code,label) VALUES (?,?,?)')->execute([$l, $c, $lb]); flash(t('Enregistré ✓')); }
        go('accounting', ['v' => 'plan']);
    }
    $double = $mode === 'double';
    inv_migrate();
    if ($a === 'count_save') {        // valide un comptage : stock ajusté aux quantités comptées, écart valorisé au CMP
        post_only(); $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date'] ?? '')) ? $_POST['date'] : gmdate('Y-m-d'); $cnt = (array)($_POST['c'] ?? []);
        $cmp = ac_cmp_map(); $pdo->beginTransaction();
        try {
            $ref = 'INVT-' . gmdate('Ymd-His'); $k = 0;
            while (true) { $x = $pdo->prepare('SELECT 1 FROM w_inventories WHERE license_id=? AND reference=?'); $x->execute([$l, $ref . ($k ? "-$k" : '')]); if (!$x->fetch()) break; $k++; }
            if ($k) $ref .= "-$k";
            $pdo->prepare('INSERT INTO w_inventories (license_id,reference,inv_date,note,created_at) VALUES (?,?,?,?,?)')->execute([$l, $ref, $dt, f_str('note', 300), now()]); $iid = (int)$pdo->lastInsertId();
            $sel = $pdo->prepare('SELECT * FROM w_products WHERE id=? AND license_id=? FOR UPDATE'); $ins = $pdo->prepare('INSERT INTO w_inventory_items (inventory_id,product_id,name,theoretical,counted,cmp) VALUES (?,?,?,?,?,?)');
            $up = $pdo->prepare('UPDATE w_products SET stock=? WHERE id=?'); $n = 0;
            foreach ($cnt as $pid => $v) {
                if (trim((string)$v) === '' || !is_numeric($v)) continue; $sel->execute([(int)$pid, $l]); $p = $sel->fetch(); if (!$p) continue;
                $counted = max(0, (int)$v); $ins->execute([$iid, $p['id'], $p['name'], (int)$p['stock'], $counted, $cmp[$p['id']] ?? (float)$p['buy_price']]); $n++;
                if ($counted !== (int)$p['stock']) $up->execute([$counted, $p['id']]);
            }
            if (!$n) throw new RuntimeException(t('Aucune quantité saisie'));
            $pdo->commit(); flash(t('Inventaire enregistré ✓') . ' ' . $ref); go('accounting', ['a' => 'count_view', 'id' => $iid]);
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); flash($e->getMessage(), 'err'); go('accounting', ['a' => 'count_new']); }
    }
    if ($a === 'count_new') {
        $s = $pdo->prepare("SELECT id,name,barcode,stock,COALESCE(NULLIF(category,''),'Général') cat FROM w_products WHERE license_id=? ORDER BY cat,name"); $s->execute([$l]); $ps = $s->fetchAll(); $cmp = ac_cmp_map();
        render_head(t('Nouvel inventaire physique'), 'accounting'); ?>
<h1><?= e(t('Nouvel inventaire physique')) ?></h1><p class="mu"><?= e(t("Saisissez les quantités réellement comptées. Laissez vide les articles non comptés. Le stock sera ajusté et l'écart comptabilisé (6031 / 311) au CMP.")) ?></p>
<form method="post" action="<?= e(url('accounting', ['a' => 'count_save'])) ?>" class="card"><?= csrf_field() ?>
<div class="two"><input type="date" name="date" value="<?= gmdate('Y-m-d') ?>" required><input name="note" placeholder="<?= e(t('Note (ex. inventaire de fin de mois)')) ?>"></div>
<input id="cq" placeholder="<?= e(t('Rechercher')) ?>" autocomplete="off">
<div class="tw"><table id="ct"><tr><th><?= e(t('Produit')) ?></th><th class="r"><?= e(t('Théorique')) ?></th><th class="r"><?= e(t('Compté')) ?></th><th class="r"><?= e(t('Écart')) ?></th></tr>
<?php foreach ($ps as $p): ?><tr data-n="<?= e(mb_strtolower($p['name'] . ' ' . $p['barcode'] . ' ' . $p['cat'])) ?>"><td><?= e($p['name']) ?><br><small class="mu"><?= e($p['cat']) ?> · CMP <?= e(ac_f($cmp[$p['id']] ?? 0)) ?></small></td><td class="r"><?= (int)$p['stock'] ?></td>
<td class="r"><input class="cn" type="number" min="0" name="c[<?= (int)$p['id'] ?>]" data-t="<?= (int)$p['stock'] ?>" style="width:90px"></td><td class="r dv"></td></tr>
<?php endforeach; ?></table></div><button class="btn cta"><?= e(t("Valider l'inventaire")) ?></button> <button type="button" class="btn noprint" onclick="print()">🖨 PDF</button></form>
<script>document.querySelectorAll('.cn').forEach(i=>i.oninput=()=>{const d=i.closest('tr').querySelector('.dv');d.textContent=i.value===''?'':(+i.value-+i.dataset.t);});
document.getElementById('cq').oninput=e=>{const q=e.target.value.toLowerCase();document.querySelectorAll('#ct tr[data-n]').forEach(r=>r.hidden=q&&!r.dataset.n.includes(q));};</script>
<?php render_foot(); return;
    }
    if ($a === 'count_view') {
        $s = $pdo->prepare('SELECT * FROM w_inventories WHERE id=? AND license_id=?'); $s->execute([(int)($_GET['id'] ?? 0), $l]); $iv = $s->fetch(); if (!$iv) go('accounting', ['v' => 'counts']);
        $it = $pdo->prepare('SELECT * FROM w_inventory_items WHERE inventory_id=? ORDER BY name'); $it->execute([$iv['id']]); $t = ac_table($iv['reference'] . ' — ' . ac_dshow($iv['inv_date']), [t('Produit'), t('Théorique'), t('Compté'), t('Écart'), 'CMP', t("Valeur de l'écart")], [0, 1, 1, 1, 1, 1]); $tq = $tv = 0.0;
        foreach ($it as $x) { $dq = (int)$x['counted'] - (int)$x['theoretical']; $dv = $dq * (float)$x['cmp']; $tq += $dq; $tv += $dv; ac_add($t, false, $x['name'], (string)$x['theoretical'], (string)$x['counted'], ($dq > 0 ? '+' : '') . $dq, ac_f((float)$x['cmp']), ac_f($dv)); }
        ac_add($t, true, t('TOTAL'), '', '', ($tq > 0 ? '+' : '') . (int)$tq, '', ac_f($tv));
        if (isset($_GET['csv'])) ac_csv($t, 'inventaire_' . $iv['reference']);
        render_head($iv['reference'], 'accounting'); ?>
<div class="bar noprint"><h1><?= e($iv['reference']) ?></h1><div class="actions"><a class="btn" href="<?= e(url('accounting', ['a' => 'count_view', 'id' => $iv['id'], 'csv' => 1])) ?>">⬇ CSV</a><button class="btn" onclick="print()">🖨</button><a class="btn" href="<?= e(url('accounting', ['v' => 'counts'])) ?>">←</a></div></div>
<p class="mu"><?= e($iv['note']) ?></p><?php ac_html($t); render_foot(); return;
    }
    $views = $double ? ['journal' => t('Journal'), 'ledger' => t('Grand livre'), 'balance' => t('Balance'), 'income' => t('Résultat'), 'sheet' => t('Bilan'), 'analytic' => t('Analytique'), 'cash' => t('Caisse'), 'inventory' => t('Inventaire'), 'stock' => t('Mouvements'), 'counts' => t('Comptages'), 'plan' => t('Plan'), 'manual' => t('Saisie')]
                      : ['summary' => t('Synthèse'), 'cash' => t('Livre de caisse'), 'inventory' => t('Inventaire'), 'stock' => t('Mouvements'), 'counts' => t('Comptages'), 'manual' => t('Saisie')];
    $v = (string)($_GET['v'] ?? array_key_first($views)); if (!isset($views[$v])) $v = array_key_first($views);
    $ok = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1; $from = $ok((string)($_GET['from'] ?? '')) ? $_GET['from'] : null; $to = $ok((string)($_GET['to'] ?? '')) ? $_GET['to'] : null; $q = trim((string)($_GET['q'] ?? ''));
    $tbl = match ($v) { 'journal' => ac_journal($from, $to, $q), 'ledger' => ac_ledger($from, $to, $q), 'balance' => ac_balance($from, $to, $q), 'income' => ac_income($from, $to), 'sheet' => ac_balance_sheet($to),
        'analytic' => ac_analytic($from, $to), 'cash' => ac_cashbook($from, $to, $q), 'summary' => ac_summary($from, $to), 'inventory' => ac_inventory($q, (string)($_GET['cat'] ?? ''), (string)($_GET['st'] ?? '')), 'counts' => ac_counts_table($from, $to), 'stock' => ac_stock_card((int)($_GET['pid'] ?? 0) ?: null, $from, $to, $q), 'plan' => ac_plan_table($q), default => null };
    if ($tbl && isset($_GET['csv'])) ac_csv($tbl, 'compta_' . $v);
    render_head(t('Comptabilité'), 'accounting'); ?>
<h1><?= e(t('Comptabilité')) ?> <small class="mu">(<?= e($double ? t('Double (OHADA)') : t('Simple')) ?>)</small></h1>
<div class="seg noprint" style="overflow-x:auto"><?php foreach ($views as $k => $lb) echo '<a class="btn sm' . ($k === $v ? ' cta' : '') . '" href="' . e(url('accounting', ['v' => $k])) . '">' . e($lb) . '</a> '; ?></div>
<?php if ($v !== 'manual'): ?><form method="get" class="card noprint"><input type="hidden" name="m" value="accounting"><input type="hidden" name="v" value="<?= e($v) ?>">
<?php if ($v === 'inventory'): ?><div class="two"><select name="cat"><option value=""><?= e(t('Toutes les catégories')) ?></option><?php foreach (ac_categories() as $c) echo '<option' . (($_GET['cat'] ?? '') === $c ? ' selected' : '') . '>' . e($c) . '</option>'; ?></select>
<select name="st"><?php foreach (['' => t('Tous les articles'), 'in' => t('En stock'), 'low' => t('Stock faible'), 'out' => t('En rupture')] as $k => $lb) echo '<option value="' . $k . '"' . (($_GET['st'] ?? '') === $k ? ' selected' : '') . '>' . e($lb) . '</option>'; ?></select></div>
<?php else: ?><div class="two"><input type="date" name="from" value="<?= e((string)$from) ?>"><input type="date" name="to" value="<?= e((string)$to) ?>"></div><?php endif; if (in_array($v, ['journal', 'ledger', 'balance', 'cash', 'plan', 'inventory', 'stock'], true)) echo '<input name="q" value="' . e($q) . '" placeholder="' . e(t('Rechercher')) . '">'; ?>
<button class="btn cta"><?= e(t('Filtrer')) ?></button> <?php if ($v === 'stock'): ?><input type="hidden" name="pid" value="<?= (int)($_GET['pid'] ?? 0) ?>"><a class="btn" href="<?= e(url('accounting', ['a' => 'move_new'])) ?>">+ <?= e(t('Mouvement')) ?></a> <?php endif; if ($v === 'counts'): ?><a class="btn" href="<?= e(url('accounting', ['a' => 'count_new'])) ?>">+ <?= e(t('Inventaire physique')) ?></a> <?php endif; if ($v !== 'plan' || true): ?><a class="btn" href="<?= e(url('accounting', ['v' => $v, 'from' => (string)$from, 'to' => (string)$to, 'q' => $q, 'cat' => (string)($_GET['cat'] ?? ''), 'st' => (string)($_GET['st'] ?? ''), 'csv' => 1])) ?>">⬇ CSV</a><?php endif; ?> <button type="button" class="btn" onclick="print()">🖨 PDF</button></form><?php endif;
    if ($v === 'manual') {
        $s = $pdo->prepare('SELECT * FROM w_acct_entries WHERE license_id=? ORDER BY entry_date DESC,id DESC LIMIT 200'); $s->execute([$l]); ?>
<form method="post" action="<?= e(url('accounting', ['a' => 'add'])) ?>" class="card"><?= csrf_field() ?><h2><?= e(t('Nouvelle écriture')) ?></h2><div class="two"><input type="date" name="date" value="<?= gmdate('Y-m-d') ?>" required><input name="journal" value="OD" maxlength="4"></div>
<input name="label" placeholder="<?= e(t('Libellé')) ?>" required><div class="two"><input name="debit" list="plan" placeholder="<?= e(t('Compte débité')) ?>" required><input name="credit" list="plan" placeholder="<?= e(t('Compte crédité')) ?>" required></div>
<input name="amount" type="number" min="1" step="any" placeholder="<?= e(t('Montant')) ?>" required><datalist id="plan"><?php foreach (ac_plan() as $c => $lb) echo '<option value="' . e((string)$c) . '">' . e($lb) . '</option>'; ?></datalist><button class="btn cta"><?= e(t('Enregistrer')) ?></button></form>
<div class="card"><?php foreach ($s as $m) echo '<div class="li"><span>' . e($m['entry_date']) . ' · ' . e($m['journal']) . ' · ' . e($m['label']) . '<br><small>D ' . e($m['debit_account']) . ' / C ' . e($m['credit_account']) . '</small></span><span><b>' . e(money_fmt((float)$m['amount'])) . '</b>'
 . '<form method="post" action="' . e(url('accounting', ['a' => 'del'])) . '" class="inline">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$m['id'] . '"><button class="btn sm">🗑</button></form></span></div>'; ?></div>
<?php } else {
        if ($v === 'plan') { ?><form method="post" action="<?= e(url('accounting', ['a' => 'account'])) ?>" class="card noprint"><?= csrf_field() ?><h2><?= e(t('Ajouter / modifier un compte')) ?></h2><div class="two"><input name="code" placeholder="<?= e(t('Compte')) ?>" required><input name="label" placeholder="<?= e(t('Intitulé')) ?>" required></div><button class="btn cta"><?= e(t('Enregistrer')) ?></button></form><?php }
        ac_html($tbl);
    }
    render_foot(); }
