<?php
/** Édition Windows autonome : activation / vérification de la licence auprès du panel distant. */
function acp_panel_url(): string {
    $u = trim((string)(cfg()['panel_url'] ?? ''));
    $d = (string)(cfg()['data_dir'] ?? '');
    if ($u === '' && $d !== '' && is_file("$d/panel_url.txt")) $u = trim((string)file_get_contents("$d/panel_url.txt"));
    return rtrim($u, '/');
}
function acp_panel_save(string $u): void { $d = (string)(cfg()['data_dir'] ?? ''); if ($d !== '') @file_put_contents("$d/panel_url.txt", $u); }
function acp_panel_valid(string $u): bool { return (bool)preg_match('#^https?://[^\s/]+\S*$#i', $u); }

/** Appel de l'API du panel : ['ok'=>bool,'http'=>int,'data'=>?array,'error'=>string] */
function acp_remote(string $panel, string $action, string $key): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'curl indisponible'];
    $ch = curl_init($panel . '/api/index.php?action=' . rawurlencode($action));
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['license_key' => $key, 'device_id' => (string)(cfg()['device_id'] ?? ''),
            'device_name' => substr((string)gethostname(), 0, 120), 'app_version' => 'win-1.0'], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false) return ['ok' => false, 'http' => 0, 'data' => null, 'error' => $err ?: 'connexion impossible'];
    $data = json_decode((string)$body, true);
    if (!is_array($data)) return ['ok' => false, 'http' => $http, 'data' => null, 'error' => 'réponse invalide'];
    return ['ok' => true, 'http' => $http, 'data' => $data, 'error' => ''];
}

/** Crée / met à jour la copie locale de la licence (les tables w_* s'y rattachent). Retourne l'id local. */
function acp_apply_license(string $key, array $d, string $status): int {
    $lic = is_array($d['license'] ?? null) ? $d['license'] : [];
    $plan = in_array($lic['plan'] ?? '', ['basic', 'pro', 'lifetime'], true) ? $lic['plan'] : 'basic';
    $t = isset($lic['expires_at']) && $lic['expires_at'] !== '' ? strtotime((string)$lic['expires_at']) : false;
    $exp = $t === false ? null : gmdate('Y-m-d H:i:s', $t);
    $n = now();
    db()->prepare('INSERT INTO licenses (license_key,plan,customer_name,shop_name,max_devices,status,expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE plan=VALUES(plan),customer_name=VALUES(customer_name),shop_name=VALUES(shop_name),max_devices=VALUES(max_devices),status=VALUES(status),expires_at=VALUES(expires_at),updated_at=VALUES(updated_at)')
        ->execute([$key, $plan, substr((string)($lic['customer_name'] ?? ''), 0, 120), substr((string)($lic['shop_name'] ?? ''), 0, 120),
                   max(1, (int)($lic['max_devices'] ?? 1)), $status, $exp, $n, $n]);
    $st = db()->prepare('SELECT id FROM licenses WHERE license_key=?'); $st->execute([$key]);
    return (int)$st->fetchColumn();
}

/** Revérifie chaque licence locale auprès du panel. Hors-ligne : aucun effet. Retourne un résumé texte. */
function acp_license_check(): string {
    $panel = acp_panel_url();
    if ($panel === '' || !acp_panel_valid($panel)) return 'panel non configuré';
    $log = [];
    foreach (db()->query('SELECT license_key FROM licenses')->fetchAll(PDO::FETCH_COLUMN) as $key) {
        $key = (string)$key;
        $r = acp_remote($panel, 'status', $key);
        if (!$r['ok'] || $r['http'] !== 200) { $log[] = "$key: ignoré (" . ($r['error'] ?: 'http ' . $r['http']) . ')'; continue; }
        $d = $r['data']; $s = (string)($d['status'] ?? '');
        if ($s === 'unknown_device') {            // appareil libéré côté panel : on retente l'activation
            $r = acp_remote($panel, 'activate', $key);
            if (!$r['ok'] || $r['http'] !== 200) { $log[] = "$key: ignoré"; continue; }
            $d = $r['data']; $s = (string)($d['status'] ?? '');
        }
        $hasLic = is_array($d['license'] ?? null);
        if ($s === 'active' && $hasLic) acp_apply_license($key, $d, 'active');
        elseif ($s === 'expired' && $hasLic && !empty($d['license']['expires_at'])) acp_apply_license($key, $d, 'active'); // expires_at passé → l'app bloque
        elseif ($s === 'revoked' && $hasLic) acp_apply_license($key, $d, 'revoked');
        elseif ($s === 'invalid') db()->prepare("UPDATE licenses SET status='revoked', updated_at=? WHERE license_key=?")->execute([now(), $key]);
        $log[] = "$key: $s";
    }
    return implode('; ', $log);
}
