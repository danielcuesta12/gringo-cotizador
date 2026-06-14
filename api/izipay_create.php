<?php
// Crea el pago en Izipay (REST) y devuelve el formToken para el formulario embebido.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/izipay.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

$body    = json_decode(file_get_contents('php://input'), true) ?: [];

// No crear cobros si la tienda está cerrada
$ubiId = (int)($body['ubicacion_id'] ?? $body['carta_id'] ?? 0);
if ($ubiId) {
    $ubi = Database::fetch("SELECT * FROM ubicaciones WHERE id = ?", [$ubiId]);
    if ($ubi && !ubicacionAbierta($ubi)) {
        echo json_encode(['ok' => false, 'error' => 'La tienda está cerrada en este momento.']); exit;
    }
}

$izc = izipayCfg();
$shopId = $izc['shop_id'];
if (!$shopId) { echo json_encode(['ok' => false, 'error' => 'Izipay no configurado']); exit; }

$mode       = $izc['mode'];
$password   = $izc['rest_pass'];   // secreta (solo-servidor)
$restServer = $izc['rest_server'];

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
