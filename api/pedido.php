<?php
// Guarda un pedido de la carta de venta (WhatsApp o Izipay). Idempotente por izipay_order_id.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$ubiId = (int)($body['ubicacion_id'] ?? $body['carta_id'] ?? 0);
if (!$ubiId) { echo json_encode(['ok' => false, 'error' => 'ubicacion']); exit; }

$izipay   = !empty($body['izipay']);
$estado   = $izipay ? 'en_preparacion' : 'pendiente';     // pagado online entra a preparación
$metodo   = $izipay ? 'izipay' : 'whatsapp';
$izOrder  = $body['izipay_order_id'] ?? null;
if ($izOrder === '') $izOrder = null;

$cols = "ubicacion_id, nombre, telefono, tipo_entrega, direccion, horario, comentarios, items_json, total, estado, metodo_pago, izipay_order_id, aceptado_at";
$vals = "?,?,?,?,?,?,?,?,?,?,?,?," . ($izipay ? 'NOW()' : 'NULL');
$params = [
    $ubiId,
    clean($body['nombre'] ?? ''),
    preg_replace('/\s+/', ' ', $body['telefono'] ?? ''),
    in_array($body['tipo_entrega'] ?? '', ['delivery', 'recojo']) ? $body['tipo_entrega'] : 'delivery',
    clean($body['direccion'] ?? ''),
    clean($body['horario'] ?? 'Lo antes posible'),
    clean($body['comentarios'] ?? ''),
    json_encode($body['items'] ?? [], JSON_UNESCAPED_UNICODE),
    (float)($body['total'] ?? 0),
    $estado,
    $metodo,
    $izOrder,
];

try {
    $id = Database::insert("INSERT INTO pedidos ($cols) VALUES ($vals)", $params);
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (PDOException $e) {
    // Choque de UNIQUE (la IPN de Izipay ya guardó este pago) → devolver el existente
    if ($izOrder && $e->getCode() === '23000') {
        $row = Database::fetch("SELECT id FROM pedidos WHERE izipay_order_id = ? LIMIT 1", [$izOrder]);
        echo json_encode(['ok' => true, 'id' => $row ? (int)$row['id'] : null, 'duplicate' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => DEBUG_MODE ? $e->getMessage() : 'error']);
    }
}
