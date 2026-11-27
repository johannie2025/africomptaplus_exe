<?php
/** Comptabilité SYSCOHADA (simple + double) — écritures dérivées à la volée des opérations, comme dans l'APK. */
const AC_STOCK_VAR = '6031';
const AC_CASH = '571', AC_MOBILE = '5712', AC_CLIENTS = '411', AC_SALES = '7011', AC_VAT = '4431', AC_COGS = '6011', AC_STOCK = '311', AC_SUPP = '401', AC_MISC = '658';

function ac_plan(): array {
    $m = require __DIR__ . '/ohada_plan.php';
    $s = db()->prepare('SELECT code,label FROM w_acct_accounts WHERE license_id=?'); $s->execute([lid()]);
    foreach ($s as $r) $m[$r['code']] = $r['label'];
    ksort($m, SORT_STRING); return $m;
}
function ac_label(string $c): string { static $p = null; $p ??= ac_plan(); return $p[$c] ?? (t('Compte') . ' ' . $c); }
function ac_expense_account(string $cat): string {
    $c = strtolower(strtr(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cat) ?: $cat, ["'" => '', '`' => '', '^' => '', '~' => '', '"' => '']));
    $has = fn(array $w) => array_reduce($w, fn($a, $x) => $a || str_contains($c, $x), false);
    if ($has(['stock', 'marchandise'])) return AC_STOCK;
    if ($has(['loyer', 'location', 'rent'])) return '6222';
    if ($has(['personnel', 'salaire', 'paie', 'salar', 'wage'])) return '661';
    if ($has(['transport', 'deplacement'])) return '618';
    if ($has(['electric', 'courant'])) return '6052';
    if ($has(['eau'])) return '6051';
    if ($has(['carburant', 'essence'])) return '6053';
    if ($has(['telephone', 'internet', 'communication', 'credit'])) return '628';
    if ($has(['publicite', 'marketing'])) return '627';
    if ($has(['fourniture', 'bureau'])) return '6055';
    if ($has(['entretien', 'reparation', 'maintenance'])) return '624';
    if ($has(['assurance'])) return '625';
    if ($has(['impot', 'taxe'])) return '641';
    if ($has(['banque', 'bancaire'])) return '631';
    return AC_MISC;
}
function ac_range(string $col, ?string $from, ?string $to, array &$a): string {
    $o = '';
    if ($from) { $o .= " AND DATE($col)>=?"; $a[] = $from; }
    if ($to)   { $o .= " AND DATE($col)<=?"; $a[] = $to; }
    return $o;
}
function ac_e(string $date, string $j, string $ref, string $label, string $kind, array $lines, int $manual = -1): array {
    return ['date' => substr($date, 0, 10), 'journal' => $j, 'ref' => $ref, 'label' => $label, 'kind' => $kind, 'manual' => $manual, 'lines' => $lines];
}
function ac_l(string $acc, float $d, float $c, string $an = ''): array { return ['a' => $acc, 'd' => $d, 'c' => $c, 'an' => $an]; }

