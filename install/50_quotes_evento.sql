-- 50 · Cotizaciones de evento: nombre amigable + marcar "atendida".
-- Sirve para que la SALIDA A EVENTO muestre un nombre legible y oculte las ya atendidas.
ALTER TABLE `quotes`
  ADD COLUMN `evento_nombre`   VARCHAR(120) NULL AFTER `event_date`,
  ADD COLUMN `evento_atendido` TINYINT(1) NOT NULL DEFAULT 0 AFTER `evento_nombre`;
