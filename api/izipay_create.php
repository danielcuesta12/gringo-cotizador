<?php
// Crea el pago en Izipay (REST) y devuelve el formToken para el formulario embebido.
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$env = @parse_ini_file(__DIR__ . '/../.env');
$shopId = $env['IZIPAY_SHOP_ID'] ?? '';
if (!$shopId) { echo json_encode(['ok' => false, 'error' => 'Izipay no configurado (.env)']); exit; }

$mode       = $env['IZIPAY_MODE'] ?? 'TEST';
$password   = $mode === 'TEST' ? ($env['IZIPAY_REST_PASS_TEST'] ?? '') : ($env['IZIPAY_REST_PASS_PROD'] ?? '');
$restServer = $env['IZIPAY_REST_SERVER'] ?? 'https://api.micuentaweb.pe';

$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$amount  = intval(floatval($body['amount'] ?? 0) * 100); // céntimos
$orderId = 'EG-' . time() . '-' . rand(1000, 9999);

$payload = [
    'amount'   => $amount,
    'currency' => 'PEN',
    'orderId'  => $orderId,
    'customer' => [
        'email'          => $body['email'] ?? 'cliente@elgringo.pe',
        'billingDetails' => [
            'firstName'   => $body['nombre']   ?? 'Cliente',
            'phoneNumber' => $body['telefono'] ?? '',
        ],
    ],
];

$ch = curl_init(rtrim($restServer, '/') . '/api-payment/V4/Charge/CreatePayment');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode($shopId . ':' . $password)],
]);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (($response['status'] ?? '') === 'SUCCESS') {
    echo json_encode(['ok' => true, 'formToken' => $response['answer']['formToken'], 'orderId' => $orderId]);
} else {
    echo json_encode(['ok' => false, 'error' => $response['answer']['errorMessage'] ?? 'Error al crear el pago']);
}
