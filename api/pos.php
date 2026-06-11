<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');
function pout($d){ echo json_encode($d); exit; }

$action = clean($_GET['action'] ?? $_POST['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$writes = ['abrir_turno','cerrar_turno','registrar_venta','fav_set','fav_clear'];
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
    $subtotal = 0.0;
    foreach ($items as $it) {
        $qty   = max(1, (int)($it['qty'] ?? 1));
        $base  = (float)($it['precio'] ?? 0);
        $mods  = [];
        $modsSum = 0.0;
        foreach ((array)($it['modificadores'] ?? []) as $m) {
            $mp = (float)($m['precio'] ?? 0);
            $mods[] = ['nombre' => clean($m['nombre'] ?? ''), 'precio' => $mp];
            $modsSum += $mp;
        }
        $nota = clean($it['nota'] ?? '');
        if ($nota !== '') $mods[] = ['nombre' => 'Nota: ' . $nota, 'precio' => 0];
        $lineUnit = $base + $modsSum;
        $lineTot  = $lineUnit * $qty;
        $dt = in_array($it['desc_tipo'] ?? '', ['porcentaje','monto'], true) ? $it['desc_tipo'] : null;
        $dv = (float)($it['desc_valor'] ?? 0);
        if ($dt === 'porcentaje') $lineTot -= $lineTot * min(100, max(0, $dv)) / 100;
        elseif ($dt === 'monto')  $lineTot -= min($lineTot, max(0, $dv));
        $subtotal += $lineTot;
        $clean[] = ['qty'=>$qty, 'nombre'=>clean($it['nombre'] ?? ''), 'precio'=>$base,
                    'modificadores'=>$mods, 'nota'=>$nota, 'desc_tipo'=>$dt, 'desc_valor'=>$dv];
    }
    $gdt = in_array($_POST['descuento_tipo'] ?? '', ['porcentaje','monto'], true) ? $_POST['descuento_tipo'] : null;
    $gdv = cleanFloat($_POST['descuento_valor'] ?? 0);
    $gMonto = 0.0;
    if ($gdt === 'porcentaje') $gMonto = $subtotal * min(100, max(0, $gdv)) / 100;
    elseif ($gdt === 'monto')  $gMonto = min($subtotal, max(0, $gdv));
    $total = max(0, $subtotal - $gMonto);
    $cTipo = in_array($_POST['cliente_tipo'] ?? '', ['nombre','dni','ruc'], true) ? $_POST['cliente_tipo'] : null;
    $cNom  = clean($_POST['cliente_nombre'] ?? '');
    $cDoc  = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['cliente_documento'] ?? ''));
    $cRaz  = clean($_POST['cliente_razon_social'] ?? '');
    $compro = clean($_POST['comprobante_tipo'] ?? 'ticket');
    if (!in_array($compro, ['ticket','boleta','factura'], true)) $compro = 'ticket';
    $notas = clean($_POST['notas_pos'] ?? '');
    $nombre = $cNom ?: 'Mostrador';
    $tipoRow = Database::fetch("SELECT tipo FROM pos_metodos_pago WHERE nombre = ? LIMIT 1", [$metodo]);
    $tipo    = $tipoRow['tipo'] ?? 'otros';
    $bucket  = ['efectivo'=>'total_efectivo','tarjeta'=>'total_tarjeta','qr'=>'total_qr'][$tipo] ?? 'total_otros';
    $pid = Database::insert(
        "INSERT INTO pedidos (ubicacion_id, nombre, tipo_entrega, items_json, total, estado, metodo_pago, origen, turno_id,
            comprobante_tipo, descuento_tipo, descuento_valor, descuento_monto,
            cliente_tipo, cliente_nombre, cliente_documento, cliente_razon_social, notas_pos, aceptado_at, horario)
         VALUES (?,?, 'recojo', ?, ?, 'en_preparacion', ?, 'pos', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'En salón')",
        [$ubi, $nombre, json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $metodo, $tid,
         $compro, $gdt, $gdv, $gMonto, $cTipo, ($cNom ?: null), ($cDoc ?: null), ($cRaz ?: null), ($notas ?: null)]);
    Database::execute("UPDATE pos_turnos SET total_ventas=total_ventas+?, total_pedidos=total_pedidos+1, $bucket=$bucket+? WHERE id=?", [$total, $total, $tid]);
    pout(['ok'=>true,'id'=>$pid,'total'=>$total]);

case 'producto_mods':
    $pid = cleanInt($_GET['producto_id'] ?? 0);
    $grupos = [];
    try {
        $grupos = Database::fetchAll(
            "SELECT g.id, g.nombre, g.tipo, g.max_opciones, g.requerido
             FROM grupos_modificadores g
             JOIN product_modifier_groups pmg ON pmg.grupo_id = g.id
             WHERE pmg.product_id = ? AND g.activo = 1
             ORDER BY pmg.orden, g.orden, g.id", [$pid]);
        foreach ($grupos as &$g) {
            $g['modificadores'] = Database::fetchAll(
                "SELECT id, nombre, precio_adicional FROM modificadores WHERE grupo_id = ? AND activo = 1 ORDER BY orden, id", [(int)$g['id']]);
        }
        unset($g);
    } catch (Exception $e) { $grupos = []; }
    pout(['ok'=>true,'grupos'=>$grupos]);

case 'favoritos':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    pout(['ok'=>true,'data'=>Database::fetchAll(
        "SELECT f.id, f.producto_id, f.posicion, p.name AS nombre, p.image AS foto
         FROM pos_favoritos f JOIN products p ON p.id = f.producto_id AND p.active = 1
         WHERE f.ubicacion_id = ? ORDER BY f.posicion, f.id", [$ubi])]);

case 'fav_set':
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $prod = cleanInt($_POST['producto_id'] ?? 0);
    $pos = cleanInt($_POST['posicion'] ?? 0);
    if (!$ubi || !$prod) pout(['ok'=>false,'error'=>'Datos']);
    Database::execute("DELETE FROM pos_favoritos WHERE ubicacion_id=? AND posicion=?", [$ubi,$pos]);
    $id = Database::insert("INSERT INTO pos_favoritos (ubicacion_id,producto_id,posicion) VALUES (?,?,?)", [$ubi,$prod,$pos]);
    pout(['ok'=>true,'id'=>$id]);

case 'fav_clear':
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $pos = cleanInt($_POST['posicion'] ?? 0);
    Database::execute("DELETE FROM pos_favoritos WHERE ubicacion_id=? AND posicion=?", [$ubi,$pos]);
    pout(['ok'=>true]);

default:
    pout(['ok'=>false,'error'=>'Acción no válida']);
}
