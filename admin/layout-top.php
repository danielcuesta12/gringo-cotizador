<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo isset($pageTitle) ? clean($pageTitle) . ' — ' : ''; ?>El Gringo Cotizador</title>
<link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
<?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body class="admin-layout">

<?php
// Logo desde company_settings
$_al  = getSetting('active_logo', 'a') === 'b' ? 'company_logo_b' : 'company_logo';
$_lp  = getSetting($_al, '');

// Contar solicitudes pendientes para el badge
$_pendingCount = 0;
try {
    $_prow = Database::fetch("SELECT COUNT(*) as n FROM quote_requests WHERE status='pendiente'");
    $_pendingCount = $_prow ? (int)$_prow['n'] : 0;
} catch (Exception $e) { $_pendingCount = 0; }
?>

<aside class="sidebar" id="sidebar">

  <div class="sidebar-brand">
    <div class="sidebar-brand-logo-wrap">
      <?php if ($_lp): ?>
        <img src="<?php echo UPLOAD_URL . $_lp; ?>" alt="Logo" class="sidebar-logo-img">
      <?php else: ?>
        <span class="sidebar-logo-fallback">&#127828;</span>
      <?php endif; ?>
      <div class="sidebar-brand-sub">Cotizador</div>
    </div>
    <button class="sidebar-close" id="sidebarClose" aria-label="Cerrar menu">&#10005;</button>
  </div>

  <nav class="sidebar-nav">

    <div class="nav-section-label">Principal</div>

    <a href="<?php echo APP_URL; ?>/admin/dashboard.php"
       class="nav-link <?php echo ($activePage??'')==='dashboard'?'active':''; ?>">
      <span class="nav-icon">&#128202;</span> Dashboard
    </a>

    <div class="nav-section-label">Gestion</div>

    <a href="<?php echo APP_URL; ?>/quotes/create.php"
       class="nav-link nav-link-highlight <?php echo ($activePage??'')==='quote-new'?'active':''; ?>">
      <span class="nav-icon">&#9998;</span> Nueva cotizacion
    </a>

    <a href="<?php echo APP_URL; ?>/admin/events/create"
       class="nav-link nav-link-pink <?php echo ($activePage??'')==='event-new'?'active':''; ?>">
      <span class="nav-icon">&#128197;</span> Nuevo evento
    </a>

    <a href="<?php echo APP_URL; ?>/quotes/list.php"
       class="nav-link <?php echo ($activePage??'')==='quotes'?'active':''; ?>">
      <span class="nav-icon">&#128203;</span> Cotizaciones
    </a>

    <a href="<?php echo APP_URL; ?>/admin/calendar"
       class="nav-link <?php echo ($activePage??'')==='calendar'?'active':''; ?>">
      <span class="nav-icon">&#128198;</span> Calendario
    </a>

    <a href="<?php echo APP_URL; ?>/admin/clients/index.php"
       class="nav-link <?php echo ($activePage??'')==='clients'?'active':''; ?>">
      <span class="nav-icon">&#128101;</span> Clientes
    </a>

    <a href="<?php echo APP_URL; ?>/admin/requests/index.php"
       class="nav-link <?php echo ($activePage??'')==='requests'?'active':''; ?>">
      <span class="nav-icon">&#128228;</span> Solicitudes
      <?php if ($_pendingCount > 0): ?>
        <span class="nav-badge nav-badge-pink"><?php echo $_pendingCount; ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section-label">Catalogo</div>

    <a href="<?php echo APP_URL; ?>/admin/products/index.php"
       class="nav-link <?php echo ($activePage??'')==='products'?'active':''; ?>">
      <span class="nav-icon">&#127828;</span> Productos
    </a>

    <a href="<?php echo APP_URL; ?>/admin/categories/index.php"
       class="nav-link <?php echo ($activePage??'')==='categories'?'active':''; ?>">
      <span class="nav-icon">&#127991;</span> Categorias
    </a>

    <a href="<?php echo APP_URL; ?>/admin/packages/index.php"
       class="nav-link <?php echo ($activePage??'')==='packages'?'active':''; ?>">
      <span class="nav-icon">&#128230;</span> Paquetes
    </a>

    <?php if (isAdmin()): ?>
    <div class="nav-section-label">Administracion</div>

    <a href="<?php echo APP_URL; ?>/admin/users/index.php"
       class="nav-link <?php echo ($activePage??'')==='users'?'active':''; ?>">
      <span class="nav-icon">&#128100;</span> Usuarios
    </a>

    <a href="<?php echo APP_URL; ?>/admin/settings/index.php"
       class="nav-link <?php echo ($activePage??'')==='settings'?'active':''; ?>">
      <span class="nav-icon">&#9881;</span> Configuracion
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
