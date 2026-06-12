-- Eventos de agenda de varios días + hora fin
ALTER TABLE `agenda`
  ADD COLUMN `fecha_fin` DATE NULL AFTER `fecha`,
  ADD COLUMN `hora_fin`  VARCHAR(10) NULL AFTER `hora`;
