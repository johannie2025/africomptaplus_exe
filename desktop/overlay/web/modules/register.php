<?php
/** Édition autonome : la clé est activée auprès du panel (internet requis une seule fois), puis tout fonctionne hors-ligne. */
require_once __DIR__ . '/../../inc/desktop_license.php';
function mod_register(): void {
    $panel = acp_panel_url();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $key = strtoupper(f_str('license_key', 40)); $email = strtolower(f_str('email', 120)); $pw = (string)($_POST['password'] ?? '');
        $purl = $panel !== '' ? $panel : rtrim(f_str('panel_url', 200), '/');
        $err = ''; $lid = 0;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = t('Email invalide.');
        elseif (strlen($pw) < 8) $err = t('Mot de passe : 8 caractères minimum.');
        else {
            $st = db()->prepare('SELECT * FROM licenses WHERE license_key=?'); $st->execute([$key]); $local = $st->fetch();
            $online = $purl !== '' && acp_panel_valid($purl);
            $r = $online ? acp_remote($purl, 'activate', $key) : null;
            if ($r && $r['ok'] && $r['http'] === 200) {
                if (($r['data']['status'] ?? '') === 'active') {
                    $lid = acp_apply_license($key, $r['data'], 'active');
                    if ($panel === '') acp_panel_save($purl);
                } else $err = (string)($r['data']['message'] ?? t('Clé de licence invalide ou expirée.'));
            } elseif ($r && $r['ok']) {
                $err = (string)($r['data']['message'] ?? t('Clé de licence invalide ou expirée.'));
            } elseif ($local && $local['status'] === 'active' && ($local['expires_at'] === null || $local['expires_at'] >= now())) {
                $lid = (int)$local['id'];   // hors-ligne : la clé déjà activée sur ce poste prouve la propriété (mot de passe oublié)
            } else {
                $err = $online ? t('Impossible de joindre le panel pour activer la licence. Vérifiez la connexion internet.') : t('Adresse du panel invalide.');
            }
            if (!$err) {
                $dup = db()->prepare('SELECT license_id FROM w_users WHERE email=?'); $dup->execute([$email]); $d = $dup->fetchColumn();
                if ($d && (int)$d !== $lid) $err = t('Cet email est déjà utilisé.');
            }
        }
        if ($err) { flash($err, 'err'); go('register'); }
        $ls = db()->prepare('SELECT shop_name FROM licenses WHERE id=?'); $ls->execute([$lid]); $shop = (string)$ls->fetchColumn();
        db()->prepare('INSERT INTO w_users (license_id,email,password_hash,lang,created_at) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE email=VALUES(email), password_hash=VALUES(password_hash)')
            ->execute([$lid, $email, password_hash($pw, PASSWORD_DEFAULT), lang_pick(), now()]);
        $uid = (int)db()->query('SELECT id FROM w_users WHERE license_id=' . $lid)->fetchColumn();
        session_regenerate_id(true); $_SESSION['wu'] = $uid; $_SESSION['wlast'] = time();
        me(true);
        if (setting('shop_name', '') === '') setting_set('shop_name', $shop);
        flash(t('Accès créé. Bienvenue !')); go('settings');
    }
    render_head(t('Accès avec ma licence'), '', false); ?>
<div class="auth"><img src="assets/icon-192.png" width="72" height="72" alt="" class="logo"><h1><?= e(t('Accès avec ma licence')) ?></h1>
<form method="post" class="card"><?= csrf_field() ?>
<label><?= e(t('Clé de licence')) ?></label><input name="license_key" required placeholder="ACP-XXXX-XXXX-XXXX" autocomplete="off">
<?php if ($panel === ''): ?><label><?= e(t('Adresse du panel')) ?></label><input name="panel_url" placeholder="https://mon-panel.exemple.com" autocomplete="off"><?php endif; ?>
<label><?= e(t('Email')) ?></label><input type="email" name="email" required autocomplete="username">
<label><?= e(t('Nouveau mot de passe')) ?></label><input type="password" name="password" required minlength="8" autocomplete="new-password">
<button class="btn cta"><?= e(t('Créer mon accès')) ?></button></form>
<p><a href="<?= e(url('login')) ?>"><?= e(t('Retour à la connexion')) ?></a></p></div>
<?php render_foot(); }
