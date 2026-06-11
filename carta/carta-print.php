<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin(); // las cartas del generador son borradores del dueño, no públicas

$id      = cleanInt($_GET['id'] ?? 0);
$preview = isset($_GET['preview']);
$c       = $id ? Database::fetch("SELECT * FROM cartas WHERE id = ?", [$id]) : null;
if (!$c) { http_response_code(404); echo 'Carta no encontrada.'; exit; }
$theme = ($_GET['theme'] ?? $c['tema']) === 'dia' ? 'dia' : 'noche';

$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';

$secs = Database::fetchAll("SELECT * FROM carta_secciones WHERE carta_id = ? ORDER BY sort_order, id", [$id]);
foreach ($secs as &$s) {
    $s['items'] = Database::fetchAll("SELECT * FROM carta_items WHERE seccion_id = ? ORDER BY sort_order, id", [(int)$s['id']]);
}
unset($s);

// QR opcional al pie → apunta al landing (raíz del dominio) con ?src= para rastreo
$qrOn  = !empty($c['qr_enabled']);
$qrUrl = '';
if ($qrOn) {
    $base  = rtrim(preg_replace('#/cotizador/?$#', '', APP_URL), '/');
    $qrUrl = $base . '/?src=' . rawurlencode(($c['qr_src'] ?? '') !== '' ? $c['qr_src'] : 'carta');
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= $theme ?>" style="--sz-section:<?= (float)$c['size_section'] ?>mm;--sz-name:<?= (float)$c['size_name'] ?>mm;--sz-price:<?= (float)$c['size_price'] ?>mm;--sz-desc:<?= (float)$c['size_desc'] ?>mm;--sz-photo:<?= (float)$c['size_photo'] ?>mm;--sz-header:<?= (float)$c['size_header'] ?>mm;--ancho:<?= (int)$c['ancho_mm'] ?>mm;">
<head>
<meta charset="UTF-8">
<title>Carta · <?= clean($c['nombre']) ?></title>
<style>
  @font-face { font-family:'ArialNarrowBold'; src:url('<?= APP_URL ?>/assets/fonts/Arial_Narrow_Bold.ttf') format('truetype'); font-display:swap; }
  html[data-theme="noche"] { --bg:#161412; --surface:#211e1b; --text:#ffffff; --muted:#9a9089; --accent:#FFDF00; --section:#FFEFBC; --divider:rgba(255,255,255,.18); --header-bg:#FFDF00; --header-text:#1A1A1A; }
  html[data-theme="dia"]   { --bg:#FFEFBC; --surface:#ffffff; --text:#1E1E1E; --muted:#7a6f55; --accent:#1E1E1E; --section:#1E1E1E; --divider:rgba(30,30,30,.25); --header-bg:#1E1E1E; --header-text:#FFEFBC; }
  * { box-sizing:border-box; margin:0; padding:0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  html, body { background:var(--bg); -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  body { width:var(--ancho); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; color:var(--text); -webkit-font-smoothing:antialiased; }
  .banner-header { background:var(--header-bg); color:var(--header-text); text-align:center; padding:18mm 14mm; }
  .banner-header img { height:var(--sz-header); width:auto; object-fit:contain; }
  html[data-theme="noche"] .banner-header img { filter:brightness(0); }
  html[data-theme="dia"]   .banner-header img { filter:brightness(0) invert(1); }
  .banner-header .brandtxt { font-weight:900; font-size:24mm; letter-spacing:1mm; line-height:.95; }
  .banner-body { padding:14mm 16mm 18mm; }
  .sec { margin-bottom:14mm; break-inside:avoid; }
  .sec-title { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:var(--sz-section); letter-spacing:2.5mm; text-transform:uppercase; color:var(--section); font-weight:700; padding-bottom:4mm; margin-bottom:8mm; border-bottom:1.4mm solid var(--divider); }
  .sec-rows.cols2 { display:grid; grid-template-columns:1fr 1fr; column-gap:14mm; }
  .row { display:flex; gap:10mm; align-items:center; padding:6mm 0; break-inside:avoid; }
  .sec-rows.cols1 > .row + .row { border-top:.5mm solid var(--divider); }
  .sec-rows.cols2 > .row { border-top:.5mm solid var(--divider); }
  .row-foto { width:var(--sz-photo); height:var(--sz-photo); border-radius:7mm; object-fit:cover; flex-shrink:0; background:var(--surface); }
  .row-foto-ph { width:var(--sz-photo); height:var(--sz-photo); border-radius:7mm; flex-shrink:0; background:var(--surface); }
  .row-main { flex:1; min-width:0; }
  .row-name { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:var(--sz-name); text-transform:uppercase; letter-spacing:.5mm; line-height:1; font-weight:700; }
  .row-desc { font-size:var(--sz-desc); color:var(--muted); line-height:1.3; margin-top:2.5mm; }
  .row-price { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:var(--sz-price); font-weight:700; color:var(--accent); white-space:nowrap; flex-shrink:0; text-align:right; padding-left:8mm; }
  .banner-qr { text-align:center; padding:0 14mm 20mm; }
  .banner-qr .qr-card { display:inline-block; background:#fff; padding:6mm; border-radius:6mm; }
  .banner-qr .qr-card img, .banner-qr .qr-card canvas { width:52mm !important; height:52mm !important; display:block; }
  .banner-qr .qr-label { color:var(--text); font-size:5mm; font-weight:700; letter-spacing:1mm; text-transform:uppercase; margin-top:5mm; }
  .printbar { position:fixed; top:10px; right:10px; z-index:10; display:flex; gap:8px; font-family:-apple-system,sans-serif; }
  .printbar button { padding:10px 16px; border:none; border-radius:10px; background:#1A1A1A; color:#fff; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 4px 14px rgba(0,0,0,.3); }
  @media print { .printbar { display:none !important; } }
</style>
</head>
<body>
  <?php if (!$preview): ?><div class="printbar"><button onclick="window.print()">Imprimir / Guardar PDF</button></div><?php endif; ?>

  <div class="banner-header">
    <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" alt="El Gringo"><?php else: ?><div class="brandtxt">EL GRINGO</div><?php endif; ?>
  </div>

  <div class="banner-body">
    <?php if (empty($secs)): ?>
      <div style="text-align:center;color:var(--muted);font-size:6mm;padding:20mm 0">Esta carta aún no tiene ítems.</div>
    <?php endif; ?>
    <?php foreach ($secs as $s): ?>
    <div class="sec">
      <div class="sec-title"><?= clean($s['nombre']) ?></div>
      <div class="sec-rows cols<?= ((int)$s['columnas'] === 2) ? '2' : '1' ?>">
        <?php foreach ($s['items'] as $p): ?>
        <div class="row">
          <?php if ($p['foto']): ?>
            <img class="row-foto" src="<?= htmlspecialchars(UPLOAD_URL . $p['foto']) ?>" alt="">
          <?php else: ?>
            <div class="row-foto-ph"></div>
          <?php endif; ?>
          <div class="row-main">
            <div class="row-name"><?= clean($p['nombre']) ?></div>
            <?php if (trim((string)$p['descripcion']) !== ''): ?><div class="row-desc"><?= clean($p['descripcion']) ?></div><?php endif; ?>
          </div>
          <div class="row-price"><?= formatMoney((float)$p['precio']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($qrOn): ?>
  <div class="banner-qr">
    <div class="qr-card"><div id="qrbox"></div></div>
    <div class="qr-label">Escanéame</div>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>
    new QRCode(document.getElementById('qrbox'), { text: <?= json_encode($qrUrl) ?>, width: 360, height: 360, colorDark: '#1A1A1A', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
  </script>
  <?php endif; ?>

  <script>
    var ANCHO_MM = <?= (int)$c['ancho_mm'] ?>;
    window.addEventListener('load', function () {
      var px = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
      var mm = Math.ceil(px * 25.4 / 96) + 2;
      var st = document.createElement('style');
      st.textContent = '@page { size: ' + ANCHO_MM + 'mm ' + mm + 'mm; margin: 0; }';
      document.head.appendChild(st);
    });
  </script>
</body>
</html>
