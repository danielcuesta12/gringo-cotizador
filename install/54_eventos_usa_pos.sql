-- 54 · Evento de liquidación: ¿vende por POS? Si sí, el ingreso = ventas POS; si no, el ingreso = monto manual.
ALTER TABLE `eventos`
  ADD COLUMN `usa_pos` TINYINT(1) NOT NULL DEFAULT 1;
