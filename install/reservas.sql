-- ============================================================
-- Reservas de mesa/cupo (formulario público + buzón admin)
-- ============================================================
CREATE TABLE IF NOT EXISTS `reservas` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`       VARCHAR(120) NOT NULL,
  `telefono`     VARCHAR(30)  NULL,
  `email`        VARCHAR(150) NULL,
  `fecha`        DATE         NOT NULL,
  `hora`         VARCHAR(10)  NULL,
  `num_personas` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `ubicacion_id` INT UNSIGNED NULL,
  `comentarios`  TEXT NULL,
  `estado`       ENUM('pendiente','confirmada','rechazada') NOT NULL DEFAULT 'pendiente',
  `ip_address`   VARCHAR(45) NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_res_estado` (`estado`),
  INDEX `idx_res_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
