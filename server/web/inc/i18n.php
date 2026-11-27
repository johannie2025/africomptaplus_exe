<?php
/** Langues FR / EN / ES : préférence utilisateur > cookie > langue du navigateur (Accept-Language) > anglais. */
function lang_pick(): string {
    static $l = null; if ($l) return $l;
    $ok = ['fr', 'en', 'es'];
    $c = $_COOKIE['lang'] ?? '';
    if (in_array($c, $ok, true)) return $l = $c;
    foreach (explode(',', strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $p) {
        $code = substr(trim(explode(';', $p)[0]), 0, 2);
        if (in_array($code, $ok, true)) return $l = $code;
    }
    return $l = 'en';
}
function lang_set(string $l): void {
    if (!in_array($l, ['fr', 'en', 'es'], true)) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie('lang', $l, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => $https, 'samesite' => 'Lax']);
    $_COOKIE['lang'] = $l;
}
/** Traduit un texte source français. Les %s sont remplacés par les arguments. */
function t(string $fr, ...$args): string {
    static $d = null;
    if ($d === null) $d = require __DIR__ . '/../lang/dict.php';
    $l = lang_pick();
    $s = $l === 'fr' ? $fr : ($d[$l][$fr] ?? $fr);
    return $args ? vsprintf($s, $args) : $s;
}
