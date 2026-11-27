<?php
function mod_whatsapp(): void {
    $l = lid(); $pdo = db(); $a = (string)($_GET['a'] ?? ''); $acc = bot_acc();
    if (in_array($a, ['connect', 'status', 'logout'], true)) {      // AJAX
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) json_out(['ok' => false, 'error' => 'csrf'], 403);
        if (!$acc['enabled']) json_out(['ok' => false, 'error' => t("Le bot WhatsApp n'est pas activé pour cette licence. Contactez le support.")]);
        try {
            if ($a === 'connect') { $r = evo_connect($acc); $pdo->prepare('UPDATE bot_accounts SET wa_state=? WHERE license_id=?')->execute([$r['state'], $l]); json_out(['ok' => true, 'state' => $r['state'], 'qr' => $r['qr'] ?? null, 'code' => $r['code'] ?? null]); }
            if ($a === 'logout') { evo_logout($acc); $pdo->prepare("UPDATE bot_accounts SET wa_state='none', wa_phone='' WHERE license_id=?")->execute([$l]); json_out(['ok' => true, 'state' => 'none']); }
            $st = evo_state($acc); $phone = $acc['wa_phone'];
            if ($st === 'open' && $phone === '') { $q = evo('GET', '/instance/fetchInstances?instanceName=' . rawurlencode($acc['instance_name'])); $row = $q['data'][0] ?? $q['data'];
                $phone = preg_replace('/\D/', '', explode('@', (string)($row['ownerJid'] ?? $row['owner'] ?? ''))[0]); }
            $pdo->prepare('UPDATE bot_accounts SET wa_state=?, wa_phone=? WHERE license_id=?')->execute([$st, $phone, $l]);
            json_out(['ok' => true, 'state' => $st, 'phone' => $phone]);
        } catch (Throwable $e) { json_out(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)]); }
    }
    if ($a === 'save') {
        post_only();
        $pdo->prepare('UPDATE bot_accounts SET bot_auto=?, allow_credit=?, momo_info=?, prompt_extra=? WHERE license_id=?')
            ->execute([!empty($_POST['bot_auto']) ? 1 : 0, !empty($_POST['allow_credit']) ? 1 : 0, f_str('momo_info', 300), f_str('prompt_extra', 1500), $l]);
        flash(t('Paramètres enregistrés')); go('whatsapp');
    }
    $acc = bot_account($l, false); $open = $acc['wa_state'] === 'open';
    $s = $pdo->prepare("SELECT id,reference,customer_name,customer_phone,total,created_at FROM w_sales WHERE license_id=? AND source='bot' ORDER BY id DESC LIMIT 10"); $s->execute([$l]); $orders = $s->fetchAll();
    render_head('WhatsApp', 'whatsapp'); ?>
<h1><?= e(t('Bot WhatsApp')) ?></h1>
<?php if (!$acc['enabled']): ?><div class="card"><h2><?= e(t('Bot WhatsApp non activé')) ?></h2><p><?= e(t("Cette fonction n'est pas incluse dans votre licence. Contactez le support pour l'activer.")) ?></p></div><?php else: ?>
<div class="card" id="wa" data-url="<?= e(url('whatsapp')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-open="<?= $open ? 1 : 0 ?>">
<h2 id="wastate"><?= $open ? '● ' . e(t('Connecté')) . ($acc['wa_phone'] ? ' +' . e($acc['wa_phone']) : '') : '○ ' . e(t('Numéro non lié')) ?></h2>
<p class="mu" id="wahelp"><?= e($open ? t('Le bot répond à vos clients, ajoute au panier, encaisse et envoie la facture PDF.') : t('Scannez le QR code : WhatsApp → Appareils connectés → Connecter un appareil.')) ?></p>
<img id="qr" alt="QR" hidden width="260" height="260"><p id="pair" class="mu" hidden></p><p id="waerr" class="neg"></p>
<button class="btn cta" id="bconnect" <?= $open ? 'hidden' : '' ?>><?= e(t('Lier mon WhatsApp (QR code)')) ?></button>
<button class="btn danger" id="blogout" <?= $open ? '' : 'hidden' ?>><?= e(t('Déconnecter ce numéro')) ?></button></div>
<form method="post" action="<?= e(url('whatsapp', ['a' => 'save'])) ?>" class="card"><?= csrf_field() ?>
<label class="chk"><input type="checkbox" name="bot_auto" value="1" <?= $acc['bot_auto'] ? 'checked' : '' ?>> <?= e(t('Bot actif (répond aux clients)')) ?></label>
<label class="chk"><input type="checkbox" name="allow_credit" value="1" <?= $acc['allow_credit'] ? 'checked' : '' ?>> <?= e(t('Autoriser la vente à crédit par le bot')) ?></label>
<label><?= e(t('Infos Mobile Money (donnée au client)')) ?></label><input name="momo_info" value="<?= e($acc['momo_info']) ?>" placeholder="<?= e(t('ex. MoMo/Orange 6xx xx xx xx — Nom')) ?>">
<label><?= e(t('Consignes pour le bot (facultatif)')) ?></label><textarea name="prompt_extra" rows="3" placeholder="<?= e(t('ex. Livraison gratuite à Bata, horaires 8h-19h')) ?>"><?= e((string)$acc['prompt_extra']) ?></textarea>
<button class="btn cta"><?= e(t('Enregistrer')) ?></button></form>
<div class="card tw"><h2>🤖 <?= e(t('Historique des ventes')) ?></h2><table>
<?php foreach ($orders as $o) echo '<tr class="click" onclick="location=\'' . e(url('sales', ['a' => 'view', 'id' => $o['id']])) . '\'"><td>' . e($o['reference']) . '<br><small>' . e($o['created_at']) . '</small></td><td>' . e($o['customer_name']) . '<br><small>' . e($o['customer_phone']) . '</small></td><td class="r">' . e(money_fmt((float)$o['total'])) . '</td></tr>'; if (!$orders) echo '<tr><td class="mu">' . e(t('Aucune vente pour le moment.')) . '</td></tr>'; ?></table></div>
<?php endif; render_foot(); }
