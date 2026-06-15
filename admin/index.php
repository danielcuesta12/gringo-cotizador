<?php
// Entrada de /admin/ — evita el 403 de carpeta sin índice.
// Manda al dashboard (que ya gatea login/permisos y redirige si hace falta).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';
redirect('/admin/dashboard.php');
