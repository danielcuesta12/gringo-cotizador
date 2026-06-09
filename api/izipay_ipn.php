<?php
// IPN server-to-server de Izipay. Configurar en el Back Office:
//   https://elgringo.pe/cotizador/api/izipay_ipn.php
// Se firma con la PASSWORD REST (no con la HMAC).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$env     = @parse_ini_file(__DIR__ . '/../.env');
$mode    = $env['IZIPAY_MODE'] ?? 'TEST';
$signKey = $mode === 'TEST' ? ($env['IZIPAY_REST_PASS_TEST'] ?? '') : ($env['IZIPAY_REST_PASS_PROD'] ?? '');

$krAnswer = $_POST['kr-answer'] ?? '';
$krHash   = $_POST['kr-hash']   ?? '';
if (!hash_equals(hash_hmac('sha256', $krAnswer, $signKey), $krHash)) { http_response_code(400); exit('Firma inválida'); }

$answer      = json_decode($krAnswer, true);
$transaction = $answer['transactions'][0] ?? null;
if (!$transaction || ($transaction['detailedStatus'] ?? '') !== 'AUTHORISED') { http_response_code(200); exit('Pago no autorizado'); }

$orderId    = $answer['orderDetails']['orderId'] ?? ('IPN-' . time());
$totalCents = $answer['orderDetails']['orderTotalAmount'] ?? 0;
$txId       = $transaction['uuid'] ?? '';

$pf = sys_get_temp_dir() . '/iz_pending_' . preg_replace('/[^A-Za-z0-9_-]/', '', $orderId) . '.json';
$pending = file_exists($pf) ? json_decode(file_get_contents($pf), true) : null;
if ($pending) @unlink($pf);

$ubiId = (int)($pending['ubicacion_id'] ?? $pending['carta_id'] ?? 0);
if (!$ubiId) { http_response_code(200); exit('Sin ubicación'); }

try {
    Database::insert(
        "INSERT INTO pedidos (ubicacion_id,nombre,telefono,tipo_entrega,direccion,horario,comentarios,items_json,total,estado,metodo_pago,izipay_order_id,aceptado_at)
         VALUES (?,?,?,?,?,?,?,?,?, 'en_preparacion','izipay',?, NOW())",
        [
            $ubiId,
            $pending['nombre']       ?? ($answer['customer']['billingDetails']['firstName'] ?? 'Cliente'),
            $pending['telefono']     ?? '',
            $pending['tipo_entrega'] ?? 'delivery',
            $pending['direccion']    ?? '',
            $pending['horario']      ?? 'Lo antes posible',
            trim(($pending['comentarios'] ?? '') . ' | Izipay ' . $orderId . ' tx:' . $txId, ' |'),
            json_encode($pending['items'] ?? []),
            $pending['total'] ?? ($totalCents / 100),
            $orderId,
        ]
    );
} catch (PDOException $e) {
    if ($e->getCode() === '23000') { http_response_code(200); exit('OK (ya registrado)'); }
    http_response_code(500); exit('Error BD');
}
http_response_code(200);
echo 'OK';
