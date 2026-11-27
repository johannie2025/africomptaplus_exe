<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(int $http, array $d): never {
    $d['server_time'] = gmdate('c');
    http_response_code($http);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function resp(string $status, string $msg, ?array $lic = null, array $extra = []): never {
    out(200, ['valid' => $status === 'active', 'status' => $status, 'message' => $msg, 'license' => $lic] + $extra);
}
/** Config bot (webhook, instance, état WhatsApp…) renvoyée à l'APK à l'activation / au statut. */
function bot_payload(array $l): array {
    try { require_once __DIR__ . '/../inc/bot.php'; $a = bot_account((int)$l['id']); return ['bot' => bot_public($a)]; }
    catch (Throwable $e) { error_log('bot payload: ' . $e->getMessage()); return []; }
}
function log_call(string $key, string $dev, string $action, string $result, string $msg): void {
    try {
        db()->prepare('INSERT INTO api_logs (license_key,device_id,action,result,message,ip,created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([substr($key, 0, 64), substr($dev, 0, 128), $action, $result, substr($msg, 0, 255), client_ip(), now()]);
    } catch (Throwable $e) { error_log('api log: ' . $e->getMessage()); }
}
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function lic_info(PDO $pdo, array $l): array {
    $st = $pdo->prepare('SELECT COUNT(*) FROM activations WHERE license_id=?'); $st->execute([$l['id']]);
    return ['plan' => $l['plan'], 'customer_name' => $l['customer_name'], 'shop_name' => $l['shop_name'],
            'expires_at' => $l['expires_at'] === null ? null : str_replace(' ', 'T', $l['expires_at']) . 'Z',
            'max_devices' => (int)$l['max_devices'], 'devices_used' => (int)$st->fetchColumn()];
}

try {
    $action = strtolower((string)($_GET['action'] ?? ''));
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'ping') {
        out(200, ['valid' => true, 'status' => 'active', 'message' => 'pong', 'license' => null]);
    }
    if ($action === 'webhook') { require_once __DIR__ . '/../inc/bot.php'; bot_webhook(); exit; }   // Evolution API → bot
    if ($method !== 'POST') out(405, ['valid' => false, 'status' => 'invalid', 'message' => 'Méthode non autorisée', 'license' => null]);

    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) out(400, ['valid' => false, 'status' => 'invalid', 'message' => 'Requête invalide', 'license' => null]);
    if ($action === '' && isset($body['action']) && is_string($body['action'])) $action = strtolower($body['action']);
    if ($action === 'verify') $action = 'activate';
    require_once __DIR__ . '/../inc/bot_api.php';
require_once __DIR__ . '/../inc/sync_api.php';
    if (!in_array($action, array_merge(['activate', 'status', 'deactivate'], BOT_ACTIONS, SYNC_ACTIONS), true))
        out(400, ['valid' => false, 'status' => 'invalid', 'message' => 'Action inconnue', 'license' => null]);

    $pdo = db();
    $ip = client_ip();

    // Limitation : échecs / 10 min / IP
    $since = gmdate('Y-m-d H:i:s', time() - 600);
    $st = $pdo->prepare("SELECT COUNT(*) FROM api_logs WHERE ip=? AND created_at>=? AND result NOT IN ('active','ok')");
    $st->execute([$ip, $since]);
    if ((int)$st->fetchColumn() >= (int)cfg()['api_fail_limit'])
        out(429, ['valid' => false, 'status' => 'invalid', 'message' => 'Trop de tentatives. Réessayez plus tard.', 'license' => null]);

    $key = strtoupper(trim((string)($body['license_key'] ?? '')));
    $dev = trim((string)($body['device_id'] ?? ''));
    $devName = substr(trim((string)($body['device_name'] ?? '')), 0, 120);
    $appVer = substr(trim((string)($body['app_version'] ?? '')), 0, 30);

    if (!preg_match('/^[A-Z0-9-]{6,32}$/', $key)) {
        log_call($key, $dev, $action, 'invalid', 'format clé');
        resp('invalid', 'Clé de licence invalide.');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{6,128}$/', $dev)) {
        log_call($key, $dev, $action, 'invalid', 'format appareil');
        out(400, ['valid' => false, 'status' => 'invalid', 'message' => 'Identifiant appareil invalide.', 'license' => null]);
    }

    $st = $pdo->prepare('SELECT * FROM licenses WHERE license_key=?'); $st->execute([$key]);
    $l = $st->fetch();
    if (!$l) { log_call($key, $dev, $action, 'invalid', 'clé inconnue'); resp('invalid', 'Clé de licence invalide.'); }

    $st = $pdo->prepare('SELECT id FROM activations WHERE license_id=? AND device_id=?');
    $st->execute([$l['id'], $dev]); $linked = $st->fetch();

    if ($action === 'deactivate') {
        $pdo->prepare('DELETE FROM activations WHERE license_id=? AND device_id=?')->execute([$l['id'], $dev]);
        log_call($key, $dev, $action, 'ok', 'appareil libéré');
        out(200, ['valid' => true, 'status' => 'active', 'message' => 'Appareil désactivé.', 'license' => lic_info($pdo, $l)]);
    }

    if ($l['status'] === 'revoked') { log_call($key, $dev, $action, 'revoked', 'révoquée'); resp('revoked', 'Cette licence a été révoquée.', lic_info($pdo, $l)); }
    if ($l['expires_at'] !== null && $l['expires_at'] < now()) {
        log_call($key, $dev, $action, 'expired', 'expirée'); resp('expired', 'Cette licence a expiré.', lic_info($pdo, $l));
    }

    if (!$linked && in_array($action, array_merge(BOT_ACTIONS, SYNC_ACTIONS), true)) resp('unknown_device', 'Appareil non activé pour cette licence.');
    if ($linked) {
        $pdo->prepare('UPDATE activations SET last_seen_at=?, ip=?, app_version=?, device_name=CASE WHEN ?<>\'\' THEN ? ELSE device_name END WHERE id=?')
            ->execute([now(), $ip, $appVer, $devName, $devName, $linked['id']]);
        if (in_array($action, BOT_ACTIONS, true)) bot_dispatch($action, $l, $body);
        if ($action === 'sync') sync_dispatch($l, $body);
        log_call($key, $dev, $action, 'active', 'ok');
        resp('active', $action === 'status' ? 'Licence active.' : 'Licence activée.', lic_info($pdo, $l), bot_payload($l));
    }
    if ($action === 'status') {
        log_call($key, $dev, $action, 'unknown_device', 'appareil non lié');
        resp('unknown_device', 'Appareil non activé pour cette licence.', lic_info($pdo, $l));
    }

    // activate : nouvel appareil
    $st = $pdo->prepare('SELECT COUNT(*) FROM activations WHERE license_id=?'); $st->execute([$l['id']]);
    if ((int)$st->fetchColumn() >= (int)$l['max_devices']) {
        log_call($key, $dev, $action, 'device_limit', 'limite appareils');
        resp('device_limit', 'Nombre maximum d\'appareils atteint pour cette licence.', lic_info($pdo, $l));
    }
    $t = now();
    $pdo->prepare('INSERT INTO activations (license_id,device_id,device_name,app_version,ip,first_activated_at,last_seen_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$l['id'], $dev, $devName, $appVer, $ip, $t, $t]);
    log_call($key, $dev, $action, 'active', 'nouvelle activation');
    resp('active', 'Licence activée avec succès.', lic_info($pdo, $l), bot_payload($l));
} catch (Throwable $e) {
    error_log('[africompta api] ' . $e->getMessage());
    out(500, ['valid' => false, 'status' => 'invalid', 'message' => 'Erreur serveur. Réessayez plus tard.', 'license' => null]);
}
