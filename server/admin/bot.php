<?php
require_once __DIR__ . '/../inc/auth.php'; require_once __DIR__ . '/../inc/layout.php'; require_once __DIR__ . '/../inc/bot.php';
require_login(); $pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check(); $do = (string)($_POST['do'] ?? ''); $lid = (int)($_POST['lid'] ?? 0);
    try {
        if ($do === 'save_global') {
            $url = rtrim(trim((string)$_POST['evo_url']), '/');
            if ($url !== '' && !preg_match('#^https?://#i', $url)) throw new RuntimeException("URL Evolution invalide (http(s)://…).");
            bot_setting_set('evo_url', $url);
            if (trim((string)$_POST['evo_key']) !== '') bot_setting_set('evo_key_enc', ai_enc(trim((string)$_POST['evo_key'])));
            bot_setting_set('public_url', rtrim(trim((string)$_POST['public_url']), '/'));
            bot_setting_set('default_enabled', !empty($_POST['default_enabled']) ? '1' : '0');
            bot_setting_set('daily_limit', (string)max(10, (int)$_POST['daily_limit']));
            bot_setting_set('prompt_global', mb_substr(trim((string)$_POST['prompt_global']), 0, 2000));
            flash('Configuration enregistrée.');
        } elseif ($do === 'test_evo') {
            $r = evo('GET', '/instance/fetchInstances');
            flash($r['code'] < 300 ? 'Evolution API joignable (' . (is_array($r['data']) ? count($r['data']) : 0) . ' instance(s)).' : evo_err($r), $r['code'] < 300 ? 'ok' : 'err');
        } elseif ($do === 'toggle') {
            $pdo->prepare('UPDATE bot_accounts SET enabled=1-enabled WHERE license_id=?')->execute([$lid]); flash('État du bot modifié.');
        } elseif ($do === 'webhook') {
            $a = bot_account($lid, false); if (!$a) throw new RuntimeException('Compte introuvable.');
            evo_set_webhook($a); flash('Webhook réappliqué : ' . bot_webhook_url($a));
        } elseif ($do === 'logout') {
            $a = bot_account($lid, false); if (!$a) throw new RuntimeException('Compte introuvable.');
            evo_logout($a); $pdo->prepare("UPDATE bot_accounts SET wa_state='none', wa_phone='' WHERE license_id=?")->execute([$lid]); flash('Instance WhatsApp déconnectée.');
        } elseif ($do === 'create_all') {
            $n = 0; foreach ($pdo->query('SELECT id FROM licenses')->fetchAll(PDO::FETCH_COLUMN) as $id) { if (!bot_account((int)$id, false)) { bot_account((int)$id); $n++; } }
            flash("$n compte(s) créé(s).");
        } elseif ($do === 'purge_logs') {
            $pdo->prepare('DELETE FROM bot_logs WHERE created_at<?')->execute([gmdate('Y-m-d H:i:s', time() - 30 * 86400)]); flash('Journaux de plus de 30 jours supprimés.');
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('bot.php');
}

$accs = $pdo->query('SELECT b.*, l.license_key, l.customer_name, l.status AS lstatus,
    (SELECT COUNT(*) FROM bot_products p WHERE p.license_id=b.license_id) AS n_prod,
    (SELECT COUNT(*) FROM bot_orders o WHERE o.license_id=b.license_id) AS n_ord,
    (SELECT COALESCE(SUM(total),0) FROM bot_orders o WHERE o.license_id=b.license_id) AS sum_ord,
    (SELECT COUNT(*) FROM bot_orders o WHERE o.license_id=b.license_id AND o.sync_state=\'pending\') AS n_pend
    FROM bot_accounts b JOIN licenses l ON l.id=b.license_id ORDER BY b.license_id DESC')->fetchAll();
$fl = (int)($_GET['l'] ?? 0);
$q = $pdo->prepare('SELECT o.*, b.shop_name, b.currency FROM bot_orders o JOIN bot_accounts b ON b.license_id=o.license_id' . ($fl ? ' WHERE o.license_id=' . $fl : '') . ' ORDER BY o.id DESC LIMIT 30'); $q->execute(); $orders = $q->fetchAll();
$q = $pdo->prepare('SELECT * FROM bot_logs' . ($fl ? ' WHERE license_id=' . $fl : '') . ' ORDER BY id DESC LIMIT 60'); $q->execute(); $logs = $q->fetchAll();
$S = fn($k, $d = '') => bot_setting($k, $d);
$badge = ['open' => 'ok', 'connecting' => 'wa', 'close' => 'ko', 'none' => 'ko'];
page_head('Bot WhatsApp', 'bot'); ?>
<h1>Bot WhatsApp — Evolution API</h1>

<div class="card"><h2>Configuration globale</h2>
<p style="color:var(--mu);margin:0 0 8px">La clé Evolution reste sur le serveur (chiffrée) : l'APK reçoit seulement son instance, son webhook et le QR code à scanner. Le « cerveau » du bot utilise le routeur IA (page IA).</p>
<form method="post" class="row"><?= csrf_field() ?><input type="hidden" name="do" value="save_global">
<div><label>URL Evolution API</label><input name="evo_url" value="<?= e($S('evo_url')) ?>" placeholder="https://evo.mondomaine.com"></div>
<div><label>Clé API globale <?= $S('evo_key_enc') ? '(enregistrée — laisser vide pour conserver)' : '' ?></label><input name="evo_key" type="password" autocomplete="off"></div>
<div><label>URL publique de l'API (webhook)</label><input name="public_url" value="<?= e($S('public_url')) ?>" placeholder="https://wiseos.alwaysdata.net/africompta/api"></div>
<div><label>Limite de messages / jour / licence</label><input type="number" name="daily_limit" value="<?= e($S('daily_limit', '500')) ?>"></div>
<div style="flex-basis:100%"><label>Consignes globales ajoutées au prompt du bot (optionnel)</label><textarea name="prompt_global" rows="3"><?= e($S('prompt_global')) ?></textarea></div>
<div><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="default_enabled" style="width:auto" <?= $S('default_enabled', '0') === '1' ? 'checked' : '' ?>> Activer le bot par défaut pour les nouvelles licences</label></div>
<div style="align-self:end"><button class="btn">Enregistrer</button></div></form>
<div class="acts" style="margin-top:10px">
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="test_evo"><button class="btn cta">Tester Evolution</button></form>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="create_all"><button class="btn ghost">Créer les comptes bot manquants</button></form>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="purge_logs"><button class="btn ghost">Purger journaux &gt; 30 j</button></form></div></div>

<div class="card"><h2>Comptes par licence</h2><div class="tw"><table>
<tr><th>Licence</th><th>Bot</th><th>WhatsApp</th><th>Produits</th><th>Commandes</th><th>Dernière synchro</th><th>Actions</th></tr>
<?php foreach ($accs as $a): ?><tr>
<td><b><?= e($a['shop_name'] ?: $a['customer_name']) ?></b><br><small><code><?= e($a['license_key']) ?></code></small></td>
<td><span class="badge <?= $a['enabled'] ? 'ok' : 'ko' ?>"><?= $a['enabled'] ? 'Autorisé' : 'Désactivé' ?></span><?= $a['enabled'] && !$a['bot_auto'] ? '<br><small>pause (client)</small>' : '' ?></td>
<td><span class="badge <?= $badge[$a['wa_state']] ?? 'wa' ?>"><?= e($a['wa_state']) ?></span><?= $a['wa_phone'] ? '<br><small>+' . e($a['wa_phone']) . '</small>' : '' ?></td>
<td><?= (int)$a['n_prod'] ?></td>
<td><?= (int)$a['n_ord'] ?> <small>(<?= number_format((float)$a['sum_ord'], 0, ',', ' ') . ' ' . e($a['currency']) ?>)</small><?= $a['n_pend'] ? '<br><small>' . (int)$a['n_pend'] . ' à synchroniser</small>' : '' ?></td>
<td><small><?= e($a['last_sync_at'] ?: '—') ?></small></td>
<td class="acts">
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="lid" value="<?= (int)$a['license_id'] ?>"><input type="hidden" name="do" value="toggle"><button class="btn sm"><?= $a['enabled'] ? 'Désactiver' : 'Autoriser' ?></button></form>
<form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="lid" value="<?= (int)$a['license_id'] ?>"><input type="hidden" name="do" value="webhook"><button class="btn sm ghost">Webhook</button></form>
<form method="post" class="inline" onsubmit="return confirm('Déconnecter WhatsApp de ce client ?')"><?= csrf_field() ?><input type="hidden" name="lid" value="<?= (int)$a['license_id'] ?>"><input type="hidden" name="do" value="logout"><button class="btn sm ghost">Déconnecter</button></form>
<a class="btn sm ghost" href="bot.php?l=<?= (int)$a['license_id'] ?>">Voir</a></td></tr>
<?php endforeach; if (!$accs): ?><tr><td colspan="7" style="color:var(--mu)">Aucun compte. Ils sont créés à l'activation d'une licence ou via le bouton ci-dessus.</td></tr><?php endif; ?></table></div></div>

<div class="card"><h2>Dernières commandes du bot<?= $fl ? ' (licence #' . $fl . ') <a href="bot.php">tout voir</a>' : '' ?></h2><div class="tw"><table>
<tr><th>Réf.</th><th>Boutique</th><th>Client</th><th>Total</th><th>Paiement</th><th>Facture</th><th>Synchro</th><th>Date (UTC)</th></tr>
<?php foreach ($orders as $o): ?><tr><td><code><?= e($o['reference']) ?></code></td><td><?= e($o['shop_name']) ?></td><td><?= e($o['customer_name']) ?><br><small>+<?= e($o['phone']) ?></small></td>
<td><?= number_format((float)$o['total'], 0, ',', ' ') . ' ' . e($o['currency']) ?></td><td><?= e($o['payment']) ?></td><td><?= $o['invoice_sent'] ? '✓ envoyée' : '✗' ?></td><td><?= e($o['sync_state']) ?></td><td><small><?= e($o['created_at']) ?></small></td></tr>
<?php endforeach; if (!$orders) echo '<tr><td colspan="8" style="color:var(--mu)">Aucune commande.</td></tr>'; ?></table></div></div>

<div class="card"><h2>Journal des conversations</h2><div class="tw"><table>
<tr><th>Date (UTC)</th><th>Lic.</th><th>Client</th><th>Sens</th><th>Message</th><th>Modèle</th></tr>
<?php foreach ($logs as $g): ?><tr><td><small><?= e($g['created_at']) ?></small></td><td><?= (int)$g['license_id'] ?></td><td>+<?= e($g['phone']) ?></td>
<td><span class="badge <?= ['in' => 'wa', 'out' => 'ok', 'err' => 'ko'][$g['direction']] ?>"><?= e($g['direction']) ?></span></td><td><?= e($g['body']) ?></td><td><small><?= e($g['model']) ?></small></td></tr>
<?php endforeach; if (!$logs) echo '<tr><td colspan="6" style="color:var(--mu)">Aucun message.</td></tr>'; ?></table></div></div>
<?php page_foot();
