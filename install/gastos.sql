-- Módulo de Gastos (control de gastos de empresa + préstamos).
-- Separado del dinero de ventas / arqueo. Solo registro y control.

CREATE TABLE IF NOT EXISTS `gasto_categorias` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gcat_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `gasto_categorias` (`nombre`) VALUES
  ('Insumos'), ('Servicios'), ('Sueldos'), ('Mantenimiento'), ('Otros');

CREATE TABLE IF NOT EXISTS `gastos` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo`         ENUM('empresa','prestamo') NOT NULL DEFAULT 'prestamo',
  `concepto`     VARCHAR(200) NOT NULL,
  `monto`        DECIMAL(10,2) NOT NULL DEFAULT 0,
  `categoria_id` INT UNSIGNED NULL,
  `ubicacion_id` INT UNSIGNED NULL,
  `usuario_id`   INT UNSIGNED NOT NULL,
  `fecha`        DATE NOT NULL,
  `tags`         VARCHAR(255) NULL,            -- slugs separados por coma (FIND_IN_SET)
  `foto`         VARCHAR(255) NULL,            -- ruta relativa (se borra a los 2 meses)
  `nota`         TEXT NULL,
  `estado`       ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
  `pagado_at`    DATETIME NULL,
  `pagado_por`   INT UNSIGNED NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gastos_tipo`    (`tipo`),
  KEY `idx_gastos_estado`  (`estado`),
  KEY `idx_gastos_usuario` (`usuario_id`),
  KEY `idx_gastos_fecha`   (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
