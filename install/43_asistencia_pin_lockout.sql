ALTER TABLE empleados
  ADD COLUMN pin_intentos INT NOT NULL DEFAULT 0,
  ADD COLUMN pin_bloqueado_hasta DATETIME NULL;
