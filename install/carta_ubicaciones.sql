-- ============================================================
-- Fase A — Base del sistema de cartas multi-ubicación
-- Aditivo: NO modifica tablas existentes. El catálogo compartido
-- sigue siendo products/categories; el precio y la disponibilidad
-- por ubicación viven en location_products.
-- ============================================================

-- Ubicaciones (locales / food truck). Cada una tiene su carta.
CREATE TABLE IF NOT EXISTS `ubicaciones` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`          VARCHAR(120) NOT NULL,
  `slug`            VARCHAR(60)  NOT NULL,               -- p.ej. 'burgerjoint', 'foodtruck'
  `descripcion`     VARCHAR(200) NULL,
  `color_header`    VARCHAR(20)  NOT NULL DEFAULT '#FCDA13',
  `sales_mode`      ENUM('menu','whatsapp','izipay') NOT NULL DEFAULT 'menu',
  `whatsapp_number` VARCHAR(30)  NULL,                   -- destino de pedidos si sales_mode='whatsapp'
  `direccion`       VARCHAR(255) NULL,
  `maps_url`        VARCHAR(500) NULL,
  `activa`          TINYINT(1)   NOT NULL DEFAULT 1,
  `es_principal`    TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`      SMALLINT     NOT NULL DEFAULT 0,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ubicaciones_slug` (`slug`),
  INDEX `idx_ubicaciones_activa` (`activa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relación ubicación ↔ producto: qué ítems ofrece cada ubicación,
-- con su PRECIO y DISPONIBILIDAD propios. La presencia de la fila
-- significa que el producto se ofrece en esa ubicación.
CREATE TABLE IF NOT EXISTS `location_products` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_id`   INT UNSIGNED NOT NULL,
  `product_id`    INT UNSIGNED NOT NULL,
  `price`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- precio de venta al público en esta ubicación
  `available`     TINYINT(1)    NOT NULL DEFAULT 1,      -- disponible / agotado en esta ubicación
  `sort_order`    SMALLINT      NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loc_prod` (`location_id`, `product_id`),
  INDEX `idx_lp_location` (`location_id`),
  CONSTRAINT `fk_lp_location` FOREIGN KEY (`location_id`) REFERENCES `ubicaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lp_product`  FOREIGN KEY (`product_id`)  REFERENCES `products` (`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ubicación principal de ejemplo (editable luego en el admin)
INSERT IGNORE INTO `ubicaciones` (`id`,`nombre`,`slug`,`sales_mode`,`activa`,`es_principal`)
VALUES (1, 'El Gringo', 'principal', 'menu', 1, 1);
