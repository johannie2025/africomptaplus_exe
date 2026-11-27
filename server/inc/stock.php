<?php
/** Gestion de stock : mouvements (ouverture / entrée / sortie), CMP exact rejoué, fiche de stock. */
function stk_migrate_tables(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS w_stock_moves (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_id INT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NULL, name VARCHAR(160) NOT NULL DEFAULT '',
        kind VARCHAR(10) NOT NULL, qty INT NOT NULL, unit_cost DECIMAL(14,4) NOT NULL DEFAULT 0, move_date DATETIME NOT NULL, reason VARCHAR(60) NOT NULL DEFAULT '', note VARCHAR(200) NOT NULL DEFAULT '',
        uid VARCHAR(40) NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_wsm_uid (license_id, uid), INDEX idx_wsm_p (license_id, product_id), INDEX idx_wsm_upd (license_id, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Événements de stock par produit : [date, type(0 entrée au coût, 1 sortie, 2 entrée au CMP), qté, coût, prio, libellé, réf]. */
function stk_events(int $l, bool $withOpening = true): array {
    $pdo = db(); $ev = [];
    $s = $pdo->prepare('SELECT i.product_id pid, i.qty, i.unit_cost, p.created_at d, p.reference r, p.supplier_name n FROM w_purchase_items i JOIN w_purchases p ON p.id=i.purchase_id WHERE p.license_id=? AND i.product_id IS NOT NULL'); $s->execute([$l]);
    foreach ($s as $r) $ev[$r['pid']][] = [(string)$r['d'], 0, (float)$r['qty'], (float)$r['unit_cost'], 1, 'Approvisionnement ' . $r['n'], (string)$r['r']];
    $s = $pdo->prepare('SELECT i.product_id pid, i.qty, s.created_at d, s.reference r FROM w_sale_items i JOIN w_sales s ON s.id=i.sale_id WHERE s.license_id=? AND i.product_id IS NOT NULL'); $s->execute([$l]);
    foreach ($s as $r) $ev[$r['pid']][] = [(string)$r['d'], 1, (float)$r['qty'], 0.0, 3, 'Vente', (string)$r['r']];
    $s = $pdo->prepare('SELECT product_id pid, kind, qty, unit_cost, move_date d, reason, note FROM w_stock_moves WHERE license_id=? AND product_id IS NOT NULL'); $s->execute([$l]);
    foreach ($s as $r) {
        if ($r['kind'] === 'opening' && !$withOpening) continue;
        $ev[$r['pid']][] = [(string)$r['d'], $r['kind'] === 'out' ? 1 : 0, (float)$r['qty'], (float)$r['unit_cost'], $r['kind'] === 'opening' ? 0 : ($r['kind'] === 'out' ? 3 : 1),
            ($r['kind'] === 'opening' ? 'Stock initial' : ($r['reason'] !== '' ? $r['reason'] : ($r['kind'] === 'out' ? 'Sortie' : 'Entrée'))), (string)$r['note']];
    }
    $s = $pdo->prepare('SELECT i.product_id pid, i.counted-i.theoretical dq, v.created_at d, v.reference r FROM w_inventory_items i JOIN w_inventories v ON v.id=i.inventory_id WHERE v.license_id=? AND i.product_id IS NOT NULL AND i.counted<>i.theoretical'); $s->execute([$l]);
    foreach ($s as $r) $ev[$r['pid']][] = [(string)$r['d'], $r['dq'] > 0 ? 2 : 1, (float)abs($r['dq']), 0.0, $r['dq'] > 0 ? 2 : 3, 'Inventaire physique', (string)$r['r']];
    return $ev;
}
/** Rejoue les événements. $trace (optionnel) reçoit les lignes de la fiche de stock. @return float CMP final */
function stk_replay(float $stock, float $buy, array $events, ?array &$trace = null): float {
    usort($events, fn($a, $b) => [$a[0], $a[4]] <=> [$b[0], $b[4]]);
    $net = 0; $first = null;
    foreach ($events as $e) { if ($e[1] === 1) $net -= $e[2]; else { $net += $e[2]; if ($e[1] === 0 && $first === null) $first = $e[3]; } }
    $q = max(0.0, $stock - $net); $c = $first ?? $buy; $trace = [];
    if ($q > 0) $trace[] = ['d' => $events ? $events[0][0] : '', 'l' => 'Report (stock non tracé)', 'r' => '', 'in' => $q, 'out' => 0.0, 'uc' => $c, 'cmp' => round($c, 4), 'q' => $q];
    foreach ($events as $e) {
        $n = (float)$e[2];
        if ($e[1] === 0) { $t = $q + $n; $c = $t > 0 ? ($q * $c + $n * $e[3]) / $t : $e[3]; $q = $t; $in = $n; $out = 0.0; $uc = $e[3]; }
        elseif ($e[1] === 2) { $q += $n; $in = $n; $out = 0.0; $uc = $c; }
        else { $q = max(0.0, $q - $n); $in = 0.0; $out = $n; $uc = $c; }
        $trace[] = ['d' => $e[0], 'l' => $e[5], 'r' => $e[6], 'in' => $in, 'out' => $out, 'uc' => $uc, 'cmp' => round($c, 4), 'q' => $q];
    }
    return round($c, 4);
}
/** @return array product_id => cmp */
function stk_cmp_map(int $l): array {
    $ev = stk_events($l); $out = []; $s = db()->prepare('SELECT id,stock,buy_price FROM w_products WHERE license_id=?'); $s->execute([$l]);
    foreach ($s as $p) $out[$p['id']] = stk_replay((float)$p['stock'], (float)$p['buy_price'], $ev[$p['id']] ?? []);
    return $out;
}
/** Enregistre un mouvement (ne modifie PAS le stock : à faire par l'appelant). */
function stk_move(int $l, int $pid, string $name, string $kind, int $qty, float $cost, string $reason = '', string $note = '', ?string $date = null, ?string $uid = null): void {
    if ($qty <= 0) return;
    db()->prepare('INSERT INTO w_stock_moves (license_id,product_id,name,kind,qty,unit_cost,move_date,reason,note,uid) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$l, $pid, mb_substr($name, 0, 160), $kind, $qty, round(max(0, $cost), 4), $date ?: gmdate('Y-m-d H:i:s'), mb_substr($reason, 0, 60), mb_substr($note, 0, 200), $uid ?: ('m' . bin2hex(random_bytes(8)))]);
}
/** Une fois par licence : crée le mouvement « stock initial » des produits existants (stock non tracé → valorisé exactement). */
function stk_migrate(int $l): void {
    static $done = []; if (isset($done[$l])) return; $done[$l] = true;
    stk_migrate_tables(); $pdo = db();
    $f = $pdo->prepare("SELECT 1 FROM w_settings WHERE license_id=? AND skey='stk_mig'"); $f->execute([$l]); if ($f->fetch()) return;
    $ev = stk_events($l, false); $op = $pdo->prepare("SELECT DISTINCT product_id FROM w_stock_moves WHERE license_id=? AND kind='opening'"); $op->execute([$l]); $has = array_flip($op->fetchAll(PDO::FETCH_COLUMN));
    $s = $pdo->prepare('SELECT id,name,stock,buy_price,created_at FROM w_products WHERE license_id=?');
    $s->execute([$l]);
    foreach ($s as $p) {
        if (isset($has[$p['id']])) continue;
        $e = $ev[$p['id']] ?? []; $net = 0; $first = null; $d = (string)$p['created_at'];
        usort($e, fn($a, $b) => [$a[0], $a[4]] <=> [$b[0], $b[4]]);
        foreach ($e as $x) { $net += $x[1] === 1 ? -$x[2] : $x[2]; if ($x[1] === 0 && $first === null) $first = $x[3]; if ($x[0] < $d) $d = $x[0]; }
        $q = (int)max(0, $p['stock'] - $net); if ($q <= 0) continue;
        stk_move($l, (int)$p['id'], $p['name'], 'opening', $q, $first ?? (float)$p['buy_price'], 'Stock initial', '', $d, null);
    }
    $pdo->prepare("INSERT IGNORE INTO w_settings (license_id,skey,svalue) VALUES (?,'stk_mig','1')")->execute([$l]);
}
