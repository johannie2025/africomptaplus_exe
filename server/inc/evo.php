<?php
/** Client Evolution API (v2). La clé globale reste sur le serveur : l'APK ne la voit jamais. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai.php';   // ai_http, ai_enc/ai_dec

function bot_setting(string $k, string $def = ''): string {
    static $c = null;
    if ($c === null) { $c = []; foreach (db()->query('SELECT skey, svalue FROM bot_settings') as $r) $c[$r['skey']] = $r['svalue']; }
    return $c[$k] ?? $def;
}
function bot_setting_set(string $k, string $v): void {
    db()->prepare('INSERT INTO bot_settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)')->execute([$k, $v]);
}
function evo_ready(): bool { return bot_setting('evo_url') !== '' && bot_setting('evo_key_enc') !== ''; }

/** @return array ['code'=>int,'data'=>array] ; lève RuntimeException si réseau/non configuré */
function evo(string $method, string $path, ?array $json = null): array {
    if (!evo_ready()) throw new RuntimeException('Evolution API non configurée (panel → Bot WhatsApp).');
    $key = ai_dec(bot_setting('evo_key_enc'));
    $url = rtrim(bot_setting('evo_url'), '/') . $path;
    $r = ai_http($method, $url, ['apikey: ' . $key], $json, 25);
    return ['code' => $r['code'], 'data' => $r['data'], 'raw' => $r['raw']];
}
function evo_err(array $r): string {
    $m = $r['data']['response']['message'] ?? $r['data']['message'] ?? $r['data']['error'] ?? '';
    if (is_array($m)) $m = json_encode($m, JSON_UNESCAPED_UNICODE);
    return 'Evolution HTTP ' . $r['code'] . ($m ? ' : ' . mb_substr((string)$m, 0, 200) : '');
}
function bot_webhook_url(array $acc): string {
    $base = bot_setting('public_url');
    if ($base === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'), '/');
    }
    return rtrim($base, '/') . '/index.php?action=webhook&t=' . $acc['token'];
}
function evo_set_webhook(array $acc): void {
    $body = ['webhook' => ['enabled' => true, 'url' => bot_webhook_url($acc), 'byEvents' => false, 'base64' => false,
             'events' => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE']]];
    $r = evo('POST', '/webhook/set/' . rawurlencode($acc['instance_name']), $body);
    if ($r['code'] >= 300) throw new RuntimeException(evo_err($r));
}
/** Crée l'instance si besoin, retourne ['qr'=>base64|null,'code'=>pairing|null,'state'=>…] */
function evo_connect(array $acc): array {
    $name = rawurlencode($acc['instance_name']);
    $s = evo('GET', "/instance/connectionState/$name");
    if ($s['code'] === 404) {
        $c = evo('POST', '/instance/create', [
            'instanceName' => $acc['instance_name'], 'qrcode' => true, 'integration' => 'WHATSAPP-BAILEYS',
            'rejectCall' => true, 'groupsIgnore' => true, 'alwaysOnline' => false, 'readMessages' => false,
            'webhook' => ['url' => bot_webhook_url($acc), 'byEvents' => false, 'base64' => false, 'events' => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE']],
        ]);
        if ($c['code'] >= 300) throw new RuntimeException(evo_err($c));
        $qr = $c['data']['qrcode']['base64'] ?? null;
        if ($qr) return ['qr' => $qr, 'state' => 'connecting'];
    } elseif ($s['code'] < 300) {
        if (($s['data']['instance']['state'] ?? '') === 'open') return ['qr' => null, 'state' => 'open'];
        try { evo_set_webhook($acc); } catch (Throwable $e) { /* non bloquant */ }
    } else throw new RuntimeException(evo_err($s));
    $q = evo('GET', "/instance/connect/$name");
    if ($q['code'] >= 300) throw new RuntimeException(evo_err($q));
    return ['qr' => $q['data']['base64'] ?? null, 'code' => $q['data']['pairingCode'] ?? null, 'state' => 'connecting'];
}
function evo_state(array $acc): string {
    $r = evo('GET', '/instance/connectionState/' . rawurlencode($acc['instance_name']));
    if ($r['code'] === 404) return 'none';
    if ($r['code'] >= 300) throw new RuntimeException(evo_err($r));
    return (string)($r['data']['instance']['state'] ?? 'close');
}
function evo_logout(array $acc): void {
    $n = rawurlencode($acc['instance_name']);
    evo('DELETE', "/instance/logout/$n");
    evo('DELETE', "/instance/delete/$n");
}
function evo_send_text(array $acc, string $to, string $text): void {
    $r = evo('POST', '/message/sendText/' . rawurlencode($acc['instance_name']), ['number' => $to, 'text' => $text]);
    if ($r['code'] >= 300) throw new RuntimeException(evo_err($r));
}
function evo_send_pdf(array $acc, string $to, string $pdfBytes, string $fileName, string $caption): void {
    $r = evo('POST', '/message/sendMedia/' . rawurlencode($acc['instance_name']), [
        'number' => $to, 'mediatype' => 'document', 'mimetype' => 'application/pdf',
        'caption' => $caption, 'fileName' => $fileName, 'media' => base64_encode($pdfBytes)]);
    if ($r['code'] >= 300) throw new RuntimeException(evo_err($r));
}
