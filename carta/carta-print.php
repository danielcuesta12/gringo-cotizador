<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin(); // las cartas del generador son borradores del dueño, no públicas

$id      = cleanInt($_GET['id'] ?? 0);
$preview = isset($_GET['preview']);
$c       = $id ? Database::fetch("SELECT * FROM cartas WHERE id = ?", [$id]) : null;
if (!$c) { http_response_code(404); echo 'Carta no encontrada.'; exit; }

// Colores de la carta (default = paleta noche si la columna no existe)
$col = [
  'bg'          => $c['col_bg']          ?? '#161412',
  'surface'     => $c['col_surface']     ?? '#211e1b',
  'text'        => $c['col_text']        ?? '#ffffff',
  'muted'       => $c['col_muted']       ?? '#9a9089',
  'accent'      => $c['col_accent']      ?? '#FFDF00',
  'section'     => $c['col_section']     ?? '#FFEFBC',
  'divider'     => $c['col_divider']     ?? '#4a4640',
  'header_bg'   => $c['col_header_bg']   ?? '#FFDF00',
  'header_text' => $c['col_header_text'] ?? '#1A1A1A',
];
// ¿el header es oscuro? → decide el filtro del logo (claro u oscuro)
$hb = sscanf($col['header_bg'], "#%02x%02x%02x");
$headerDark = $hb ? ((0.299*$hb[0] + 0.587*$hb[1] + 0.114*$hb[2]) < 140) : false;
$logoFilter = $headerDark ? 'brightness(0) invert(1)' : 'brightness(0)';

$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';

$secs = Database::fetchAll("SELECT * FROM carta_secciones WHERE carta_id = ? ORDER BY sort_order, id", [$id]);
foreach ($secs as &$s) {
    $s['items'] = Database::fetchAll("SELECT * FROM carta_items WHERE seccion_id = ? ORDER BY sort_order, id", [(int)$s['id']]);
}
unset($s);

