<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('acp_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}
function secure_headers(): void {
    header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin'); header('Cache-Control: no-store');
}
function require_login(): array {
    secure_headers(); start_session();
    $to = cfg()['session_timeout'];
    if (empty($_SESSION['admin_id']) || (time() - ($_SESSION['last'] ?? 0)) > $to) {
        session_unset(); redirect('login.php');
    }
    $_SESSION['last'] = time();
    return ['id' => $_SESSION['admin_id'], 'username' => $_SESSION['admin_user']];
}
function login_attempt(string $u, string $p): bool {
    $st = db()->prepare('SELECT id,username,password_hash FROM admins WHERE username=?');
    $st->execute([$u]); $a = $st->fetch();
    $ok = password_verify($p, $a['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ012');
    if (!$a || !$ok) return false;
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$a['id']; $_SESSION['admin_user'] = $a['username']; $_SESSION['last'] = time();
    return true;
}
