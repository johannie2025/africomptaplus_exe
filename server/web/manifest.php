<?php
header('Content-Type: application/manifest+json; charset=utf-8');
$b = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/manifest.php'), '/');
echo json_encode(['name' => 'AfriCompta+', 'short_name' => 'AfriCompta+', 'description' => 'Gestion de boutique : ventes, stock, dettes, WhatsApp',
  'start_url' => $b . '/index.php', 'scope' => $b . '/', 'display' => 'standalone', 'orientation' => 'any', 'background_color' => '#f4f7f5', 'theme_color' => '#0b6b4f',
  'icons' => [['src' => $b . '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'], ['src' => $b . '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']]],
  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
