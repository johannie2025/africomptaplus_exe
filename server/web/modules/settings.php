<?php
function mod_settings(): void {
    $l = lid(); $pdo = db(); $a = (string)($_GET['a'] ?? ''); $countries = require __DIR__ . '/../inc/countries.php';
    if ($a === 'save') {
        post_only();
        foreach (['shop_name' => 120, 'shop_activity' => 120, 'shop_address' => 200, 'shop_phone' => 30, 'shop_tax_id' => 60, 'footer' => 200] as $k => $max) setting_set($k, f_str($k, $max));
        $cur = strtoupper(f_str('currency', 8)) ?: 'XAF'; setting_set('currency', preg_match('/^[A-Z]{3,4}$/', $cur) ? $cur : 'XAF');
        setting_set('tva_enabled', !empty($_POST['tva_enabled']) ? '1' : '0');
        setting_set('tax_rate', (string)max(0, min(100, f_num('tax_rate')))); setting_set('tax_label', f_str('tax_label', 20) ?: 'TVA');
        if (isset($countries[$_POST['country'] ?? ''])) setting_set('country', $_POST['country']);
        bot_acc(); flash(t('Paramètres enregistrés')); go('settings');
    }
    if ($a === 'country') {      // charge devise, TVA et libellé fiscal du pays choisi
        post_only(); $c = $countries[$_POST['country'] ?? ''] ?? null;
        if ($c) { setting_set('country', $_POST['country']); setting_set('currency', $c[1]); setting_set('tax_rate', (string)$c[2]); setting_set('tax_label', 'TVA'); setting_set('tax_id_label', $c[3]); bot_acc();
                  flash(t('Configuration ') . $c[0] . t(' chargée') . ' (' . t('TVA par défaut : ') . $c[2] . ' % ' . t('(indicatif)') . ')'); }
        go('settings');
    }
    if ($a === 'acct') { post_only(); $m = $_POST['mode'] ?? 'off'; setting_set('acct_mode', in_array($m, ['off', 'simple', 'double'], true) ? $m : 'off'); flash(t('Paramètres enregistrés')); go('settings'); }
    if ($a === 'export') {      // sauvegarde JSON des données de la boutique (aucun secret)
        $out = ['exported_at' => now(), 'settings' => []];
        $st = $pdo->prepare('SELECT skey,svalue FROM w_settings WHERE license_id=?'); $st->execute([$l]); foreach ($st as $r) $out['settings'][$r['skey']] = $r['svalue'];
        foreach (['w_products', 'w_customers', 'w_sales', 'w_payments', 'w_expenses', 'w_suppliers', 'w_purchases', 'w_supplier_payments', 'w_documents', 'w_acct_entries', 'w_acct_accounts'] as $t) { $q = $pdo->prepare("SELECT * FROM $t WHERE license_id=?"); $q->execute([$l]); $out[$t] = $q->fetchAll(); }
        $q = $pdo->prepare('SELECT i.* FROM w_sale_items i JOIN w_sales s ON s.id=i.sale_id WHERE s.license_id=?'); $q->execute([$l]); $out['w_sale_items'] = $q->fetchAll();
        header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="africompta-export-' . gmdate('Ymd') . '.json"'); echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit;
    }
    if ($a === 'conflict') {     // résolution d'un conflit de synchronisation : garder le web (ignorer) ou appliquer la valeur de l'appareil
        post_only(); require_once __DIR__ . '/../../inc/sync_api.php'; sync_migrate();
        $q = $pdo->prepare('SELECT * FROM w_sync_conflicts WHERE id=? AND license_id=? AND resolved=0'); $q->execute([(int)($_POST['id'] ?? 0), $l]); $c = $q->fetch();
        if ($c) {
            if (($_POST['act'] ?? '') === 'apply' && isset(SY_FIELDS[$c['tbl']]) ) {
                $col = SY_FIELDS[$c['tbl']][$c['field']] ?? null;
                if ($col) $pdo->prepare('UPDATE ' . $c['tbl'] . " SET `$col`=? WHERE license_id=? AND uid=?")->execute([$c['device_value'], $l, $c['uid']]);
            }
            $pdo->prepare('UPDATE w_sync_conflicts SET resolved=1 WHERE id=?')->execute([$c['id']]);
            flash(t('Enregistré ✓'));
        }
        go('settings');
    }
    if ($a === 'password') {
        post_only(); $u = me(); $new = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), $u['password_hash'])) flash(t('Mot de passe actuel incorrect.'), 'err');
        elseif (strlen($new) < 8) flash(t('Mot de passe : 8 caractères minimum.'), 'err');
        else { $pdo->prepare('UPDATE w_users SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]); flash(t('Mot de passe modifié.')); }
        go('settings');
    }
    $cur = setting('country', '');
    render_head(t('Paramètres'), 'settings'); ?>
<h1><?= e(t('Ma boutique & licence')) ?></h1>
<form method="post" action="<?= e(url('settings', ['a' => 'country'])) ?>" class="card"><?= csrf_field() ?><h2><?= e(t('Pays (OHADA)')) ?></h2>
<p class="mu"><?= e(t("Charge la devise, la TVA par défaut, l'identifiant fiscal et les comptes du pays")) ?></p>
<div class="two"><select name="country"><option value=""><?= e(t('Aucun pays chargé')) ?></option><?php foreach ($countries as $k => $c) echo '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . e($c[0]) . ' (' . $c[1] . ')</option>'; ?></select><button class="btn"><?= e(t('Charger la configuration du pays')) ?></button></div></form>
<form method="post" action="<?= e(url('settings', ['a' => 'save'])) ?>" class="card"><?= csrf_field() ?><h2><?= e(t('Identité de la boutique')) ?></h2>
<label><?= e(t('Nom de la boutique *')) ?></label><input name="shop_name" required value="<?= e(setting('shop_name', (string)me()['lshop'])) ?>" placeholder="<?= e(t('Ex : Boutique Mama Awa')) ?>">
<label><?= e(t('Activité / slogan')) ?></label><input name="shop_activity" value="<?= e(setting('shop_activity')) ?>">
<label><?= e(t('Adresse / localisation')) ?></label><input name="shop_address" value="<?= e(setting('shop_address')) ?>">
<div class="two"><div><label><?= e(t('Téléphone')) ?></label><input name="shop_phone" value="<?= e(setting('shop_phone')) ?>"></div><div><label><?= e(t('N° fiscal / RCCM (optionnel)')) ?></label><input name="shop_tax_id" value="<?= e(setting('shop_tax_id')) ?>"></div></div>
<label><?= e(t('Message de pied de page')) ?></label><input name="footer" value="<?= e(setting('footer', t('Merci pour votre achat !'))) ?>">
<div class="two"><div><label><?= e(t('Devise : ')) ?></label><input name="currency" value="<?= e(setting('currency', 'XAF')) ?>" maxlength="4"></div><div><label><?= e(t('Taux de TVA (%)')) ?></label><input name="tax_rate" type="number" step="any" min="0" max="100" value="<?= e(setting('tax_rate', '0')) ?>"></div></div>
<input type="hidden" name="country" value="<?= e($cur) ?>"><input type="hidden" name="tax_label" value="<?= e(setting('tax_label', 'TVA')) ?>">
<label class="chk"><input type="checkbox" name="tva_enabled" value="1" <?= setting('tva_enabled', '0') === '1' ? 'checked' : '' ?>> <?= e(t('TVA activée')) ?></label>
<button class="btn cta"><?= e(t('Enregistrer')) ?></button></form>
<?php try { $cf = $pdo->prepare('SELECT * FROM w_sync_conflicts WHERE license_id=? AND resolved=0 ORDER BY id DESC LIMIT 100'); $cf->execute([$l]); $cfl = $cf->fetchAll(); } catch (Throwable $e) { $cfl = []; }
if ($cfl): ?><div class="card"><h2>⚠ <?= e(t('Conflits de synchronisation')) ?> (<?= count($cfl) ?>)</h2><p class="mu"><?= e(t("Modifié à la fois sur l'appli et sur le web. La valeur du web a été conservée.")) ?></p>
<?php foreach ($cfl as $c): ?><div class="li"><span><b><?= e($c['label']) ?></b> · <?= e($c['field']) ?><br><small><?= e(t('Web')) ?> : <?= e($c['web_value']) ?> · <?= e(t('Appli')) ?> : <?= e($c['device_value']) ?></small></span>
<form method="post" action="<?= e(url('settings', ['a' => 'conflict'])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sm" name="act" value="apply"><?= e(t("Utiliser l'appli")) ?></button> <button class="btn sm" name="act" value="keep"><?= e(t('Garder le web')) ?></button></form></div>
<?php endforeach; ?></div><?php endif; ?>
<form method="post" action="<?= e(url('settings', ['a' => 'acct'])) ?>" class="card"><?= csrf_field() ?><h2><?= e(t('Comptabilité')) ?></h2>
<select name="mode"><?php foreach (['off' => t('Désactivée'), 'simple' => t('Simple (caisse, recettes/dépenses)'), 'double' => t('Double (OHADA, partie double)')] as $k => $lb) echo '<option value="' . $k . '"' . (setting('acct_mode', 'off') === $k ? ' selected' : '') . '>' . e($lb) . '</option>'; ?></select>
<button class="btn"><?= e(t('Enregistrer')) ?></button></form>
<div class="card"><h2><?= e(t('Export des données')) ?></h2><a class="btn" href="<?= e(url('settings', ['a' => 'export'])) ?>">⬇ JSON</a></div>
<div class="card"><h2><?= e(t('Licence')) ?></h2><p><?= e(t('Offre : ')) ?><b><?= e(me()['plan']) ?></b> · <?= e(me()['expires_at'] ? e(t("Valable jusqu'au ")) . substr((string)me()['expires_at'], 0, 10) : t('illimitée')) ?></p></div>
<form method="post" action="<?= e(url('settings', ['a' => 'password'])) ?>" class="card"><?= csrf_field() ?><h2><?= e(t('Mot de passe')) ?></h2>
<input type="password" name="old" placeholder="<?= e(t('Mot de passe actuel')) ?>" required autocomplete="current-password"><input type="password" name="new" placeholder="<?= e(t('Nouveau mot de passe')) ?>" required minlength="8" autocomplete="new-password">
<button class="btn"><?= e(t('Enregistrer')) ?></button></form>
<?php render_foot(); }
