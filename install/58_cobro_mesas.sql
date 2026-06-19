-- 58_cobro_mesas.sql — Mesas POS Sub-build C: pagos de cuentas (split + mixto), precuenta, descuento.
-- Idempotente. Aplicar en phpMyAdmin tras git pull.

CREATE TABLE IF NOT EXISTS `cuenta_pagos` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cuenta_id`             INT UNSIGNED NOT NULL,
  `ubicacion_id`          INT UNSIGNED NOT NULL,
  `turno_id`              INT UNSIGNED NULL,
  `parte_num`             SMALLINT NOT NULL DEFAULT 1,
  `metodo_pago`           VARCHAR(60) NOT NULL,
  `tipo`                  ENUM('efectivo','tarjeta','qr','otros') NOT NULL DEFAULT 'otros',
  `monto`                 DECIMAL(10,2) NOT NULL,
  `empleado_id`           INT UNSIGNED NULL,
  `comprobante_pedido_id` INT UNSIGNED NULL,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cpago_cuenta` (`cuenta_id`),
  KEY `idx_cpago_ubi` (`ubicacion_id`),
  KEY `idx_cpago_turno` (`turno_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- cuentas: columnas de cobro (guard portable por columna)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='precuenta_at');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `precuenta_at` DATETIME NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='descuento_tipo');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `descuento_tipo` ENUM('porcentaje','monto') NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='descuento_valor');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `descuento_valor` DECIMAL(10,2) NOT NULL DEFAULT 0", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='descuento_monto');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `descuento_monto` DECIMAL(10,2) NOT NULL DEFAULT 0", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='cobrada_at');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `cobrada_at` DATETIME NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
