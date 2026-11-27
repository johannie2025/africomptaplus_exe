<?php
require_once __DIR__ . '/../inc/auth.php';
secure_headers(); start_session();
$err = '';
try {
    $n = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($n === 0) redirect('install.php');
} catch (Throwable $e) { error_log($e->getMessage()); $err = 'Base de données inaccessible.'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    csrf_check();
    usleep(300000);
    if (login_attempt(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) redirect('index.php');
    $err = 'Identifiants incorrects.';
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="login card"><h1>AfriCompta+ Licences</h1>
<?php if ($err) echo '<div class="flash err">' . e($err) . '</div>'; ?>
<form method="post"><?= csrf_field() ?>
<label>Utilisateur</label><input name="username" required autofocus autocomplete="username">
<label>Mot de passe</label><input type="password" name="password" required autocomplete="current-password">
<p><button class="btn cta" style="width:100%;padding:14px">Se connecter</button></p></form></div></body></html>
