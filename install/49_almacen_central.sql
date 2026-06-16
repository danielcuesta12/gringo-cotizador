-- 49 · Almacén central: ubicación que guarda y despacha pero NUNCA vende.
-- Se crea con activa=0 + es_almacen=1 → queda oculto de carta/POS/KDS (que filtran activa=1)
-- pero visible en los selectores de inventario (activa=1 OR es_almacen=1).
ALTER TABLE `ubicaciones`
  ADD COLUMN `es_almacen` TINYINT(1) NOT NULL DEFAULT 0 AFTER `es_principal`;
