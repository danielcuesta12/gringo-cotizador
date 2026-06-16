<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (!can('inv_recetas') && !can('inv_insumos')) { echo json_encode(['ok'=>false,'error'=>'Sin permisos']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'buscar') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok'=>true,'items'=>[]]); exit; }
    $rows = Database::fetchAll(
        "SELECT id, nombre, unidad, tipo FROM insumos WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 12",
        ['%' . $q . '%']
    );
    echo json_encode(['ok'=>true, 'items'=>$rows]);
    exit;
}

if ($action === 'crear') {
    verifyCsrf();
    $nombre = clean($_POST['nombre'] ?? '');
    $unidad = clean($_POST['unidad'] ?? 'unidad') ?: 'unidad';
    $tipo   = in_array($_POST['tipo'] ?? '', ['ingrediente','descartable']) ? $_POST['tipo'] : 'ingrediente';
    $costo  = max(0, cleanFloat($_POST['costo_unitario'] ?? 0));
    if ($nombre === '') { echo json_encode(['ok'=>false,'error'=>'Falta el nombre']); exit; }
    $exist = Database::fetch("SELECT id, nombre, unidad, tipo FROM insumos WHERE activo=1 AND LOWER(nombre)=LOWER(?) LIMIT 1", [$nombre]);
    if ($exist) { echo json_encode(['ok'=>true, 'insumo'=>$exist, 'reusado'=>true]); exit; }
    $id = Database::insert("INSERT INTO insumos (nombre,unidad,costo_unitario,tipo,activo) VALUES (?,?,?,?,1)", [$nombre,$unidad,$costo,$tipo]);
    echo json_encode(['ok'=>true, 'insumo'=>['id'=>$id,'nombre'=>$nombre,'unidad'=>$unidad,'tipo'=>$tipo]]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Acción inválida']);
