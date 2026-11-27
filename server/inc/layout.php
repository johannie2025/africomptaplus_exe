<?php
function page_head(string $title, string $nav = ''): void {
    $items = ['index' => 'Tableau de bord', 'licenses' => 'Licences', 'ai' => 'IA', 'bot' => 'Bot WhatsApp',  'logs' => 'Journal', 'account' => 'Compte'];
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . ' · AfriCompta+ Licences</title><link rel="stylesheet" href="assets/style.css"></head><body>'
       . '<div class="top"><b>AfriCompta+ Licences</b>';
    foreach ($items as $k => $v) echo '<a class="' . ($nav === $k ? 'on' : '') . '" href="' . $k . '.php">' . $v . '</a>';
    echo '<a href="logout.php">Déconnexion</a></div><div class="wrap">';
    if ($f = flash()) echo '<div class="flash ' . ($f[0] === 'ok' ? 'ok' : 'err') . '">' . e($f[1]) . '</div>';
}
function page_foot(): void { echo '</div></body></html>'; }
