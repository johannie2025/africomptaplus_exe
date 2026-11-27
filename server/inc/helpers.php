<?php
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function gen_key(): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $n = strlen($a);
    $g = [];
    for ($i = 0; $i < 3; $i++) { $s = ''; for ($j = 0; $j < 4; $j++) $s .= $a[random_int(0, $n - 1)]; $g[] = $s; }
    return 'ACP-' . implode('-', $g);
}
function unique_key(PDO $pdo): string {
    for ($i = 0; $i < 20; $i++) {
        $k = gen_key();
        $st = $pdo->prepare('SELECT 1 FROM licenses WHERE license_key=?'); $st->execute([$k]);
        if (!$st->fetch()) return $k;
    }
    throw new RuntimeException('Génération de clé impossible');
}
function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}
function redirect(string $u): never { header('Location: ' . $u); exit; }
function paginate(int $total, int $page, int $per = 25): array {
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, $page), $pages);
    return ['page' => $page, 'pages' => $pages, 'per' => $per, 'offset' => ($page - 1) * $per];
}
function pager_html(array $p, array $q): string {
    if ($p['pages'] <= 1) return '';
    $h = '<nav class="pager">';
    for ($i = max(1, $p['page'] - 2); $i <= min($p['pages'], $p['page'] + 2); $i++) {
        $qs = http_build_query(array_merge($q, ['p' => $i]));
        $h .= '<a class="pg' . ($i == $p['page'] ? ' on' : '') . '" href="?' . e($qs) . '">' . $i . '</a>';
    }
    return $h . '</nav>';
}
function license_state(array $l): string {
    if ($l['status'] === 'revoked') return 'revoked';
    if ($l['expires_at'] !== null && $l['expires_at'] < now()) return 'expired';
    return 'active';
}
function state_badge(string $s): string {
    $m = ['active' => ['Active', 'ok'], 'revoked' => ['Révoquée', 'ko'], 'expired' => ['Expirée', 'wa']];
    return '<span class="badge ' . $m[$s][1] . '">' . $m[$s][0] . '</span>';
}
function wa_link(array $l): string {
    $msg = "Bonjour " . ($l['customer_name'] ?: '') . ", voici votre clé d'activation AfriCompta+ : " . $l['license_key']
         . "\nSaisissez-la dans Paramètres > Licence. Merci ! — Wise Design";
    $num = preg_replace('/\D/', '', $l['customer_phone'] ?: cfg()['wa_number']);
    return 'https://wa.me/' . $num . '?text=' . rawurlencode($msg);
}
