<?php
require_once __DIR__ . '/../inc/auth.php'; require_once __DIR__ . '/../inc/layout.php';
require_login(); $pdo = db(); $n = now();
$q = function (string $sql, array $a = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($a); return (int)$s->fetchColumn(); };
$total = $q('SELECT COUNT(*) FROM licenses');
$revoked = $q("SELECT COUNT(*) FROM licenses WHERE status='revoked'");
$expired = $q("SELECT COUNT(*) FROM licenses WHERE status='active' AND expires_at IS NOT NULL AND expires_at<?", [$n]);
$active = $total - $revoked - $expired;
$devices = $q('SELECT COUNT(*) FROM activations');
$act7 = $q('SELECT COUNT(*) FROM activations WHERE first_activated_at>=?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
$logs = $pdo->query('SELECT * FROM api_logs ORDER BY id DESC LIMIT 10')->fetchAll();
page_head('Tableau de bord', 'index'); ?>
<h1>Tableau de bord</h1>
<div class="grid">
<?php foreach ([['Licences', $total], ['Actives', $active], ['Révoquées', $revoked], ['Expirées', $expired], ['Appareils liés', $devices], ['Activations (7 j)', $act7]] as [$l, $v]) echo '<div class="kpi"><small>' . e($l) . '</small><div>' . (int)$v . '</div></div>'; ?>
</div><p><a class="btn cta" href="licenses.php">+ Nouvelle licence</a></p>
<div class="card"><h2>Derniers appels API</h2><div class="tw"><table><tr><th>Date (UTC)</th><th>Action</th><th>Clé</th><th>Résultat</th><th>IP</th></tr>
<?php foreach ($logs as $r) echo '<tr><td>' . e($r['created_at']) . '</td><td>' . e($r['action']) . '</td><td>' . e($r['license_key']) . '</td><td>' . e($r['result']) . '</td><td>' . e($r['ip']) . '</td></tr>'; ?>
</table></div><p><a href="logs.php">Tout le journal →</a></p></div>
<?php page_foot();
