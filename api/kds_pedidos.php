<?php
// KDS — pedidos activos (pendiente / en_preparacion) de una ubicación, con segundos transcurridos.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }

$ubi    = cleanInt($_GET['ubicacion_id'] ?? 0);
$limite = max(1, min(100, cleanInt($_GET['limite'] ?? 50)));
$estados = array_values(array_filter(array_map('trim', explode(',', $_GET['estados'] ?? 'pendiente,en_preparacion'))));
$estados = array_values(array_intersect($estados, ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado']));
if (empty($estados)) $estados = ['pendiente', 'en_preparacion'];

$ph = implode(',', array_fill(0, count($estados), '?'));
try {
    // Gating: los pedidos de WhatsApp (carta) sin pagar no entran a cocina hasta
    // que el cajero los acepte (pasan a 'en_preparacion'). Izipay y POS nacen ya
    // en 'en_preparacion', así que no se ven afectados.
    $gate = " AND NOT (p.metodo_pago = 'whatsapp' AND p.estado = 'pendiente')";
    if ($ubi > 0) {
        $sql = "SELECT p.*, TIMESTAMPDIFF(SECOND, p.aceptado_at, NOW()) AS elapsed_seconds
                FROM pedidos p WHERE p.estado IN ($ph)$gate AND p.ubicacion_id = ?
                ORDER BY p.created_at ASC LIMIT $limite";
        $rows = Database::fetchAll($sql, array_merge($estados, [$ubi]));
    } else {
        $sql = "SELECT p.*, TIMESTAMPDIFF(SECOND, p.aceptado_at, NOW()) AS elapsed_seconds
                FROM pedidos p WHERE p.estado IN ($ph)$gate
                ORDER BY p.created_at ASC LIMIT $limite";
        $rows = Database::fetchAll($sql, $estados);
    }
    foreach ($rows as &$p) {
        $p['id']     = (int)$p['id'];
        $p['items']  = json_decode($p['items_json'] ?? '[]', true) ?: [];
        $p['origen'] = $p['origen'] ?? 'carta';
    }
    echo json_encode(['ok' => true, 'pedidos' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => DEBUG_MODE ? $e->getMessage() : 'error']);
}
