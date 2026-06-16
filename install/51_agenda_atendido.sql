-- 51 · Eventos libres (agenda): marcar "atendido" para ocultarlos del selector de salida a evento.
ALTER TABLE `agenda`
  ADD COLUMN `atendido` TINYINT(1) NOT NULL DEFAULT 0 AFTER `titulo`;
