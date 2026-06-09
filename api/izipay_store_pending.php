<?php
// Guarda los datos del pedido antes del pago, para que la IPN los recupere.
header('Content-Type: application/json; charset=utf-8');
$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$orderId = preg_replace('/[^A-Za-z0-9_-]/', '', $body['orderId'] ?? '');
if (!$orderId) { echo json_encode(['ok' => false]); exit; }
@file_put_contents(sys_get_temp_dir() . '/iz_pending_' . $orderId . '.json', json_encode($body));
echo json_encode(['ok' => true]);
