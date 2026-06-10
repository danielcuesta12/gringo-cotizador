-- ============================================================
-- Adicionales / modificadores de productos
-- ============================================================
CREATE TABLE IF NOT EXISTS `grupos_modificadores` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`       VARCHAR(100) NOT NULL,
  `descripcion`  VARCHAR(255) NULL,
  `tipo`         ENUM('unico','multiple') NOT NULL DEFAULT 'unico',  -- unico=elige 1 (radio), multiple=varios (checkbox)
  `max_opciones` INT UNSIGNED NULL,                                  -- tope para 'multiple' (null=sin tope)
  `requerido`    TINYINT(1)   NOT NULL DEFAULT 0,
  `orden`        INT          NOT NULL DEFAULT 0,
  `activo`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `modificadores` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grupo_id`         INT UNSIGNED NOT NULL,
  `nombre`           VARCHAR(100) NOT NULL,
  `precio_adicional` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `orden`            INT          NOT NULL DEFAULT 0,
  `activo`           TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  INDEX `idx_mod_grupo` (`grupo_id`),
  CONSTRAINT `fk_mod_grupo` FOREIGN KEY (`grupo_id`) REFERENCES `grupos_modificadores`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué grupos de adicionales aplican a cada producto (catálogo compartido)
CREATE TABLE IF NOT EXISTS `product_modifier_groups` (
  `product_id` INT UNSIGNED NOT NULL,
  `grupo_id`   INT UNSIGNED NOT NULL,
  `orden`      INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`product_id`, `grupo_id`),
  CONSTRAINT `fk_pmg_prod`  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pmg_grupo` FOREIGN KEY (`grupo_id`)   REFERENCES `grupos_modificadores`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
