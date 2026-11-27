<?php
require_once __DIR__ . '/../inc/auth.php'; require_once __DIR__ . '/../inc/layout.php';
require_login(); $pdo = db();
$f = ['key' => trim((string)($_GET['key'] ?? '')), 'result' => trim((string)($_GET['result'] ?? '')), 'ip' => trim((string)($_GET['ip'] ?? ''))];
$w = []; $a = [];
if ($f['key'] !== '') { $w[] = 'license_key LIKE ?'; $a[] = '%' . strtoupper($f['key']) . '%'; }
if ($f['result'] !== '') { $w[] = 'result=?'; $a[] = $f['result']; }
if ($f['ip'] !== '') { $w[] = 'ip=?'; $a[] = $f['ip']; }
$wh = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$st = $pdo->prepare("SELECT COUNT(*) FROM api_logs $wh"); $st->execute($a);
$p = paginate((int)$st->fetchColumn(), (int)($_GET['p'] ?? 1), 50);
$st = $pdo->prepare("SELECT * FROM api_logs $wh ORDER BY id DESC LIMIT {$p['per']} OFFSET {$p['offset']}"); $st->execute($a);
page_head('Journal', 'logs'); ?>
<h1>Journal API</h1>
<form class="card row" method="get"><input name="key" placeholder="Clé" value="<?= e($f['key']) ?>">
<select name="result"><option value="">Tous résultats</option><?php foreach (['active','ok','invalid','revoked','expired','device_limit','unknown_device'] as $r) echo '<option' . ($f['result'] === $r ? ' selected' : '') . '>' . $r . '</option>'; ?></select>
<input name="ip" placeholder="IP" value="<?= e($f['ip']) ?>"><button class="btn">Filtrer</button></form>
<div class="card tw"><table><tr><th>Date (UTC)</th><th>Action</th><th>Clé</th><th>Appareil</th><th>Résultat</th><th>Message</th><th>IP</th></tr>
<?php foreach ($st as $r) echo '<tr><td>' . e($r['created_at']) . '</td><td>' . e($r['action']) . '</td><td>' . e($r['license_key']) . '</td><td>' . e($r['device_id']) . '</td><td>' . e($r['result']) . '</td><td>' . e($r['message']) . '</td><td>' . e($r['ip']) . '</td></tr>'; ?>
</table><?= pager_html($p, $f) ?></div>
<?php page_foot();
