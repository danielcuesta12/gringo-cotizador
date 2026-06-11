-- ============================================================
-- Migración: QR al pie del banner del Generador de cartas PDF
-- Aplicar UNA vez en instalaciones que ya tienen la tabla `cartas`.
-- (Si la columna ya existe, MySQL dará error inofensivo: ignóralo.)
-- ============================================================
ALTER TABLE `cartas`
  ADD COLUMN `qr_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `qr_src` VARCHAR(80) NOT NULL DEFAULT '';
