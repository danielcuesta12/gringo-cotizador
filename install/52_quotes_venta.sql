-- 52 · Venta real de un evento/cotización (registrable desde el calendario por el admin).
-- Para eventos pasados que nunca pasaron por la liquidación: guardar cuánto se vendió de verdad.
ALTER TABLE `quotes`
  ADD COLUMN `venta_real` DECIMAL(10,2) NULL AFTER `evento_atendido`;
