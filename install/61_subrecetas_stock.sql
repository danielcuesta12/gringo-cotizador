-- 61_subrecetas_stock.sql — Subrecetas con stock + producción por lotes. Idempotente.

-- Toggle opt-in: la subreceta se produce y lleva stock propio.
ALTER TABLE `subrecetas`
  ADD COLUMN IF NOT EXISTS `lleva_stock` TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `subreceta_stock` (
  `subreceta_id` INT UNSIGNED NOT NULL,
  `ubicacion_id` INT UNSIGNED NOT NULL,
  `stock`        DECIMAL(12,3) NOT NULL DEFAULT 0.000,   -- puede ir negativo
  `stock_min`    DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`subreceta_id`,`ubicacion_id`),
  KEY `idx_ss_ubi` (`ubicacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `subreceta_movimientos` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ubicacion_id`   INT UNSIGNED NOT NULL,
  `subreceta_id`   INT UNSIGNED NOT NULL,
  `tipo`           ENUM('produccion','transferencia','venta','ajuste','merma') NOT NULL,
  `cantidad`       DECIMAL(12,3) NOT NULL,
  `costo_unitario` DECIMAL(10,4) NULL,
  `motivo`         VARCHAR(160) NULL,
  `ref`            VARCHAR(60)  NULL,
  `pedido_id`      INT UNSIGNED NULL,
  `user_id`        INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sm_sub` (`subreceta_id`),
  KEY `idx_sm_ubi` (`ubicacion_id`),
  KEY `idx_sm_pedido` (`pedido_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
