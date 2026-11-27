<?php
try {
    require_once __DIR__ . '/../app/inc/db.php';
    db()->query('SELECT COUNT(*) FROM licenses')->fetchColumn();
    echo 'ok';
} catch (Throwable $e) { http_response_code(500); echo 'db error: ' . $e->getMessage(); }
