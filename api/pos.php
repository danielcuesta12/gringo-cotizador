<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');
function pout($d){ echo json_encode($d); exit; }

$action = clean($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$writes = ['abrir_turno','cerrar_turno','registrar_venta'];
if (in_array($action, $writes, true)) { if (!$isPost) pout(['ok'=>false,'error'=>'Método']); verifyCsrf(); }
$uid = (int)(currentUser()['id'] ?? 0);

switch ($action) {

// Productos disponibles de una ubicación (para la grilla), agrupados por categoría
case 'productos':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    $rows = Database::fetchAll(
        "SELECT p.id, p.name AS nombre, p.image AS foto, c.name AS categoria, lp.price AS precio
         FROM location_products lp
         JOIN products p ON p.id = lp.product_id AND p.active = 1
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE lp.location_id = ? AND lp.available = 1
         ORDER BY c.sort_order, c.name, lp.sort_order, p.sort_order, p.name", [$ubi]);
    pout(['ok'=>true,'data'=>$rows]);

// Métodos de pago activos
case 'metodos':
    pout(['ok'=>true,'data'=>Database::fetchAll("SELECT id,nombre,tipo FROM pos_metodos_pago WHERE activo=1 ORDER BY orden,id")]);

// Turno abierto del cajero en esa ubicación (o null)
case 'turno_actual':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    $t = Database::fetch("SELECT * FROM pos_turnos WHERE usuario_id=? AND ubicacion_id=? AND estado='abierto' ORDER BY id DESC LIMIT 1", [$uid,$ubi]);
    pout(['ok'=>true,'turno'=>$t]);

case 'abrir_turno':
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $monto = cleanFloat($_POST['monto_inicial'] ?? 0);
    if (!$ubi) pout(['ok'=>false,'error'=>'Ubicación']);
    $ya = Database::fetch("SELECT id FROM pos_turnos WHERE usuario_id=? AND ubicacion_id=? AND estado='abierto'", [$uid,$ubi]);
    if ($ya) pout(['ok'=>true,'id'=>(int)$ya['id']]);
    $id = Database::insert("INSERT INTO pos_turnos (usuario_id,ubicacion_id,monto_inicial) VALUES (?,?,?)", [$uid,$ubi,$monto]);
    pout(['ok'=>true,'id'=>$id]);

case 'cerrar_turno':
    $tid = cleanInt($_POST['turno_id'] ?? 0);
    $montoFinal = cleanFloat($_POST['monto_final'] ?? 0);
    $t = Database::fetch("SELECT * FROM pos_turnos WHERE id=? AND usuario_id=? AND estado='abierto'", [$tid,$uid]);
    if (!$t) pout(['ok'=>false,'error'=>'Turno no encontrado']);
    $ag = Database::fetch(
        "SELECT COUNT(*) n, COALESCE(SUM(total),0) tot,
                COALESCE(SUM(CASE WHEN m.tipo='efectivo' THEN p.total ELSE 0 END),0) ef,
                COALESCE(SUM(CASE WHEN m.tipo='tarjeta'  THEN p.total ELSE 0 END),0) ta,
                COALESCE(SUM(CASE WHEN m.tipo='qr'       THEN p.total ELSE 0 END),0) qr,
                COALESCE(SUM(CASE WHEN m.tipo NOT IN ('efectivo','tarjeta','qr') OR m.tipo IS NULL THEN p.total ELSE 0 END),0) ot
         FROM pedidos p LEFT JOIN pos_metodos_pago m ON m.nombre = p.metodo_pago
         WHERE p.turno_id = ? AND p.estado <> 'cancelado'", [$tid]);
    Database::execute(
        "UPDATE pos_turnos SET estado='cerrado', cerrado_en=NOW(), monto_final=?,
            total_pedidos=?, total_ventas=?, total_efectivo=?, total_tarjeta=?, total_qr=?, total_otros=? WHERE id=?",
        [$montoFinal, (int)$ag['n'], $ag['tot'], $ag['ef'], $ag['ta'], $ag['qr'], $ag['ot'], $tid]);
    pout(['ok'=>true]);

case 'registrar_venta':
    $ubi   = cleanInt($_POST['ubicacion_id'] ?? 0);
    $tid   = cleanInt($_POST['turno_id'] ?? 0);
    $metodo= clean($_POST['metodo_pago'] ?? 'Efectivo');
    $total = cleanFloat($_POST['total'] ?? 0);
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!$ubi || !$tid || !is_array($items) || !count($items)) pout(['ok'=>false,'error'=>'Datos incompletos']);
    $t = Database::fetch("SELECT id FROM pos_turnos WHERE id=? AND usuario_id=? AND estado='abierto'", [$tid,$uid]);
    if (!$t) pout(['ok'=>false,'error'=>'Caja cerrada']);
    $clean = [];
    foreach ($items as $it) {
        $clean[] = [
            'qty'    => max(1,(int)($it['qty'] ?? 1)),
            'nombre' => clean($it['nombre'] ?? ''),
            'precio' => (float)($it['precio'] ?? 0),
            'modificadores' => array_values(array_map(fn($m)=>['nombre'=>clean($m['nombre'] ?? '')], (array)($it['modificadores'] ?? []))),
        ];
    }
    $nombre = clean($_POST['cliente_nombre'] ?? '') ?: 'Mostrador';
    $compro = clean($_POST['comprobante_tipo'] ?? 'ticket');
    if (!in_array($compro, ['ticket','boleta','factura'], true)) $compro = 'ticket';
    // Total autoritativo recalculado en el servidor desde los ítems (en F1 sin descuento).
    $total = 0.0;
    foreach ($clean as $it) { $total += $it['qty'] * $it['precio']; }
    // Acumular en el bucket del método (efectivo/tarjeta/qr/otros). $bucket es de una whitelist fija (no input).
    $tipoRow = Database::fetch("SELECT tipo FROM pos_metodos_pago WHERE nombre = ? LIMIT 1", [$metodo]);
    $tipo    = $tipoRow['tipo'] ?? 'otros';
    $bucket  = ['efectivo'=>'total_efectivo','tarjeta'=>'total_tarjeta','qr'=>'total_qr'][$tipo] ?? 'total_otros';
    $pid = Database::insert(
        "INSERT INTO pedidos (ubicacion_id, nombre, tipo_entrega, items_json, total, estado, metodo_pago, origen, turno_id, comprobante_tipo, aceptado_at, horario)
         VALUES (?,?, 'recojo', ?, ?, 'en_preparacion', ?, 'pos', ?, ?, NOW(), 'En salón')",
        [$ubi, $nombre, json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $metodo, $tid, $compro]);
    Database::execute("UPDATE pos_turnos SET total_ventas=total_ventas+?, total_pedidos=total_pedidos+1, $bucket=$bucket+? WHERE id=?", [$total, $total, $tid]);
    pout(['ok'=>true,'id'=>$pid]);

default:
    pout(['ok'=>false,'error'=>'Acción no válida']);
}
