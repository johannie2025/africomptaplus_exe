<?php
require_once __DIR__ . '/../inc/auth.php'; require_once __DIR__ . '/../inc/layout.php';
$me = require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = db()->prepare('SELECT password_hash FROM admins WHERE id=?'); $st->execute([$me['id']]); $h = $st->fetchColumn();
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify((string)($_POST['current'] ?? ''), (string)$h)) flash('Mot de passe actuel incorrect.', 'err');
    elseif (strlen($new) < 10) flash('Nouveau mot de passe : 10 caractères minimum.', 'err');
    elseif ($new !== ($_POST['confirm'] ?? '')) flash('La confirmation ne correspond pas.', 'err');
    else { db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]); flash('Mot de passe modifié.'); }
    redirect('account.php');
}
page_head('Compte', 'account'); ?>
<h1>Mon compte</h1><div class="card" style="max-width:460px"><p>Connecté : <b><?= e($me['username']) ?></b></p>
<form method="post"><?= csrf_field() ?><label>Mot de passe actuel</label><input type="password" name="current" required>
<label>Nouveau mot de passe</label><input type="password" name="new" required minlength="10">
<label>Confirmation</label><input type="password" name="confirm" required>
<p><button class="btn">Modifier</button></p></form></div>
<?php page_foot();
