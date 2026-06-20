-- 59_cuenta_mesas.sql — Mesas POS Sub-build E1: mesas secundarias (juntadas) de una cuenta.
-- La principal sigue en cuentas.mesa_id; esta tabla solo guarda las secundarias. Idempotente.

CREATE TABLE IF NOT EXISTS `cuenta_mesas` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cuenta_id`  INT UNSIGNED NOT NULL,
  `mesa_id`    INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cmesa` (`cuenta_id`, `mesa_id`),
  KEY `idx_cmesa_mesa` (`mesa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
