CREATE TABLE IF NOT EXISTS evento_gastos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  evento_id   INT NOT NULL,
  categoria_id INT UNSIGNED NULL,
  monto       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  descripcion VARCHAR(160) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eg_ev (evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
