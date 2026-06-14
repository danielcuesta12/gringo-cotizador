<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo isset($pageTitle) ? clean($pageTitle) . ' — ' : ''; ?>El Gringo Cotizador</title>
<link rel="icon" type="image/png" href="<?= appIcon('/img/favicon.png') ?>">
<link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/style.css') ?: time(); ?>">
<?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body class="admin-layout">

<?php
// ---- Logo del sidebar (fondo oscuro): usar el logo claro (Logo B); fallback al principal (A) ----
$_logoRel  = getSetting('company_logo_b', '');
if (empty($_logoRel)) $_logoRel = getSetting('company_logo', '');
$_logoUrl  = $_logoRel ? UPLOAD_URL  . $_logoRel : '';
$_logoFile = $_logoRel ? UPLOAD_PATH . $_logoRel : '';

// ---- Solicitudes pendientes (badge) ----
$pendingCount = 0;
try {
    $prow = Database::fetch("SELECT COUNT(*) as n FROM quote_requests WHERE status='pendiente'");
    $pendingCount = $prow ? (int)$prow['n'] : 0;
} catch (Exception $e) { $pendingCount = 0; }
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <button class="sidebar-close" id="sidebarClose" aria-label="Cerrar menu">&#10005;</button>
    <div class="sidebar-logo">
      <?php if ($_logoUrl && file_exists($_logoFile)): ?>
        <img src="<?php echo clean($_logoUrl); ?>" alt="El Gringo">
      <?php else: ?>
        <div class="sidebar-logo-fallback">EG</div>
      <?php endif; ?>
    </div>
    <div class="sidebar-brand-sub">Cotizador</div>
  </div>

  <nav class="sidebar-nav">

    <?php /* ===== 1. Dashboard (standalone) ===== */ ?>
    <?php if (can('dashboard')): ?>
    <a href="<?php echo APP_URL; ?>/admin/dashboard.php"
       class="nav-link <?php echo ($activePage??'')==='dashboard'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg></span> Dashboard
    </a>
    <?php endif; ?>

    <?php /* ===== 2. Cotizaciones ===== */ ?>
    <?php if (can('quotes') || can('events') || can('calendar') || can('requests') || can('reservas') || can('clients')): ?>
    <div class="sb-group" data-sb-group="cotizaciones">
      <button type="button" class="nav-section-label sb-header" aria-expanded="true">
        <span class="sb-dot sb-dot-pink"></span>Cotizaciones<span class="sb-chevron">&#9662;</span>
      </button>
      <div class="sb-items">

        <?php if (can('quotes')): ?>
        <a href="<?php echo APP_URL; ?>/quotes/create.php"
           class="nav-link nav-link-highlight <?php echo ($activePage??'')==='quote-new'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span> Nueva cotización
        </a>
        <?php endif; ?>

        <?php if (can('quotes')): ?>
        <a href="<?php echo APP_URL; ?>/quotes/list.php"
           class="nav-link <?php echo ($activePage??'')==='quotes'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg></span> Cotizaciones
        </a>
        <?php endif; ?>

        <?php if (can('events')): ?>
        <a href="<?php echo APP_URL; ?>/admin/events/create"
           class="nav-link nav-link-event <?php echo ($activePage??'')==='event-new'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg></span> Nuevo evento
        </a>
        <?php endif; ?>

        <?php if (can('calendar')): ?>
        <a href="<?php echo APP_URL; ?>/admin/calendar"
           class="nav-link <?php echo ($activePage??'')==='calendar'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span> Calendario
        </a>
        <?php endif; ?>

        <?php if (can('requests')): ?>
        <a href="<?php echo APP_URL; ?>/admin/requests/index.php"
           class="nav-link <?php echo ($activePage??'')==='requests'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/></svg></span> Solicitudes
          <?php if ($pendingCount > 0): ?>
            <span class="nav-badge nav-badge-req"><?php echo $pendingCount; ?></span>
          <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (can('reservas')): ?>
        <a href="<?php echo APP_URL; ?>/admin/reservas/index.php"
           class="nav-link <?php echo ($activePage??'')==='reservas'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M9 16l2 2 4-4"/></svg></span> Reservas
        </a>
        <?php endif; ?>

        <?php if (can('clients')): ?>
        <a href="<?php echo APP_URL; ?>/admin/clients/index.php"
           class="nav-link <?php echo ($activePage??'')==='clients'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> Clientes
        </a>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

    <?php /* ===== 3. Operación · POS y Cartas ===== */ ?>
    <?php if (can('pos_terminal') || can('pedidos') || can('kds') || can('pos_monitor') || can('pos_caja') || can('pos_clientes') || can('cartas_pdf') || can('pos_metodos') || isAdmin()): ?>
    <div class="sb-group" data-sb-group="operacion">
      <button type="button" class="nav-section-label sb-header" aria-expanded="true">
        <span class="sb-dot sb-dot-yellow"></span>Operación · POS y Cartas<span class="sb-chevron">&#9662;</span>
      </button>
      <div class="sb-items">

        <?php if (can('pos_terminal')): ?>
        <a href="<?php echo APP_URL; ?>/pos/terminal.php" target="_blank"
           class="nav-link nav-link-pos">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></span> POS · Terminal
        </a>
        <?php endif; ?>

        <?php if (can('pedidos')): ?>
        <a href="<?php echo APP_URL; ?>/admin/pedidos/index.php"
           class="nav-link <?php echo ($activePage??'')==='pedidos'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg></span> Pedidos
        </a>
        <?php endif; ?>

        <?php if (can('kds')): ?>
        <a href="<?php echo APP_URL; ?>/admin/kds/index.php" target="_blank"
           class="nav-link">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></span> KDS · Cocina
        </a>
        <?php endif; ?>

        <?php if (can('pos_monitor')): ?>
        <a href="<?php echo APP_URL; ?>/admin/pos/monitor.php" target="_blank"
           class="nav-link">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span> POS · En vivo
        </a>
        <?php endif; ?>

        <?php if (can('pos_caja')): ?>
        <a href="<?php echo APP_URL; ?>/admin/pos/caja.php"
           class="nav-link <?php echo ($activePage??'')==='pos-caja'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2M2 12h20"/></svg></span> POS · Caja
        </a>
        <?php endif; ?>

        <?php if (can('pos_clientes')): ?>
        <a href="<?php echo APP_URL; ?>/admin/pos/clientes.php"
           class="nav-link <?php echo ($activePage??'')==='pos-clientes'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> POS · Clientes
        </a>
        <?php endif; ?>

        <?php if (isAdmin()): ?>
        <a href="<?php echo APP_URL; ?>/admin/reportes/index.php"
           class="nav-link <?php echo ($activePage??'')==='reportes'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="8" rx="1"/><rect x="12" y="6" width="3" height="12" rx="1"/><rect x="17" y="13" width="3" height="5" rx="1"/></svg></span> Reportes
        </a>
        <?php endif; ?>

        <?php if (can('cartas_pdf')): ?>
        <a href="<?php echo APP_URL; ?>/admin/cartas/index.php"
           class="nav-link <?php echo ($activePage??'')==='cartas-pdf'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg></span> Generador cartas PDF
        </a>
        <?php endif; ?>

        <?php if (can('pos_metodos')): ?>
        <a href="<?php echo APP_URL; ?>/admin/pos/metodos.php"
           class="nav-link <?php echo ($activePage??'')==='pos-metodos'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span> POS · Métodos
        </a>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

    <?php /* ===== 4. Catálogo ===== */ ?>
    <?php if (can('products') || can('categories') || can('packages') || can('modifiers') || can('locations')): ?>
    <div class="sb-group" data-sb-group="catalogo">
      <button type="button" class="nav-section-label sb-header" aria-expanded="true">
        <span class="sb-dot"></span>Catálogo<span class="sb-chevron">&#9662;</span>
      </button>
      <div class="sb-items">

        <?php if (can('products')): ?>
        <a href="<?php echo APP_URL; ?>/admin/products/index.php"
           class="nav-link <?php echo ($activePage??'')==='products'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg></span> Productos
        </a>
        <?php endif; ?>

        <?php if (can('categories')): ?>
        <a href="<?php echo APP_URL; ?>/admin/categories/index.php"
           class="nav-link <?php echo ($activePage??'')==='categories'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.41l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42Z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor" stroke="none"/></svg></span> Categorías
        </a>
        <?php endif; ?>

        <?php if (can('packages')): ?>
        <a href="<?php echo APP_URL; ?>/admin/packages/index.php"
           class="nav-link <?php echo ($activePage??'')==='packages'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg></span> Paquetes
        </a>
        <?php endif; ?>

        <?php if (can('modifiers')): ?>
        <a href="<?php echo APP_URL; ?>/admin/modifiers/index.php"
           class="nav-link <?php echo ($activePage??'')==='modifiers'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg></span> Adicionales
        </a>
        <?php endif; ?>

        <?php if (can('locations')): ?>
        <a href="<?php echo APP_URL; ?>/admin/locations/index.php"
           class="nav-link <?php echo ($activePage??'')==='locations'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span> Ubicaciones
        </a>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

    <?php /* ===== 5. Inventario ===== */ ?>
    <?php if (can('inv_insumos') || can('inv_stock') || can('inv_recetas') || can('inv_movimientos') || can('inv_compras') || can('inv_evento')): ?>
    <div class="sb-group" data-sb-group="inventario">
      <button type="button" class="nav-section-label sb-header" aria-expanded="true">
        <span class="sb-dot"></span>Inventario<span class="sb-chevron">&#9662;</span>
      </button>
      <div class="sb-items">

        <?php if (can('inv_insumos')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/insumos.php"
           class="nav-link <?php echo ($activePage??'')==='inv-insumos'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg></span> Insumos
        </a>
        <?php endif; ?>

        <?php if (can('inv_stock')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/stock.php"
           class="nav-link <?php echo ($activePage??'')==='inv-stock'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-7m7 5h-7m7 5h-7M4 7h.01M4 12h.01M4 17h.01"/></svg></span> Stock
        </a>
        <?php endif; ?>

        <?php if (can('inv_recetas')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/recetas.php"
           class="nav-link <?php echo ($activePage??'')==='inv-recetas'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 11a2 2 0 1 1-4 0 4 4 0 0 1 8 0c0 4-4 7-4 11"/><path d="M9 22h6"/></svg></span> Recetas y costos
        </a>
        <?php endif; ?>

        <?php if (can('inv_movimientos')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/movimientos.php"
           class="nav-link <?php echo ($activePage??'')==='inv-movimientos'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></span> Movimientos
        </a>
        <?php endif; ?>

        <?php if (can('inv_compras')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/compras.php"
           class="nav-link <?php echo ($activePage??'')==='inv-compras'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span> Compras
        </a>
        <?php endif; ?>

        <?php if (can('inv_evento')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/salida_evento.php"
           class="nav-link <?php echo ($activePage??'')==='inv-evento'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v4H3zM3 7v13a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V7M9 11h6"/></svg></span> Salida a evento
        </a>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

    <?php /* ===== 6. Marketing ===== */ ?>
    <?php if (can('landing') || can('qr') || can('analytics')): ?>
    <div class="sb-group" data-sb-group="marketing">
      <button type="button" class="nav-section-label sb-header" aria-expanded="true">
        <span class="sb-dot"></span>Marketing<span class="sb-chevron">&#9662;</span>
      </button>
      <div class="sb-items">

        <?php if (can('landing')): ?>
        <a href="<?php echo APP_URL; ?>/admin/landing/index.php"
           class="nav-link <?php echo ($activePage??'')==='landing'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></span> Landing
        </a>
        <?php endif; ?>

        <?php if (can('qr')): ?>
        <a href="<?php echo APP_URL; ?>/admin/qr.php"
           class="nav-link <?php echo ($activePage??'')==='qr-gen'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 21v.01M17 21h.01M21 17h.01"/></svg></span> Generador de QR
        </a>
        <?php endif; ?>

        <?php if (can('analytics')): ?>
        <a href="<?php echo APP_URL; ?>/admin/analytics/index.php"
           class="nav-link <?php echo ($activePage??'')==='analytics'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></span> Analítica
        </a>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>

    <?php /* ===== 6.5 Finanzas ===== */ ?>
    <?php if (can('gastos')): ?>
    <div class="sb-group" data-sb-group="finanzas">
      <button type="button" class="nav-section-label sb-header" aria-expanded="true">
        <span class="sb-dot sb-dot-pink"></span>Finanzas<span class="sb-chevron">&#9662;</span>
      </button>
      <div class="sb-items">
        <a href="<?php echo APP_URL; ?>/admin/gastos/index.php"
           class="nav-link <?php echo ($activePage??'')==='gastos'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></svg></span> Registro de gastos
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php /* ===== 7. Sistema (admin) ===== */ ?>
    <?php if (isAdmin()): ?>
    <div class="sb-group" data-sb-group="sistema">
      <button type="button" class="nav-section-label sb-header" aria-expanded="true">
        <span class="sb-dot"></span>Sistema<span class="sb-chevron">&#9662;</span>
      </button>
      <div class="sb-items">

        <a href="<?php echo APP_URL; ?>/admin/settings/index.php"
           class="nav-link <?php echo ($activePage??'')==='settings'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg></span> Configuración
        </a>

        <a href="<?php echo APP_URL; ?>/admin/facturacion/index.php"
           class="nav-link <?php echo ($activePage??'')==='facturacion'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8M8 9h2"/></svg></span> Facturación
        </a>

        <a href="<?php echo APP_URL; ?>/admin/users/index.php"
           class="nav-link <?php echo ($activePage??'')==='users'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span> Usuarios
        </a>

      </div>
    </div>
    <?php endif; ?>

  </nav>

  <script>
  (function () {
    var nav = document.querySelector('.sidebar-nav');
    if (!nav) return;
    var KEY = 'sb_collapsed';
    var collapsed = [];
    try { collapsed = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { collapsed = []; }

    var groups = nav.querySelectorAll('.sb-group');
    // Find the group containing the active link so it always stays expanded.
    var activeGroupId = null;
    var activeLink = nav.querySelector('.nav-link.active');
    if (activeLink) {
      var g = activeLink.closest('.sb-group');
      if (g) activeGroupId = g.getAttribute('data-sb-group');
    }

    function save() {
      try { localStorage.setItem(KEY, JSON.stringify(collapsed)); } catch (e) {}
    }

    groups.forEach(function (group) {
      var id = group.getAttribute('data-sb-group');
      var header = group.querySelector('.sb-header');
      var shouldCollapse = collapsed.indexOf(id) !== -1 && id !== activeGroupId;
      if (shouldCollapse) {
        group.classList.add('collapsed');
        if (header) header.setAttribute('aria-expanded', 'false');
      }
      if (header) {
        header.addEventListener('click', function () {
          var nowCollapsed = group.classList.toggle('collapsed');
          header.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
          var i = collapsed.indexOf(id);
          if (nowCollapsed) { if (i === -1) collapsed.push(id); }
          else { if (i !== -1) collapsed.splice(i, 1); }
          save();
        });
      }
    });
  })();
  </script>

  <div class="sidebar-user">
    <div class="sidebar-user-avatar">
      <?php echo strtoupper(substr($_SESSION['user_name']??'U', 0, 1)); ?>
    </div>
    <div class="sidebar-user-info">
      <div class="sidebar-user-name"><?php echo clean($_SESSION['user_name']??''); ?></div>
      <div class="sidebar-user-role"><?php echo ucfirst($_SESSION['user_role']??''); ?></div>
    </div>
    <a href="<?php echo APP_URL; ?>/auth/logout.php" class="sidebar-logout" title="Cerrar sesion">&#9211;</a>
  </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="main-wrapper">
  <header class="topbar">
    <button class="topbar-menu" id="menuToggle" aria-label="Abrir menu">&#9776;</button>
    <div class="topbar-title"><?php echo isset($pageTitle) ? clean($pageTitle) : 'Panel'; ?></div>
    <div class="topbar-actions">
      <a href="<?php echo APP_URL; ?>/quotes/create.php" class="btn-topbar-new">+ Cotizacion</a>
    </div>
  </header>

  <?php foreach (getFlashMessages() as $msg): ?>
  <div class="flash-message flash-<?php echo clean($msg['type']); ?>">
    <?php $icon = array('success'=>'&#10003;','error'=>'&#10007;','info'=>'&#9432;'); ?>
    <span class="flash-icon"><?php echo isset($icon[$msg['type']])?$icon[$msg['type']]:'&bull;'; ?></span>
    <?php echo clean($msg['message']); ?>
    <button class="flash-close" onclick="this.parentElement.remove()">&#10005;</button>
  </div>
  <?php endforeach; ?>

  <main class="page-content">