/** Toutes les écritures de la période, triées par date. */
function ac_entries(?string $from, ?string $to): array {
    $l = lid(); $pdo = db(); $out = [];
    $q = function (string $sql, array $a) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchAll(); };
    // ventes
    $a = [$l]; $r = ac_range('s.created_at', $from, $to, $a);
    $cats = [];
    foreach ($q("SELECT i.sale_id, COALESCE(NULLIF(p.category,''),'Général') cat, SUM(i.qty*i.unit_price) rev, SUM(i.qty*i.buy_price) cost FROM w_sale_items i
        JOIN w_sales s ON s.id=i.sale_id LEFT JOIN w_products p ON p.id=i.product_id WHERE s.license_id=?$r GROUP BY i.sale_id, cat", $a) as $x) $cats[$x['sale_id']][] = $x;
    $a = [$l]; $r = ac_range('created_at', $from, $to, $a);
    foreach ($q("SELECT * FROM w_sales WHERE license_id=?$r ORDER BY created_at,id", $a) as $s) {
        $tender = $s['payment'] === 'credit' ? AC_CLIENTS : ($s['payment'] === 'mobile_money' ? AC_MOBILE : AC_CASH);
        $lines = [ac_l($tender, (float)$s['total'], 0)]; $cl = $cats[$s['id']] ?? [];
        $sum = array_sum(array_map(fn($c) => (float)$c['rev'], $cl)); $diff = (float)$s['total'] - (float)$s['tax_amount'] - $sum;
        if ($cl) foreach ($cl as $i => $c) $lines[] = ac_l(AC_SALES, 0, (float)$c['rev'] + ($i === 0 && abs($diff) >= 0.5 ? $diff : 0), $c['cat']);
        else $lines[] = ac_l(AC_SALES, 0, (float)$s['total'] - (float)$s['tax_amount']);
        if ($s['tax_amount'] > 0) $lines[] = ac_l(AC_VAT, 0, (float)$s['tax_amount']);
        $cost = 0; foreach ($cl as $c) if ($c['cost'] > 0) { $lines[] = ac_l(AC_COGS, (float)$c['cost'], 0, $c['cat']); $cost += (float)$c['cost']; }
        if ($cost > 0) $lines[] = ac_l(AC_STOCK, 0, $cost);
        $out[] = ac_e($s['created_at'], 'VT', $s['reference'], t('Vente') . ' ' . $s['reference'] . ' – ' . $s['customer_name'], 'sale', $lines);
    }
    // règlements de dettes clients
    $a = [$l]; $r = ac_range('p.created_at', $from, $to, $a);
    foreach ($q("SELECT p.*, s.customer_name FROM w_payments p JOIN w_sales s ON s.id=p.sale_id WHERE p.license_id=?$r ORDER BY p.created_at,p.id", $a) as $p) {
        $mm = $p['method'] === 'mobile_money'; $ref = 'REC-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT);
        $out[] = ac_e($p['created_at'], $mm ? 'BQ' : 'CA', $ref, t('Règlement dette') . ' ' . $p['customer_name'] . " ($ref)", 'debt', [ac_l($mm ? AC_MOBILE : AC_CASH, (float)$p['amount'], 0), ac_l(AC_CLIENTS, 0, (float)$p['amount'])]);
    }
    // dépenses
    $a = [$l]; $r = ac_range('created_at', $from, $to, $a);
    foreach ($q("SELECT * FROM w_expenses WHERE license_id=?$r ORDER BY created_at,id", $a) as $e) {
        $acc = ac_expense_account((string)$e['category']); $st = $acc === AC_STOCK;
        $out[] = ac_e($e['created_at'], 'AC', 'DEP-' . $e['id'], ($st ? t('Achat de stock') : t('Dépense') . ' ' . $e['category']) . ($e['description'] ? ' – ' . $e['description'] : ''), $st ? 'stock' : 'expense',
            [ac_l($acc, (float)$e['amount'], 0, $st ? '' : (string)$e['category']), ac_l(AC_CASH, 0, (float)$e['amount'])]);
    }
    // achats fournisseurs
    $a = [$l]; $r = ac_range('created_at', $from, $to, $a);
    foreach ($q("SELECT * FROM w_purchases WHERE license_id=?$r ORDER BY created_at,id", $a) as $u) {
        $lines = [ac_l(AC_STOCK, (float)$u['total'], 0)];
        if ($u['initial_paid'] > 0) $lines[] = ac_l($u['payment'] === 'mobile_money' ? AC_MOBILE : AC_CASH, 0, (float)$u['initial_paid']);
        if ($u['total'] - $u['initial_paid'] > 0.001) $lines[] = ac_l(AC_SUPP, 0, (float)$u['total'] - (float)$u['initial_paid']);
        $out[] = ac_e($u['created_at'], 'AC', $u['reference'], t('Achat') . ' ' . $u['reference'] . ' – ' . $u['supplier_name'], 'stock', $lines);
    }
    $a = [$l]; $r = ac_range('sp.created_at', $from, $to, $a);
    foreach ($q("SELECT sp.*, p.supplier_name, p.reference pref FROM w_supplier_payments sp JOIN w_purchases p ON p.id=sp.purchase_id WHERE sp.license_id=?$r ORDER BY sp.created_at,sp.id", $a) as $x) {
        $mm = $x['method'] === 'mobile_money';
        $out[] = ac_e($x['created_at'], $mm ? 'BQ' : 'CA', 'RGF-' . str_pad((string)$x['id'], 5, '0', STR_PAD_LEFT), t('Règlement fournisseur') . ' ' . $x['supplier_name'] . ' (' . $x['pref'] . ')', 'stock',
            [ac_l(AC_SUPP, (float)$x['amount'], 0), ac_l($mm ? AC_MOBILE : AC_CASH, 0, (float)$x['amount'])]);
    }
    // inventaires physiques : écart négatif = D 6031 / C 311 ; écart positif = D 311 / C 6031 (valorisés au CMP)
    inv_migrate(); $a = [$l]; $r = ac_range('inv_date', $from, $to, $a);
    foreach ($q("SELECT * FROM w_inventories WHERE license_id=?$r ORDER BY inv_date,id", $a) as $iv) {
        $loss = $gain = 0.0;
        foreach ($q('SELECT theoretical,counted,cmp FROM w_inventory_items WHERE inventory_id=?', [$iv['id']]) as $it) { $v = ((int)$it['counted'] - (int)$it['theoretical']) * (float)$it['cmp']; if ($v < 0) $loss -= $v; else $gain += $v; }
        $loss = round($loss); $gain = round($gain); $lines = [];
        if ($loss > 0) { $lines[] = ac_l(AC_STOCK_VAR, $loss, 0); $lines[] = ac_l(AC_STOCK, 0, $loss); }
        if ($gain > 0) { $lines[] = ac_l(AC_STOCK, $gain, 0); $lines[] = ac_l(AC_STOCK_VAR, 0, $gain); }
        if ($lines) $out[] = ac_e($iv['inv_date'], 'OD', $iv['reference'], t('Inventaire physique') . ' ' . $iv['reference'] . ($iv['note'] ? ' – ' . $iv['note'] : ''), 'stock', $lines);
    }
    // mouvements de stock manuels : stock initial D 311 / C 101 ; entrée D 311 / C 6031 ; sortie D 6031 / C 311 (valorisés au coût enregistré)
    stk_migrate($l); $a = [$l]; $r = ac_range('move_date', $from, $to, $a);
    foreach ($q("SELECT * FROM w_stock_moves WHERE license_id=?$r ORDER BY move_date,id", $a) as $m) {
        $v = round((float)$m['qty'] * (float)$m['unit_cost']); if ($v <= 0) continue;
        $lab = ($m['kind'] === 'opening' ? t('Stock initial') : ($m['reason'] !== '' ? $m['reason'] : ($m['kind'] === 'out' ? t('Sortie de stock') : t('Entrée de stock')))) . ' – ' . $m['name'] . ' ×' . (int)$m['qty'];
        $lines = $m['kind'] === 'out' ? [ac_l(AC_STOCK_VAR, $v, 0), ac_l(AC_STOCK, 0, $v)] : [ac_l(AC_STOCK, $v, 0), ac_l($m['kind'] === 'opening' ? '101' : AC_STOCK_VAR, 0, $v)];
        $out[] = ac_e($m['move_date'], 'OD', 'MVT-' . $m['id'], $lab, 'stock', $lines);
    }
    // saisies manuelles
    $a = [$l]; $r = ac_range('entry_date', $from, $to, $a);
    foreach ($q("SELECT * FROM w_acct_entries WHERE license_id=?$r ORDER BY entry_date,id", $a) as $m)
        $out[] = ac_e($m['entry_date'], $m['journal'], 'SAI-' . $m['id'], $m['label'], 'manual', [ac_l($m['debit_account'], (float)$m['amount'], 0), ac_l($m['credit_account'], 0, (float)$m['amount'])], (int)$m['id']);
    usort($out, fn($x, $y) => strcmp($x['date'], $y['date']));
    return $out;
}

function ac_n(float $v): string { return $v == 0 ? '' : number_format($v, 0, ',', ' '); }
function ac_f(float $v): string { return number_format($v, 0, ',', ' '); }
function ac_dshow(string $d): string { return strlen($d) >= 10 ? substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4) : ''; }
function ac_sold(float $s): string { return $s == 0 ? '0' : ac_f(abs($s)) . ($s > 0 ? ' D' : ' C'); }
function ac_match(?string $q, string ...$cells): bool { if (!$q || trim($q) === '') return true; $q = mb_strtolower(trim($q)); foreach ($cells as $c) if (str_contains(mb_strtolower($c), $q)) return true; return false; }
/** Tableau générique : ['title','head'=>[], 'right'=>[bool], 'rows'=>[[bold, cell…]]]. */
function ac_table(string $title, array $head, array $right): array { return ['title' => $title, 'head' => $head, 'right' => $right, 'rows' => []]; }
function ac_add(array &$t, bool $bold, ...$cells): void { $t['rows'][] = [$bold, $cells]; }

