<?php
// Routeur du serveur PHP intégré : remplace les .htaccess (Apache) du serveur d'origine.
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
if ($path === '/__health') { require __DIR__ . '/health.php'; return true; }
if ($path === '/' || $path === '') { header('Location: /web/index.php'); return true; }
if (preg_match('#(^|/)(inc|modules|lang|sql|data|runtime)(/|$)#i', $path)
    || preg_match('#\.(sql|md|log|ini|bak)$#i', $path)
    || preg_match('#(^|/)\.#', $path)) {
    http_response_code(403); echo 'Forbidden'; return true;
}
return false; // fichier statique ou script PHP : traité normalement
