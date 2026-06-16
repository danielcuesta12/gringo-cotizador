<?php
// IPN server-to-server de Izipay. Configurar en el Back Office:
//   https://elgringo.pe/api/izipay_ipn.php
// Se firma con la PASSWORD REST (no con la HMAC).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/izipay.php';

$izc     = izipayCfg();
$mode    = $izc['mode'];
$signKey = $izc['rest_pass'];   // IPN se firma con la PASSWORD REST (secreta)

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

// Comprobante pedido en la carta (mismo mapeo que api/pedido.php) — para que llegue a la bandeja del POS.
$compTipo = in_array($pending['comprobante_tipo'] ?? '', ['boleta', 'factura'], true) ? $pending['comprobante_tipo'] : 'ticket';
$cDoc     = preg_replace('/[^0-9A-Za-z]/', '', (string)($pending['cliente_documento'] ?? ''));
$cNom     = clean($pending['cliente_nombre'] ?? '');
$cNombre  = $compTipo === 'factura' ? ''   : $cNom;
$cRazon   = $compTipo === 'factura' ? $cNom : '';
$cEmail   = cleanEmail($pending['cliente_email'] ?? '');
$hasEmailCol = (bool) Database::fetch("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cliente_email'");

try {
    $cols = "ubicacion_id,nombre,telefono,tipo_entrega,direccion,horario,comentarios,items_json,total,estado,metodo_pago,izipay_order_id,comprobante_tipo,cliente_documento,cliente_nombre,cliente_razon_social"
          . ($hasEmailCol ? ",cliente_email" : "") . ",aceptado_at";
    $vals = "?,?,?,?,?,?,?,?,?, 'en_preparacion','izipay',?,?,?,?,?" . ($hasEmailCol ? ",?" : "") . ", NOW()";
    $params = [
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
        $compTipo,
        ($cDoc ?: null),
        ($cNombre ?: null),
        ($cRazon ?: null),
    ];
    if ($hasEmailCol) $params[] = ($cEmail ?: null);
    Database::insert("INSERT INTO pedidos ($cols) VALUES ($vals)", $params);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') { http_response_code(200); exit('OK (ya registrado)'); }
    http_response_code(500); exit('Error BD');
}
http_response_code(200);
echo 'OK';
