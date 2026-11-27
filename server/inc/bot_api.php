<?php
/** Actions appareil (APK) liées au bot : QR WhatsApp, synchro stock, récupération des ventes du bot. */
require_once __DIR__ . '/bot.php';

const BOT_ACTIONS = ['wa_connect', 'wa_status', 'wa_logout', 'bot_sync', 'bot_orders', 'bot_ack'];

function bot_fail(string $msg, int $http = 200): never {
    out($http, ['valid' => true, 'status' => 'active', 'ok' => false, 'message' => $msg, 'license' => null]);
}
function bot_ok(array $extra = []): never {
    out(200, ['valid' => true, 'status' => 'active', 'ok' => true, 'message' => 'OK', 'license' => null] + $extra);
}

function bot_dispatch(string $action, array $lic, array $body): never {
    $acc = bot_account((int)$lic['id']);
    if (!$acc['enabled']) bot_fail("Le bot WhatsApp n'est pas activé pour cette licence. Contactez le support.");
    $pdo = db();
    try {
        switch ($action) {
            case 'wa_connect':
                $r = evo_connect($acc);
                $pdo->prepare('UPDATE bot_accounts SET wa_state=? WHERE license_id=?')->execute([$r['state'], $acc['license_id']]);
                bot_ok(['wa_state' => $r['state'], 'qr' => $r['qr'] ?? null, 'pairing_code' => $r['code'] ?? null]);
            case 'wa_status':
                $st = evo_state($acc);
                $phone = $acc['wa_phone'];
                if ($st === 'open' && $phone === '') {
                    $q = evo('GET', '/instance/fetchInstances?instanceName=' . rawurlencode($acc['instance_name']));
                    $row = isset($q['data'][0]) ? $q['data'][0] : $q['data'];
                    $owner = (string)($row['ownerJid'] ?? $row['owner'] ?? $row['instance']['owner'] ?? '');
                    $phone = preg_replace('/\D/', '', explode('@', $owner)[0]);
                }
                $pdo->prepare('UPDATE bot_accounts SET wa_state=?, wa_phone=? WHERE license_id=?')->execute([$st, $phone, $acc['license_id']]);
                bot_ok(['bot' => bot_public(bot_account((int)$lic['id'], false))]);
            case 'wa_logout':
                evo_logout($acc);
                $pdo->prepare("UPDATE bot_accounts SET wa_state='none', wa_phone='' WHERE license_id=?")->execute([$acc['license_id']]);
                bot_ok();
            case 'bot_sync':
                $c = $body['config'] ?? [];
                if (is_array($c)) {
                    $pdo->prepare('UPDATE bot_accounts SET shop_name=?, currency=?, tax_rate=?, tax_label=?, momo_info=?, prompt_extra=?, allow_credit=?, bot_auto=?, last_sync_at=? WHERE license_id=?')
                        ->execute([mb_substr((string)($c['shop_name'] ?? $acc['shop_name']), 0, 120), substr((string)($c['currency'] ?? 'XAF'), 0, 8),
                            max(0, min(100, (float)($c['tax_rate'] ?? 0))), mb_substr((string)($c['tax_label'] ?? 'TVA'), 0, 20),
                            mb_substr((string)($c['momo_info'] ?? ''), 0, 300), mb_substr((string)($c['prompt_extra'] ?? ''), 0, 1500),
                            !empty($c['allow_credit']) ? 1 : 0, !isset($c['bot_auto']) || $c['bot_auto'] ? 1 : 0, now(), $acc['license_id']]);
                }
                $prods = ($acc['source'] ?? 'app') === 'web' ? null : ($body['products'] ?? null); $n = 0;   // source web : le stock du panel fait foi
                if (is_array($prods)) {
                    $t = now(); $pdo->beginTransaction();
                    $up = $pdo->prepare('INSERT INTO bot_products (license_id,local_id,name,barcode,category,price,stock,updated_at) VALUES (?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE name=VALUES(name),barcode=VALUES(barcode),category=VALUES(category),price=VALUES(price),stock=VALUES(stock),updated_at=VALUES(updated_at)');
                    foreach (array_slice($prods, 0, 5000) as $p) {
                        if (!is_array($p) || !isset($p['id'])) continue;
                        $up->execute([$acc['license_id'], (int)$p['id'], mb_substr((string)($p['name'] ?? ''), 0, 160), mb_substr((string)($p['barcode'] ?? ''), 0, 64),
                            mb_substr((string)($p['category'] ?? ''), 0, 80), (float)($p['price'] ?? 0), (int)($p['stock'] ?? 0), $t]);
                        $n++;
                    }
                    if (!empty($body['full'])) $pdo->prepare('DELETE FROM bot_products WHERE license_id=? AND updated_at<?')->execute([$acc['license_id'], $t]);
                    $pdo->commit();
                }
                bot_ok(['synced' => $n, 'bot' => bot_public(bot_account((int)$lic['id'], false))]);
            case 'bot_orders':
                $st = $pdo->prepare("SELECT * FROM bot_orders WHERE license_id=? AND sync_state='pending' ORDER BY id LIMIT 100"); $st->execute([$acc['license_id']]);
                $out = [];
                foreach ($st as $o) $out[] = ['reference' => $o['reference'], 'phone' => $o['phone'], 'customer_name' => $o['customer_name'], 'payment' => $o['payment'],
                    'subtotal' => (float)$o['subtotal'], 'tax_amount' => (float)$o['tax_amount'], 'total' => (float)$o['total'],
                    'created_at' => $o['created_at'], 'items' => json_decode($o['items'], true) ?: []];
                bot_ok(['orders' => $out]);
            case 'bot_ack':
                $refs = array_slice(array_filter((array)($body['refs'] ?? []), 'is_string'), 0, 200);
                $up = $pdo->prepare("UPDATE bot_orders SET sync_state='synced' WHERE license_id=? AND reference=?");
                foreach ($refs as $r) $up->execute([$acc['license_id'], $r]);
                bot_ok();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[bot_api ' . $action . '] ' . $e->getMessage());
        bot_fail(mb_substr($e->getMessage(), 0, 200));
    }
    bot_fail('Action inconnue');
}
