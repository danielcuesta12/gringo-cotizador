-- 57_cuentas.sql — Mesas POS Sub-build B: cuentas, anulaciones, enlace de comandas.
-- Idempotente. Aplicar en phpMyAdmin tras git pull.

CREATE TABLE IF NOT EXISTS `cuentas` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mesa_id`       INT UNSIGNED NOT NULL,
  `ubicacion_id`  INT UNSIGNED NOT NULL,
  `empleado_id`   INT UNSIGNED NULL,
  `num_comensales` INT NOT NULL DEFAULT 0,
  `estado`        ENUM('abierta','cerrada','cancelada') NOT NULL DEFAULT 'abierta',
  `total`         DECIMAL(10,2) NOT NULL DEFAULT 0,
  `abierta_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cerrada_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cuentas_mesa` (`mesa_id`),
  KEY `idx_cuentas_ubi` (`ubicacion_id`),
  KEY `idx_cuentas_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cuenta_anulaciones` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cuenta_id`   INT UNSIGNED NOT NULL,
  `pedido_id`   INT UNSIGNED NOT NULL,
  `item_idx`    INT NULL,
  `motivo`      VARCHAR(160) NOT NULL,
  `empleado_id` INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_canul_cuenta` (`cuenta_id`),
  KEY `idx_canul_pedido` (`pedido_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- pedidos: columnas de enlace (guard portable)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cuenta_id');
SET @s := IF(@c=0, "ALTER TABLE `pedidos` ADD COLUMN `cuenta_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='mesa_id');
SET @s := IF(@c=0, "ALTER TABLE `pedidos` ADD COLUMN `mesa_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- pedidos.origen: agregar 'mesa' al ENUM (re-ejecutable: MODIFY al set completo)
ALTER TABLE `pedidos` MODIFY COLUMN `origen` ENUM('carta','pos','mesa') NOT NULL DEFAULT 'carta';
