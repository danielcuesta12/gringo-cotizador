<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cuentas.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function mout($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function mozoEmp(): int { return (int)($_SESSION['mozo_emp'] ?? 0); }
function mozoUbi(): int { return (int)($_SESSION['mozo_ubi'] ?? 0); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$writes = ['login_pin', 'logout', 'abrir_cuenta', 'enviar_comanda', 'anular', 'cerrar_cuenta_vacia'];
if (in_array($action, $writes, true) && $action !== 'login_pin') verifyCsrf();

// --- acciones públicas (sin sesión de mozo) ---
if ($action === 'mozos') {
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    mout(['ok' => true, 'mozos' => Database::fetchAll(
        "SELECT id, nombre FROM empleados WHERE ubicacion_id = ? AND activo = 1 ORDER BY nombre", [$ubi])]);
}

if ($action === 'login_pin') {
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $eid = cleanInt($_POST['empleado_id'] ?? 0);
    $pin = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    $emp = Database::fetch("SELECT * FROM empleados WHERE id = ? AND ubicacion_id = ? AND activo = 1", [$eid, $ubi]);
    if (!$emp || empty($emp['pin_hash'])) mout(['ok' => false, 'error' => 'Mozo no válido']);
    if (!empty($emp['pin_bloqueado_hasta']) && strtotime($emp['pin_bloqueado_hasta']) > time()) {
        mout(['ok' => false, 'error' => 'Bloqueado por intentos. Espera unos minutos.']);
    }
    if (!password_verify($pin, $emp['pin_hash'])) {
        $intentos = (int)($emp['pin_intentos'] ?? 0) + 1;
        try {
            if ($intentos >= 5) Database::execute("UPDATE empleados SET pin_intentos=0, pin_bloqueado_hasta=(NOW()+INTERVAL 5 MINUTE) WHERE id=?", [$eid]);
            else Database::execute("UPDATE empleados SET pin_intentos=? WHERE id=?", [$intentos, $eid]);
        } catch (\Throwable $e) {}
        mout(['ok' => false, 'error' => 'PIN incorrecto']);
    }
    try { Database::execute("UPDATE empleados SET pin_intentos=0, pin_bloqueado_hasta=NULL WHERE id=?", [$eid]); } catch (\Throwable $e) {}
    $_SESSION['mozo_emp'] = (int)$emp['id'];
    $_SESSION['mozo_ubi'] = $ubi;
    $_SESSION['mozo_nombre'] = $emp['nombre'];
    mout(['ok' => true, 'nombre' => $emp['nombre']]);
}

if ($action === 'sesion') {
    mout(['ok' => true, 'mozo' => mozoEmp() ? ['emp' => mozoEmp(), 'nombre' => $_SESSION['mozo_nombre'] ?? '', 'ubi' => mozoUbi()] : null]);
}
if ($action === 'logout') { unset($_SESSION['mozo_emp'], $_SESSION['mozo_ubi'], $_SESSION['mozo_nombre']); mout(['ok' => true]); }

// --- de aquí en adelante requiere sesión de mozo ---
if (!mozoEmp()) { http_response_code(401); mout(['ok' => false, 'error' => 'sesión requerida']); }
$ubi = mozoUbi();

// Geocerca: las escrituras mandan lat/lng
function geoGate(int $ubi): void {
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
    if (!dentroGeocerca($ubi, $lat, $lng)) {
        mout(['ok' => false, 'error' => 'Debes estar en el local · activa la ubicación', 'geo' => true]);
    }
}

switch ($action) {

    case 'plano': {
        $pisos = [];
        foreach (Database::fetchAll("SELECT id FROM mesa_pisos WHERE ubicacion_id = ? AND activo = 1 ORDER BY orden, id", [$ubi]) as $row) {
            $pid = (int)$row['id'];
            $p = Database::fetch("SELECT id, nombre, orden, fondo_img, ancho, alto FROM mesa_pisos WHERE id = ?", [$pid]);
            $p['mesas'] = Database::fetchAll("SELECT id, numero, capacidad, forma, pos_x, pos_y, ancho, alto FROM mesas WHERE piso_id = ? AND activa = 1 ORDER BY id", [$pid]);
            $p['elementos'] = Database::fetchAll("SELECT id, tipo, texto, pos_x, pos_y, ancho, alto FROM mesa_elementos WHERE piso_id = ? ORDER BY id", [$pid]);
            $pisos[] = $p;
        }
        mout(['ok' => true, 'pisos' => $pisos]);
    }

    case 'plano_estados':
        mout(array_merge(['ok' => true], mesaEstados($ubi)));

    case 'menu': {
        $prods = Database::fetchAll(
            "SELECT p.id, p.name AS nombre, COALESCE(c.name,'Sin categoría') AS categoria, c.sort_order AS cat_orden, lp.price AS precio
             FROM location_products lp JOIN products p ON p.id = lp.product_id AND p.active = 1
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE lp.location_id = ? AND lp.available = 1
             ORDER BY c.sort_order, c.name, lp.sort_order, p.name", [$ubi]);
        // grupos de modificadores por producto (tolerante: si faltan tablas, queda sin modificadores)
        foreach ($prods as &$pr) {
            try {
                $pr['grupos'] = Database::fetchAll(
                    "SELECT g.id, g.nombre, g.tipo, g.max_opciones, g.requerido FROM grupos_modificadores g
                     JOIN product_modifier_groups pmg ON pmg.grupo_id = g.id
                     WHERE pmg.product_id = ? AND g.activo = 1 ORDER BY g.orden, g.id", [(int)$pr['id']]);
                foreach ($pr['grupos'] as &$g) {
                    $g['opciones'] = Database::fetchAll(
                        "SELECT id, nombre, precio_adicional AS precio FROM modificadores WHERE grupo_id = ? AND activo = 1 ORDER BY orden, id", [(int)$g['id']]);
                }
                unset($g);
            } catch (\Throwable $e) { $pr['grupos'] = []; }
        }
        unset($pr);
        $cats = [];
        foreach ($prods as $p) { $cats[$p['categoria']] = true; }
        mout(['ok' => true, 'categorias' => array_keys($cats), 'productos' => $prods]);
    }

    case 'abrir_cuenta':
        geoGate($ubi);
        $mesaId = cleanInt($_POST['mesa_id'] ?? 0);
        $m = Database::fetch("SELECT id FROM mesas WHERE id = ? AND ubicacion_id = ? AND activa = 1", [$mesaId, $ubi]);
        if (!$m) mout(['ok' => false, 'error' => 'mesa inválida']);
        mout(['ok' => true, 'cuenta_id' => cuentaAbrir($mesaId, $ubi, mozoEmp(), cleanInt($_POST['num_comensales'] ?? 0))]);

    case 'cuenta': {
        $cid = cleanInt($_GET['cuenta_id'] ?? 0);
        $d = cuentaDetalle($cid, $ubi);
        if (!$d) mout(['ok' => false, 'error' => 'cuenta no encontrada']);
        mout(['ok' => true, 'cuenta' => $d]);
    }

    case 'enviar_comanda':
        geoGate($ubi);
        $cid = cleanInt($_POST['cuenta_id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true) ?: [];
        mout(comandaEnviar($cid, $items, mozoEmp(), $ubi));

    case 'anular':
        geoGate($ubi);
        $cid = cleanInt($_POST['cuenta_id'] ?? 0);
        $pid = cleanInt($_POST['pedido_id'] ?? 0);
        $idx = ($_POST['item_idx'] ?? '') === '' ? null : cleanInt($_POST['item_idx']);
        mout(cuentaAnular($cid, $pid, $idx, clean($_POST['motivo'] ?? ''), mozoEmp(), $ubi));

    case 'cerrar_cuenta_vacia':
        geoGate($ubi);
        $cid = cleanInt($_POST['cuenta_id'] ?? 0);
        $n = (int)(Database::fetch("SELECT COUNT(*) n FROM pedidos WHERE cuenta_id = ? AND estado <> 'cancelado'", [$cid])['n'] ?? 0);
        if ($n > 0) mout(['ok' => false, 'error' => 'la cuenta tiene comandas']);
        Database::execute("UPDATE cuentas SET estado = 'cancelada', cerrada_at = NOW() WHERE id = ? AND ubicacion_id = ? AND estado = 'abierta'", [$cid, $ubi]);
        mout(['ok' => true]);

    default:
        http_response_code(400);
        mout(['ok' => false, 'error' => 'acción inválida']);
}
