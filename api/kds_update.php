<?php
// KDS — cambiar estado de un pedido (aceptar / listo / cancelar / eliminar).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
verifyCsrf();   // token vía header X-CSRF-Token

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$id     = (int)($body['id'] ?? 0);
if (!$id) { echo json_encode(['ok' => false, 'error' => 'id']); exit; }

try {
    switch ($action) {
        case 'aceptar':
            Database::execute("UPDATE pedidos SET estado='en_preparacion', aceptado_at=NOW() WHERE id=?", [$id]);
            break;
        case 'marcar_listo':
            Database::execute("UPDATE pedidos SET estado='listo', completado_at=NOW() WHERE id=?", [$id]);
            break;
        case 'cancelar':
            Database::execute("UPDATE pedidos SET estado='cancelado', completado_at=NOW() WHERE id=?", [$id]);
            break;
        case 'entregar':
            Database::execute("UPDATE pedidos SET estado='entregado', completado_at=NOW() WHERE id=?", [$id]);
            break;
        case 'eliminar':
            if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'perm']); exit; }
            Database::execute("DELETE FROM pedidos WHERE id=?", [$id]);
            break;
        default:
            echo json_encode(['ok' => false, 'error' => 'action']); exit;
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => DEBUG_MODE ? $e->getMessage() : 'error']);
}
