<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

$id  = cleanInt($_GET['id'] ?? 0);
$loc = $id ? Database::fetch("SELECT * FROM ubicaciones WHERE id = ?", [$id]) : null;
if (!$loc) { flashMessage('error', 'Ubicación no encontrada.'); redirect('/admin/locations/index.php'); }

$bannerBase = APP_URL . '/carta/banner.php?slug=' . rawurlencode($loc['slug']);

$pageTitle  = 'Banner — ' . $loc['nombre'];
$activePage = 'locations';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/locations/index.php">Ubicaciones</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= clean($loc['nombre']) ?> · Banner</span>
</div>

<div class="page-header"><div class="page-header-left"><h1>Banner imprimible — <?= clean($loc['nombre']) ?></h1>
  <p>Gigantografía de 42 cm de ancho para el food truck. Elige el tema, ábrelo e imprime a PDF.</p></div></div>

<div class="card" style="max-width:560px">
  <div class="card-body">
    <label class="form-label">Tema del banner</label>
    <div style="display:flex;gap:10px;margin:6px 0 18px">
      <label style="flex:1;display:flex;align-items:center;gap:8px;border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer">
        <input type="radio" name="bannertheme" value="noche" checked> <span><strong>Nocturna</strong> · fondo oscuro</span>
      </label>
      <label style="flex:1;display:flex;align-items:center;gap:8px;border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer">
        <input type="radio" name="bannertheme" value="dia"> <span><strong>Crema</strong> · fondo claro</span>
      </label>
    </div>

    <button type="button" class="btn btn-primary" onclick="abrirBanner()" style="gap:6px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
      Abrir banner para imprimir
    </button>

    <div style="margin-top:18px;font-size:13px;color:var(--text-secondary);line-height:1.7">
      <strong>Cómo sacar el PDF:</strong><br>
      1. Pulsa «Abrir banner»: se abre en una pestaña nueva.<br>
      2. Ahí pulsa «Imprimir / Guardar PDF» (o Cmd/Ctrl + P).<br>
      3. En destino elige <strong>Guardar como PDF</strong> y guarda. El PDF sale a 42 cm de ancho exacto.<br>
      4. Envía ese PDF a la imprenta. (Para mejores fotos, usa imágenes de producto en buena resolución desde <em>Productos</em>.)
    </div>
  </div>
</div>

<script>
  var BANNER_BASE = <?= json_encode($bannerBase) ?>;
  function abrirBanner() {
    var t = document.querySelector('input[name="bannertheme"]:checked');
    var theme = t ? t.value : 'noche';
    window.open(BANNER_BASE + '&theme=' + encodeURIComponent(theme), '_blank', 'noopener');
  }
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
