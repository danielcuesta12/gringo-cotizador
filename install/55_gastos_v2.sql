-- 55_gastos_v2.sql — Gastos v2: subcategorías, líneas (multi), origen/enlaces.
-- Idempotente (re-ejecutable). Aplicar en phpMyAdmin tras git pull.

-- 1) Subcategorías
CREATE TABLE IF NOT EXISTS `gasto_subcategorias` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `categoria_id` INT UNSIGNED NOT NULL,
  `nombre`       VARCHAR(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gsub` (`categoria_id`,`nombre`),
  KEY `idx_gsub_cat` (`categoria_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Líneas de gasto
CREATE TABLE IF NOT EXISTS `gasto_items` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gasto_id`          INT UNSIGNED NOT NULL,
  `concepto`          VARCHAR(200) NULL,
  `monto`             DECIMAL(10,2) NOT NULL DEFAULT 0,
  `categoria_id`      INT UNSIGNED NULL,
  `subcategoria_id`   INT UNSIGNED NULL,
  `insumo_id`         INT UNSIGNED NULL,
  `cantidad`          DECIMAL(12,3) NULL,
  `inv_movimiento_id` INT UNSIGNED NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gi_gasto` (`gasto_id`),
  KEY `idx_gi_cat` (`categoria_id`),
  KEY `idx_gi_sub` (`subcategoria_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Columnas nuevas en gastos (guard portable MySQL 5.7 / MariaDB)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gastos' AND column_name='origen');
SET @s := IF(@c=0, "ALTER TABLE `gastos` ADD COLUMN `origen` ENUM('manual','pos','evento') NOT NULL DEFAULT 'manual'", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gastos' AND column_name='turno_id');
SET @s := IF(@c=0, "ALTER TABLE `gastos` ADD COLUMN `turno_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gastos' AND column_name='evento_id');
SET @s := IF(@c=0, "ALTER TABLE `gastos` ADD COLUMN `evento_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gastos' AND column_name='proveedor_id');
SET @s := IF(@c=0, "ALTER TABLE `gastos` ADD COLUMN `proveedor_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4) Backfill: una línea por cada gasto existente (idempotente)
INSERT INTO `gasto_items` (`gasto_id`,`concepto`,`monto`,`categoria_id`)
SELECT g.`id`, g.`concepto`, g.`monto`, g.`categoria_id`
FROM `gastos` g
WHERE NOT EXISTS (SELECT 1 FROM `gasto_items` gi WHERE gi.`gasto_id` = g.`id`);

-- 5) Categoría por defecto para gastos del POS
INSERT IGNORE INTO `gasto_categorias` (`nombre`) VALUES ('Caja / Operación');
