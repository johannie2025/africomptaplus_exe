<?php
function render_head(string $title, string $nav = '', bool $app = true): void {
    $l = lang_pick(); $u = $app ? me() : null;
    echo '<!doctype html><html lang="' . $l . '"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
       . '<meta name="theme-color" content="#0b6b4f"><meta name="mobile-web-app-capable" content="yes">'
       . '<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="AfriCompta+">'
       . '<link rel="manifest" href="manifest.php"><link rel="icon" href="assets/icon-192.png"><link rel="apple-touch-icon" href="assets/icon-192.png">'
       . '<link rel="stylesheet" href="assets/app.css?v=3"><title>' . e($title) . ' · AfriCompta+</title></head><body class="' . ($app && $u ? 'with-nav' : 'plain') . '">';
    if ($app && $u) {
        $items = ['dashboard' => ['🏠', t('Accueil')], 'pos' => ['🛒', t('Vente')], 'sales' => ['🧾', t('Historique')], 'documents' => ['📄', t('Devis')], 'suppliers' => ['🚚', t('Fournisseurs')], 'products' => ['📦', t('Produits')], 'customers' => ['👥', t('Clients')],
                  'debts' => ['💳', t('Dettes')], 'expenses' => ['💸', t('Dépenses')], 'accounting' => ['📒', t('Compta')], 'reports' => ['📊', t('Rapports')], 'whatsapp' => ['💬', 'WhatsApp'], 'settings' => ['⚙️', t('Paramètres')]];
        if (setting('acct_mode', 'off') === 'off') unset($items['accounting']);
        echo '<header class="top"><b>AfriCompta+</b><span class="shop">' . e(setting('shop_name', (string)$u['lshop'])) . '</span>' . lang_switch() . '</header>';
        echo '<nav class="nav">';
        foreach ($items as $k => [$ic, $lb]) echo '<a class="' . ($nav === $k ? 'on' : '') . '" href="' . url($k) . '"><i>' . $ic . '</i><span>' . e($lb) . '</span></a>';
        echo '<a href="' . url('logout') . '"><i>🚪</i><span>' . e(t('Quitter')) . '</span></a></nav>';
    } else {
        echo '<div class="langbar">' . lang_switch() . '</div>';
    }
    echo '<main class="wrap">';
    if ($f = flash()) echo '<div class="flash ' . ($f[0] === 'ok' ? 'ok' : 'err') . '">' . e($f[1]) . '</div>';
}
function render_foot(): void {
    echo '</main><script src="assets/app.js?v=3"></script><script>if("serviceWorker" in navigator)navigator.serviceWorker.register("sw.js").catch(()=>{});</script></body></html>';
}
function lang_switch(): string {
    $cur = lang_pick(); $o = '<form method="get" action="' . e(url('lang')) . '" class="lang"><input type="hidden" name="m" value="lang"><select name="l" onchange="this.form.submit()" aria-label="Language">';
    foreach (['fr' => 'Français', 'en' => 'English', 'es' => 'Español'] as $k => $v) $o .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . '>' . $v . '</option>';
    return $o . '</select></form>';
}
