<?php
// CLI : revérifie les licences auprès du panel (silencieux / sans effet hors-ligne).
$app = (string)getenv('ACP_APP_DIR');
require "$app/inc/db.php";
require "$app/inc/desktop_license.php";
try { echo acp_license_check(), "\n"; } catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
