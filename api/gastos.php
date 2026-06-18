<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/inventario.php';
require_once __DIR__ . '/../includes/gastos.php';

requireLogin();
if (!can('gastos')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
header('Content-Type: application/json; charset=utf-8');

$action  = $_GET['action'] ?? $_POST['action'] ?? '';
$writes  = ['crear_categoria', 'crear_subcategoria', 'crear_insumo', 'crear_proveedor'];
if (in_array($action, $writes, true)) verifyCsrf();

function jout($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

switch ($action) {
    case 'buscar_categorias':
        jout(['ok' => true, 'items' => gastoCategorias(trim((string)($_GET['q'] ?? '')))]);

    case 'buscar_subcategorias':
        jout(['ok' => true, 'items' => gastoSubcategorias(cleanInt($_GET['categoria_id'] ?? 0), trim((string)($_GET['q'] ?? '')))]);

    case 'buscar_insumos':
        if (!inventarioListo()) jout(['ok' => true, 'items' => []]);
        $q = trim((string)($_GET['q'] ?? ''));
        $rows = $q !== ''
            ? Database::fetchAll("SELECT id, nombre FROM insumos WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 30", ['%' . $q . '%'])
            : Database::fetchAll("SELECT id, nombre FROM insumos WHERE activo=1 ORDER BY nombre LIMIT 30");
        jout(['ok' => true, 'items' => $rows]);

    case 'buscar_proveedores':
        try {
            $q = trim((string)($_GET['q'] ?? ''));
            $rows = $q !== ''
                ? Database::fetchAll("SELECT id, nombre FROM proveedores WHERE nombre LIKE ? ORDER BY nombre LIMIT 30", ['%' . $q . '%'])
                : Database::fetchAll("SELECT id, nombre FROM proveedores ORDER BY nombre LIMIT 30");
        } catch (\Throwable $e) { $rows = []; }
        jout(['ok' => true, 'items' => $rows]);

    case 'crear_categoria':
        $item = gastoCrearCategoria(clean($_POST['nombre'] ?? ''));
        jout($item['id'] ? ['ok' => true, 'item' => $item] : ['ok' => false, 'error' => 'nombre vacío']);

    case 'crear_subcategoria':
        $item = gastoCrearSubcategoria(cleanInt($_POST['categoria_id'] ?? 0), clean($_POST['nombre'] ?? ''));
        jout($item['id'] ? ['ok' => true, 'item' => $item] : ['ok' => false, 'error' => 'falta categoría o nombre']);

    case 'crear_insumo':
        if (!inventarioListo()) jout(['ok' => false, 'error' => 'inventario no disponible']);
        $nombre = clean($_POST['nombre'] ?? '');
        if ($nombre === '') jout(['ok' => false, 'error' => 'nombre vacío']);
        $ex = Database::fetch("SELECT id, nombre FROM insumos WHERE nombre = ?", [$nombre]);
        if ($ex) jout(['ok' => true, 'item' => $ex]);
        $id = Database::insert("INSERT INTO insumos (nombre, unidad, costo_unitario, activo) VALUES (?, 'und', 0, 1)", [$nombre]);
        jout(['ok' => true, 'item' => ['id' => $id, 'nombre' => $nombre]]);

    case 'crear_proveedor':
        $nombre = clean($_POST['nombre'] ?? '');
        if ($nombre === '') jout(['ok' => false, 'error' => 'nombre vacío']);
        try {
            $ex = Database::fetch("SELECT id, nombre FROM proveedores WHERE nombre = ?", [$nombre]);
            if ($ex) jout(['ok' => true, 'item' => $ex]);
            $id = Database::insert("INSERT INTO proveedores (nombre) VALUES (?)", [$nombre]);
            jout(['ok' => true, 'item' => ['id' => $id, 'nombre' => $nombre]]);
        } catch (\Throwable $e) { jout(['ok' => false, 'error' => 'proveedores no disponible']); }

    default:
        http_response_code(400);
        jout(['ok' => false, 'error' => 'acción inválida']);
}
