<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/helpers.php';

// Raíz de la instancia:
//  - Logueado  → al panel (atajo cómodo).
//  - Visitante → el landing público (link-in-bio). Así, una instancia servida en
//    su propio dominio (ej. marcona.elgringo.pe) muestra su landing en la raíz.
if (isLoggedIn()) {
    redirect('/admin/dashboard.php');
} else {
    require __DIR__ . '/landing.php';
}
