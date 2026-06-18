-- 56_mesas.sql — Mesas POS Sub-build A: pisos, mesas, elementos del plano.
-- Idempotente. Aplicar en phpMyAdmin tras git pull.

CREATE TABLE IF NOT EXISTS `mesa_pisos` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ubicacion_id` INT UNSIGNED NOT NULL,
  `nombre`       VARCHAR(80) NOT NULL,
  `orden`        INT NOT NULL DEFAULT 0,
  `fondo_img`    VARCHAR(255) NULL,
  `ancho`        INT NOT NULL DEFAULT 1000,
  `alto`         INT NOT NULL DEFAULT 700,
  `activo`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mp_ubi` (`ubicacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mesas` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `piso_id`      INT UNSIGNED NOT NULL,
  `ubicacion_id` INT UNSIGNED NOT NULL,
  `numero`       VARCHAR(20) NOT NULL,
  `capacidad`    INT NOT NULL DEFAULT 4,
  `forma`        ENUM('cuadrada','redonda') NOT NULL DEFAULT 'cuadrada',
  `pos_x`        INT NOT NULL DEFAULT 0,
  `pos_y`        INT NOT NULL DEFAULT 0,
  `ancho`        INT NOT NULL DEFAULT 60,
  `alto`         INT NOT NULL DEFAULT 60,
  `activa`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mesas_piso` (`piso_id`),
  KEY `idx_mesas_ubi` (`ubicacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mesa_elementos` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `piso_id` INT UNSIGNED NOT NULL,
  `tipo`    ENUM('etiqueta','forma') NOT NULL,
  `texto`   VARCHAR(120) NULL,
  `pos_x`   INT NOT NULL DEFAULT 0,
  `pos_y`   INT NOT NULL DEFAULT 0,
  `ancho`   INT NOT NULL DEFAULT 100,
  `alto`    INT NOT NULL DEFAULT 30,
  PRIMARY KEY (`id`),
  KEY `idx_melem_piso` (`piso_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
