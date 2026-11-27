<?php
require_once __DIR__ . '/../inc/auth.php'; require_once __DIR__ . '/../inc/layout.php'; require_once __DIR__ . '/../inc/actions.php';
require_login(); $pdo = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $do = (string)($_POST['do'] ?? '');
    if ($do === 'save') {
        $plan = in_array($_POST['plan'] ?? '', ['basic', 'pro', 'lifetime'], true) ? $_POST['plan'] : 'basic';
        $exp = trim((string)($_POST['expires_at'] ?? ''));
        $exp = ($plan === 'lifetime' || $exp === '' || !strtotime($exp)) ? null : gmdate('Y-m-d 23:59:59', strtotime($exp));
        $pdo->prepare('UPDATE licenses SET plan=?,customer_name=?,customer_phone=?,customer_email=?,shop_name=?,max_devices=?,expires_at=?,notes=?,updated_at=? WHERE id=?')
            ->execute([$plan, substr(trim((string)$_POST['customer_name']), 0, 120), substr(trim((string)$_POST['customer_phone']), 0, 40),
                substr(trim((string)$_POST['customer_email']), 0, 120), substr(trim((string)$_POST['shop_name']), 0, 120),
                min(100, max(1, (int)$_POST['max_devices'])), $exp, trim((string)$_POST['notes']), now(), $id]);
        flash('Licence enregistrée.');
    } elseif ($do === 'free') {
        $pdo->prepare('DELETE FROM activations WHERE id=? AND license_id=?')->execute([(int)$_POST['aid'], $id]);
        flash('Appareil libéré.');
    } else {
        $m = license_action($pdo, $do, $id); flash($m);
        if ($do === 'delete') redirect('licenses.php');
    }
    redirect('license.php?id=' . $id);
}
$st = $pdo->prepare('SELECT * FROM licenses WHERE id=?'); $st->execute([$id]); $l = $st->fetch();
if (!$l) { flash('Licence introuvable.', 'err'); redirect('licenses.php'); }
$devs = $pdo->prepare('SELECT * FROM activations WHERE license_id=? ORDER BY last_seen_at DESC'); $devs->execute([$id]);
$logs = $pdo->prepare('SELECT * FROM api_logs WHERE license_key=? ORDER BY id DESC LIMIT 30'); $logs->execute([$l['license_key']]);
page_head('Licence ' . $l['license_key'], 'licenses'); ?>
<h1><code><?= e($l['license_key']) ?></code> <?= state_badge(license_state($l)) ?></h1>
<p class="acts"><a class="btn sm ok" target="_blank" rel="noopener" href="<?= e(wa_link($l)) ?>">Envoyer par WhatsApp</a><a class="btn sm ghost" href="licenses.php">← Retour</a></p>
<form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= $id ?>">
<h2>Informations</h2><div class="row">
<div><label>Client</label><input name="customer_name" value="<?= e($l['customer_name']) ?>"></div>
<div><label>Téléphone</label><input name="customer_phone" value="<?= e($l['customer_phone']) ?>"></div>
<div><label>Email</label><input name="customer_email" value="<?= e($l['customer_email']) ?>"></div>
<div><label>Boutique</label><input name="shop_name" value="<?= e($l['shop_name']) ?>"></div>
<div><label>Plan</label><select name="plan"><?php foreach (['basic', 'pro', 'lifetime'] as $p) echo '<option' . ($l['plan'] === $p ? ' selected' : '') . '>' . $p . '</option>'; ?></select></div>
<div><label>Appareils max</label><input type="number" name="max_devices" min="1" max="100" value="<?= (int)$l['max_devices'] ?>"></div>
<div><label>Expiration (vide = illimitée)</label><input type="date" name="expires_at" value="<?= e($l['expires_at'] ? substr($l['expires_at'], 0, 10) : '') ?>"></div>
<div style="grid-column:1/-1"><label>Notes</label><textarea name="notes" rows="2"><?= e($l['notes']) ?></textarea></div></div>
<p><button class="btn cta">Enregistrer</button></p></form>
<div class="card"><h2>Appareils liés</h2><div class="tw"><table><tr><th>Appareil</th><th>Nom</th><th>Version</th><th>1re activation</th><th>Dernier contact</th><th>IP</th><th></th></tr>
<?php foreach ($devs as $d): ?><tr><td><?= e($d['device_id']) ?></td><td><?= e($d['device_name']) ?></td><td><?= e($d['app_version']) ?></td><td><?= e($d['first_activated_at']) ?></td><td><?= e($d['last_seen_at']) ?></td><td><?= e($d['ip']) ?></td>
<td><form method="post" onsubmit="return confirm('Libérer cet appareil ?')"><?= csrf_field() ?><input type="hidden" name="do" value="free"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aid" value="<?= (int)$d['id'] ?>"><button class="btn sm ko">Libérer</button></form></td></tr><?php endforeach; ?>
</table></div></div>
<div class="card"><h2>Derniers appels</h2><div class="tw"><table><tr><th>Date (UTC)</th><th>Action</th><th>Appareil</th><th>Résultat</th><th>IP</th></tr>
<?php foreach ($logs as $r) echo '<tr><td>' . e($r['created_at']) . '</td><td>' . e($r['action']) . '</td><td>' . e($r['device_id']) . '</td><td>' . e($r['result']) . '</td><td>' . e($r['ip']) . '</td></tr>'; ?>
</table></div></div>
<?php page_foot();
