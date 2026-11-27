<?php
function mod_login(): void {
    if (me()) go('dashboard');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $fails = (int)($_SESSION['lf'] ?? 0);
        if ($fails >= 5) { usleep(1500000); }
        $st = db()->prepare('SELECT * FROM w_users WHERE email=?'); $st->execute([strtolower(f_str('email', 120))]); $u = $st->fetch();
        $ok = password_verify((string)($_POST['password'] ?? ''), $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012');
        if ($u && $ok) {
            session_regenerate_id(true);
            $_SESSION['wu'] = (int)$u['id']; $_SESSION['wlast'] = time(); $_SESSION['lf'] = 0;
            db()->prepare('UPDATE w_users SET last_login_at=? WHERE id=?')->execute([now(), $u['id']]);
            if (in_array($u['lang'], ['fr', 'en', 'es'], true)) lang_set($u['lang']);
            go('dashboard');
        }
        $_SESSION['lf'] = $fails + 1; flash(t('Email ou mot de passe incorrect.'), 'err'); go('login');
    }
    render_head(t('Connexion'), '', false); ?>
<div class="auth"><img src="assets/icon-192.png" width="72" height="72" alt="" class="logo"><h1>AfriCompta+</h1>
<p class="mu"><?= e(t('Gérez votre boutique depuis le web : ventes, stock, dettes, WhatsApp.')) ?></p>
<form method="post" class="card"><?= csrf_field() ?>
<label><?= e(t('Email')) ?></label><input type="email" name="email" required autocomplete="username">
<label><?= e(t('Mot de passe')) ?></label><input type="password" name="password" required autocomplete="current-password">
<button class="btn cta"><?= e(t('Se connecter')) ?></button></form>
<p><a href="<?= e(url('register')) ?>"><?= e(t('Première visite ou mot de passe oublié ? Utiliser ma clé de licence')) ?></a></p></div>
<?php render_foot(); }
