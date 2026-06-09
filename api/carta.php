<?php
// Devuelve la carta de una ubicación en el formato que espera el menú de marcona,
// pero leyendo la BD del cotizador (products + categories + location_products).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$ubiId = cleanInt($_GET['ubicacion_id'] ?? 0);
$slug  = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['slug'] ?? '');
if (!$ubiId && $slug) {
    $u = Database::fetch("SELECT id FROM ubicaciones WHERE slug = ?", [$slug]);
    $ubiId = $u ? (int)$u['id'] : 0;
}
if (!$ubiId) { echo json_encode(['ok' => false, 'error' => 'ubicacion']); exit; }

$ubi = Database::fetch("SELECT * FROM ubicaciones WHERE id = ?", [$ubiId]);
if (!$ubi) { echo json_encode(['ok' => false, 'error' => 'ubicacion']); exit; }

// Productos ofrecidos en esta ubicación, con su precio/disponibilidad, agrupados por categoría
$rows = Database::fetchAll(
    "SELECT c.id AS cat_id, c.name AS cat_name, c.sort_order AS cat_order,
            p.id AS pid, p.name AS pname, p.description AS pdesc, p.image AS pimg,
            lp.price AS price, lp.available AS available, lp.sort_order AS lp_order
     FROM location_products lp
     JOIN products p ON p.id = lp.product_id AND p.active = 1
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE lp.location_id = ?
     ORDER BY c.sort_order, c.name, lp.sort_order, p.sort_order, p.name",
    [$ubiId]
);

$secMap = [];
foreach ($rows as $r) {
    $cid = (int)($r['cat_id'] ?? 0);
    if (!isset($secMap[$cid])) {
        $secMap[$cid] = [
            'id'        => $cid,
            'nombre'    => $r['cat_name'] ?: 'Carta',
            'subtitulo' => '',
            'activo'    => 1,
            'productos' => [],
        ];
    }
    $secMap[$cid]['productos'][] = [
        'id'                   => (int)$r['pid'],
        'nombre'               => $r['pname'],
        'descripcion'          => $r['pdesc'],
        'precio'               => (float)$r['price'],
        'foto'                 => $r['pimg'] ? UPLOAD_URL . $r['pimg'] : null,
        'badge'                => 'ninguno',
        'activo'               => (int)$r['available'],   // el menú oculta los no disponibles
        'variantes'            => [],
        'grupos_modificadores' => [],
    ];
}

echo json_encode(['ok' => true, 'secciones' => array_values($secMap), 'carta' => $ubi]);
