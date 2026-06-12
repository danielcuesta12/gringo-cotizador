-- Bloqueo de día opcional: un evento de agenda solo marca el día "no disponible" si bloquea=1
ALTER TABLE `agenda` ADD COLUMN `bloquea` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notas`;
