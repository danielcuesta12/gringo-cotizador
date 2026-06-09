<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo isset($pageTitle) ? clean($pageTitle) . ' — ' : ''; ?>El Gringo Cotizador</title>
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

    <div class="nav-section-label">Principal</div>

    <a href="<?php echo APP_URL; ?>/admin/dashboard.php"
       class="nav-link <?php echo ($activePage??'')==='dashboard'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg></span> Dashboard
    </a>

    <a href="<?php echo APP_URL; ?>/quotes/create.php"
       class="nav-link nav-link-highlight <?php echo ($activePage??'')==='quote-new'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span> Nueva cotización
    </a>

    <a href="<?php echo APP_URL; ?>/admin/events/create"
       class="nav-link nav-link-event <?php echo ($activePage??'')==='event-new'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg></span> Nuevo evento
    </a>

    <div class="nav-section-label">Gestión</div>

    <a href="<?php echo APP_URL; ?>/quotes/list.php"
       class="nav-link <?php echo ($activePage??'')==='quotes'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg></span> Cotizaciones
    </a>

    <a href="<?php echo APP_URL; ?>/admin/calendar"
       class="nav-link <?php echo ($activePage??'')==='calendar'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span> Calendario
    </a>

    <a href="<?php echo APP_URL; ?>/admin/clients/index.php"
       class="nav-link <?php echo ($activePage??'')==='clients'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> Clientes
    </a>

    <a href="<?php echo APP_URL; ?>/admin/requests/index.php"
       class="nav-link <?php echo ($activePage??'')==='requests'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/></svg></span> Solicitudes
      <?php if ($pendingCount > 0): ?>
        <span class="nav-badge nav-badge-req"><?php echo $pendingCount; ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section-label">Catálogo</div>

    <a href="<?php echo APP_URL; ?>/admin/products/index.php"
       class="nav-link <?php echo ($activePage??'')==='products'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg></span> Productos
    </a>

    <a href="<?php echo APP_URL; ?>/admin/categories/index.php"
       class="nav-link <?php echo ($activePage??'')==='categories'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.41l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42Z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor" stroke="none"/></svg></span> Categorías
    </a>

    <a href="<?php echo APP_URL; ?>/admin/packages/index.php"
       class="nav-link <?php echo ($activePage??'')==='packages'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg></span> Paquetes
    </a>

    <?php if (isAdmin()): ?>
    <div class="nav-section-label">Administración</div>

    <a href="<?php echo APP_URL; ?>/admin/users/index.php"
       class="nav-link <?php echo ($activePage??'')==='users'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span> Usuarios
    </a>

    <a href="<?php echo APP_URL; ?>/admin/settings/index.php"
       class="nav-link <?php echo ($activePage??'')==='settings'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg></span> Configuración
    </a>
    <?php endif; ?>

  </nav>

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
