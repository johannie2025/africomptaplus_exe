<?php
/** Crée (ou réinitialise) l'accès web d'une licence : la clé de licence prouve la propriété. */
function mod_register(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $key = strtoupper(f_str('license_key', 40)); $email = strtolower(f_str('email', 120)); $pw = (string)($_POST['password'] ?? '');
        $st = db()->prepare('SELECT * FROM licenses WHERE license_key=?'); $st->execute([$key]); $l = $st->fetch();
        $err = '';
        if (!$l || $l['status'] !== 'active' || ($l['expires_at'] !== null && $l['expires_at'] < now())) $err = t('Clé de licence invalide ou expirée.');
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = t('Email invalide.');
        elseif (strlen($pw) < 8) $err = t('Mot de passe : 8 caractères minimum.');
        else {
            $dup = db()->prepare('SELECT license_id FROM w_users WHERE email=?'); $dup->execute([$email]); $d = $dup->fetchColumn();
            if ($d && (int)$d !== (int)$l['id']) $err = t('Cet email est déjà utilisé.');
        }
        if ($err) { flash($err, 'err'); go('register'); }
        db()->prepare('INSERT INTO w_users (license_id,email,password_hash,lang,created_at) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE email=VALUES(email), password_hash=VALUES(password_hash)')
            ->execute([$l['id'], $email, password_hash($pw, PASSWORD_DEFAULT), lang_pick(), now()]);
        $uid = (int)db()->query('SELECT id FROM w_users WHERE license_id=' . (int)$l['id'])->fetchColumn();
        session_regenerate_id(true); $_SESSION['wu'] = $uid; $_SESSION['wlast'] = time();
        me(true);
        if (setting('shop_name', '') === '') setting_set('shop_name', (string)$l['shop_name']);
        flash(t('Accès créé. Bienvenue !')); go('settings');
    }
    render_head(t('Accès avec ma licence'), '', false); ?>
<div class="auth"><img src="assets/icon-192.png" width="72" height="72" alt="" class="logo"><h1><?= e(t('Accès avec ma licence')) ?></h1>
<form method="post" class="card"><?= csrf_field() ?>
<label><?= e(t('Clé de licence')) ?></label><input name="license_key" required placeholder="ACP-XXXX-XXXX-XXXX" autocomplete="off">
<label><?= e(t('Email')) ?></label><input type="email" name="email" required autocomplete="username">
<label><?= e(t('Nouveau mot de passe')) ?></label><input type="password" name="password" required minlength="8" autocomplete="new-password">
<button class="btn cta"><?= e(t('Créer mon accès')) ?></button></form>
<p><a href="<?= e(url('login')) ?>"><?= e(t('Retour à la connexion')) ?></a></p></div>
<?php render_foot(); }
