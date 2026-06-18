<?php
/**
 * Gastos v2 — persistencia compartida (cabecera + líneas), búsqueda/creación
 * de categorías/subcategorías y enganche opcional con inventario.
 * Consumido por: admin/gastos/form.php, api/gastos.php, api/pos.php (cerrar_turno),
 * admin/inventory/evento_detalle.php.
 */
require_once __DIR__ . '/inventario.php';

/** ¿Existen las tablas de gastos v2? */
function gastosListo(): bool
{
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'gasto_items'"
        );
    } catch (\Throwable $e) { return false; }
}

/** Lista/busca categorías de gasto. */
function gastoCategorias(?string $q = null, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    if ($q !== null && $q !== '') {
        return Database::fetchAll(
            "SELECT id, nombre FROM gasto_categorias WHERE nombre LIKE ? ORDER BY nombre LIMIT $limit",
            ['%' . $q . '%']
        );
    }
    return Database::fetchAll("SELECT id, nombre FROM gasto_categorias ORDER BY nombre LIMIT $limit");
}

/** Lista/busca subcategorías dentro de una categoría. */
function gastoSubcategorias(int $catId, ?string $q = null, int $limit = 30): array
{
    if ($catId <= 0) return [];
    $limit = max(1, min(100, $limit));
    if ($q !== null && $q !== '') {
        return Database::fetchAll(
            "SELECT id, nombre FROM gasto_subcategorias WHERE categoria_id = ? AND nombre LIKE ? ORDER BY nombre LIMIT $limit",
            [$catId, '%' . $q . '%']
        );
    }
    return Database::fetchAll(
        "SELECT id, nombre FROM gasto_subcategorias WHERE categoria_id = ? ORDER BY nombre LIMIT $limit",
        [$catId]
    );
}

/** Crea (o recupera) una categoría por nombre. */
function gastoCrearCategoria(string $nombre): array
{
    $nombre = trim($nombre);
    if ($nombre === '') return ['id' => 0, 'nombre' => ''];
    Database::execute("INSERT IGNORE INTO gasto_categorias (nombre) VALUES (?)", [$nombre]);
    $r = Database::fetch("SELECT id, nombre FROM gasto_categorias WHERE nombre = ?", [$nombre]);
    return $r ?: ['id' => 0, 'nombre' => $nombre];
}

/** Crea (o recupera) una subcategoría dentro de una categoría. */
function gastoCrearSubcategoria(int $catId, string $nombre): array
{
    $nombre = trim($nombre);
    if ($catId <= 0 || $nombre === '') return ['id' => 0, 'nombre' => ''];
    Database::execute("INSERT IGNORE INTO gasto_subcategorias (categoria_id, nombre) VALUES (?,?)", [$catId, $nombre]);
    $r = Database::fetch("SELECT id, nombre FROM gasto_subcategorias WHERE categoria_id = ? AND nombre = ?", [$catId, $nombre]);
    return $r ?: ['id' => 0, 'nombre' => $nombre];
}

/** Líneas de un gasto con nombres legibles. */
function gastoItems(int $gastoId): array
{
    return Database::fetchAll(
        "SELECT gi.*, c.nombre AS cat_nombre, s.nombre AS sub_nombre, i.nombre AS insumo_nombre
         FROM gasto_items gi
         LEFT JOIN gasto_categorias c ON c.id = gi.categoria_id
         LEFT JOIN gasto_subcategorias s ON s.id = gi.subcategoria_id
         LEFT JOIN insumos i ON i.id = gi.insumo_id
         WHERE gi.gasto_id = ? ORDER BY gi.id",
        [$gastoId]
    );
}

