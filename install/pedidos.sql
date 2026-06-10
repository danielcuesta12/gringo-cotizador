-- ============================================================
-- Fase C — Pedidos (carta de venta: WhatsApp + Izipay)
-- ============================================================
CREATE TABLE IF NOT EXISTS `pedidos` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ubicacion_id`    INT UNSIGNED NOT NULL,
  `nombre`          VARCHAR(150) NULL,
  `telefono`        VARCHAR(30)  NULL,
  `tipo_entrega`    VARCHAR(20)  NOT NULL DEFAULT 'delivery',  -- delivery | recojo
  `direccion`       VARCHAR(255) NULL,
  `horario`         VARCHAR(100) NULL,
  `comentarios`     TEXT         NULL,
  `items_json`      TEXT         NULL,                          -- carrito serializado
  `total`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `estado`          ENUM('pendiente','en_preparacion','listo','entregado','cancelado') NOT NULL DEFAULT 'pendiente',
  `metodo_pago`     ENUM('whatsapp','izipay') NOT NULL DEFAULT 'whatsapp',
  `izipay_order_id` VARCHAR(60)  NULL,                          -- idempotencia de pagos
  `origen`          ENUM('carta','pos') NOT NULL DEFAULT 'carta', -- carta de venta o punto de venta (POS, fase E)
  `aceptado_at`     DATETIME     NULL,                          -- inicio de preparación (timer KDS)
  `completado_at`   DATETIME     NULL,                          -- marcado listo / cancelado (historial KDS)
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_izipay_order` (`izipay_order_id`),
  INDEX `idx_pedidos_ubi` (`ubicacion_id`),
  INDEX `idx_pedidos_estado` (`estado`),
  CONSTRAINT `fk_pedidos_ubi` FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
