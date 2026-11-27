<?php
function cfg(): array { static $c; return $c ??= require __DIR__ . '/config.php'; }
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $c = cfg();
    $dsn = $c['db_dsn'] ?: "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['db_user'] ?? null, $c['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}
function now(): string { return gmdate('Y-m-d H:i:s'); }
