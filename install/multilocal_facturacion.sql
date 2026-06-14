-- Facturación multi-local — Fase 1.
-- Cada ubicación maneja su propia serie y correlativo de comprobantes
-- (boleta/factura) para que dos POS nunca colisionen correlativos en SUNAT.
-- Además, una referencia/zona corta que ve el cliente en el selector de tienda.
ALTER TABLE `ubicaciones`
  ADD COLUMN IF NOT EXISTS `serie_boleta`  VARCHAR(10)  NULL                AFTER `instagram`,
  ADD COLUMN IF NOT EXISTS `serie_factura` VARCHAR(10)  NULL                AFTER `serie_boleta`,
  ADD COLUMN IF NOT EXISTS `num_boleta`    INT UNSIGNED NOT NULL DEFAULT 1  AFTER `serie_factura`,
  ADD COLUMN IF NOT EXISTS `num_factura`   INT UNSIGNED NOT NULL DEFAULT 1  AFTER `num_boleta`,
  ADD COLUMN IF NOT EXISTS `referencia`    VARCHAR(120) NULL                AFTER `num_factura`;
