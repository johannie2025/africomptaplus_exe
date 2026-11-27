<?php
/**
 * Couche IA multi-fournisseurs : liste des modèles, appel, routage avec cooldown et fallback rotatif.
 * Utilisée par l'admin (test) et par le bot WhatsApp.
 */
require_once __DIR__ . '/db.php';

class AiError extends RuntimeException {
    public int $http; public ?int $retryAfter; public bool $quotaDay; public array $attempts = [];
    public function __construct(string $m, int $http = 0, ?int $retryAfter = null, bool $quotaDay = false) {
        parent::__construct($m); $this->http = $http; $this->retryAfter = $retryAfter; $this->quotaDay = $quotaDay;
    }
}

function ai_presets(): array {
    return [
        'openrouter' => ['OpenRouter', 'openai', 'https://openrouter.ai/api/v1', 1],
        'groq'       => ['Groq', 'openai', 'https://api.groq.com/openai/v1', 1],
        'gemini'     => ['Google Gemini', 'gemini', 'https://generativelanguage.googleapis.com/v1beta', 1],
        'cerebras'   => ['Cerebras', 'openai', 'https://api.cerebras.ai/v1', 1],
        'mistral'    => ['Mistral', 'openai', 'https://api.mistral.ai/v1', 0],
        'openai'     => ['OpenAI', 'openai', 'https://api.openai.com/v1', 0],
        'anthropic'  => ['Anthropic', 'anthropic', 'https://api.anthropic.com/v1', 0],
        'deepseek'   => ['DeepSeek', 'openai', 'https://api.deepseek.com', 0],
        'together'   => ['Together AI', 'openai', 'https://api.together.xyz/v1', 0],
        'custom'     => ['Personnalisé (compatible OpenAI)', 'openai', '', 0],
    ];
}

// ── Chiffrement des clés API ────────────────────────────────────────────────
function ai_secret(): string {
    $s = (string)(cfg()['app_secret'] ?? '');
    if ($s === '' || $s === 'CHANGE_ME') throw new RuntimeException("Définissez 'app_secret' dans inc/config.local.php avant d'enregistrer une clé API.");
    return hash('sha256', $s, true);
}
function ai_enc(string $plain): string {
    $iv = random_bytes(12);
    $c = openssl_encrypt($plain, 'aes-256-gcm', ai_secret(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $c);
}
function ai_dec(string $b64): string {
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', ai_secret(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

function ai_settings(): array {
    $d = ['prefer' => 'free_first', 'cooldown_base' => '60', 'cooldown_max' => '3600', 'max_attempts' => '5', 'timeout' => '30'];
    foreach (db()->query('SELECT skey, svalue FROM ai_settings') as $r) $d[$r['skey']] = $r['svalue'];
    return $d;
}

// ── HTTP ────────────────────────────────────────────────────────────────────
function ai_http(string $method, string $url, array $headers, ?array $json, int $timeout): array {
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($line);
        },
    ]);
    if ($json !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new AiError('Réseau : ' . $err, 0);
    $data = json_decode((string)$body, true);
    return ['code' => $code, 'data' => is_array($data) ? $data : [], 'raw' => (string)$body, 'headers' => $respHeaders];
}

function ai_http_error(array $r): AiError {
    $msg = $r['data']['error']['message'] ?? $r['data']['error'] ?? $r['data']['message'] ?? substr($r['raw'], 0, 200);
    if (is_array($msg)) $msg = json_encode($msg);
    $ra = isset($r['headers']['retry-after']) && ctype_digit($r['headers']['retry-after']) ? (int)$r['headers']['retry-after'] : null;
    $daily = (bool)preg_match('/per day|daily|RPD|exhausted your .*quota/i', (string)$msg) && $r['code'] === 429;
    return new AiError('HTTP ' . $r['code'] . ' : ' . substr((string)$msg, 0, 180), $r['code'], $ra, $daily);
}

function ai_provider_headers(array $p, string $key): array {
    if ($p['kind'] === 'anthropic') return ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
    if ($p['kind'] === 'gemini') return ['x-goog-api-key: ' . $key];
    $h = ['Authorization: Bearer ' . $key];
    if ($p['preset'] === 'openrouter') { $h[] = 'X-Title: AfriCompta+'; }
    return $h;
}

