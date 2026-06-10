-- ============================================================
-- Cierre manual de tienda por ubicación
-- Permite cerrar una ubicación indefinidamente desde el admin,
-- sin importar el horario. La carta la muestra cerrada y bloquea pedidos.
-- ============================================================
ALTER TABLE `ubicaciones`
  ADD COLUMN `cerrado_manual` TINYINT(1) NOT NULL DEFAULT 0 AFTER `activa`;
