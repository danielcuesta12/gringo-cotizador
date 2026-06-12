<?php
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$app = $_GET['app'] ?? 'pos';

$apps = [
    'pos' => [
        'name'        => 'El Gringo POS',
        'short_name'  => 'POS',
        'start_url'   => APP_URL . '/pos/terminal.php',
        'theme_color' => '#161412',
    ],
    'kds' => [
        'name'        => 'El Gringo KDS',
        'short_name'  => 'KDS',
        'start_url'   => APP_URL . '/admin/kds/index.php',
        'theme_color' => '#161412',
    ],
    'monitor' => [
        'name'        => 'Ventas en vivo',
        'short_name'  => 'Ventas',
        'start_url'   => APP_URL . '/admin/pos/monitor.php',
        'theme_color' => '#161412',
    ],
];

if (!isset($apps[$app])) {
    $app = 'pos';
}

$cfg = $apps[$app];

$manifest = [
    'name'             => $cfg['name'],
    'short_name'       => $cfg['short_name'],
    'start_url'        => $cfg['start_url'],
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#161412',
    'theme_color'      => $cfg['theme_color'],
    'scope'            => APP_URL . '/',
    'icons'            => [
        ['src' => APP_URL . '/assets/img/favicon-180.png', 'sizes' => '180x180', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => APP_URL . '/assets/img/favicon-180.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => APP_URL . '/assets/img/favicon-180.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