// ── Liste des modèles d'un fournisseur ──────────────────────────────────────
/** Retourne [[id, label, is_free], …] depuis l'API du fournisseur. */
function ai_list_models(array $p): array {
    $key = ai_dec($p['api_key_enc']);
    if ($key === '') throw new AiError('Clé API illisible (app_secret modifié ?)');
    $base = rtrim($p['base_url'], '/');
    $out = [];
    $skip = '/(embed|whisper|tts|dall-e|moderation|guard|rerank|image|vision-preview|audio|transcribe|davinci|babbage|realtime)/i';
    if ($p['kind'] === 'gemini') {
        $tok = '';
        for ($i = 0; $i < 6; $i++) {
            $r = ai_http('GET', $base . '/models?pageSize=200' . ($tok ? '&pageToken=' . urlencode($tok) : ''), ai_provider_headers($p, $key), null, 20);
            if ($r['code'] >= 300) throw ai_http_error($r);
            foreach ($r['data']['models'] ?? [] as $m) {
                if (!in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) continue;
                $id = preg_replace('#^models/#', '', $m['name']);
                if (preg_match($skip, $id)) continue;
                $out[] = [$id, $m['displayName'] ?? $id, (int)$p['free_tier']];
            }
            $tok = $r['data']['nextPageToken'] ?? '';
            if ($tok === '') break;
        }
    } elseif ($p['kind'] === 'anthropic') {
        $r = ai_http('GET', $base . '/models?limit=100', ai_provider_headers($p, $key), null, 20);
        if ($r['code'] >= 300) throw ai_http_error($r);
        foreach ($r['data']['data'] ?? [] as $m) $out[] = [$m['id'], $m['display_name'] ?? $m['id'], 0];
    } else {
        $r = ai_http('GET', $base . '/models', ai_provider_headers($p, $key), null, 25);
        if ($r['code'] >= 300) throw ai_http_error($r);
        foreach ($r['data']['data'] ?? [] as $m) {
            $id = (string)($m['id'] ?? ''); if ($id === '' || preg_match($skip, $id)) continue;
            $free = (int)$p['free_tier'];
            if ($p['preset'] === 'openrouter') {   // détection du gratuit via la tarification
                $pr = $m['pricing'] ?? [];
                $free = (str_ends_with($id, ':free') || ((float)($pr['prompt'] ?? 1) == 0.0 && (float)($pr['completion'] ?? 1) == 0.0)) ? 1 : 0;
            }
            $out[] = [$id, $m['name'] ?? $id, $free];
        }
    }
    usort($out, fn($a, $b) => strcmp($a[0], $b[0]));
    return $out;
}

/** Met à jour ai_models (conserve activation et priorité des modèles déjà connus). */
function ai_sync_models(int $providerId, array $models): int {
    $pdo = db(); $n = 0;
    $st = $pdo->prepare('INSERT INTO ai_models (provider_id, model_id, label, is_free) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE label=VALUES(label)');
    foreach ($models as $m) { $st->execute([$providerId, $m[0], mb_substr($m[1], 0, 190), $m[2]]); $n++; }
    $pdo->prepare('UPDATE ai_providers SET models_fetched_at=? WHERE id=?')->execute([now(), $providerId]);
    return $n;
}

// ── Appel d'un modèle ───────────────────────────────────────────────────────
/**
 * @param array $messages [['role'=>'system|user|assistant','content'=>'…'], …]
 * @return array ['content'=>string, 'tool_calls'=>array|null]
 */
function ai_call(array $p, string $model, array $messages, array $opts = []): array {
    $key = ai_dec($p['api_key_enc']);
    if ($key === '') throw new AiError('Clé API illisible', 401);
    $base = rtrim($p['base_url'], '/');
    $timeout = (int)($opts['timeout'] ?? 30);
    $temp = $opts['temperature'] ?? 0.3; $max = (int)($opts['max_tokens'] ?? 800);
    $h = ai_provider_headers($p, $key);

    if ($p['kind'] === 'gemini') {
        $sys = ''; $contents = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') { $sys .= $m['content'] . "\n"; continue; }
            $contents[] = ['role' => $m['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => (string)$m['content']]]];
        }
        $body = ['contents' => $contents, 'generationConfig' => ['temperature' => $temp, 'maxOutputTokens' => $max]];
        if ($sys !== '') $body['systemInstruction'] = ['parts' => [['text' => trim($sys)]]];
        $r = ai_http('POST', $base . '/models/' . rawurlencode($model) . ':generateContent', $h, $body, $timeout);
        if ($r['code'] >= 300) throw ai_http_error($r);
        $t = '';
        foreach ($r['data']['candidates'][0]['content']['parts'] ?? [] as $part) $t .= $part['text'] ?? '';
        if ($t === '') throw new AiError('Réponse vide', 502);
        return ['content' => $t, 'tool_calls' => null];
    }
    if ($p['kind'] === 'anthropic') {
        $sys = ''; $msgs = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') { $sys .= $m['content'] . "\n"; continue; }
            $msgs[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string)$m['content']];
        }
        $body = ['model' => $model, 'max_tokens' => $max, 'temperature' => $temp, 'messages' => $msgs];
        if ($sys !== '') $body['system'] = trim($sys);
        $r = ai_http('POST', $base . '/messages', $h, $body, $timeout);
        if ($r['code'] >= 300) throw ai_http_error($r);
        $t = '';
        foreach ($r['data']['content'] ?? [] as $c) if (($c['type'] ?? '') === 'text') $t .= $c['text'];
        if ($t === '') throw new AiError('Réponse vide', 502);
        return ['content' => $t, 'tool_calls' => null];
    }
    $body = ['model' => $model, 'messages' => $messages, 'temperature' => $temp, 'max_tokens' => $max];
    if (!empty($opts['tools'])) { $body['tools'] = $opts['tools']; $body['tool_choice'] = 'auto'; }
    $r = ai_http('POST', $base . '/chat/completions', $h, $body, $timeout);
    if ($r['code'] >= 300) throw ai_http_error($r);
    $msg = $r['data']['choices'][0]['message'] ?? null;
    if (!$msg) throw new AiError('Réponse vide', 502);
    if (($msg['content'] ?? '') === '' && empty($msg['tool_calls'])) throw new AiError('Réponse vide', 502);
    return ['content' => (string)($msg['content'] ?? ''), 'tool_calls' => $msg['tool_calls'] ?? null];
}