function ac_totals(?string $from, ?string $to): array {
    $m = [];
    foreach (ac_entries($from, $to) as $e) foreach ($e['lines'] as $l) { $m[$l['a']] ??= [0.0, 0.0]; $m[$l['a']][0] += $l['d']; $m[$l['a']][1] += $l['c']; }
    ksort($m, SORT_STRING); return $m;
}
function ac_journal(?string $from, ?string $to, ?string $q): array {
    $t = ac_table(t('Journal général'), [t('Date'), t('Jnl'), t('Compte'), t('Libellé'), t('Débit'), t('Crédit')], [0, 0, 0, 0, 1, 1]); $td = $tc = 0;
    foreach (ac_entries($from, $to) as $e) {
        $ok = ac_match($q, $e['label'], $e['ref'], $e['journal']);
        if (!$ok) foreach ($e['lines'] as $l) if (ac_match($q, $l['a'], ac_label($l['a']))) { $ok = true; break; }
        if (!$ok) continue; $first = true;
        foreach ($e['lines'] as $l) { ac_add($t, false, $first ? ac_dshow($e['date']) : '', $first ? $e['journal'] : '', $l['a'], $first ? $e['label'] : '   ' . ac_label($l['a']), ac_n($l['d']), ac_n($l['c'])); $first = false; $td += $l['d']; $tc += $l['c']; }
    }
    ac_add($t, true, '', '', '', t('TOTAL'), ac_f($td), ac_f($tc)); return $t;
}
function ac_ledger(?string $from, ?string $to, ?string $q): array {
    $t = ac_table(t('Grand livre'), [t('Date'), t('Jnl'), t('Libellé'), t('Débit'), t('Crédit'), t('Solde')], [0, 0, 0, 1, 1, 1]); $by = [];
    foreach (ac_entries($from, $to) as $e) foreach ($e['lines'] as $l) $by[$l['a']][] = [$e, $l];
    ksort($by, SORT_STRING);
    foreach ($by as $acc => $rows) {
        if (!ac_match($q, (string)$acc, ac_label((string)$acc))) continue;
        ac_add($t, true, '', '', $acc . ' – ' . ac_label((string)$acc), '', '', ''); $run = $td = $tc = 0;
        foreach ($rows as [$e, $l]) { $run += $l['d'] - $l['c']; $td += $l['d']; $tc += $l['c']; ac_add($t, false, ac_dshow($e['date']), $e['journal'], $e['label'], ac_n($l['d']), ac_n($l['c']), ac_sold($run)); }
        ac_add($t, true, '', '', 'Total ' . $acc, ac_f($td), ac_f($tc), ac_sold($td - $tc));
    }
    return $t;
}
function ac_balance(?string $from, ?string $to, ?string $q): array {
    $t = ac_table(t('Balance générale'), [t('Compte'), t('Intitulé'), t('Débit'), t('Crédit'), t('Solde D'), t('Solde C')], [0, 0, 1, 1, 1, 1]); $td = $tc = $sd = $sc = 0;
    foreach (ac_totals($from, $to) as $acc => [$d, $c]) {
        if (!ac_match($q, (string)$acc, ac_label((string)$acc))) continue; $s = $d - $c;
        ac_add($t, false, $acc, ac_label((string)$acc), ac_n($d), ac_n($c), $s > 0 ? ac_f($s) : '', $s < 0 ? ac_f(-$s) : ''); $td += $d; $tc += $c; if ($s > 0) $sd += $s; else $sc -= $s;
    }
    ac_add($t, true, '', t('TOTAL'), ac_f($td), ac_f($tc), ac_f($sd), ac_f($sc)); return $t;
}
function ac_income(?string $from, ?string $to): array {
    $t = ac_table(t('Compte de résultat'), [t('Compte'), t('Libellé'), t('Montant')], [0, 0, 1]); $m = ac_totals($from, $to); $prod = $chg = $ventes = $achats = 0;
    ac_add($t, true, '', t('PRODUITS (classe 7)'), '');
    foreach ($m as $a => [$d, $c]) if (str_starts_with((string)$a, '7')) { $v = $c - $d; $prod += $v; if (str_starts_with((string)$a, '70')) $ventes += $v; ac_add($t, false, $a, ac_label((string)$a), ac_f($v)); }
    ac_add($t, true, '', t('Total produits'), ac_f($prod)); ac_add($t, true, '', t('CHARGES (classe 6)'), '');
    foreach ($m as $a => [$d, $c]) if (str_starts_with((string)$a, '6')) { $v = $d - $c; $chg += $v; if (str_starts_with((string)$a, '60')) $achats += $v; ac_add($t, false, $a, ac_label((string)$a), ac_f($v)); }
    ac_add($t, true, '', t('Total charges'), ac_f($chg)); ac_add($t, true, '', t("Marge commerciale (ventes − coût d'achat)"), ac_f($ventes - $achats));
    ac_add($t, true, '', $prod - $chg >= 0 ? t('RÉSULTAT NET : BÉNÉFICE') : t('RÉSULTAT NET : PERTE'), ac_f($prod - $chg)); return $t;
}
function ac_balance_sheet(?string $to): array {
    $t = ac_table(t('Bilan'), [t('Compte'), t('Libellé'), t('Montant')], [0, 0, 1]); $actif = $passif = $res = 0; $A = $P = [];
    foreach (ac_totals(null, $to) as $acc => [$d, $c]) {
        $cl = ((string)$acc)[0]; $s = $d - $c;
        if ($cl === '7') $res += -$s; elseif ($cl === '6') $res -= $s;
        elseif ($cl >= '1' && $cl <= '5') { if ($s > 0) { $A[] = [$acc, ac_label((string)$acc), ac_f($s)]; $actif += $s; } elseif ($s < 0) { $P[] = [$acc, ac_label((string)$acc), ac_f(-$s)]; $passif += -$s; } }
    }
    ac_add($t, true, '', t('ACTIF'), ''); foreach ($A as $r) ac_add($t, false, ...$r);
    if ($res < 0) { ac_add($t, false, '139', t('Résultat net : perte'), ac_f(-$res)); $actif += -$res; }
    ac_add($t, true, '', t('TOTAL ACTIF'), ac_f($actif)); ac_add($t, true, '', t('PASSIF'), ''); foreach ($P as $r) ac_add($t, false, ...$r);
    if ($res > 0) { ac_add($t, false, '131', t('Résultat net : bénéfice'), ac_f($res)); $passif += $res; }
    ac_add($t, true, '', t('TOTAL PASSIF'), ac_f($passif));
    $s = db()->prepare('SELECT COALESCE(SUM(stock*buy_price),0) FROM w_products WHERE license_id=?'); $s->execute([lid()]);
    ac_add($t, false, '', t("Pour info : stock valorisé au coût d'achat (inventaire)"), ac_f((float)$s->fetchColumn())); return $t;
}
function ac_analytic(?string $from, ?string $to): array {
    $t = ac_table(t('Comptabilité analytique'), [t('Centre / nature'), 'CA HT', t('Coûts'), t('Marge'), t('Taux')], [0, 1, 1, 1, 1]); $cent = []; $chg = [];
    foreach (ac_entries($from, $to) as $e) foreach ($e['lines'] as $l) {
        if ($l['a'] === AC_SALES || $l['a'] === AC_COGS) { $k = $l['an'] ?: t('Non ventilé'); $cent[$k] ??= [0.0, 0.0]; if ($l['a'] === AC_SALES) $cent[$k][0] += $l['c'] - $l['d']; else $cent[$k][1] += $l['d'] - $l['c']; }
        elseif (str_starts_with($l['a'], '6') && !str_starts_with($l['a'], '60')) { $k = ac_label($l['a']); $chg[$k] = ($chg[$k] ?? 0) + $l['d'] - $l['c']; }
    }
    ksort($cent); ksort($chg); ac_add($t, true, t('CENTRES : CATÉGORIES DE PRODUITS'), '', '', '', ''); $ca = $co = 0;
    foreach ($cent as $k => [$v, $c]) { $m = $v - $c; ac_add($t, false, $k, ac_f($v), ac_f($c), ac_f($m), $v > 0 ? round($m * 100 / $v) . ' %' : ''); $ca += $v; $co += $c; }
    ac_add($t, true, t('Total marge brute'), ac_f($ca), ac_f($co), ac_f($ca - $co), $ca > 0 ? round(($ca - $co) * 100 / $ca) . ' %' : '');
    ac_add($t, true, t("CHARGES D'EXPLOITATION PAR NATURE"), '', '', '', ''); $tc = 0; foreach ($chg as $k => $v) { ac_add($t, false, $k, '', ac_f($v), '', ''); $tc += $v; }
    ac_add($t, true, t('Total charges'), '', ac_f($tc), '', ''); ac_add($t, true, $ca - $co - $tc >= 0 ? t('RÉSULTAT ANALYTIQUE (bénéfice)') : t('RÉSULTAT ANALYTIQUE (perte)'), '', '', ac_f($ca - $co - $tc), ''); return $t;
}
function ac_cash_net(array $e): float { $v = 0; foreach ($e['lines'] as $l) if ($l['a'] === AC_CASH || $l['a'] === AC_MOBILE) $v += $l['d'] - $l['c']; return $v; }
function ac_cashbook(?string $from, ?string $to, ?string $q): array {
    $t = ac_table(t('Livre de caisse'), [t('Date'), t('Libellé'), t('Entrée'), t('Sortie'), t('Solde')], [0, 0, 1, 1, 1]); $run = 0; $in = $out = 0;
    if ($from) { foreach (ac_entries(null, date('Y-m-d', strtotime($from . ' -1 day'))) as $e) $run += ac_cash_net($e); ac_add($t, true, '', t('Report à nouveau'), '', '', ac_f($run)); }
    foreach (ac_entries($from, $to) as $e) { $n = ac_cash_net($e); $run += $n; if ($n == 0 || !ac_match($q, $e['label'], $e['ref'])) continue;
        ac_add($t, false, ac_dshow($e['date']), $e['label'], $n > 0 ? ac_f($n) : '', $n < 0 ? ac_f(-$n) : '', ac_f($run)); if ($n > 0) $in += $n; else $out -= $n; }
    ac_add($t, true, '', t('TOTAL'), ac_f($in), ac_f($out), ac_f($run)); return $t;
}
function ac_summary(?string $from, ?string $to): array {
    $t = ac_table(t('Synthèse de trésorerie'), [t('Libellé'), t('Montant')], [0, 1]); $sale = $debt = $oin = $exp = $stock = $oout = 0;
    foreach (ac_entries($from, $to) as $e) { $n = ac_cash_net($e);
        switch ($e['kind']) { case 'sale': $sale += $n; break; case 'debt': $debt += $n; break; case 'expense': $exp += -$n; break; case 'stock': $stock += -$n; break; default: if ($n >= 0) $oin += $n; else $oout += -$n; } }
    $in = $sale + $debt + $oin; $out = $exp + $stock + $oout;
    ac_add($t, true, t('ENTRÉES'), ''); ac_add($t, false, t('Ventes encaissées (espèces + mobile)'), ac_f($sale)); ac_add($t, false, t('Règlements de dettes clients'), ac_f($debt)); ac_add($t, false, t('Autres entrées'), ac_f($oin)); ac_add($t, true, t('Total entrées'), ac_f($in));
    ac_add($t, true, t('SORTIES'), ''); ac_add($t, false, t('Achats de stock'), ac_f($stock)); ac_add($t, false, t('Charges et dépenses'), ac_f($exp)); ac_add($t, false, t('Autres sorties'), ac_f($oout)); ac_add($t, true, t('Total sorties'), ac_f($out));
    ac_add($t, true, t('SOLDE DE LA PÉRIODE'), ac_f($in - $out)); $cli = $fou = 0;
    foreach (ac_entries(null, $to) as $e) foreach ($e['lines'] as $l) { if ($l['a'] === AC_CLIENTS) $cli += $l['d'] - $l['c']; if ($l['a'] === AC_SUPP) $fou += $l['c'] - $l['d']; }
    ac_add($t, false, t('Créances clients restant à encaisser (cumul)'), ac_f($cli)); ac_add($t, false, t('Dettes fournisseurs restant à payer (cumul)'), ac_f($fou)); return $t;
}
function ac_plan_table(?string $q): array {
    $t = ac_table(t('Plan comptable SYSCOHADA'), [t('Compte'), t('Intitulé')], [0, 0]);
    foreach (ac_plan() as $c => $l) if (ac_match($q, (string)$c, $l)) ac_add($t, strlen((string)$c) <= 2, (string)$c, $l);
    return $t;
}
function ac_html(array $t): void {
    echo '<div class="card tw"><h2>' . e($t['title']) . '</h2><table><tr>';
    foreach ($t['head'] as $i => $h) echo '<th' . (!empty($t['right'][$i]) ? ' class="r"' : '') . '>' . e($h) . '</th>';
    echo '</tr>';
    foreach ($t['rows'] as $ri => [$bold, $cells]) { $lk = $t['links'][$ri] ?? null; echo '<tr class="' . ($bold ? 'b ' : '') . ($lk ? 'click' : '') . '"' . ($lk ? ' onclick="location=\'' . e($lk) . '\'"' : '') . '>'; foreach ($cells as $i => $c) echo '<td' . (!empty($t['right'][$i]) ? ' class="r"' : '') . '>' . e((string)$c) . '</td>'; echo '</tr>'; }
    echo '</table></div>';
}
function ac_csv(array $t, string $name): never {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '_', $name) . '.csv"');
    echo "\xEF\xBB\xBF"; $f = fopen('php://output', 'w'); fputcsv($f, $t['head'], ';');
    foreach ($t['rows'] as [$b, $cells]) fputcsv($f, $cells, ';'); exit;
}

