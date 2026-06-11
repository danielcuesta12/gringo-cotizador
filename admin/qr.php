<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

$pageTitle  = 'Generador de QR';
$activePage = 'qr-gen';
include __DIR__ . '/layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Generador de QR</h1>
    <p>Crea un QR para cualquier enlace y define tu etiqueta de origen (src) para rastreo</p>
  </div>
</div>

<div class="card" style="max-width:560px">
  <div class="card-body">
    <div style="margin-bottom:14px">
      <label class="form-label">Enlace (URL)</label>
      <input type="text" id="qg-url" placeholder="https://elgringo.pe/..." oninput="renderQR()"
             style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;margin-top:4px">
    </div>
    <div style="margin-bottom:6px">
      <label class="form-label">Etiqueta de origen (src) — opcional</label>
      <input type="text" id="qg-src" placeholder="ej: flyer-junio" oninput="renderQR()"
             style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:9px;font-size:14px;margin-top:4px">
      <div style="font-size:11px;color:var(--text-muted);margin-top:5px">Se agrega como <code>?src=</code> (o <code>&amp;src=</code>) al final del enlace. El rastreo funciona si el destino registra el parámetro (como tu landing/sitio).</div>
    </div>

    <div style="text-align:center;margin:20px 0 14px">
      <div id="qg-qr" style="display:inline-block;padding:16px;background:#fff;border-radius:14px;border:1px solid var(--border)"></div>
      <div id="qg-final" style="font-size:12px;color:var(--text-secondary);margin-top:12px;word-break:break-all;font-family:monospace"></div>
    </div>

    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <button type="button" class="btn btn-primary" onclick="downloadQR()" style="gap:6px">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        Descargar PNG
      </button>
      <a id="qg-open" href="#" target="_blank" rel="noopener" class="btn btn-ghost">Abrir enlace</a>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  function finalUrl(){
    var u = document.getElementById('qg-url').value.trim();
    var s = document.getElementById('qg-src').value.trim();
    if (!u) return '';
    if (s) u += (u.indexOf('?') >= 0 ? '&' : '?') + 'src=' + encodeURIComponent(s);
    return u;
  }
  function renderQR(){
    var u = finalUrl();
    document.getElementById('qg-final').textContent = u;
    document.getElementById('qg-open').href = u || '#';
    var box = document.getElementById('qg-qr'); box.innerHTML = '';
    if (!u) return;
    new QRCode(box, { text: u, width: 240, height: 240, colorDark: '#1A1A1A', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
  }
  function downloadQR(){
    var el = document.querySelector('#qg-qr img') || document.querySelector('#qg-qr canvas');
    if (!el) { alert('Escribe un enlace primero.'); return; }
    var url = el.tagName === 'IMG' ? el.src : el.toDataURL('image/png');
    var a = document.createElement('a'); a.href = url; a.download = 'qr.png';
    document.body.appendChild(a); a.click(); a.remove();
  }
  renderQR();
</script>

<?php include __DIR__ . '/layout-bottom.php'; ?>
