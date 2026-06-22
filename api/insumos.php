<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/inventario.php';
header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (!can('inv_recetas') && !can('inv_insumos') && !can('modifiers') && !can('inv_compras')) { echo json_encode(['ok'=>false,'error'=>'Sin permisos']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'buscar') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok'=>true,'items'=>[]]); exit; }
    $rows = Database::fetchAll(
        "SELECT id, nombre, unidad, tipo, costo_unitario FROM insumos WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 12",
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
    $exist = Database::fetch("SELECT id, nombre, unidad, tipo, costo_unitario FROM insumos WHERE activo=1 AND LOWER(nombre)=LOWER(?) LIMIT 1", [$nombre]);
    if ($exist) { echo json_encode(['ok'=>true, 'insumo'=>$exist, 'reusado'=>true]); exit; }
    $id = Database::insert("INSERT INTO insumos (nombre,unidad,costo_unitario,tipo,activo) VALUES (?,?,?,?,1)", [$nombre,$unidad,$costo,$tipo]);
    echo json_encode(['ok'=>true, 'insumo'=>['id'=>$id,'nombre'=>$nombre,'unidad'=>$unidad,'tipo'=>$tipo,'costo_unitario'=>$costo]]);
    exit;
}

if ($action === 'receta_mod_get') {
    $mid = cleanInt($_GET['modificador_id'] ?? 0);
    $rows = Database::fetchAll(
        "SELECT rm.insumo_id, rm.cantidad, i.nombre, i.unidad, i.costo_unitario
           FROM receta_modificadores rm JOIN insumos i ON i.id = rm.insumo_id
          WHERE rm.modificador_id = ? ORDER BY i.nombre",
        [$mid]
    );
    echo json_encode(['ok'=>true, 'items'=>$rows]);
    exit;
}

if ($action === 'receta_mod_save') {
    verifyCsrf();
    $mid = cleanInt($_POST['modificador_id'] ?? 0);
    if ($mid <= 0 || !Database::fetch("SELECT id FROM modificadores WHERE id = ?", [$mid])) {
        echo json_encode(['ok'=>false,'error'=>'Modificador inválido']); exit;
    }
    $ins  = $_POST['insumo_id'] ?? [];
    $cant = $_POST['cantidad'] ?? [];
    Database::execute("DELETE FROM receta_modificadores WHERE modificador_id = ?", [$mid]);
    $seen = [];
    foreach ($ins as $idx => $iid) {
        $iid = (int)$iid; $c = (float)($cant[$idx] ?? 0);
        if ($iid <= 0 || $c <= 0 || isset($seen[$iid])) continue;
        $seen[$iid] = true;
        Database::insert("INSERT INTO receta_modificadores (modificador_id,insumo_id,cantidad) VALUES (?,?,?)", [$mid, $iid, $c]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'componentes_buscar') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok'=>true,'items'=>[]]); exit; }
    $items = [];
    foreach (Database::fetchAll(
        "SELECT id, nombre, unidad, costo_unitario FROM insumos WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 12",
        ['%' . $q . '%']
    ) as $i) {
        $items[] = ['tipo'=>'insumo','id'=>(int)$i['id'],'nombre'=>$i['nombre'],'unidad'=>$i['unidad'],'costo'=>(float)$i['costo_unitario']];
    }
    if (subrecetasListo()) {
        foreach (Database::fetchAll(
            "SELECT id, nombre, unidad FROM subrecetas WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 12",
            ['%' . $q . '%']
        ) as $s) {
            $items[] = ['tipo'=>'subreceta','id'=>(int)$s['id'],'nombre'=>$s['nombre'],'unidad'=>$s['unidad'],'costo'=>subrecetaCostoUM((int)$s['id'])];
        }
    }
    echo json_encode(['ok'=>true, 'items'=>$items]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Acción inválida']);
