-- 53 · Venta real de un evento libre (agenda), registrable desde el calendario por el admin.
-- Las cotizaciones ya tienen su venta por el total; los eventos libres no, así que se registra aquí.
ALTER TABLE `agenda`
  ADD COLUMN `venta_real` DECIMAL(10,2) NULL;
