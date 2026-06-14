<?php
// Config PÚBLICA de Izipay para el formulario embebido (settings → .env).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/izipay.php';

header('Content-Type: application/json; charset=utf-8');
$c = izipayCfg();
echo json_encode([
    'jsUrl'      => $c['js_url'],
    'publicKey'  => $c['public_key'],   // pública por diseño
    'mode'       => $c['mode'],
    'configured' => izipayConfigured(),
]);
