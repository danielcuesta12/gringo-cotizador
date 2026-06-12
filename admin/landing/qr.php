<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('landing');

// URL pública de la landing: raíz del dominio
$base = rtrim(preg_replace('#/cotizador/?$#', '', APP_URL), '/');

$pageTitle  = 'QR de la landing';
$activePage = 'landing';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/landing/index.php">Landing</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current">QR</span>
</div>

<div class="page-header"><div class="page-header-left">
  <h1>QR de la landing</h1>
  <p>Apunta a <strong><?= clean($base) ?></strong>. La <em>etiqueta de origen</em> queda en la URL (<code>?src=…</code>) para atribuir luego en analítica.</p>
</div></div>

<div class="card" style="max-width:440px">
  <div class="card-body">

    <div class="form-group" style="margin-bottom:16px">
      <label>Etiqueta de origen <small style="font-weight:400;color:var(--text-muted)">(para distinguir cada QR)</small></label>
      <input type="text" id="srcInput" value="qr" placeholder="qr, sticker, volante, mesa…" oninput="rebuild()">
      <div class="form-hint">Ej: <code>sticker-foodtruck</code>, <code>volante</code>, <code>mesa-1</code>. Solo letras, números y guiones.</div>
    </div>

    <div style="text-align:center">
      <div id="qr" style="display:inline-block;padding:16px;background:#fff;border-radius:14px;border:1px solid var(--border)"></div>
      <div id="qrUrl" style="font-size:12px;color:var(--text-secondary);margin:14px 0;word-break:break-all;font-family:monospace"></div>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <button type="button" class="btn btn-primary" onclick="downloadQR()" style="gap:6px">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Descargar PNG
        </button>
        <a id="openLink" href="#" target="_blank" class="btn btn-ghost">Abrir landing</a>
      </div>
    </div>

  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  var BASE = <?= json_encode($base) ?>;
  var qr = null;

  function currentSrc() {
    return (document.getElementById('srcInput').value || 'qr')
      .toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '') || 'qr';
  }
  function currentUrl() {
    return BASE + '/?src=' + encodeURIComponent(currentSrc());
  }
  function rebuild() {
    var url = currentUrl();
    document.getElementById('qr').innerHTML = '';
    qr = new QRCode(document.getElementById('qr'), {
      text: url, width: 280, height: 280,
      colorDark: '#1A1A1A', colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M
    });
    document.getElementById('qrUrl').textContent = url;
    document.getElementById('openLink').href = url;
  }
  function downloadQR() {
    var el = document.querySelector('#qr img') || document.querySelector('#qr canvas');
    if (!el) return;
    var url = el.tagName === 'IMG' ? el.src : el.toDataURL('image/png');
    var a = document.createElement('a');
    a.href = url; a.download = 'qr-landing-' + currentSrc() + '.png';
    document.body.appendChild(a); a.click(); a.remove();
  }
  rebuild();
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
