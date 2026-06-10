-- Horario e Instagram editables por ubicación
ALTER TABLE `ubicaciones`
  ADD COLUMN IF NOT EXISTS `hora_apertura` TINYINT UNSIGNED NOT NULL DEFAULT 18,
  ADD COLUMN IF NOT EXISTS `hora_cierre`   TINYINT UNSIGNED NOT NULL DEFAULT 24,
  ADD COLUMN IF NOT EXISTS `instagram`     VARCHAR(100) NULL;
