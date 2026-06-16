ALTER TABLE insumos
  ADD COLUMN tipo ENUM('ingrediente','descartable') NOT NULL DEFAULT 'ingrediente';

CREATE TABLE IF NOT EXISTS receta_modificadores (
  modificador_id INT UNSIGNED NOT NULL,
  insumo_id      INT UNSIGNED NOT NULL,
  cantidad       DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (modificador_id, insumo_id),
  INDEX idx_rm_insumo (insumo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