/** Revierte (con un ajuste compensatorio) el inventario de todas las líneas de un gasto. */
function gastoRevertirInventario(int $gastoId): void
{
    if (!inventarioListo()) return;
    $ubi = (int)(Database::fetch("SELECT ubicacion_id FROM gastos WHERE id = ?", [$gastoId])['ubicacion_id'] ?? 0);
    foreach (Database::fetchAll("SELECT id, insumo_id, cantidad, inv_movimiento_id FROM gasto_items WHERE gasto_id = ? AND inv_movimiento_id IS NOT NULL", [$gastoId]) as $it) {
        $ins = (int)$it['insumo_id']; $cant = (float)$it['cantidad'];
        if ($ubi > 0 && $ins > 0 && $cant > 0) {
            invMovimiento($ubi, $ins, 'ajuste', -$cant, ['motivo' => 'Reversa gasto #' . $gastoId]);
        }
        Database::execute("UPDATE gasto_items SET inv_movimiento_id = NULL WHERE id = ?", [(int)$it['id']]);
    }
}

/** Aplica el enganche de inventario para una línea (compra). Devuelve el id del movimiento o 0. */
function gastoAplicarInventarioItem(int $ubicacionId, int $insumoId, float $cantidad, float $monto): int
{
    if (!inventarioListo() || $ubicacionId <= 0 || $insumoId <= 0 || $cantidad <= 0) return 0;
    $costoUnit = $cantidad > 0 ? $monto / $cantidad : 0;
    return invEntradaCompra($ubicacionId, $insumoId, $cantidad, $costoUnit, ['motivo' => 'Gasto']);
}

/**
 * Inserta/actualiza un gasto (cabecera + líneas). Recalcula el total de la cabecera
 * como la suma de las líneas y aplica el enganche de inventario en líneas con insumo+cantidad.
 * En edición: revierte el inventario previo, reemplaza líneas y reaplica (recompute fresco).
 */
function gastoGuardar(array $h, array $items, ?int $id = null): int
{
    $tipo     = in_array($h['tipo'] ?? '', ['empresa','prestamo'], true) ? $h['tipo'] : 'empresa';
    $concepto = trim((string)($h['concepto'] ?? ''));
    $ubiId    = !empty($h['ubicacion_id']) ? (int)$h['ubicacion_id'] : null;
    $provId   = !empty($h['proveedor_id']) ? (int)$h['proveedor_id'] : null;
    $fecha    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($h['fecha'] ?? '')) ? $h['fecha'] : date('Y-m-d');
    $tags     = ($h['tags'] ?? '') ?: null;
    $foto     = $h['foto'] ?? null;
    $nota     = ($h['nota'] ?? '') ?: null;
    $estado   = in_array($h['estado'] ?? '', ['pendiente','pagado'], true) ? $h['estado'] : ($tipo === 'empresa' ? 'pagado' : 'pendiente');
    $usuario  = (int)($h['usuario_id'] ?? (currentUser()['id'] ?? 0));
    $origen   = in_array($h['origen'] ?? '', ['manual','pos','evento'], true) ? $h['origen'] : 'manual';
    $turnoId  = !empty($h['turno_id'])  ? (int)$h['turno_id']  : null;
    $eventoId = !empty($h['evento_id']) ? (int)$h['evento_id'] : null;

    // Normaliza líneas (descarta las de monto<=0 salvo que tengan concepto)
    $clean = [];
    $total = 0.0;
    foreach ($items as $it) {
        $monto = round((float)($it['monto'] ?? 0), 2);
        $cat   = !empty($it['categoria_id'])    ? (int)$it['categoria_id']    : null;
        $sub   = !empty($it['subcategoria_id']) ? (int)$it['subcategoria_id'] : null;
        $ins   = !empty($it['insumo_id'])       ? (int)$it['insumo_id']       : null;
        $cant  = isset($it['cantidad']) && $it['cantidad'] !== '' ? (float)$it['cantidad'] : null;
        $conc  = trim((string)($it['concepto'] ?? ''));
        if ($monto <= 0 && $conc === '') continue;
        $clean[] = ['concepto' => ($conc ?: null), 'monto' => $monto, 'categoria_id' => $cat,
                    'subcategoria_id' => $sub, 'insumo_id' => $ins, 'cantidad' => $cant];
        $total += $monto;
    }
    if (!$clean) { $clean[] = ['concepto' => ($concepto ?: 'Gasto'), 'monto' => 0, 'categoria_id' => null,
                               'subcategoria_id' => null, 'insumo_id' => null, 'cantidad' => null]; }
    $total = round($total, 2);

    if ($id) {
        // Edición: revierte inventario previo y reemplaza líneas.
        gastoRevertirInventario($id);
        Database::execute("DELETE FROM gasto_items WHERE gasto_id = ?", [$id]);
        Database::execute(
            "UPDATE gastos SET tipo=?, concepto=?, monto=?, ubicacion_id=?, proveedor_id=?, fecha=?, tags=?, foto=?, nota=?, estado=? WHERE id=?",
            [$tipo, $concepto, $total, $ubiId, $provId, $fecha, $tags, $foto, $nota, $estado, $id]
        );
        $gid = $id;
    } else {
        $gid = Database::insert(
            "INSERT INTO gastos (tipo, concepto, monto, ubicacion_id, proveedor_id, usuario_id, fecha, tags, foto, nota, estado, origen, turno_id, evento_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$tipo, $concepto, $total, $ubiId, $provId, $usuario, $fecha, $tags, $foto, $nota, $estado, $origen, $turnoId, $eventoId]
        );
    }

    foreach ($clean as $it) {
        $movId = null;
        if ($ubiId && $it['insumo_id'] && $it['cantidad'] && $it['cantidad'] > 0) {
            $m = gastoAplicarInventarioItem($ubiId, (int)$it['insumo_id'], (float)$it['cantidad'], (float)$it['monto']);
            $movId = $m ?: null;
        }
        Database::execute(
            "INSERT INTO gasto_items (gasto_id, concepto, monto, categoria_id, subcategoria_id, insumo_id, cantidad, inv_movimiento_id)
             VALUES (?,?,?,?,?,?,?,?)",
            [$gid, $it['concepto'], $it['monto'], $it['categoria_id'], $it['subcategoria_id'], $it['insumo_id'], $it['cantidad'], $movId]
        );
    }
    return (int)$gid;
}

