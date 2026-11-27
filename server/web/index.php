<?php
declare(strict_types=1);
/**
 * AfriCompta+ Web (PWA) — point d'entrée unique + routeur central.
 * Chaque module vit dans modules/<nom>.php et expose mod_<nom>().
 */
require_once __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/layout.php';

secure_headers_web(); web_session();
if (isset($_GET['lang_set'])) lang_set((string)$_GET['lang_set']);

const ROUTES = ['login', 'register', 'logout', 'lang', 'dashboard', 'pos', 'products', 'customers', 'debts', 'expenses', 'reports', 'whatsapp', 'settings', 'sales', 'documents', 'suppliers', 'accounting'];
$m = (string)($_GET['m'] ?? '');
if ($m === '') $m = me() ? 'dashboard' : 'login';
if (!in_array($m, ROUTES, true)) { http_response_code(404); $m = 'dashboard'; }

try {
    require __DIR__ . '/modules/' . $m . '.php';
    ('mod_' . $m)();
} catch (Throwable $e) {
    error_log('[africompta web] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    render_head(t('Erreur'), '', false);
    echo '<div class="card"><h2>' . e(t('Une erreur est survenue')) . '</h2><p>' . e(t('Réessayez dans un instant.')) . '</p></div>';
    render_foot();
}
