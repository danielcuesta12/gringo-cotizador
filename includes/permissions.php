<?php
// ============================================================
// permissions.php — Catálogo de permisos y utilidades
// ============================================================

// Catálogo de permisos: grupo => [clave => etiqueta]
function permissionCatalog(): array {
    return [
        'Ventas' => [
            'dashboard' => 'Dashboard',
            'quotes'    => 'Cotizaciones',
            'events'    => 'Eventos',
            'calendar'  => 'Calendario',
            'clients'   => 'Clientes',
            'requests'  => 'Solicitudes',
            'reservas'  => 'Reservas',
            'analytics' => 'Analítica',
        ],
        'Catálogo' => [
            'products'   => 'Productos',
            'categories' => 'Categorías',
            'packages'   => 'Paquetes',
            'modifiers'  => 'Modificadores',
            'locations'  => 'Ubicaciones',
        ],
        'Carta / POS' => [
            'pedidos'      => 'Pedidos',
            'kds'          => 'KDS (cocina)',
            'pos_terminal' => 'POS Terminal',
            'pos_metodos'  => 'POS Métodos',
            'pos_caja'     => 'POS Caja (arqueo)',
            'pos_monitor'  => 'POS En vivo',
            'pos_clientes' => 'Clientes POS',
        ],
        'Inventario' => [
            'inv_insumos'     => 'Insumos',
            'inv_stock'       => 'Stock',
            'inv_recetas'     => 'Recetas',
            'inv_movimientos' => 'Movimientos',
            'inv_compras'     => 'Compras',
            'inv_evento'      => 'Salida a evento',
        ],
        'Marketing' => [
            'cartas_pdf' => 'Generador de cartas',
            'qr'         => 'Generador QR',
            'landing'    => 'Landing',
        ],
        'Finanzas' => [
            'gastos' => 'Registro de gastos',
        ],
        'Personal' => [
            'asistencia' => 'Control de asistencia',
        ],
    ];
}

// Plantillas rápidas: clave de plantilla => array de claves de permiso
function permissionTemplates(): array {
    return [
        'cajero'     => ['pos_terminal', 'pos_caja'],
        'cocina'     => ['kds'],
        'ventas'     => ['dashboard', 'quotes', 'events', 'calendar', 'clients', 'requests', 'reservas'],
        'inventario' => ['inv_insumos', 'inv_stock', 'inv_recetas', 'inv_movimientos', 'inv_compras', 'inv_evento'],
        // 'admin' (acceso total) se maneja con role='admin', no con esta lista
    ];
}

// Etiquetas legibles de las plantillas (para la UI)
function permissionTemplateLabels(): array {
    return [
        'cajero'     => 'Cajero',
        'cocina'     => 'Cocina',
        'ventas'     => 'Asistente de ventas',
        'inventario' => 'Encargado de inventario',
        'admin'      => 'Administrador (acceso total)',
    ];
}

// Todas las claves válidas (aplanadas)
function allPermissionKeys(): array {
    $keys = [];
    foreach (permissionCatalog() as $grupo => $items) {
        foreach ($items as $k => $label) $keys[] = $k;
    }
    return $keys;
}

// Mapa clave => ruta (sin .php cuando aplica), para redirigir al primer acceso disponible
function permissionPaths(): array {
    return [
        'dashboard' => '/admin/dashboard.php',
        'quotes'    => '/quotes/list.php',
        'events'    => '/admin/events/create.php',
        'calendar'  => '/admin/calendar.php',
        'clients'   => '/admin/clients/index.php',
        'requests'  => '/admin/requests/index.php',
        'reservas'  => '/admin/reservas/index.php',
        'analytics' => '/admin/analytics/index.php',
        'products'   => '/admin/products/index.php',
        'categories' => '/admin/categories/index.php',
        'packages'   => '/admin/packages/index.php',
        'modifiers'  => '/admin/modifiers/index.php',
        'locations'  => '/admin/locations/index.php',
        'pedidos'      => '/admin/pedidos/index.php',
        'kds'          => '/admin/kds/index.php',
        'pos_terminal' => '/pos/terminal.php',
        'pos_metodos'  => '/admin/pos/metodos.php',
        'pos_caja'     => '/admin/pos/caja.php',
        'pos_monitor'  => '/admin/pos/monitor.php',
        'pos_clientes' => '/admin/pos/clientes.php',
        'inv_insumos'     => '/admin/inventory/insumos.php',
        'inv_stock'       => '/admin/inventory/stock.php',
        'inv_recetas'     => '/admin/inventory/recetas.php',
        'inv_movimientos' => '/admin/inventory/movimientos.php',
        'inv_compras'     => '/admin/inventory/compras.php',
        'inv_evento'      => '/admin/inventory/salida_evento.php',
        'cartas_pdf' => '/admin/cartas/index.php',
        'qr'         => '/admin/qr.php',
        'landing'    => '/admin/landing/index.php',
        'gastos'     => '/admin/gastos/index.php',
        'asistencia' => '/admin/asistencia/index.php',
    ];
}

// Sanitiza un array de claves recibidas (del form) → solo claves válidas, JSON o null
function sanitizePermissions($arr): ?string {
    if (!is_array($arr)) return null;
    $valid = allPermissionKeys();
    $out = [];
    foreach ($arr as $k) {
        $k = (string)$k;
        if (in_array($k, $valid, true) && !in_array($k, $out, true)) $out[] = $k;
    }
    return $out ? json_encode($out) : null;
}