/** Elimina un gasto: revierte inventario, borra líneas, borra foto y cabecera. */
function gastoEliminar(int $id): void
{
    if ($id <= 0) return;
    gastoRevertirInventario($id);
    $g = Database::fetch("SELECT foto FROM gastos WHERE id = ?", [$id]);
    if ($g && !empty($g['foto']) && defined('UPLOAD_PATH') && is_file(UPLOAD_PATH . $g['foto'])) @unlink(UPLOAD_PATH . $g['foto']);
    Database::execute("DELETE FROM gasto_items WHERE gasto_id = ?", [$id]);
    Database::execute("DELETE FROM gastos WHERE id = ?", [$id]);
}

/** Mueve los evento_gastos legacy de un evento al registro global (idempotente: borra el origen). */
function gastoMigrarEventoLegacy(int $eventoId, int $usuarioId): void
{
    if ($eventoId <= 0) return;
    try {
        $rows = Database::fetchAll("SELECT * FROM evento_gastos WHERE evento_id = ?", [$eventoId]);
    } catch (\Throwable $e) { return; } // tabla legacy no existe
    foreach ($rows as $eg) {
        gastoGuardar(
            ['tipo' => 'empresa', 'concepto' => (string)($eg['descripcion'] ?? 'Gasto de evento'),
             'ubicacion_id' => null, 'fecha' => date('Y-m-d', strtotime((string)$eg['created_at'])),
             'estado' => 'pagado', 'usuario_id' => $usuarioId, 'origen' => 'evento', 'evento_id' => $eventoId],
            [['concepto' => (string)($eg['descripcion'] ?? ''), 'monto' => (float)$eg['monto'], 'categoria_id' => ($eg['categoria_id'] ?? null)]]
        );
        Database::execute("DELETE FROM evento_gastos WHERE id = ?", [(int)$eg['id']]);
    }
}
