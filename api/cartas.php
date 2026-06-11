<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');
if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Sin permisos']); exit; }

function jout($data) { echo json_encode($data); exit; }
$clampSize = fn($v, $def) => max(4.0, min(120.0, (float)(($v === '' || $v === null) ? $def : $v)));
$cleanFoto = function ($v) {
    $v = trim((string)$v);
    return ($v !== '' && strpos($v, '..') === false && preg_match('#^[A-Za-z0-9._/-]+$#', $v)) ? $v : '';
};

$action = clean($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

$writeActions = ['create','delete','save_meta','seccion_create','seccion_update','seccion_delete',
                 'seccion_reorder','item_create','item_update','item_delete','item_reorder',
                 'cargar_ubicacion','upload_foto'];
if (in_array($action, $writeActions, true)) {
    if (!$isPost) jout(['ok' => false, 'error' => 'Método no permitido']);
    verifyCsrf();
}

switch ($action) {

case 'list':
    $rows = Database::fetchAll(
        "SELECT c.id, c.nombre, c.tema, c.updated_at,
                (SELECT COUNT(*) FROM carta_items i WHERE i.carta_id = c.id) AS item_count
         FROM cartas c ORDER BY c.updated_at DESC, c.id DESC");
    jout(['ok' => true, 'data' => $rows]);

case 'get':
    $id = cleanInt($_GET['id'] ?? 0);
    $c  = Database::fetch("SELECT * FROM cartas WHERE id = ?", [$id]);
    if (!$c) jout(['ok' => false, 'error' => 'Carta no encontrada']);
    $secs = Database::fetchAll("SELECT * FROM carta_secciones WHERE carta_id = ? ORDER BY sort_order, id", [$id]);
    foreach ($secs as &$s) {
        $s['items'] = Database::fetchAll("SELECT * FROM carta_items WHERE seccion_id = ? ORDER BY sort_order, id", [(int)$s['id']]);
    }
    unset($s);
    jout(['ok' => true, 'carta' => $c, 'secciones' => $secs]);

case 'create':
    $nombre = clean($_POST['nombre'] ?? '') ?: 'Carta sin nombre';
    $newId  = Database::insert("INSERT INTO cartas (nombre) VALUES (?)", [$nombre]);
    jout(['ok' => true, 'id' => $newId]);

case 'delete':
    Database::execute("DELETE FROM cartas WHERE id = ?", [cleanInt($_POST['id'] ?? 0)]);
    jout(['ok' => true]);

case 'save_meta':
    $id     = cleanInt($_POST['id'] ?? 0);
    $nombre = clean($_POST['nombre'] ?? '') ?: 'Carta sin nombre';
    $tema   = ($_POST['tema'] ?? 'noche') === 'dia' ? 'dia' : 'noche';
    $ancho  = max(100, min(2000, cleanInt($_POST['ancho_mm'] ?? 420)));
    $qrEnabled = !empty($_POST['qr_enabled']) ? 1 : 0;
    $qrSrc     = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['qr_src'] ?? ''));
    Database::execute(
        "UPDATE cartas SET nombre=?, tema=?, ancho_mm=?, size_section=?, size_name=?, size_price=?, size_desc=?, size_photo=?, size_header=?, qr_enabled=?, qr_src=? WHERE id=?",
        [$nombre, $tema, $ancho,
         $clampSize($_POST['size_section'] ?? '', 24),
         $clampSize($_POST['size_name'] ?? '', 18),
         $clampSize($_POST['size_price'] ?? '', 16),
         $clampSize($_POST['size_desc'] ?? '', 14),
         $clampSize($_POST['size_photo'] ?? '', 60),
         $clampSize($_POST['size_header'] ?? '', 55),
         $qrEnabled, $qrSrc,
         $id]);
    jout(['ok' => true]);

case 'seccion_create':
    $cartaId = cleanInt($_POST['carta_id'] ?? 0);
    $nombre  = clean($_POST['nombre'] ?? '') ?: 'Sección';
    $ord     = (int) (Database::fetch("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM carta_secciones WHERE carta_id=?", [$cartaId])['n'] ?? 1);
    $sid     = Database::insert("INSERT INTO carta_secciones (carta_id, nombre, sort_order) VALUES (?,?,?)", [$cartaId, $nombre, $ord]);
    jout(['ok' => true, 'id' => $sid]);

case 'seccion_update':
    $sid    = cleanInt($_POST['id'] ?? 0);
    $nombre = clean($_POST['nombre'] ?? '') ?: 'Sección';
    $cols   = (cleanInt($_POST['columnas'] ?? 1) === 2) ? 2 : 1;
    Database::execute("UPDATE carta_secciones SET nombre=?, columnas=? WHERE id=?", [$nombre, $cols, $sid]);
    jout(['ok' => true]);

case 'seccion_delete':
    Database::execute("DELETE FROM carta_secciones WHERE id=?", [cleanInt($_POST['id'] ?? 0)]);
    jout(['ok' => true]);

case 'seccion_reorder':
    $cartaId = cleanInt($_POST['carta_id'] ?? 0);
    $ids = $_POST['ids'] ?? [];
    if ($cartaId && is_array($ids)) { $o = 1; foreach ($ids as $sid) { Database::execute("UPDATE carta_secciones SET sort_order=? WHERE id=? AND carta_id=?", [$o++, cleanInt($sid), $cartaId]); } }
    jout(['ok' => true]);

case 'item_create':
    $cartaId = cleanInt($_POST['carta_id'] ?? 0);
    $secId   = cleanInt($_POST['seccion_id'] ?? 0);
    $nombre  = clean($_POST['nombre'] ?? '') ?: 'Ítem';
    $desc    = clean($_POST['descripcion'] ?? '');
    $precio  = cleanFloat($_POST['precio'] ?? 0);
    $foto    = $cleanFoto($_POST['foto'] ?? '');
    $ord     = (int) (Database::fetch("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM carta_items WHERE seccion_id=?", [$secId])['n'] ?? 1);
    $iid     = Database::insert(
        "INSERT INTO carta_items (carta_id, seccion_id, nombre, descripcion, precio, foto, sort_order) VALUES (?,?,?,?,?,?,?)",
        [$cartaId, $secId, $nombre, $desc ?: null, $precio, $foto ?: null, $ord]);
    jout(['ok' => true, 'id' => $iid]);

case 'item_update':
    $iid    = cleanInt($_POST['id'] ?? 0);
    $nombre = clean($_POST['nombre'] ?? '') ?: 'Ítem';
    $desc   = clean($_POST['descripcion'] ?? '');
    $precio = cleanFloat($_POST['precio'] ?? 0);
    $foto   = $cleanFoto($_POST['foto'] ?? '');
    if (isset($_POST['seccion_id'])) {
        $secId = cleanInt($_POST['seccion_id']);
        Database::execute("UPDATE carta_items SET nombre=?, descripcion=?, precio=?, foto=?, seccion_id=? WHERE id=?",
            [$nombre, $desc ?: null, $precio, $foto ?: null, $secId, $iid]);
    } else {
        Database::execute("UPDATE carta_items SET nombre=?, descripcion=?, precio=?, foto=? WHERE id=?",
            [$nombre, $desc ?: null, $precio, $foto ?: null, $iid]);
    }
    jout(['ok' => true]);

case 'item_delete':
    Database::execute("DELETE FROM carta_items WHERE id=?", [cleanInt($_POST['id'] ?? 0)]);
    jout(['ok' => true]);

case 'item_reorder':
    $cartaId = cleanInt($_POST['carta_id'] ?? 0);
    $ids     = $_POST['ids'] ?? [];
    $secId   = cleanInt($_POST['seccion_id'] ?? 0);
    if ($cartaId && $secId && is_array($ids)) { $o = 1; foreach ($ids as $iid) { Database::execute("UPDATE carta_items SET sort_order=?, seccion_id=? WHERE id=? AND carta_id=?", [$o++, $secId, cleanInt($iid), $cartaId]); } }
    jout(['ok' => true]);

case 'cargar_ubicacion':
    $cartaId = cleanInt($_POST['carta_id'] ?? 0);
    $ubiId   = cleanInt($_POST['ubicacion_id'] ?? 0);
    if (!$cartaId || !$ubiId) jout(['ok' => false, 'error' => 'Datos incompletos']);
    $rows = Database::fetchAll(
        "SELECT c.name AS cat_name, c.sort_order AS cat_order,
                p.name AS pname, p.description AS pdesc, p.image AS pimg, lp.price AS price
         FROM location_products lp
         JOIN products p ON p.id = lp.product_id AND p.active = 1
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE lp.location_id = ? AND lp.available = 1
         ORDER BY c.sort_order, c.name, lp.sort_order, p.sort_order, p.name",
        [$ubiId]);
    $pdo = Database::getInstance();
    $pdo->beginTransaction();
    try {
        $baseOrd  = (int) (Database::fetch("SELECT COALESCE(MAX(sort_order),0) AS n FROM carta_secciones WHERE carta_id=?", [$cartaId])['n'] ?? 0);
        $secByCat = [];
        $itemOrd  = [];
        $secOrd   = $baseOrd;
        foreach ($rows as $r) {
            $cat = $r['cat_name'] ?: 'Carta';
            if (!isset($secByCat[$cat])) {
                $secOrd++;
                $secByCat[$cat] = Database::insert("INSERT INTO carta_secciones (carta_id, nombre, sort_order) VALUES (?,?,?)", [$cartaId, clean($cat), $secOrd]);
                $itemOrd[$secByCat[$cat]] = 0;
            }
            $sid = $secByCat[$cat];
            $itemOrd[$sid]++;
            $pdesc = clean($r['pdesc'] ?? '');
            Database::execute(
                "INSERT INTO carta_items (carta_id, seccion_id, nombre, descripcion, precio, foto, sort_order) VALUES (?,?,?,?,?,?,?)",
                [$cartaId, $sid, clean($r['pname']), ($pdesc !== '' ? $pdesc : null), (float)$r['price'], ($r['pimg'] ?: null), $itemOrd[$sid]]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        jout(['ok' => false, 'error' => 'No se pudo cargar la ubicación']);
    }
    jout(['ok' => true, 'added' => count($rows)]);

case 'upload_foto':
    if (empty($_FILES['foto']['name'])) jout(['ok' => false, 'error' => 'Sin archivo']);
    $up = uploadImage($_FILES['foto'], 'carta');
    if (!$up) jout(['ok' => false, 'error' => 'Error al subir. Usa JPG, PNG o WebP (máx. 2MB).']);
    jout(['ok' => true, 'foto' => $up]);

default:
    jout(['ok' => false, 'error' => 'Acción no válida']);
}
