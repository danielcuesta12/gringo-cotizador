-- ============================================================
-- POS (Fase E) — turnos de caja, métodos de pago, favoritos
-- Aplicar una vez. Requiere que exista la tabla `pedidos`.
-- ============================================================
CREATE TABLE IF NOT EXISTS `pos_turnos` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `ubicacion_id`   INT UNSIGNED NOT NULL,
  `monto_inicial`  DECIMAL(10,2) NOT NULL DEFAULT 0,
  `monto_final`    DECIMAL(10,2) NULL,
  `total_efectivo` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_tarjeta`  DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_qr`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_otros`    DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_ventas`   DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_pedidos`  INT NOT NULL DEFAULT 0,
  `abierto_en`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cerrado_en`     DATETIME NULL,
  `estado`         ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
  PRIMARY KEY (`id`),
  INDEX `idx_turno_estado` (`estado`),
  INDEX `idx_turno_ubi` (`ubicacion_id`),
  INDEX `idx_turno_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_metodos_pago` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(60) NOT NULL,
  `tipo`   ENUM('efectivo','tarjeta','qr','otros') NOT NULL DEFAULT 'otros',
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `orden`  SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_favoritos` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ubicacion_id` INT UNSIGNED NOT NULL,
  `producto_id`  INT UNSIGNED NOT NULL,
  `posicion`     SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_fav_ubi` (`ubicacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Métodos por defecto
INSERT IGNORE INTO `pos_metodos_pago` (`id`,`nombre`,`tipo`,`orden`) VALUES
  (1,'Efectivo','efectivo',1),(2,'Tarjeta','tarjeta',2),(3,'Yape / Plin','qr',3);

-- Ampliar `pedidos` para el POS (compatibles; no rompen carta/KDS).
-- metodo_pago pasa de ENUM a VARCHAR para admitir efectivo/tarjeta/qr.
ALTER TABLE `pedidos` MODIFY COLUMN `metodo_pago` VARCHAR(60) NOT NULL DEFAULT 'whatsapp';
ALTER TABLE `pedidos`
  ADD COLUMN `turno_id` INT UNSIGNED NULL,
  ADD INDEX `idx_pedidos_turno` (`turno_id`),
  ADD COLUMN `descuento_tipo` ENUM('porcentaje','monto') NULL,
  ADD COLUMN `descuento_valor` DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN `descuento_monto` DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN `cliente_tipo` ENUM('nombre','dni','ruc') NULL,
  ADD COLUMN `cliente_nombre` VARCHAR(255) NULL,
  ADD COLUMN `cliente_documento` VARCHAR(20) NULL,
  ADD COLUMN `cliente_razon_social` VARCHAR(255) NULL,
  ADD COLUMN `comprobante_tipo` ENUM('ticket','boleta','factura') NOT NULL DEFAULT 'ticket',
  ADD COLUMN `notas_pos` TEXT NULL;
