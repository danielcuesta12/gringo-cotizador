<?php
// Vistas de producto + likes ❤️ de la carta. Llamado por carta/index.php y carta/menu.php.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$body    = json_decode(file_get_contents('php://input'), true) ?: [];
$action  = $_GET['action'] ?? $body['action'] ?? '';
$prodId  = (int)($_GET['producto_id']  ?? $body['producto_id']  ?? 0);
$ubiId   = (int)($_GET['ubicacion_id'] ?? $body['ubicacion_id'] ?? 0);
$version = substr(preg_replace('/[^a-z0-9_-]/i', '', $_GET['version'] ?? $body['version'] ?? 'pedidos'), 0, 20) ?: 'pedidos';

if (!$prodId) { echo json_encode(['ok' => false, 'total' => 0]); exit; }

try {
    if ($action === 'registrar_vista') {
        // Vista de producto -> evento de analítica (la carta no espera respuesta)
        $page = ($version === 'menu') ? 'menu' : 'carta';
        Database::insert(
            "INSERT INTO analytics_events (event_type,page,ubicacion_id,meta_json) VALUES ('product_view',?,?,?)",
            [$page, $ubiId ?: null, json_encode(['product_id' => $prodId], JSON_UNESCAPED_UNICODE)]
        );
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'toggle_like') {
        $liked = !empty($body['liked']);   // estado nuevo enviado por el front
        $delta = $liked ? 1 : -1;
        Database::execute(
            "INSERT INTO product_likes (product_id,ubicacion_id,version,total) VALUES (?,?,?, GREATEST(0,?))
             ON DUPLICATE KEY UPDATE total = GREATEST(0, total + ?)",
            [$prodId, $ubiId, $version, $delta, $delta]
        );
        $row = Database::fetch("SELECT total FROM product_likes WHERE product_id=? AND ubicacion_id=? AND version=?", [$prodId, $ubiId, $version]);
        echo json_encode(['ok' => true, 'total' => (int)($row['total'] ?? 0)]); exit;
    }

    if ($action === 'get_likes') {
        $row = Database::fetch("SELECT total FROM product_likes WHERE product_id=? AND ubicacion_id=? AND version=?", [$prodId, $ubiId, $version]);
        echo json_encode(['ok' => true, 'total' => (int)($row['total'] ?? 0)]); exit;
    }

    echo json_encode(['ok' => false, 'total' => 0]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'total' => 0]);   // tablas no creadas aún
}
