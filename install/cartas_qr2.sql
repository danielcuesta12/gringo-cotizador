-- ============================================================
-- Migración: segundo QR (link personalizado) al pie del banner del Generador
-- Aplicar UNA vez en instalaciones que ya tienen la tabla `cartas`.
-- (Si alguna columna ya existe, MySQL dará error inofensivo: ignóralo.)
-- ============================================================
ALTER TABLE `cartas`
  ADD COLUMN `qr2_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `qr2_url`     VARCHAR(500) NOT NULL DEFAULT '',
  ADD COLUMN `qr2_src`     VARCHAR(80)  NOT NULL DEFAULT '',
  ADD COLUMN `qr2_label`   VARCHAR(80)  NOT NULL DEFAULT '';
