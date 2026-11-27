<?php
/** Cerveau du bot de vente WhatsApp : webhook Evolution → IA (protocole JSON) → panier → encaissement → facture. */
require_once __DIR__ . '/evo.php';
require_once __DIR__ . '/minipdf.php';

function bot_log(?int $lic, string $phone, string $dir, string $body, string $model = ''): void {
    try {
        db()->prepare('INSERT INTO bot_logs (license_id,phone,direction,body,model,created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$lic, substr($phone, 0, 30), $dir, mb_substr($body, 0, 600), substr($model, 0, 120), now()]);
    } catch (Throwable $e) { error_log('bot_log: ' . $e->getMessage()); }
}

/** Compte bot d'une licence (créé à la volée : instance + jeton webhook). */
function bot_account(int $licenseId, bool $create = true): ?array {
    $st = db()->prepare('SELECT * FROM bot_accounts WHERE license_id=?'); $st->execute([$licenseId]);
    $a = $st->fetch();
    if ($a || !$create) return $a ?: null;
    $l = db()->prepare('SELECT shop_name FROM licenses WHERE id=?'); $l->execute([$licenseId]); $shop = (string)$l->fetchColumn();
    db()->prepare('INSERT INTO bot_accounts (license_id,enabled,instance_name,token,shop_name,created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$licenseId, (int)bot_setting('default_enabled', '0'), 'acp' . $licenseId . '-' . bin2hex(random_bytes(3)), bin2hex(random_bytes(20)), $shop, now()]);
    return bot_account($licenseId, false);
}
function bot_public(array $a): array {   // config renvoyée à l'APK à l'activation
    return ['enabled' => (bool)$a['enabled'], 'instance' => $a['instance_name'], 'webhook_url' => bot_webhook_url($a),
            'wa_state' => $a['wa_state'], 'wa_phone' => $a['wa_phone'], 'bot_auto' => (bool)$a['bot_auto'],
            'allow_credit' => (bool)$a['allow_credit'], 'momo_info' => $a['momo_info'], 'prompt_extra' => (string)$a['prompt_extra']];
}

// ── Webhook ─────────────────────────────────────────────────────────────────
function bot_webhook(): void {
    $raw = (string)file_get_contents('php://input');
    // Répondre tout de suite à Evolution, traiter ensuite.
    ignore_user_abort(true); @set_time_limit(120);
    header('Content-Type: text/plain'); header('Connection: close'); header('Content-Length: 2');
    http_response_code(200); echo 'ok';
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    $p = json_decode($raw, true);
    $t = (string)($_GET['t'] ?? '');
    if (!is_array($p) || !preg_match('/^[a-f0-9]{40}$/', $t)) return;
    $st = db()->prepare('SELECT * FROM bot_accounts WHERE token=?'); $st->execute([$t]);
    $acc = $st->fetch();
    if (!$acc) return;
    $event = strtolower(str_replace('_', '.', (string)($p['event'] ?? '')));
    $d = $p['data'] ?? [];
    if ($event === 'connection.update') {
        $state = (string)($d['state'] ?? $d['status'] ?? '');
        if ($state !== '') {
            $phone = preg_replace('/\D/', '', explode('@', (string)($d['wuid'] ?? ''))[0]);
            db()->prepare('UPDATE bot_accounts SET wa_state=?, wa_phone=IF(?<>\'\',?,wa_phone) WHERE license_id=?')->execute([substr($state, 0, 20), $phone, $phone, $acc['license_id']]);
        }
        return;
    }
    if ($event !== 'messages.upsert') return;
    $msgs = isset($d['messages']) ? $d['messages'] : (isset($d['key']) ? [$d] : (is_array($d) ? $d : []));
    foreach ($msgs as $m) if (is_array($m) && isset($m['key'])) {
        try { bot_on_message($acc, $m); } catch (Throwable $e) { bot_log((int)$acc['license_id'], '', 'err', $e->getMessage()); }
    }
}

function bot_text(array $m): string {
    $x = $m['message'] ?? [];
    return trim((string)($x['conversation'] ?? $x['extendedTextMessage']['text'] ?? $x['imageMessage']['caption']
        ?? $x['buttonsResponseMessage']['selectedDisplayText'] ?? $x['listResponseMessage']['title'] ?? ''));
}

function bot_on_message(array $acc, array $m): void {
    $lic = (int)$acc['license_id'];
    $jid = (string)($m['key']['remoteJid'] ?? '');
    if (!empty($m['key']['fromMe']) || $jid === '' || str_ends_with($jid, '@g.us') || str_contains($jid, 'broadcast')) return;
    if (!$acc['enabled'] || !$acc['bot_auto']) return;
    $lc = db()->prepare('SELECT status, expires_at FROM licenses WHERE id=?'); $lc->execute([$lic]); $l = $lc->fetch();
    if (!$l || $l['status'] !== 'active' || ($l['expires_at'] !== null && $l['expires_at'] < now())) return;
    $phone = preg_replace('/\D/', '', explode('@', $jid)[0]);
    if (strlen($phone) < 6) return;
    $msgId = (string)($m['key']['id'] ?? '');
    $text = bot_text($m);

    // limitation : par client / minute et par licence / jour
    $n = db()->prepare('SELECT COUNT(*) FROM bot_logs WHERE license_id=? AND phone=? AND direction=\'in\' AND created_at>=?');
    $n->execute([$lic, $phone, gmdate('Y-m-d H:i:s', time() - 60)]);
    if ((int)$n->fetchColumn() >= 12) return;
    $n = db()->prepare('SELECT COUNT(*) FROM bot_logs WHERE license_id=? AND direction=\'in\' AND created_at>=?');
    $n->execute([$lic, gmdate('Y-m-d 00:00:00')]);
    if ((int)$n->fetchColumn() >= (int)bot_setting('daily_limit', '500')) return;

    $lockName = 'bot:' . $lic . ':' . $phone;
    $g = db()->prepare('SELECT GET_LOCK(?, 20)'); $g->execute([$lockName]);
    if ((int)$g->fetchColumn() !== 1) return;
    try {
        $s = bot_session($lic, $phone, (string)($m['pushName'] ?? ''));
        if ($msgId !== '' && in_array($msgId, $s['ids'], true)) return;   // webhook rejoué
        if ($msgId !== '') { $s['ids'][] = $msgId; $s['ids'] = array_slice($s['ids'], -30); }
        if ($text === '') {
            bot_log($lic, $phone, 'in', '[non-texte]');
            evo_send_text($acc, $jid, "Je ne comprends que les messages texte pour le moment 🙏 Écrivez-moi le produit que vous cherchez.");
            bot_session_save($lic, $phone, $s); return;
        }
        bot_log($lic, $phone, 'in', $text);
        $s['turn']++;
        $reply = bot_think($acc, $s, $jid, $text, $model);
        if ($reply !== '') { evo_send_text($acc, $jid, $reply); bot_log($lic, $phone, 'out', $reply, $model ?? ''); }
        $s['hist'][] = ['user', mb_substr($text, 0, 500)];
        if ($reply !== '') $s['hist'][] = ['assistant', mb_substr($reply, 0, 700)];
        $s['hist'] = array_slice($s['hist'], -12);
        bot_session_save($lic, $phone, $s);
    } finally {
        $r = db()->prepare('SELECT RELEASE_LOCK(?)'); $r->execute([$lockName]);
    }
}

// ── Session (panier + historique) ───────────────────────────────────────────
function bot_session(int $lic, string $phone, string $push): array {
    $st = db()->prepare('SELECT * FROM bot_sessions WHERE license_id=? AND phone=?'); $st->execute([$lic, $phone]);
    $r = $st->fetch();
    $h = $r ? (json_decode((string)$r['history'], true) ?: []) : [];
    $stale = $r && strtotime($r['updated_at'] . ' UTC') < time() - 6 * 3600;   // conversation oubliée après 6 h d'inactivité
    return [
        'name' => $r['customer_name'] ?? '' ?: mb_substr($push, 0, 60),
        'cart' => ($r && !$stale) ? (json_decode((string)$r['cart'], true) ?: []) : [],
        'pay' => ($r && !$stale) ? (string)$r['payment'] : '',
        'hist' => $stale ? [] : ($h['hist'] ?? []),
        'ids' => $h['ids'] ?? [],
        'turn' => (int)($h['turn'] ?? 0),
        'confirm_turn' => $stale ? null : ($h['confirm_turn'] ?? null),
    ];
}
function bot_session_save(int $lic, string $phone, array $s): void {
    $h = json_encode(['hist' => $s['hist'], 'ids' => $s['ids'], 'turn' => $s['turn'], 'confirm_turn' => $s['confirm_turn']], JSON_UNESCAPED_UNICODE);
    db()->prepare('INSERT INTO bot_sessions (license_id,phone,customer_name,cart,payment,history,msg_count,updated_at) VALUES (?,?,?,?,?,?,1,?)
        ON DUPLICATE KEY UPDATE customer_name=VALUES(customer_name),cart=VALUES(cart),payment=VALUES(payment),history=VALUES(history),msg_count=msg_count+1,updated_at=VALUES(updated_at)')
        ->execute([$lic, $phone, $s['name'], json_encode($s['cart'], JSON_UNESCAPED_UNICODE), $s['pay'], $h, now()]);
}

// ── Prompt ──────────────────────────────────────────────────────────────────
function bot_system(array $acc, array $s): string {
    $cur = $acc['currency'];
    $pay = '"cash" (espèces)' . ', "mobile_money"' . ($acc['allow_credit'] ? ', "credit" (à crédit, nom du client obligatoire)' : '');
    $p = "Tu es l'assistant de vente WhatsApp de la boutique « " . ($acc['shop_name'] ?: 'la boutique') . " ». Devise : $cur.\n"
       . "Réponds dans la langue du client (français, anglais ou espagnol), en messages courts et chaleureux, sans mise en forme lourde.\n"
       . "Tu ne parles que de la boutique et de ses produits. Tu n'inventes JAMAIS un produit, un prix ou un stock : appelle d'abord \"search\".\n"
       . "Tu réponds UNIQUEMENT par UN objet JSON, sans texte autour. Actions possibles :\n"
       . '{"action":"search","query":"mots du produit"}' . "\n"
       . '{"action":"add","product_id":12,"qty":2}' . "\n"
       . '{"action":"remove","product_id":12}' . "\n"
       . '{"action":"cart"}  {"action":"clear"}' . "\n"
       . '{"action":"set_payment","method":"cash|mobile_money' . ($acc['allow_credit'] ? '|credit' : '') . '"}' . "\n"
       . '{"action":"set_name","name":"Nom du client"}' . "\n"
       . '{"action":"checkout"}  (encaisse la vente et envoie la facture)' . "\n"
       . '{"action":"reply","text":"message à envoyer au client"}' . "\n"
       . "Le serveur exécute l'action et te répond « RESULT: … ». Enchaîne les actions utiles puis termine TOUJOURS par \"reply\".\n"
       . "Modes de paiement acceptés : $pay.\n"
       . "Parcours de vente : trouver les produits → ajouter au panier → récapituler (articles, quantités, total) → demander le mode de paiement"
       . " (et le nom si besoin) → demander une confirmation explicite (« oui ») → seulement alors \"checkout\". Ne fais jamais checkout sans confirmation du client.\n"
       . "Si le produit est en rupture ou absent, propose des alternatives trouvées par \"search\".\n";
    if ($acc['momo_info'] !== '') $p .= "Infos Mobile Money de la boutique (à donner si le client choisit mobile_money) : " . $acc['momo_info'] . "\n";
    if (trim((string)$acc['prompt_extra']) !== '') $p .= "Consignes de la boutique : " . trim($acc['prompt_extra']) . "\n";
    $g = bot_setting('prompt_global'); if ($g !== '') $p .= $g . "\n";
    $p .= "\nÉTAT ACTUEL — " . bot_cart_text($acc, $s);
    return $p;
}
function bot_cart_totals(array $acc, array $s): array {
    $sub = 0; foreach ($s['cart'] as $c) $sub += $c['price'] * $c['qty'];
    $tax = round($sub * (float)$acc['tax_rate'] / 100.0);
    return [$sub, $tax, $sub + $tax];
}
function bot_cart_text(array $acc, array $s): string {
    $cur = $acc['currency'];
    if (!$s['cart']) return 'Panier vide.' . ($s['name'] ? " Nom du client : {$s['name']}." : '');
    $o = 'Panier : ';
    foreach ($s['cart'] as $c) $o .= "[id {$c['id']}] {$c['qty']} × {$c['name']} @ " . number_format($c['price'], 0, ',', ' ') . " = " . number_format($c['price'] * $c['qty'], 0, ',', ' ') . "; ";
    [$sub, $tax, $tot] = bot_cart_totals($acc, $s);
    $o .= "Sous-total " . number_format($sub, 0, ',', ' ') . ($tax > 0 ? ", {$acc['tax_label']} " . number_format($tax, 0, ',', ' ') : '') . ", TOTAL " . number_format($tot, 0, ',', ' ') . " $cur.";
    $o .= ' Paiement : ' . ($s['pay'] ?: 'non choisi') . '. Nom : ' . ($s['name'] ?: 'inconnu') . '.';
    return $o;
}

// ── Boucle IA ───────────────────────────────────────────────────────────────
function bot_extract_json(string $t): ?array {
    $a = strpos($t, '{'); $b = strrpos($t, '}');
    if ($a === false || $b === false || $b < $a) return null;
    $j = json_decode(substr($t, $a, $b - $a + 1), true);
    return is_array($j) ? $j : null;
}
function bot_merge(array $msgs): array {   // fusionne les rôles consécutifs identiques (Anthropic/Gemini l'exigent)
    $o = [];
    foreach ($msgs as $m) {
        if ($o && $o[count($o) - 1]['role'] === $m['role'] && $m['role'] !== 'system') $o[count($o) - 1]['content'] .= "\n" . $m['content'];
        else $o[] = $m;
    }
    return $o;
}

function bot_think(array $acc, array &$s, string $jid, string $text, &$modelUsed = null): string {
    $msgs = [['role' => 'system', 'content' => bot_system($acc, $s)]];
    foreach ($s['hist'] as [$r, $c]) $msgs[] = ['role' => $r, 'content' => $c];
    $msgs[] = ['role' => 'user', 'content' => $text];
    $lic = (int)$acc['license_id'];
    for ($step = 0; $step < 6; $step++) {
        try {
            $r = ai_complete(bot_merge($msgs), ['temperature' => 0.2, 'max_tokens' => 600]);
        } catch (AiError $e) {
            bot_log($lic, '', 'err', 'IA : ' . $e->getMessage());
            return "Désolé, je suis momentanément indisponible. Réessayez dans quelques minutes 🙏";
        }
        $modelUsed = $r['provider'] . '/' . $r['model'];
        $j = bot_extract_json($r['content']);
        if (!$j || empty($j['action'])) {   // le modèle a répondu en texte libre : on l'envoie tel quel
            $t = trim(preg_replace('/^```\w*|```$/m', '', $r['content']));
            return mb_substr($t, 0, 1500);
        }
        $act = (string)$j['action'];
        if ($act === 'reply') return mb_substr(trim((string)($j['text'] ?? '')), 0, 1500);
        $res = bot_exec($acc, $s, $jid, $act, $j);
        if (is_array($res)) return $res[0];   // message final imposé par le serveur (vente conclue)
        $msgs[] = ['role' => 'assistant', 'content' => json_encode($j, JSON_UNESCAPED_UNICODE)];
        $msgs[] = ['role' => 'user', 'content' => 'RESULT: ' . $res];
    }
    return "Pouvez-vous reformuler votre demande ? Je n'ai pas bien compris 🙏";
}

/** @return string résultat renvoyé à l'IA, ou [message final] */
function bot_exec(array $acc, array &$s, string $jid, string $act, array $j) {
    $lic = (int)$acc['license_id']; $cur = $acc['currency'];
    switch ($act) {
        case 'search':
            $words = array_values(array_filter(preg_split('/[\s,;]+/u', mb_strtolower((string)($j['query'] ?? ''))), fn($w) => mb_strlen($w) >= 2));
            $web = ($acc['source'] ?? 'app') === 'web';
            $run = function (array $ws) use ($lic, $web) {
                $sql = $web ? 'SELECT id AS local_id,name,sell_price AS price,stock FROM w_products WHERE license_id=?' : 'SELECT local_id,name,price,stock FROM bot_products WHERE license_id=?'; $a = [$lic];
                foreach ($ws as $w) { $sql .= ' AND (LOWER(name) LIKE ? OR LOWER(category) LIKE ? OR barcode=?)'; $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $w) . '%'; array_push($a, $like, $like, $w); }
                $st = db()->prepare($sql . ' ORDER BY (stock>0) DESC, name LIMIT 8'); $st->execute($a); return $st->fetchAll();
            };
            $rows = $words ? $run($words) : [];
            if (!$rows && count($words) > 1) { usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); $rows = $run([$words[0]]); }
            if (!$rows) return 'Aucun produit trouvé.';
            $o = '';
            foreach ($rows as $r) $o .= "id={$r['local_id']} | {$r['name']} | " . number_format((float)$r['price'], 0, ',', ' ') . " $cur | " . ((int)$r['stock'] > 0 ? 'stock ' . (int)$r['stock'] : 'RUPTURE') . "\n";
            return trim($o);
        case 'add':
            $id = (int)($j['product_id'] ?? 0); $q = max(1, min(999, (int)($j['qty'] ?? 1)));
            $web = ($acc['source'] ?? 'app') === 'web';
            $st = db()->prepare($web ? 'SELECT id AS local_id,name,sell_price AS price,stock FROM w_products WHERE license_id=? AND id=?' : 'SELECT * FROM bot_products WHERE license_id=? AND local_id=?'); $st->execute([$lic, $id]); $p = $st->fetch();
            if (!$p) return 'Produit introuvable (utilisez un id renvoyé par search).';
            $have = 0; foreach ($s['cart'] as $c) if ($c['id'] === $id) $have = $c['qty'];
            if ($have + $q > (int)$p['stock']) return "Stock insuffisant : " . (int)$p['stock'] . " disponible(s) pour {$p['name']}" . ($have ? " (déjà $have au panier)" : '') . '.';
            $found = false;
            foreach ($s['cart'] as &$c) if ($c['id'] === $id) { $c['qty'] += $q; $found = true; } unset($c);
            if (!$found) $s['cart'][] = ['id' => $id, 'name' => $p['name'], 'price' => (float)$p['price'], 'qty' => $q];
            $s['confirm_turn'] = null;
            return 'Ajouté. ' . bot_cart_text($acc, $s);
        case 'remove':
            $id = (int)($j['product_id'] ?? 0);
            $s['cart'] = array_values(array_filter($s['cart'], fn($c) => $c['id'] !== $id)); $s['confirm_turn'] = null;
            return 'Retiré. ' . bot_cart_text($acc, $s);
        case 'clear': $s['cart'] = []; $s['pay'] = ''; $s['confirm_turn'] = null; return 'Panier vidé.';
        case 'cart': return bot_cart_text($acc, $s);
        case 'set_payment':
            $m = (string)($j['method'] ?? '');
            if (!in_array($m, ['cash', 'mobile_money', 'credit'], true) || ($m === 'credit' && !$acc['allow_credit'])) return 'Mode de paiement non accepté.';
            $s['pay'] = $m; $s['confirm_turn'] = null; return 'Paiement : ' . $m;
        case 'set_name':
            $s['name'] = mb_substr(trim((string)($j['name'] ?? '')), 0, 60); return 'Nom enregistré : ' . $s['name'];
        case 'checkout':
            if (!$s['cart']) return 'Le panier est vide.';
            if ($s['pay'] === '') return 'Mode de paiement non choisi : demande-le au client.';
            if ($s['pay'] === 'credit' && trim($s['name']) === '') return 'Nom du client requis pour un achat à crédit.';
            if ($s['confirm_turn'] === null || $s['turn'] <= $s['confirm_turn']) {
                $s['confirm_turn'] = $s['turn'];
                return "Confirmation requise : envoie via reply le récapitulatif (articles, total, paiement) et demande au client de confirmer par « oui ». Ne rappelle checkout qu'après sa réponse.";
            }
            return [bot_checkout($acc, $s, $jid)];
    }
    return 'Action inconnue.';
}

// ── Encaissement + facture ──────────────────────────────────────────────────
function bot_checkout(array $acc, array &$s, string $jid): string {
    $pdo = db(); $lic = (int)$acc['license_id']; $phone = preg_replace('/\D/', '', explode('@', $jid)[0]);
    [$sub, $tax, $tot] = bot_cart_totals($acc, $s);
    $pdo->beginTransaction();
    try {
        $web = ($acc['source'] ?? 'app') === 'web';
        $dec = $pdo->prepare($web ? 'UPDATE w_products SET stock=stock-? WHERE license_id=? AND id=? AND stock>=?' : 'UPDATE bot_products SET stock=stock-? WHERE license_id=? AND local_id=? AND stock>=?');
        foreach ($s['cart'] as $c) {
            $dec->execute([$c['qty'], $lic, $c['id'], $c['qty']]);
            if ($dec->rowCount() < 1) { $pdo->rollBack(); $s['confirm_turn'] = null; return "Désolé, « {$c['name']} » n'est plus disponible en quantité suffisante. Je mets à jour votre panier : dites-moi si vous voulez ajuster la quantité 🙏"; }
        }
        $items = array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'qty' => $c['qty'], 'price' => $c['price']], $s['cart']);
        $pdo->prepare('INSERT INTO bot_orders (license_id,reference,phone,customer_name,items,subtotal,tax_amount,total,payment,sync_state,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$lic, 'TMP' . bin2hex(random_bytes(6)), $phone, $s['name'], json_encode($items, JSON_UNESCAPED_UNICODE), $sub, $tax, $tot, $s['pay'], $web ? 'synced' : 'pending', now()]);
        $id = (int)$pdo->lastInsertId(); $ref = 'WA-' . gmdate('ymd') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE bot_orders SET reference=? WHERE id=?')->execute([$ref, $id]);
        if ($web) {   // boutique gérée sur le panel web : la vente est enregistrée directement (client, lignes, dette éventuelle)
            $cu = $pdo->prepare('SELECT id,name FROM w_customers WHERE license_id=? AND phone IN (?,?) LIMIT 1'); $cu->execute([$lic, $phone, '+' . $phone]); $c = $cu->fetch();
            if (!$c) { $pdo->prepare('INSERT INTO w_customers (license_id,name,phone,created_at) VALUES (?,?,?,?)')->execute([$lic, $s['name'] ?: 'Client WhatsApp', '+' . $phone, now()]); $c = ['id' => (int)$pdo->lastInsertId(), 'name' => $s['name'] ?: 'Client WhatsApp']; }
            $paid = $s['pay'] === 'credit' ? 0 : $tot;
            $pdo->prepare("INSERT INTO w_sales (license_id,reference,customer_id,customer_name,customer_phone,subtotal,tax_amount,total,paid,payment,source,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,'bot',?)")
                ->execute([$lic, $ref, $c['id'], $c['name'], '+' . $phone, $sub, $tax, $tot, $paid, $s['pay'], now()]);
            $sid = (int)$pdo->lastInsertId();
            $bp = $pdo->prepare('SELECT buy_price FROM w_products WHERE id=? AND license_id=?'); $ii = $pdo->prepare('INSERT INTO w_sale_items (sale_id,product_id,name,qty,unit_price,buy_price) VALUES (?,?,?,?,?,?)');
            foreach ($items as $it) { $bp->execute([$it['id'], $lic]); $ii->execute([$sid, $it['id'], $it['name'], $it['qty'], $it['price'], (float)$bp->fetchColumn()]); }
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }

    $o = ['reference' => $ref, 'created_at' => now(), 'customer_name' => $s['name'], 'phone' => $phone, 'subtotal' => $sub, 'tax_amount' => $tax, 'total' => $tot, 'payment' => $s['pay']];
    $cur = $acc['currency'];
    $sent = false;
    try {
        evo_send_pdf($acc, $jid, bot_invoice_pdf($acc, $o, $items), "Facture-$ref.pdf", "Facture $ref — " . money($tot, $cur));
        $sent = true; $pdo->prepare('UPDATE bot_orders SET invoice_sent=1 WHERE id=?')->execute([$id]);
    } catch (Throwable $e) { bot_log($lic, $phone, 'err', 'PDF : ' . $e->getMessage()); }

    $msg = "✅ Commande $ref enregistrée — total " . money($tot, $cur) . '.';
    if ($s['pay'] === 'mobile_money') $msg .= $acc['momo_info'] !== '' ? "\nPaiement Mobile Money : " . $acc['momo_info'] : "\nPaiement par Mobile Money.";
    elseif ($s['pay'] === 'cash') $msg .= "\nPaiement en espèces à la remise.";
    else $msg .= "\nVente à crédit enregistrée à votre nom.";
    if (!$sent) { $msg .= "\n\nArticles :"; foreach ($items as $i) $msg .= "\n• {$i['qty']} × {$i['name']} = " . money($i['qty'] * $i['price'], $cur); }
    else $msg .= "\nVotre facture PDF est jointe 📎";
    $msg .= "\nMerci pour votre confiance !";
    $s['cart'] = []; $s['pay'] = ''; $s['confirm_turn'] = null;
    return $msg;
}
