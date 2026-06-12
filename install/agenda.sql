-- Agenda: eventos del calendario SIN cotización (solo disponibilidad, no suman a ventas)
CREATE TABLE IF NOT EXISTS `agenda` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fecha`      DATE         NOT NULL,
  `titulo`     VARCHAR(160) NOT NULL,
  `hora`       VARCHAR(10)  NULL,
  `lugar`      VARCHAR(255) NULL,
  `notas`      TEXT         NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_agenda_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
