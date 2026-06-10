-- ============================================================
-- Módulo de inventario — Bloque A (base + costeo)
-- ============================================================

-- Catálogo de insumos (ingredientes / materiales)
CREATE TABLE IF NOT EXISTS `insumos` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`         VARCHAR(120) NOT NULL,
  `unidad`         VARCHAR(20)  NOT NULL DEFAULT 'unidad',   -- g, ml, unidad, …
  `costo_unitario` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,    -- costo por unidad base
  `activo`         TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock y mínimo por ubicación (cada local su almacén)
CREATE TABLE IF NOT EXISTS `insumo_stock` (
  `insumo_id`    INT UNSIGNED NOT NULL,
  `ubicacion_id` INT UNSIGNED NOT NULL,
  `stock`        DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `stock_min`    DECIMAL(12,3) NOT NULL DEFAULT 0.000,       -- umbral de alerta
  PRIMARY KEY (`insumo_id`, `ubicacion_id`),
  INDEX `idx_is_ubi` (`ubicacion_id`),
  CONSTRAINT `fk_is_insumo` FOREIGN KEY (`insumo_id`)    REFERENCES `insumos`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_is_ubi`    FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Receta / ficha técnica: insumos que consume cada producto (por unidad vendida)
CREATE TABLE IF NOT EXISTS `recetas` (
  `product_id` INT UNSIGNED NOT NULL,
  `insumo_id`  INT UNSIGNED NOT NULL,
  `cantidad`   DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`product_id`, `insumo_id`),
  INDEX `idx_rec_insumo` (`insumo_id`),
  CONSTRAINT `fk_rec_prod`   FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rec_insumo` FOREIGN KEY (`insumo_id`)  REFERENCES `insumos`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro mayor de movimientos de stock (auditable). cantidad con signo: + entra, - sale.
CREATE TABLE IF NOT EXISTS `inventario_movimientos` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ubicacion_id`   INT UNSIGNED NOT NULL,
  `insumo_id`      INT UNSIGNED NOT NULL,
  `tipo`           ENUM('ingreso','ajuste','merma','venta','evento','compra','transferencia') NOT NULL,
  `cantidad`       DECIMAL(12,3) NOT NULL,                   -- + entra / - sale
  `costo_unitario` DECIMAL(10,4) NULL,                       -- costo al momento (entradas)
  `motivo`         VARCHAR(255) NULL,
  `ref`            VARCHAR(60)  NULL,                         -- nº de evento, etc.
  `pedido_id`      INT UNSIGNED NULL,                         -- si vino de un pedido
  `user_id`        INT UNSIGNED NULL,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_mov_ubi`    (`ubicacion_id`),
  INDEX `idx_mov_insumo` (`insumo_id`),
  INDEX `idx_mov_tipo`   (`tipo`),
  INDEX `idx_mov_fecha`  (`created_at`),
  CONSTRAINT `fk_mov_insumo` FOREIGN KEY (`insumo_id`)    REFERENCES `insumos`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_mov_ubi`    FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
