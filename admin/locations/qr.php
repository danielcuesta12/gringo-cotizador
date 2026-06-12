<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('locations');

$id  = cleanInt($_GET['id'] ?? 0);
$loc = $id ? Database::fetch("SELECT * FROM ubicaciones WHERE id = ?", [$id]) : null;
if (!$loc) { flashMessage('error', 'Ubicación no encontrada.'); redirect('/admin/locations/index.php'); }

// URL pública del menú: raíz del dominio + /{slug}/menu + origen para atribución
$base    = rtrim(preg_replace('#/cotizador/?$#', '', APP_URL), '/');
$menuUrl = $base . '/' . rawurlencode($loc['slug']) . '/menu?src=qr-' . rawurlencode($loc['slug']);

$pageTitle  = 'QR — ' . $loc['nombre'];
$activePage = 'locations';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/locations/index.php">Ubicaciones</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= clean($loc['nombre']) ?> · QR</span>
</div>

<div class="page-header"><div class="page-header-left"><h1>QR del menú — <?= clean($loc['nombre']) ?></h1>
  <p>Imprímelo o compártelo. Apunta al menú público de esta ubicación.</p></div></div>

<div class="card" style="max-width:420px">
  <div class="card-body" style="text-align:center">
    <div id="qr" style="display:inline-block;padding:16px;background:#fff;border-radius:14px;border:1px solid var(--border)"></div>
    <div style="font-size:12px;color:var(--text-secondary);margin:14px 0;word-break:break-all;font-family:monospace"><?= clean($menuUrl) ?></div>
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <button type="button" class="btn btn-primary" onclick="downloadQR()" style="gap:6px">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        Descargar PNG
      </button>
      <a href="<?= clean($menuUrl) ?>" target="_blank" class="btn btn-ghost">Abrir menú</a>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  var MENU_URL = <?= json_encode($menuUrl) ?>;
  var SLUG = <?= json_encode($loc['slug']) ?>;
  new QRCode(document.getElementById('qr'), {
    text: MENU_URL, width: 280, height: 280,
    colorDark: '#1A1A1A', colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });
  function downloadQR() {
    var el = document.querySelector('#qr img') || document.querySelector('#qr canvas');
    if (!el) return;
    var url = el.tagName === 'IMG' ? el.src : el.toDataURL('image/png');
    var a = document.createElement('a');
    a.href = url; a.download = 'qr-' + SLUG + '-menu.png';
    document.body.appendChild(a); a.click(); a.remove();
  }
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
