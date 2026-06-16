-- Migración 46: Control diario de eventos — tablas de días y conteo de insumos

CREATE TABLE IF NOT EXISTS evento_dias (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  evento_id  INT NOT NULL,
  fecha      DATE NOT NULL,
  dia_num    INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_ev_dia (evento_id, dia_num),
  INDEX idx_ed_ev (evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evento_dia_conteo (
  dia_id    INT NOT NULL,
  insumo_id INT UNSIGNED NOT NULL,
  corregido DECIMAL(12,3) NULL,
  conteo    DECIMAL(12,3) NULL,
  PRIMARY KEY (dia_id, insumo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
