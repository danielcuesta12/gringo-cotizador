<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';

// Raíz de la instancia = landing público (link-in-bio), SIEMPRE.
// Aunque haya una sesión de admin abierta: la raíz es la cara pública del negocio
// (igual que Lima, cuya raíz sirve landing.php directo). Para el panel se usa /admin.
require __DIR__ . '/landing.php';
