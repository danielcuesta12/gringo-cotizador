<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (!can('products') && !can('categories')) { echo json_encode(['ok'=>false,'error'=>'Sin permisos']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'crear') {
    verifyCsrf();
    $name = clean($_POST['name'] ?? '');
    if ($name === '') { echo json_encode(['ok'=>false,'error'=>'Falta el nombre']); exit; }
    $exist = Database::fetch("SELECT id, name FROM categories WHERE active=1 AND LOWER(name)=LOWER(?) LIMIT 1", [$name]);
    if ($exist) { echo json_encode(['ok'=>true, 'categoria'=>$exist, 'reusado'=>true]); exit; }
    $id = Database::insert("INSERT INTO categories (name,active) VALUES (?,1)", [$name]);
    echo json_encode(['ok'=>true, 'categoria'=>['id'=>$id,'name'=>$name]]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Acción inválida']);
