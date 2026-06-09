-- ============================================================
-- Landing link-in-bio — botones editables desde el admin
-- ============================================================
CREATE TABLE IF NOT EXISTS `landing_links` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label`      VARCHAR(80)  NOT NULL,
  `sublabel`   VARCHAR(120) NULL,
  `url`        VARCHAR(500) NOT NULL,
  `icon`       VARCHAR(40)  NOT NULL DEFAULT 'link',     -- clave del set de iconos
  `style`      ENUM('primary','wa','dark','pink','neutral') NOT NULL DEFAULT 'neutral',
  `new_tab`    TINYINT(1)   NOT NULL DEFAULT 1,
  `active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order` SMALLINT     NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_landing_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Botones iniciales (edítalos/borra desde el panel)
INSERT IGNORE INTO `landing_links` (`id`,`label`,`sublabel`,`url`,`icon`,`style`,`sort_order`) VALUES
(1, 'Pedir delivery',    'PedidosYa · llega a tu casa',   '#', 'delivery',  'primary', 1),
(2, 'Ver la carta',      'Todo el menú y precios',        'https://elgringo.pe/principal/menu', 'carta', 'neutral', 2),
(3, 'Cotizar un evento', 'Catering, fiestas, food truck', 'https://elgringo.pe/cotizador/solicitud', 'evento', 'pink', 3),
(4, 'Instagram',         '@elgringoburger',               '#', 'instagram', 'neutral', 4);