// ───────────────────────── Inventaire valorisé au CMP ─────────────────────────
function ac_cmp_map(): array { return stk_cmp_map(lid()); }
/** Fiche / journal de stock : toutes les opérations avec CMP courant et stock après opération. */
function ac_stock_card(?int $pid, ?string $from, ?string $to, ?string $q): array {
    $l = lid(); stk_migrate($l); $ev = stk_events($l);
    $t = ac_table($pid ? t('Fiche de stock') : t('Mouvements de stock'), [t('Date'), t('Produit'), t('Opération'), t('Réf'), t('Entrée'), t('Sortie'), t('Coût unit.'), 'CMP', t('Stock'), t('Valeur stock')], [0, 0, 0, 0, 1, 1, 1, 1, 1, 1]);
    $s = db()->prepare('SELECT id,name,stock,buy_price,barcode FROM w_products WHERE license_id=? ORDER BY name'); $s->execute([$l]); $rows = [];
    foreach ($s as $p) {
        if ($pid && (int)$p['id'] !== $pid) continue; if (!$pid && !ac_match($q, $p['name'], $p['barcode'], '')) continue;
        $tr = []; stk_replay((float)$p['stock'], (float)$p['buy_price'], $ev[$p['id']] ?? [], $tr);
        foreach ($tr as $x) { $d = substr($x['d'], 0, 10); if ($from && $d < $from) continue; if ($to && $d > $to) continue; $rows[] = [$x['d'], $p['name'], $x]; }
    }
    usort($rows, fn($a, $b) => strcmp($a[0], $b[0]));
    foreach ($rows as [$d, $n, $x]) ac_add($t, false, ac_dshow(substr($d, 0, 10)), $n, t($x['l']), $x['r'], $x['in'] ? (string)(int)$x['in'] : '', $x['out'] ? (string)(int)$x['out'] : '', ac_f($x['uc']), ac_f($x['cmp']), (string)(int)$x['q'], ac_f($x['q'] * $x['cmp']));
    return $t;
}
function ac_categories(): array { $s = db()->prepare("SELECT DISTINCT COALESCE(NULLIF(category,''),'Général') c FROM w_products WHERE license_id=? ORDER BY c"); $s->execute([lid()]); return $s->fetchAll(PDO::FETCH_COLUMN); }
function ac_inventory(?string $q, ?string $cat, string $state): array {
    $t = ac_table(t('Inventaire valorisé (CMP)'), [t('Produit'), t('Qté'), 'CMP', t('Valeur au CMP'), t('Prix de vente'), t('Valeur de vente'), t('Marge latente')], [0, 1, 1, 1, 1, 1, 1]);
    $cmp = ac_cmp_map(); $s = db()->prepare("SELECT *, COALESCE(NULLIF(category,''),'Général') cat FROM w_products WHERE license_id=? ORDER BY cat, name"); $s->execute([lid()]);
    $groups = []; foreach ($s as $p) {
        if (!ac_match($q, $p['name'], $p['barcode'], $p['cat'])) continue; if ($cat && $p['cat'] !== $cat) continue;
        $st = (int)$p['stock']; if ($state === 'in' && $st <= 0) continue; if ($state === 'out' && $st > 0) continue; if ($state === 'low' && $st > (int)$p['min_stock']) continue;
        $groups[$p['cat']][] = $p;
    }
    $tq = $tv = $ts = 0.0;
    foreach ($groups as $g => $rows) {
        ac_add($t, true, $g, '', '', '', '', '', ''); $gq = $gv = $gs = 0.0;
        foreach ($rows as $p) {
            $c = $cmp[$p['id']] ?? (float)$p['buy_price']; $q1 = (int)$p['stock']; $v = $q1 * $c; $sv = $q1 * (float)$p['sell_price'];
            ac_add($t, false, '   ' . $p['name'] . ($q1 <= (int)$p['min_stock'] ? ($q1 <= 0 ? ' ⛔' : ' ⚠') : ''), (string)$q1, ac_f($c), ac_f($v), ac_f((float)$p['sell_price']), ac_f($sv), ac_f($sv - $v));
            $t['links'][count($t['rows']) - 1] = url('accounting', ['v' => 'stock', 'pid' => $p['id']]); $gq += $q1; $gv += $v; $gs += $sv;
        }
        ac_add($t, true, t('Sous-total') . ' ' . $g, ac_f($gq), '', ac_f($gv), '', ac_f($gs), ac_f($gs - $gv)); $tq += $gq; $tv += $gv; $ts += $gs;
    }
    ac_add($t, true, t('TOTAL INVENTAIRE'), ac_f($tq), '', ac_f($tv), '', ac_f($ts), ac_f($ts - $tv));
    $b = ac_totals(null, null)[AC_STOCK] ?? [0, 0]; $book = $b[0] - $b[1];
    ac_add($t, false, t('Solde comptable du compte 311 (stock)'), '', '', ac_f($book), '', '', '');
    ac_add($t, false, t('Écart inventaire − comptabilité'), '', '', ac_f($tv - $book), '', '', '');
    return $t;
}
function ac_counts_table(?string $from, ?string $to): array {
    inv_migrate(); $t = ac_table(t('Inventaires physiques (comptages)'), [t('Date'), t('Référence'), t('Articles'), t('Écart (qté)'), t('Écart (valeur)')], [0, 0, 1, 1, 1]);
    $a = [lid()]; $r = ac_range('inv_date', $from, $to, $a);
    $s = db()->prepare("SELECT v.*, COUNT(i.id) n, COALESCE(SUM(i.counted-i.theoretical),0) dq, COALESCE(SUM((i.counted-i.theoretical)*i.cmp),0) dv FROM w_inventories v LEFT JOIN w_inventory_items i ON i.inventory_id=v.id WHERE v.license_id=?$r GROUP BY v.id ORDER BY v.inv_date DESC, v.id DESC"); $s->execute($a);
    foreach ($s as $v) { ac_add($t, false, ac_dshow($v['inv_date']), $v['reference'], (string)$v['n'], (string)(int)$v['dq'], ac_f((float)$v['dv'])); $t['links'][count($t['rows']) - 1] = url('accounting', ['a' => 'count_view', 'id' => $v['id']]); }
    return $t;
}
