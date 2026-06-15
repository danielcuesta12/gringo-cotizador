-- Control de asistencia: padrón de empleados + ledger de marcas + geocerca por local.

CREATE TABLE IF NOT EXISTS empleados (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(120) NOT NULL,
  foto_referencia VARCHAR(255) NULL,
  ubicacion_id    INT NULL,
  user_id         INT NULL,
  pin_hash        VARCHAR(255) NULL,
  cargo           VARCHAR(80) NULL,
  activo          TINYINT NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asistencia_marcas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  empleado_id     INT NOT NULL,
  ubicacion_id    INT NULL,
  tipo            ENUM('entrada','salida') NOT NULL,
  foto            VARCHAR(255) NULL,
  lat             DECIMAL(10,7) NULL,
  lng             DECIMAL(10,7) NULL,
  distancia_m     INT NULL,
  dentro_geocerca TINYINT NOT NULL DEFAULT 1,
  fuente          ENUM('tablet','celular') NOT NULL DEFAULT 'tablet',
  verificacion    VARCHAR(40) NULL,
  origen          ENUM('app','manual') NOT NULL DEFAULT 'app',
  nota            VARCHAR(255) NULL,
  registrada_por  INT NULL,
  marcada_at      DATETIME NOT NULL,
  KEY idx_emp_fecha (empleado_id, marcada_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE ubicaciones
  ADD COLUMN lat             DECIMAL(10,7) NULL,
  ADD COLUMN lng             DECIMAL(10,7) NULL,
  ADD COLUMN geocerca_radio  INT NOT NULL DEFAULT 100,
  ADD COLUMN geocerca_activa TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN modo_marcaje    ENUM('tablet','celular') NOT NULL DEFAULT 'tablet',
  ADD COLUMN asistencia_token VARCHAR(40) NULL;
