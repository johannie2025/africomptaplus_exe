<?php
require_once __DIR__ . '/../inc/auth.php';
secure_headers(); start_session();
$msg = ''; $done = false; $locked = false;
try {
    $locked = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
} catch (Throwable $e) { error_log($e->getMessage()); $msg = 'Base inaccessible ou schéma non importé (sql/schema.sql).'; $locked = true; }
if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $u = trim((string)($_POST['username'] ?? '')); $p = (string)($_POST['password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $u)) $msg = 'Utilisateur invalide (3-60 car. alphanumériques).';
    elseif (strlen($p) < 10) $msg = 'Mot de passe : 10 caractères minimum.';
    else {
        db()->prepare('INSERT INTO admins (username,password_hash,created_at) VALUES (?,?,?)')->execute([$u, password_hash($p, PASSWORD_DEFAULT), now()]);
        $done = true; $locked = true;
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installation</title><link rel="stylesheet" href="assets/style.css"></head><body>
<div class="login card"><h1>Installation</h1>
<?php if ($done): ?><div class="flash ok">Administrateur créé. <b>Supprimez maintenant admin/install.php</b>, puis <a href="login.php">connectez-vous</a>.</div>
<?php elseif ($locked): ?><div class="flash err"><?= e($msg ?: 'Installation déjà effectuée. Supprimez ce fichier.') ?></div>
<?php else: ?><?php if ($msg) echo '<div class="flash err">' . e($msg) . '</div>'; ?>
<form method="post"><?= csrf_field() ?><label>Utilisateur admin</label><input name="username" required>
<label>Mot de passe (10+ caractères)</label><input type="password" name="password" required minlength="10">
<p><button class="btn cta" style="width:100%;padding:14px">Créer l'administrateur</button></p></form><?php endif; ?></div></body></html>
