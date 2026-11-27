<?php
declare(strict_types=1);
/** Amorçage du panel web (point d'entrée unique : index.php). */
require_once __DIR__ . '/../../inc/db.php';
require_once __DIR__ . '/../../inc/helpers.php';
require_once __DIR__ . '/../../inc/csrf.php';
require_once __DIR__ . '/../../inc/bot.php';      // Evolution + bot (inclut ai.php, minipdf.php)
require_once __DIR__ . '/../../inc/sync_schema.php';
require_once __DIR__ . '/i18n.php';

function web_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('acp_web');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function secure_headers_web(): void {
    header('X-Frame-Options: SAMEORIGIN'); header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin'); header('Permissions-Policy: camera=(self)');
}
function base_url(): string { return rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/'); }
function url(string $m = '', array $q = []): string {
    return base_url() . '/index.php' . ($m !== '' || $q ? '?' . http_build_query(['m' => $m] + $q) : '');
}
function go(string $m, array $q = []): never { redirect(url($m, $q)); }

// ── Authentification ────────────────────────────────────────────────────────
function me(bool $reset = false): ?array {
    static $u = false;
    if ($reset) $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['wu'])) {
            if (time() - (int)($_SESSION['wlast'] ?? 0) > 8 * 3600) { session_unset(); }
            else {
                $st = db()->prepare('SELECT u.*, l.status AS lstatus, l.expires_at, l.shop_name AS lshop, l.plan FROM w_users u JOIN licenses l ON l.id=u.license_id WHERE u.id=?');
                $st->execute([(int)$_SESSION['wu']]); $u = $st->fetch() ?: null; $_SESSION['wlast'] = time();
            }
        }
    }
    return $u;
}
function require_user(): array {
    $u = me(); if (!$u) go('login');
    if ($u['lstatus'] !== 'active' || ($u['expires_at'] !== null && $u['expires_at'] < now())) {
        session_unset(); flash(t('Licence expirée ou révoquée. Contactez le support.'), 'err'); go('login');
    }
    return $u;
}
function lid(): int { return (int)require_user()['license_id']; }

function setting(string $k, string $def = ''): string {
    static $c = [];
    $l = lid();
    if (!isset($c[$l])) { $c[$l] = []; $st = db()->prepare('SELECT skey, svalue FROM w_settings WHERE license_id=?'); $st->execute([$l]); foreach ($st as $r) $c[$l][$r['skey']] = $r['svalue']; }
    return $c[$l][$k] ?? $def;
}
function setting_set(string $k, string $v): void {
    db()->prepare('INSERT INTO w_settings (license_id,skey,svalue) VALUES (?,?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')->execute([lid(), $k, $v]);
}
function money_fmt(float $v): string { return number_format($v, 0, ',', ' ') . ' ' . setting('currency', 'XAF'); }
function tax_rate(): float { return setting('tva_enabled', '0') === '1' ? max(0, min(100, (float)setting('tax_rate', '0'))) : 0.0; }

/** Compte bot de la licence, synchronisé avec les réglages de la boutique et marqué source = web. */
function bot_acc(): array {
    $a = bot_account(lid());
    db()->prepare("UPDATE bot_accounts SET source='web', shop_name=?, currency=?, tax_rate=?, tax_label=? WHERE license_id=?")
        ->execute([setting('shop_name', (string)(me()['lshop'] ?? '')), setting('currency', 'XAF'), tax_rate(), setting('tax_label', 'TVA'), lid()]);
    return bot_account(lid(), false);
}
function post_only(): void { if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; } csrf_check(); }
function json_out(array $d, int $http = 200): never { http_response_code($http); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function f_str(string $k, int $max = 200): string { return mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max); }
function f_num(string $k): float { return (float)str_replace([' ', ','], ['', '.'], (string)($_POST[$k] ?? '0')); }
