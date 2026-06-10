-- ============================================================
-- Analítica de páginas públicas (landing, cartas, solicitud)
-- ============================================================

-- Eventos genéricos: visitas, clics, embudo, búsquedas, vistas de producto…
CREATE TABLE IF NOT EXISTS `analytics_events` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type`   VARCHAR(40)  NOT NULL,              -- page_view, link_click, product_view, add_to_cart, checkout_open, order_placed, search
  `page`         VARCHAR(30)  NULL,                  -- landing, carta, menu, solicitud
  `ubicacion_id` INT UNSIGNED NULL,
  `src`          VARCHAR(60)  NULL,                  -- de ?src= (QR, sticker, volante…)
  `referrer`     VARCHAR(255) NULL,
  `device`       VARCHAR(10)  NULL,                  -- mobile | desktop
  `session_id`   VARCHAR(40)  NULL,
  `meta_json`    TEXT         NULL,                  -- {product_id, label, term, total, ...}
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ev_type`    (`event_type`),
  INDEX `idx_ev_page`    (`page`),
  INDEX `idx_ev_ubi`     (`ubicacion_id`),
  INDEX `idx_ev_created` (`created_at`),
  INDEX `idx_ev_session` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contador de likes ❤️ por producto / ubicación / versión (pedidos | menu)
CREATE TABLE IF NOT EXISTS `product_likes` (
  `product_id`   INT UNSIGNED NOT NULL,
  `ubicacion_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `version`      VARCHAR(20)  NOT NULL DEFAULT 'pedidos',
  `total`        INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`product_id`, `ubicacion_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
