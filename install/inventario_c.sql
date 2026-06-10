-- ============================================================
-- Módulo de inventario — Bloque C (compras + proveedores)
-- ============================================================

CREATE TABLE IF NOT EXISTS `proveedores` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`     VARCHAR(150) NOT NULL,
  `contacto`   VARCHAR(150) NULL,
  `telefono`   VARCHAR(40)  NULL,
  `activo`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compras` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proveedor_id` INT UNSIGNED NULL,
  `ubicacion_id` INT UNSIGNED NOT NULL,           -- almacén que recibe
  `fecha`        DATE         NOT NULL,
  `total`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `nota`         VARCHAR(255) NULL,
  `user_id`      INT UNSIGNED NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_compra_prov` (`proveedor_id`),
  INDEX `idx_compra_ubi`  (`ubicacion_id`),
  INDEX `idx_compra_fecha` (`fecha`),
  CONSTRAINT `fk_compra_prov` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_compra_ubi`  FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compra_items` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `compra_id`      INT UNSIGNED NOT NULL,
  `insumo_id`      INT UNSIGNED NOT NULL,
  `cantidad`       DECIMAL(12,3) NOT NULL,
  `costo_unitario` DECIMAL(10,4) NOT NULL,
  `subtotal`       DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_ci_compra` (`compra_id`),
  CONSTRAINT `fk_ci_compra` FOREIGN KEY (`compra_id`) REFERENCES `compras`(`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_ci_insumo` FOREIGN KEY (`insumo_id`) REFERENCES `insumos`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
