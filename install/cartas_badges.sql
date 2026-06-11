-- ============================================================
-- Generador de cartas PDF — badges por producto
-- Cada ítem guarda un arreglo JSON de badges:
--   [{ "texto":"Recomendado", "posicion":"abajo|lado",
--      "bg":"#16A34A", "color":"#FFFFFF", "size":10 }, ...]
-- Aplicar una vez.
-- ============================================================
ALTER TABLE `carta_items` ADD COLUMN `badges` TEXT NULL AFTER `foto`;
