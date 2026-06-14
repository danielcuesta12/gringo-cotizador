-- NubeFact Fase 2 — correo del cliente para envío del comprobante electrónico.
-- Se guarda en el pedido para que el reintento de emisión (server-side, solo lee
-- de BD) pueda volver a indicarle a NubeFact a quién enviar el PDF+XML oficial.
ALTER TABLE `pedidos`
  ADD COLUMN `cliente_email` VARCHAR(120) NULL AFTER `cliente_razon_social`;
