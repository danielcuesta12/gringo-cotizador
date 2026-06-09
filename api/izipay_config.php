<?php
// Config pública de Izipay para el formulario embebido (lee .env del cotizador)
header('Content-Type: application/json; charset=utf-8');
$env  = @parse_ini_file(__DIR__ . '/../.env');
$mode = $env['IZIPAY_MODE'] ?? 'TEST';
echo json_encode([
    'jsUrl'      => $env['IZIPAY_JS_URL'] ?? '',
    'publicKey'  => $mode === 'TEST' ? ($env['IZIPAY_PUBLIC_KEY_TEST'] ?? '') : ($env['IZIPAY_PUBLIC_KEY_PROD'] ?? ''),
    'mode'       => $mode,
    'configured' => !empty($env['IZIPAY_SHOP_ID']),
]);
