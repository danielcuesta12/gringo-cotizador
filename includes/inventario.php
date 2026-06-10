<?php
// Helpers del módulo de inventario. Requiere config/database/helpers ya cargados.

/**
 * Aplica un movimiento de stock y actualiza insumo_stock.
 * $cantidad con signo: positivo entra, negativo sale.
 * Devuelve el id del movimiento (o 0 si falla silenciosamente).
 */
function invMovimiento(int $ubicacionId, int $insumoId, string $tipo, float $cantidad, array $opts = []): int
{
    if (!$insumoId || !$ubicacionId || $cantidad == 0) return 0;
    try {
        $id = Database::insert(
            "INSERT INTO inventario_movimientos (ubicacion_id,insumo_id,tipo,cantidad,costo_unitario,motivo,ref,pedido_id,user_id)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [
                $ubicacionId, $insumoId, $tipo, $cantidad,
                $opts['costo_unitario'] ?? null,
                $opts['motivo'] ?? null,
                $opts['ref'] ?? null,
                $opts['pedido_id'] ?? null,
                $opts['user_id'] ?? (currentUser()['id'] ?? null),
            ]
        );
        Database::execute(
            "INSERT INTO insumo_stock (insumo_id,ubicacion_id,stock) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock)",
            [$insumoId, $ubicacionId, $cantidad]
        );
        return $id;
    } catch (Exception $e) {
        return 0;
    }
}

/** Fija el stock mínimo (umbral de alerta) de un insumo en una ubicación. */
function invSetStockMin(int $ubicacionId, int $insumoId, float $min): void
{
    try {
        Database::execute(
            "INSERT INTO insumo_stock (insumo_id,ubicacion_id,stock_min) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE stock_min = VALUES(stock_min)",
            [$insumoId, $ubicacionId, max(0, $min)]
        );
    } catch (Exception $e) {}
}

/** Costo de la receta de un producto (suma de insumo×cantidad×costo). */
function recetaCosto(int $productId): float
{
    try {
        $r = Database::fetch(
            "SELECT COALESCE(SUM(r.cantidad * i.costo_unitario),0) c
             FROM recetas r JOIN insumos i ON i.id = r.insumo_id
             WHERE r.product_id = ?",
            [$productId]
        );
        return (float)($r['c'] ?? 0);
    } catch (Exception $e) { return 0.0; }
}

/**
 * Descuenta del stock los insumos de un pedido (explota la receta de cada ítem).
 * Idempotente: usa pedidos.stock_descontado para no descontar dos veces.
 * Tolerante: si faltan tablas/columna, no hace nada (no rompe el KDS).
 */
function descontarStockPedido(int $pedidoId): void
{
    try {
        $p = Database::fetch("SELECT id, ubicacion_id, items_json, stock_descontado FROM pedidos WHERE id = ?", [$pedidoId]);
        if (!$p || (int)($p['stock_descontado'] ?? 0) === 1) return;

        $ubi   = (int)$p['ubicacion_id'];
        $items = json_decode($p['items_json'] ?? '[]', true) ?: [];
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (float)($it['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            $receta = Database::fetchAll("SELECT insumo_id, cantidad FROM recetas WHERE product_id = ?", [$pid]);
            foreach ($receta as $r) {
                invMovimiento($ubi, (int)$r['insumo_id'], 'venta', -((float)$r['cantidad'] * $qty),
                    ['pedido_id' => $pedidoId, 'motivo' => 'Venta · pedido #' . str_pad((string)$pedidoId, 3, '0', STR_PAD_LEFT)]);
            }
        }
        Database::execute("UPDATE pedidos SET stock_descontado = 1 WHERE id = ?", [$pedidoId]);
    } catch (Exception $e) { /* tablas/columna no creadas: ignorar */ }
}

/**
 * Entrada de stock por compra: suma stock en la ubicación (movimiento 'compra')
 * y actualiza el costo unitario del insumo con costo PROMEDIO PONDERADO
 * (usando el stock global previo de ese insumo en todas las ubicaciones).
 */
function invEntradaCompra(int $ubicacionId, int $insumoId, float $cantidad, float $costoUnit, array $opts = []): void
{
    if ($insumoId <= 0 || $cantidad <= 0) return;
    try {
        $stockPrev = (float)(Database::fetch("SELECT COALESCE(SUM(stock),0) s FROM insumo_stock WHERE insumo_id = ?", [$insumoId])['s'] ?? 0);
        $costoPrev = (float)(Database::fetch("SELECT costo_unitario c FROM insumos WHERE id = ?", [$insumoId])['c'] ?? 0);
        $denom = $stockPrev + $cantidad;
        $nuevoCosto = $denom > 0 ? (($stockPrev * $costoPrev) + ($cantidad * $costoUnit)) / $denom : $costoUnit;

        invMovimiento($ubicacionId, $insumoId, 'compra', $cantidad, [
            'costo_unitario' => $costoUnit,
            'motivo'         => $opts['motivo'] ?? 'Compra',
            'ref'            => $opts['ref'] ?? null,
        ]);
        Database::execute("UPDATE insumos SET costo_unitario = ? WHERE id = ?", [round($nuevoCosto, 4), $insumoId]);
    } catch (Exception $e) {}
}

/** ¿Existe ya el módulo de inventario en la BD? (para mensajes "aplica el SQL"). */
function inventarioListo(): bool
{
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'insumos'"
        );
    } catch (Exception $e) { return false; }
}

/** ¿Existe ya el módulo de compras (Bloque C)? */
function comprasListo(): bool
{
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'compras'"
        );
    } catch (Exception $e) { return false; }
}
