-- ============================================================
-- POS — arqueo de caja al cierre de turno
-- Caja esperada = caja inicial + ingresos efectivo + ventas EFECTIVO - gastos
-- Diferencia    = caja esperada - caja real (contada)
-- Aplicar una vez.
-- ============================================================
ALTER TABLE `pos_turnos`
  ADD COLUMN `ingreso_efectivo` DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `monto_inicial`,
  ADD COLUMN `gastos_total`     DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `ingreso_efectivo`,
  ADD COLUMN `gastos_json`      TEXT NULL AFTER `gastos_total`,
  ADD COLUMN `caja_esperada`    DECIMAL(10,2) NULL AFTER `monto_final`,
  ADD COLUMN `caja_real`        DECIMAL(10,2) NULL AFTER `caja_esperada`,
  ADD COLUMN `diferencia`       DECIMAL(10,2) NULL AFTER `caja_real`;
