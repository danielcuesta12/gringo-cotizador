-- ============================================================
-- Fase D — KDS: columnas extra en `pedidos`
-- Ejecuta esto SOLO si ya aplicaste install/pedidos.sql antes.
-- (Si aún no creaste la tabla, install/pedidos.sql ya las incluye.)
-- ============================================================

-- origen del pedido (carta de venta o POS de salón — fase E)
ALTER TABLE `pedidos`
  ADD COLUMN `origen` ENUM('carta','pos') NOT NULL DEFAULT 'carta' AFTER `izipay_order_id`;

-- momento en que se marcó listo/cancelado (para el historial del KDS)
ALTER TABLE `pedidos`
  ADD COLUMN `completado_at` DATETIME NULL AFTER `aceptado_at`;
