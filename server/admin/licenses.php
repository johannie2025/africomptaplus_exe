<?php
require_once __DIR__ . '/../inc/auth.php'; require_once __DIR__ . '/../inc/layout.php'; require_once __DIR__ . '/../inc/actions.php';
require_login(); $pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['do'] ?? '');
    if ($act === 'create') {
        $plan = in_array($_POST['plan'] ?? '', ['basic', 'pro', 'lifetime'], true) ? $_POST['plan'] : 'basic';
        $qty = min(50, max(1, (int)($_POST['qty'] ?? 1)));
        $max = min(100, max(1, (int)($_POST['max_devices'] ?? 1)));
        $days = (int)($_POST['days'] ?? 0);
        $exp = ($plan === 'lifetime' || $days <= 0) ? null : gmdate('Y-m-d H:i:s', time() + $days * 86400);
        $t = now(); $keys = [];
        $ins = $pdo->prepare('INSERT INTO licenses (license_key,plan,customer_name,customer_phone,customer_email,shop_name,max_devices,status,expires_at,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        for ($i = 0; $i < $qty; $i++) {
            $k = unique_key($pdo);
            $ins->execute([$k, $plan, substr(trim((string)$_POST['customer_name']), 0, 120), substr(trim((string)$_POST['customer_phone']), 0, 40),
                substr(trim((string)$_POST['customer_email']), 0, 120), substr(trim((string)$_POST['shop_name']), 0, 120), $max, 'active', $exp,
                trim((string)$_POST['notes']), $t, $t]);
            $keys[] = $k;
        }
        flash(($qty > 1 ? "$qty licences créées : " : 'Licence créée : ') . implode(', ', $keys));
    } else {
        flash(license_action($pdo, $act, (int)($_POST['id'] ?? 0)));
    }
    redirect('licenses.php?' . http_build_query(array_filter(['q' => $_POST['rq'] ?? '', 'status' => $_POST['rstatus'] ?? ''])));
}

$q = trim((string)($_GET['q'] ?? '')); $status = (string)($_GET['status'] ?? '');
$w = []; $a = [];
if ($q !== '') { $w[] = '(license_key LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ? OR shop_name LIKE ?)'; $l = '%' . $q . '%'; array_push($a, $l, $l, $l, $l); }
if ($status === 'active') { $w[] = "status='active' AND (expires_at IS NULL OR expires_at>=?)"; $a[] = now(); }
elseif ($status === 'revoked') $w[] = "status='revoked'";
elseif ($status === 'expired') { $w[] = "status='active' AND expires_at IS NOT NULL AND expires_at<?"; $a[] = now(); }
$wh = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$st = $pdo->prepare("SELECT COUNT(*) FROM licenses $wh"); $st->execute($a);
$p = paginate((int)$st->fetchColumn(), (int)($_GET['p'] ?? 1), 25);
$st = $pdo->prepare("SELECT l.*, (SELECT COUNT(*) FROM activations a WHERE a.license_id=l.id) AS devs FROM licenses l $wh ORDER BY l.id DESC LIMIT {$p['per']} OFFSET {$p['offset']}");
$st->execute($a); $rows = $st->fetchAll();

page_head('Licences', 'licenses'); ?>
<h1>Licences</h1>
<details class="card" <?= $_GET['new'] ?? '' ? 'open' : '' ?>><summary class="btn cta">+ Nouvelle licence</summary>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="create">
<div class="row"><div><label>Client</label><input name="customer_name"></div><div><label>Téléphone (WhatsApp)</label><input name="customer_phone" placeholder="+240…"></div><div><label>Email</label><input type="email" name="customer_email"></div>
<div><label>Boutique</label><input name="shop_name"></div>
<div><label>Plan</label><select name="plan"><option value="basic">Basic</option><option value="pro">Pro</option><option value="lifetime">Lifetime (illimitée)</option></select></div>
<div><label>Appareils max</label><input type="number" name="max_devices" value="1" min="1" max="100"></div>
<div><label>Durée (jours, 0 = illimitée)</label><input type="number" name="days" value="365" min="0"></div>
<div><label>Quantité (1-50)</label><input type="number" name="qty" value="1" min="1" max="50"></div>
<div><label>Notes</label><input name="notes"></div></div>
<p><button class="btn cta">Générer</button></p></form></details>
<form class="card row" method="get"><input name="q" placeholder="Clé, client, téléphone, boutique" value="<?= e($q) ?>">
<select name="status"><option value="">Tous statuts</option><?php foreach (['active' => 'Actives', 'revoked' => 'Révoquées', 'expired' => 'Expirées'] as $k => $v) echo '<option value="' . $k . '"' . ($status === $k ? ' selected' : '') . '>' . $v . '</option>'; ?></select>
<button class="btn">Filtrer</button></form>
<div class="card tw"><table><tr><th>Clé</th><th>Client / Boutique</th><th>Plan</th><th>Statut</th><th>Expire</th><th>Appareils</th><th>Actions</th></tr>
<?php foreach ($rows as $r): $s = license_state($r); ?>
<tr><td><a href="license.php?id=<?= (int)$r['id'] ?>"><code><?= e($r['license_key']) ?></code></a></td>
<td><?= e($r['customer_name']) ?><br><small style="color:var(--mu)"><?= e($r['shop_name']) ?></small></td>
<td><?= e($r['plan']) ?></td><td><?= state_badge($s) ?></td><td><?= $r['expires_at'] ? e(substr($r['expires_at'], 0, 10)) : '∞' ?></td>
<td><?= (int)$r['devs'] ?>/<?= (int)$r['max_devices'] ?></td>
<td><div class="acts"><a class="btn sm ok" target="_blank" rel="noopener" href="<?= e(wa_link($r)) ?>">WhatsApp</a>
<?php
$btn = function (string $do, string $label, string $cls = 'ghost', string $confirm = '') use ($r, $q, $status) {
    echo '<form method="post" class="inline"' . ($confirm ? ' onsubmit="return confirm(\'' . e($confirm) . '\')"' : '') . '>' . csrf_field()
       . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
       . '<input type="hidden" name="rq" value="' . e($q) . '"><input type="hidden" name="rstatus" value="' . e($status) . '">'
       . '<button class="btn sm ' . $cls . '">' . $label . '</button></form>';
};
if ($r['status'] === 'revoked') $btn('restore', 'Réactiver', 'ok'); else $btn('revoke', 'Révoquer', 'ko', 'Révoquer cette licence ?');
if ($r['expires_at']) { $btn('ext30', '+30 j'); $btn('ext365', '+1 an'); }
$btn('reset', 'Reset appareils', 'ghost', 'Libérer tous les appareils ?');
$btn('delete', 'Supprimer', 'ko', 'Supprimer définitivement ?');
?></div></td></tr>
<?php endforeach; if (!$rows) echo '<tr><td colspan="7">Aucune licence.</td></tr>'; ?>
</table><?= pager_html($p, ['q' => $q, 'status' => $status]) ?></div>
<?php page_foot();
