-- ============================================================
-- Permisos por usuario (control de acceso fino)
-- permissions = JSON array de claves, ej: ["pos_terminal","pos_caja"]
-- Los usuarios role='admin' tienen acceso total (no usan esta columna).
-- ============================================================
ALTER TABLE `users` ADD COLUMN `permissions` TEXT NULL AFTER `role`;

-- Default para asistentes existentes: accesos de ventas (para no dejarlos sin nada)
UPDATE `users` SET `permissions` = '["dashboard","quotes","events","calendar","clients","requests"]'
WHERE `role` = 'asistente' AND (`permissions` IS NULL OR `permissions` = '');
