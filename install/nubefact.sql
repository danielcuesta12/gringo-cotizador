-- ============================================================
-- NubeFact — Comprobantes electrónicos (boletas/facturas SUNAT)
-- Columnas de comprobante en pedidos
-- ============================================================
ALTER TABLE `pedidos`
  ADD COLUMN `comprobante_serie`   VARCHAR(10)  NULL,
  ADD COLUMN `comprobante_numero`  INT UNSIGNED NULL,
  ADD COLUMN `comprobante_estado`  ENUM('no_aplica','pendiente','emitido','error') NOT NULL DEFAULT 'no_aplica',
  ADD COLUMN `comprobante_pdf`     VARCHAR(500) NULL,
  ADD COLUMN `comprobante_xml`     VARCHAR(500) NULL,
  ADD COLUMN `comprobante_cdr`     VARCHAR(500) NULL,
  ADD COLUMN `comprobante_hash`    VARCHAR(120) NULL,
  ADD COLUMN `comprobante_qr`      TEXT NULL,
  ADD COLUMN `comprobante_error`   TEXT NULL,
  ADD COLUMN `comprobante_emitido_at` DATETIME NULL;
