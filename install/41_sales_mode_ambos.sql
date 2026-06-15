-- Permite que una tienda venda por WhatsApp e Izipay a la vez.
ALTER TABLE ubicaciones
  MODIFY COLUMN sales_mode ENUM('menu','whatsapp','izipay','ambos') NOT NULL DEFAULT 'menu';
