CREATE TABLE IF NOT EXISTS eventos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(160) NOT NULL,
  quote_id      INT UNSIGNED NULL,
  ubicacion_id  INT UNSIGNED NULL,
  fecha_inicio  DATE NOT NULL,
  fecha_fin     DATE NULL,
  venta_manual  DECIMAL(10,2) NULL,
  estado        ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ev_fecha (fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evento_insumos (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  evento_id        INT NOT NULL,
  insumo_id        INT UNSIGNED NOT NULL,
  cantidad_inicial DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  costo_unitario   DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  UNIQUE KEY uq_ev_ins (evento_id, insumo_id),
  INDEX idx_ei_ev (evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
