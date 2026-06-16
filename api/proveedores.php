<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (!can('inv_compras')) { echo json_encode(['ok'=>false,'error'=>'Sin permisos']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'buscar') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok'=>true,'items'=>[]]); exit; }
    $rows = Database::fetchAll("SELECT id, nombre FROM proveedores WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 12", ['%' . $q . '%']);
    echo json_encode(['ok'=>true, 'items'=>$rows]);
    exit;
}

if ($action === 'crear') {
    verifyCsrf();
    $nombre = clean($_POST['nombre'] ?? '');
    $tel    = clean($_POST['telefono'] ?? '');
    if ($nombre === '') { echo json_encode(['ok'=>false,'error'=>'Falta el nombre']); exit; }
    $exist = Database::fetch("SELECT id, nombre FROM proveedores WHERE activo=1 AND LOWER(nombre)=LOWER(?) LIMIT 1", [$nombre]);
    if ($exist) { echo json_encode(['ok'=>true, 'proveedor'=>$exist, 'reusado'=>true]); exit; }
    $id = Database::insert("INSERT INTO proveedores (nombre,telefono,activo) VALUES (?,?,1)", [$nombre, $tel ?: null]);
    echo json_encode(['ok'=>true, 'proveedor'=>['id'=>$id,'nombre'=>$nombre]]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Acción inválida']);
