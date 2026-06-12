-- Tipo de botón del landing: link normal, o form embebido (cotización / reserva)
ALTER TABLE `landing_links` ADD COLUMN `tipo` ENUM('link','cotizacion','reserva') NOT NULL DEFAULT 'link' AFTER `url`;
-- El botón de cotización existente pasa a tipo 'cotizacion'
UPDATE `landing_links` SET `tipo`='cotizacion' WHERE `url` LIKE '%solicitud%';
-- Sembrar el botón de Reservas (si no existe uno de reserva)
INSERT INTO `landing_links` (`label`,`sublabel`,`url`,`tipo`,`icon`,`style`,`sort_order`,`active`)
SELECT 'Reservar una mesa','Aparta tu cupo en El Gringo','reserva.php','reserva','calendar','',
       (SELECT COALESCE(MAX(x.sort_order),0)+1 FROM landing_links x), 1
WHERE NOT EXISTS (SELECT 1 FROM landing_links y WHERE y.tipo='reserva');
