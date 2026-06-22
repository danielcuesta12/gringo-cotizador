-- 60_costeo_recetas.sql — Costeo & Recetas Fase 1.
-- Subrecetas (preps) + componentes mixtos (insumo|subreceta) + ficha técnica. Idempotente.

CREATE TABLE IF NOT EXISTS `subrecetas` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`      VARCHAR(120) NOT NULL,
  `unidad`      VARCHAR(20)  NOT NULL DEFAULT 'unidad',
  `rendimiento` DECIMAL(12,3) NOT NULL DEFAULT 1.000,
  `activo`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subreceta_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `subreceta_items` (
  `subreceta_id` INT UNSIGNED NOT NULL,
  `insumo_id`    INT UNSIGNED NOT NULL,
  `cantidad`     DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`subreceta_id`,`insumo_id`),
  KEY `idx_sri_insumo` (`insumo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `receta_componentes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `tipo`       ENUM('insumo','subreceta') NOT NULL DEFAULT 'insumo',
  `ref_id`     INT UNSIGNED NOT NULL,
  `cantidad`   DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rc` (`product_id`,`tipo`,`ref_id`),
  KEY `idx_rc_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: recetas existentes -> componentes tipo insumo. Solo productos sin componentes (no pisa ediciones posteriores).
INSERT IGNORE INTO `receta_componentes` (`product_id`,`tipo`,`ref_id`,`cantidad`)
SELECT r.`product_id`, 'insumo', r.`insumo_id`, r.`cantidad`
  FROM `recetas` r
 WHERE NOT EXISTS (SELECT 1 FROM `receta_componentes` rc WHERE rc.`product_id` = r.`product_id`);

CREATE TABLE IF NOT EXISTS `receta_ficha` (
  `product_id`    INT UNSIGNED NOT NULL,
  `porciones`     INT NOT NULL DEFAULT 1,
  `procedimiento` TEXT NULL,
  `montaje`       TEXT NULL,
  `notas`         TEXT NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
