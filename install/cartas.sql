-- ============================================================
-- Generador de cartas PDF — cartas a medida (independiente de products/ubicaciones)
-- ============================================================
CREATE TABLE IF NOT EXISTS `cartas` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`       VARCHAR(120) NOT NULL,
  `tema`         ENUM('noche','dia') NOT NULL DEFAULT 'noche',
  `ancho_mm`     SMALLINT UNSIGNED NOT NULL DEFAULT 420,
  `size_section` DECIMAL(4,1) NOT NULL DEFAULT 24.0,
  `size_name`    DECIMAL(4,1) NOT NULL DEFAULT 18.0,
  `size_price`   DECIMAL(4,1) NOT NULL DEFAULT 16.0,
  `size_desc`    DECIMAL(4,1) NOT NULL DEFAULT 14.0,
  `size_photo`   DECIMAL(4,1) NOT NULL DEFAULT 60.0,
  `size_header`  DECIMAL(4,1) NOT NULL DEFAULT 55.0,
  `qr_enabled`   TINYINT(1) NOT NULL DEFAULT 0,
  `qr_src`       VARCHAR(80) NOT NULL DEFAULT '',
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `carta_secciones` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `carta_id`   INT UNSIGNED NOT NULL,
  `nombre`     VARCHAR(120) NOT NULL,
  `columnas`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_secc_carta` (`carta_id`),
  CONSTRAINT `fk_secc_carta` FOREIGN KEY (`carta_id`) REFERENCES `cartas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `carta_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `carta_id`    INT UNSIGNED NOT NULL,
  `seccion_id`  INT UNSIGNED NOT NULL,
  `nombre`      VARCHAR(160) NOT NULL,
  `descripcion` VARCHAR(500) NULL,
  `precio`      DECIMAL(10,2) NOT NULL DEFAULT 0,
  `foto`        VARCHAR(255) NULL,
  `sort_order`  SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_item_carta` (`carta_id`),
  INDEX `idx_item_secc` (`seccion_id`),
  CONSTRAINT `fk_item_carta` FOREIGN KEY (`carta_id`) REFERENCES `cartas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_secc` FOREIGN KEY (`seccion_id`) REFERENCES `carta_secciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carta de ejemplo para validar el render (id 1). Borrable luego desde el admin.
INSERT IGNORE INTO `cartas` (`id`,`nombre`,`tema`) VALUES (1, 'Carta de ejemplo', 'noche');
INSERT IGNORE INTO `carta_secciones` (`id`,`carta_id`,`nombre`,`columnas`,`sort_order`) VALUES
  (1, 1, 'Smash Burgers', 1, 1),
  (2, 1, 'Bebidas', 2, 2);
INSERT IGNORE INTO `carta_items` (`carta_id`,`seccion_id`,`nombre`,`descripcion`,`precio`,`foto`,`sort_order`) VALUES
  (1, 1, 'Gringo Smash', 'Smash 110gr, cheddar, pickles, tocino, salsa gringo', 28.90, NULL, 1),
  (1, 1, 'BBQ Smash',    'Smash 110gr, emmental, onion strings, BBQ, tocino',    28.90, NULL, 2),
  (1, 2, 'Pink Lemonade', NULL, 6.00, NULL, 1),
  (1, 2, 'Limonada Clásica', NULL, 5.00, NULL, 2);
