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

/** ¿Existe ya el módulo de inventario en la BD? (para mensajes "aplica el SQL"). */
function inventarioListo(): bool
{
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'insumos'"
        );
    } catch (Exception $e) { return false; }
}
