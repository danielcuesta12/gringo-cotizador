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

// Comprobante deseado por el cliente (el cajero lo confirma/ajusta en su bandeja).
$compTipo = in_array($body['comprobante_tipo'] ?? '', ['boleta', 'factura'], true) ? $body['comprobante_tipo'] : 'ticket';
$cDoc     = preg_replace('/[^0-9A-Za-z]/', '', (string)($body['cliente_documento'] ?? ''));
$cNom     = clean($body['cliente_nombre'] ?? '');
$cEmail   = filter_var(trim((string)($body['cliente_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
$cNombre  = $compTipo === 'factura' ? ''   : $cNom;   // boleta/ticket → nombre
$cRazon   = $compTipo === 'factura' ? $cNom : '';     // factura → razón social

// cliente_email puede no existir aún (migración nubefact_email.sql pendiente).
$hasEmailCol = (bool) Database::fetch(
    "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'pedidos' AND column_name = 'cliente_email'"
);

$cols = "ubicacion_id, nombre, telefono, tipo_entrega, direccion, horario, comentarios, items_json, total, estado, metodo_pago, izipay_order_id, comprobante_tipo, cliente_documento, cliente_nombre, cliente_razon_social"
      . ($hasEmailCol ? ", cliente_email" : "")
      . ", aceptado_at";
$vals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?" . ($hasEmailCol ? ",?" : "") . "," . ($izipay ? 'NOW()' : 'NULL');
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
    $compTipo,
    ($cDoc ?: null),
    ($cNombre ?: null),
    ($cRazon ?: null),
];
if ($hasEmailCol) $params[] = ($cEmail ?: null);

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