// QR 1 (al landing) y QR 2 (link personalizado), opcionales, al pie
$qr1On  = !empty($c['qr_enabled']);
$qr1Url = '';
if ($qr1On) {
    $base   = rtrim(preg_replace('#/cotizador/?$#', '', APP_URL), '/');
    $qr1Url = $base . '/?src=' . rawurlencode(($c['qr_src'] ?? '') !== '' ? $c['qr_src'] : 'carta');
}
$qr2On  = !empty($c['qr2_enabled']) && trim((string)($c['qr2_url'] ?? '')) !== '';
$qr2Url = '';
if ($qr2On) {
    $qr2Url = trim($c['qr2_url']);
    if (($c['qr2_src'] ?? '') !== '') $qr2Url .= (strpos($qr2Url, '?') !== false ? '&' : '?') . 'src=' . rawurlencode($c['qr2_src']);
}
$anyQr = $qr1On || $qr2On;
?>
<!DOCTYPE html>
<html lang="es" style="--sz-section:<?= (float)$c['size_section'] ?>mm;--sz-name:<?= (float)$c['size_name'] ?>mm;--sz-price:<?= (float)$c['size_price'] ?>mm;--sz-desc:<?= (float)$c['size_desc'] ?>mm;--sz-photo:<?= (float)$c['size_photo'] ?>mm;--sz-header:<?= (float)$c['size_header'] ?>mm;--ancho:<?= (int)$c['ancho_mm'] ?>mm;--bg:<?= htmlspecialchars($col['bg']) ?>;--surface:<?= htmlspecialchars($col['surface']) ?>;--text:<?= htmlspecialchars($col['text']) ?>;--muted:<?= htmlspecialchars($col['muted']) ?>;--accent:<?= htmlspecialchars($col['accent']) ?>;--section:<?= htmlspecialchars($col['section']) ?>;--divider:<?= htmlspecialchars($col['divider']) ?>;--header-bg:<?= htmlspecialchars($col['header_bg']) ?>;--header-text:<?= htmlspecialchars($col['header_text']) ?>;">
<head>
<meta charset="UTF-8">
<title>Carta · <?= clean($c['nombre']) ?></title>
<style>
  @font-face { font-family:'ArialNarrowBold'; src:url('<?= APP_URL ?>/assets/fonts/Arial_Narrow_Bold.ttf') format('truetype'); font-display:swap; }
  * { box-sizing:border-box; margin:0; padding:0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  html, body { background:var(--bg); -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  body { width:var(--ancho); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; color:var(--text); -webkit-font-smoothing:antialiased; }
  .banner-header { background:var(--header-bg); color:var(--header-text); text-align:center; padding:18mm 14mm; }
  .banner-header img { height:var(--sz-header); width:auto; object-fit:contain; filter:<?= $logoFilter ?>; }
  .banner-header .brandtxt { font-weight:900; font-size:24mm; letter-spacing:1mm; line-height:.95; }
  .banner-body { padding:14mm 16mm 18mm; }
  .sec { margin-bottom:14mm; break-inside:avoid; }
  .sec-title { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:var(--sz-section); letter-spacing:2.5mm; text-transform:uppercase; color:var(--section); font-weight:700; padding-bottom:4mm; margin-bottom:8mm; border-bottom:1.4mm solid var(--divider); }
  .sec-rows.cols2 { display:grid; grid-template-columns:1fr 1fr; column-gap:14mm; }
  .row { padding:6mm 0; break-inside:avoid; }
  .sec-rows.cols1 > .row + .row { border-top:.5mm solid var(--divider); }
  .sec-rows.cols2 > .row { border-top:.5mm solid var(--divider); }
  /* 1 columna: foto | nombre+descripción | precio a la derecha */
  .sec-rows.cols1 > .row { display:grid; grid-template-columns:auto 1fr auto; grid-template-areas:"foto name price" "foto desc price"; column-gap:10mm; row-gap:1.5mm; align-items:center; }
  /* 2 columnas: foto+nombre arriba, descripción debajo, precio centrado debajo */
  .sec-rows.cols2 > .row { display:grid; grid-template-columns:auto 1fr; grid-template-areas:"foto name" "desc desc" "price price"; column-gap:8mm; row-gap:3mm; align-items:center; }
  .row-foto, .row-foto-ph { grid-area:foto; width:var(--sz-photo); height:var(--sz-photo); border-radius:7mm; background:var(--surface); }
  .row-foto { object-fit:cover; }
  .row-name { grid-area:name; font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:var(--sz-name); text-transform:uppercase; letter-spacing:.5mm; line-height:1; font-weight:700; }
  .row-desc { grid-area:desc; font-size:var(--sz-desc); color:var(--muted); line-height:1.3; }
  .row-price { grid-area:price; font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:var(--sz-price); font-weight:700; color:var(--accent); white-space:nowrap; align-self:center; }
  .sec-rows.cols1 .row-price { text-align:right; }
  .sec-rows.cols2 .row-price { text-align:center; }
  .banner-qr { display:flex; justify-content:center; align-items:flex-start; gap:18mm; padding:0 14mm 20mm; }
  .qr-item { text-align:center; }
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
          <div class="row-name"><?= clean($p['nombre']) ?></div>
          <?php if (trim((string)$p['descripcion']) !== ''): ?><div class="row-desc"><?= clean($p['descripcion']) ?></div><?php endif; ?>
          <div class="row-price"><?= formatMoney((float)$p['precio']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($anyQr): ?>
  <div class="banner-qr">
    <?php if ($qr1On): ?><div class="qr-item"><div class="qr-card"><div id="qrbox1"></div></div><div class="qr-label">Escanéame</div></div><?php endif; ?>
    <?php if ($qr2On): ?><div class="qr-item"><div class="qr-card"><div id="qrbox2"></div></div><div class="qr-label"><?= clean(($c['qr2_label'] ?? '') !== '' ? $c['qr2_label'] : 'Escanéame') ?></div></div><?php endif; ?>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>
    <?php if ($qr1On): ?>new QRCode(document.getElementById('qrbox1'), { text: <?= json_encode($qr1Url) ?>, width: 360, height: 360, colorDark: '#1A1A1A', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });<?php endif; ?>
    <?php if ($qr2On): ?>new QRCode(document.getElementById('qrbox2'), { text: <?= json_encode($qr2Url) ?>, width: 360, height: 360, colorDark: '#1A1A1A', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });<?php endif; ?>
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