// ── Routeur : rotation + cooldown + fallback ────────────────────────────────
function ai_cooldown_seconds(AiError $e, array $m, array $s): int {
    $base = max(5, (int)$s['cooldown_base']); $max = max($base, (int)$s['cooldown_max']);
    if ($e->http === 401 || $e->http === 403) return 6 * 3600;           // clé / droits : on laisse souffler longtemps
    if ($e->http === 404) return 24 * 3600;                              // modèle retiré
    if ($e->quotaDay) return max(60, strtotime('tomorrow 00:00:00 UTC') - time());   // quota journalier épuisé
    $fails = (int)$m['fail_count'];
    $sec = $base * (2 ** min($fails, 10));
    if (!$m['is_free']) $sec = max(15, intdiv($sec, 4));                 // payant : pause plus courte
    if ($e->retryAfter !== null) $sec = max($sec, $e->retryAfter);
    return min($sec, $max);
}

/** Prochain modèle disponible (hors $exclude) : tri préférence → priorité → moins récemment utilisé (rotation). */
function ai_pick(array $exclude, string $prefer): ?array {
    $order = $prefer === 'free_first' ? 'm.is_free DESC, ' : ($prefer === 'paid_first' ? 'm.is_free ASC, ' : '');
    $in = $exclude ? ' AND m.id NOT IN (' . implode(',', array_map('intval', $exclude)) . ')' : '';
    $sql = "SELECT m.*, p.kind, p.base_url, p.api_key_enc, p.preset, p.free_tier, p.name AS provider_name
            FROM ai_models m JOIN ai_providers p ON p.id=m.provider_id
            WHERE m.enabled=1 AND p.enabled=1 AND (m.cooldown_until IS NULL OR m.cooldown_until <= ?) $in
            ORDER BY {$order}m.priority ASC, (m.last_used_at IS NULL) DESC, m.last_used_at ASC, m.id ASC LIMIT 1";
    $st = db()->prepare($sql); $st->execute([now()]);
    return $st->fetch() ?: null;
}

/**
 * Appelle l'IA en essayant jusqu'à max_attempts modèles. Un modèle en échec passe en cooldown
 * (progressif) ; les modèles de même priorité tournent à tour de rôle.
 * @return array ['content','tool_calls','model','provider','attempts'=>[[model,error],…]]
 */
function ai_complete(array $messages, array $opts = []): array {
    $s = ai_settings(); $pdo = db();
    $opts['timeout'] = $opts['timeout'] ?? (int)$s['timeout'];
    $tried = []; $log = [];
    for ($i = 0; $i < max(1, (int)$s['max_attempts']); $i++) {
        $m = ai_pick($tried, $s['prefer']);
        if (!$m) break;
        $tried[] = (int)$m['id'];
        $pdo->prepare('UPDATE ai_models SET last_used_at=? WHERE id=?')->execute([now(), $m['id']]);
        try {
            $r = ai_call($m, $m['model_id'], $messages, $opts);
            $pdo->prepare('UPDATE ai_models SET fail_count=0, cooldown_until=NULL, last_error=\'\', ok_count=ok_count+1 WHERE id=?')->execute([$m['id']]);
            return $r + ['model' => $m['model_id'], 'provider' => $m['provider_name'], 'attempts' => $log];
        } catch (AiError $e) {
            $cd = ai_cooldown_seconds($e, $m, $s);
            // Une erreur 400 (requête invalide) n'est pas la faute du modèle : pas de cooldown.
            if ($e->http === 400) $cd = 0;
            $pdo->prepare('UPDATE ai_models SET fail_count=fail_count+1, cooldown_until=?, last_error=? WHERE id=?')
                ->execute([$cd > 0 ? gmdate('Y-m-d H:i:s', time() + $cd) : null, mb_substr($e->getMessage(), 0, 250), $m['id']]);
            $log[] = [$m['provider_name'] . ' / ' . $m['model_id'], $e->getMessage() . ($cd ? " → pause {$cd}s" : '')];
        }
    }
    $st = $pdo->query('SELECT MIN(cooldown_until) FROM ai_models m JOIN ai_providers p ON p.id=m.provider_id WHERE m.enabled=1 AND p.enabled=1 AND m.cooldown_until > UTC_TIMESTAMP()');
    $next = $st ? $st->fetchColumn() : null;
    $e = new AiError('Aucun modèle disponible' . ($next ? " (prochain retour vers $next UTC)" : ' — activez au moins un modèle'));
    $e->attempts = $log;
    throw $e;
}
