<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name' => 'El Gringo · Mozo',
    'short_name' => 'Mozo',
    'start_url' => APP_URL . '/mozo/index.php',
    'display' => 'standalone',
    'background_color' => '#1E1E1E',
    'theme_color' => '#1E1E1E',
    'icons' => [],
], JSON_UNESCAPED_SLASHES);
