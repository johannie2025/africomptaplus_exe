<?php
// Configuration — surchargée par inc/config.local.php si présent.
$cfg = [
    'db_host' => 'mysql-wiseos.alwaysdata.net',
    'db_name' => 'wiseos_africompta',
    'db_user' => 'wiseos',
    'db_pass' => 'CHANGE_ME',
    'db_dsn'  => null,               // DSN complet optionnel (tests)
    'session_timeout' => 3600,
    'wa_number' => '240222886007',
    'api_fail_limit' => 20,
    'app_secret' => 'CHANGE_ME',      // chaîne aléatoire longue (chiffre les clés API des fournisseurs IA)          // échecs / 10 min / IP
];
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) $cfg = array_replace($cfg, $local);
}
return $cfg;
