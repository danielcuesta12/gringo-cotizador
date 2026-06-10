-- ============================================================
-- Módulo de inventario — Bloque B
-- Marca si a un pedido ya se le descontó el stock (idempotencia del KDS).
-- ============================================================
ALTER TABLE `pedidos`
  ADD COLUMN `stock_descontado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `completado_at`;
