<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
if (!can('mesas')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$writes = ['crear_piso', 'renombrar_piso', 'eliminar_piso', 'guardar_piso', 'subir_fondo'];
if (in_array($action, $writes, true)) verifyCsrf();

function jout($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** Carga un piso completo (mesas + elementos) como arreglo asociativo. */
function pisoFull(int $pisoId): ?array {
    $p = Database::fetch("SELECT id, nombre, orden, fondo_img, ancho, alto FROM mesa_pisos WHERE id = ?", [$pisoId]);
    if (!$p) return null;
    $p['mesas'] = Database::fetchAll(
        "SELECT id, numero, capacidad, forma, pos_x, pos_y, ancho, alto FROM mesas WHERE piso_id = ? AND activa = 1 ORDER BY id", [$pisoId]);
    $p['elementos'] = Database::fetchAll(
        "SELECT id, tipo, texto, pos_x, pos_y, ancho, alto FROM mesa_elementos WHERE piso_id = ? ORDER BY id", [$pisoId]);
    return $p;
}

switch ($action) {

    case 'plano':
        $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
        $pisos = [];
        foreach (Database::fetchAll("SELECT id FROM mesa_pisos WHERE ubicacion_id = ? AND activo = 1 ORDER BY orden, id", [$ubi]) as $row) {
            $full = pisoFull((int)$row['id']);
            if ($full) $pisos[] = $full;
        }
        jout(['ok' => true, 'pisos' => $pisos]);

    case 'crear_piso':
        $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
        $nombre = clean($_POST['nombre'] ?? '') ?: 'Piso';
        if ($ubi <= 0) jout(['ok' => false, 'error' => 'ubicación inválida']);
        $orden = (int)(Database::fetch("SELECT COALESCE(MAX(orden),0)+1 n FROM mesa_pisos WHERE ubicacion_id = ?", [$ubi])['n'] ?? 1);
        $id = Database::insert("INSERT INTO mesa_pisos (ubicacion_id, nombre, orden) VALUES (?,?,?)", [$ubi, $nombre, $orden]);
        jout(['ok' => true, 'piso' => ['id' => $id, 'nombre' => $nombre, 'orden' => $orden, 'fondo_img' => null, 'ancho' => 1000, 'alto' => 700, 'mesas' => [], 'elementos' => []]]);

    case 'renombrar_piso':
        $pid = cleanInt($_POST['piso_id'] ?? 0);
        $nombre = clean($_POST['nombre'] ?? '');
        if ($pid && $nombre !== '') Database::execute("UPDATE mesa_pisos SET nombre = ? WHERE id = ?", [$nombre, $pid]);
        jout(['ok' => true]);

    case 'eliminar_piso':
        $pid = cleanInt($_POST['piso_id'] ?? 0);
        if ($pid) {
            Database::execute("DELETE FROM mesas WHERE piso_id = ?", [$pid]);
            Database::execute("DELETE FROM mesa_elementos WHERE piso_id = ?", [$pid]);
            Database::execute("DELETE FROM mesa_pisos WHERE id = ?", [$pid]);
        }
        jout(['ok' => true]);

    case 'guardar_piso':
        $pid = cleanInt($_POST['piso_id'] ?? 0);
        $piso = Database::fetch("SELECT id, ubicacion_id FROM mesa_pisos WHERE id = ?", [$pid]);
        if (!$piso) jout(['ok' => false, 'error' => 'piso no encontrado']);
        $ubi = (int)$piso['ubicacion_id'];
        $mesas = json_decode($_POST['mesas'] ?? '[]', true) ?: [];
        $elems = json_decode($_POST['elementos'] ?? '[]', true) ?: [];

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $idmap = [];
            // Mesas: upsert + recolectar ids vivos
            $keepM = [];
            foreach ($mesas as $m) {
                $forma = ($m['forma'] ?? 'cuadrada') === 'redonda' ? 'redonda' : 'cuadrada';
                $numero = substr(trim((string)($m['numero'] ?? '')), 0, 20) ?: '?';
                $cap = max(1, (int)($m['capacidad'] ?? 4));
                $x = (int)($m['pos_x'] ?? 0); $y = (int)($m['pos_y'] ?? 0);
                $w = max(20, (int)($m['ancho'] ?? 60)); $h = max(20, (int)($m['alto'] ?? 60));
                $mid = isset($m['id']) && (int)$m['id'] > 0 ? (int)$m['id'] : 0;
                if ($mid > 0) {
                    Database::execute("UPDATE mesas SET numero=?, capacidad=?, forma=?, pos_x=?, pos_y=?, ancho=?, alto=? WHERE id=? AND piso_id=?",
                        [$numero, $cap, $forma, $x, $y, $w, $h, $mid, $pid]);
                } else {
                    $mid = Database::insert("INSERT INTO mesas (piso_id, ubicacion_id, numero, capacidad, forma, pos_x, pos_y, ancho, alto) VALUES (?,?,?,?,?,?,?,?,?)",
                        [$pid, $ubi, $numero, $cap, $forma, $x, $y, $w, $h]);
                    if (isset($m['id'])) $idmap[(string)$m['id']] = $mid;
                }
                $keepM[] = $mid;
            }
            // Borrar mesas que ya no están
            $existM = Database::fetchAll("SELECT id FROM mesas WHERE piso_id = ?", [$pid]);
            foreach ($existM as $row) {
                if (!in_array((int)$row['id'], $keepM, true)) Database::execute("DELETE FROM mesas WHERE id = ?", [(int)$row['id']]);
            }
            // Elementos: mismo patrón
            $keepE = [];
            foreach ($elems as $e) {
                $tipo = ($e['tipo'] ?? 'etiqueta') === 'forma' ? 'forma' : 'etiqueta';
                $texto = substr(trim((string)($e['texto'] ?? '')), 0, 120) ?: null;
                $x = (int)($e['pos_x'] ?? 0); $y = (int)($e['pos_y'] ?? 0);
                $w = max(10, (int)($e['ancho'] ?? 100)); $h = max(8, (int)($e['alto'] ?? 30));
                $eid = isset($e['id']) && (int)$e['id'] > 0 ? (int)$e['id'] : 0;
                if ($eid > 0) {
                    Database::execute("UPDATE mesa_elementos SET tipo=?, texto=?, pos_x=?, pos_y=?, ancho=?, alto=? WHERE id=? AND piso_id=?",
                        [$tipo, $texto, $x, $y, $w, $h, $eid, $pid]);
                } else {
                    $eid = Database::insert("INSERT INTO mesa_elementos (piso_id, tipo, texto, pos_x, pos_y, ancho, alto) VALUES (?,?,?,?,?,?,?)",
                        [$pid, $tipo, $texto, $x, $y, $w, $h]);
                    if (isset($e['id'])) $idmap[(string)$e['id']] = $eid;
                }
                $keepE[] = $eid;
            }
            $existE = Database::fetchAll("SELECT id FROM mesa_elementos WHERE piso_id = ?", [$pid]);
            foreach ($existE as $row) {
                if (!in_array((int)$row['id'], $keepE, true)) Database::execute("DELETE FROM mesa_elementos WHERE id = ?", [(int)$row['id']]);
            }
            $pdo->commit();
            jout(['ok' => true, 'idmap' => $idmap]);
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            jout(['ok' => false, 'error' => 'no se pudo guardar']);
        }

    case 'subir_fondo':
        $pid = cleanInt($_POST['piso_id'] ?? 0);
        if (!$pid || empty($_FILES['fondo']['name'])) jout(['ok' => false, 'error' => 'falta archivo']);
        $up = uploadImage($_FILES['fondo'], 'planos');
        if (!$up) jout(['ok' => false, 'error' => 'no se pudo subir']);
        Database::execute("UPDATE mesa_pisos SET fondo_img = ? WHERE id = ?", [$up, $pid]);
        jout(['ok' => true, 'fondo_img' => $up]);

    default:
        http_response_code(400);
        jout(['ok' => false, 'error' => 'acción inválida']);
}
