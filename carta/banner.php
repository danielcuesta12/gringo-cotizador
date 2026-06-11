<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$slug  = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['slug'] ?? '');
$theme = ($_GET['theme'] ?? 'noche') === 'dia' ? 'dia' : 'noche';
$ubi   = $slug ? Database::fetch("SELECT * FROM ubicaciones WHERE slug = ? AND activa = 1", [$slug]) : null;
if (!$ubi) { http_response_code(404); echo 'Carta no encontrada.'; exit; }
$ubiId = (int) $ubi['id'];

$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';

$rows = Database::fetchAll(
    "SELECT c.name AS cat_name, c.sort_order AS cat_order,
            p.name AS pname, p.description AS pdesc, p.image AS pimg,
            lp.price AS price
     FROM location_products lp
     JOIN products p ON p.id = lp.product_id AND p.active = 1
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE lp.location_id = ? AND lp.available = 1
     ORDER BY c.sort_order, c.name, lp.sort_order, p.sort_order, p.name",
    [$ubiId]
);

$secs = [];
foreach ($rows as $r) {
    $cat = $r['cat_name'] ?: 'Carta';
    $secs[$cat][] = $r;
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8">
<title>Banner · <?= clean($ubi['nombre']) ?></title>
<style>
  @font-face { font-family:'ArialNarrowBold'; src:url('<?= APP_URL ?>/assets/fonts/Arial_Narrow_Bold.ttf') format('truetype'); font-display:swap; }
  html[data-theme="noche"] {
    --bg:#161412; --surface:#211e1b; --text:#ffffff; --muted:#9a9089;
    --accent:#FFDF00; --section:#FFEFBC; --divider:rgba(255,255,255,.18); --header-bg:#FFDF00; --header-text:#1A1A1A;
  }
  html[data-theme="dia"] {
    --bg:#FFEFBC; --surface:#ffffff; --text:#1E1E1E; --muted:#7a6f55;
    --accent:#1E1E1E; --section:#1E1E1E; --divider:rgba(30,30,30,.25); --header-bg:#1E1E1E; --header-text:#FFEFBC;
  }
  * { box-sizing:border-box; margin:0; padding:0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  html, body { background:var(--bg); -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  body { width:420mm; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; color:var(--text); -webkit-font-smoothing:antialiased; }
  .banner-header { background:var(--header-bg); color:var(--header-text); text-align:center; padding:18mm 14mm; }
  .banner-header img { height:55mm; width:auto; object-fit:contain; }
  html[data-theme="noche"] .banner-header img { filter:brightness(0); }
  html[data-theme="dia"]   .banner-header img { filter:brightness(0) invert(1); }
  .banner-header .brandtxt { font-weight:900; font-size:24mm; letter-spacing:1mm; line-height:.95; }
  .banner-header .sub { font-size:7mm; font-weight:800; letter-spacing:4mm; margin-top:4mm; }
  .banner-body { padding:14mm 16mm 18mm; }
  .sec { margin-bottom:14mm; break-inside:avoid; }
  .sec-title { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:24mm; letter-spacing:2.5mm; text-transform:uppercase; color:var(--section); font-weight:700; padding-bottom:4mm; margin-bottom:8mm; border-bottom:1.4mm solid var(--divider); }
  .row { display:flex; gap:10mm; align-items:center; padding:6mm 0; break-inside:avoid; }
  .row + .row { border-top:.5mm solid var(--divider); }
  .row-foto { width:60mm; height:60mm; border-radius:7mm; object-fit:cover; flex-shrink:0; background:var(--surface); }
  .row-foto-ph { width:60mm; height:60mm; border-radius:7mm; flex-shrink:0; background:var(--surface); }
  .row-main { flex:1; min-width:0; }
  .row-name { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:18mm; text-transform:uppercase; letter-spacing:.5mm; line-height:1; font-weight:700; }
  .row-desc { font-size:14mm; color:var(--muted); line-height:1.3; margin-top:2.5mm; }
  .row-price { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:16mm; font-weight:700; color:var(--accent); white-space:nowrap; flex-shrink:0; text-align:right; padding-left:8mm; }
  .printbar { position:fixed; top:10px; right:10px; z-index:10; display:flex; gap:8px; font-family:-apple-system,sans-serif; }
  .printbar button { padding:10px 16px; border:none; border-radius:10px; background:#1A1A1A; color:#fff; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 4px 14px rgba(0,0,0,.3); }
  @media print { .printbar { display:none !important; } }
</style>
</head>
<body>
  <div class="printbar"><button onclick="window.print()">Imprimir / Guardar PDF</button></div>

  <div class="banner-header">
    <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" alt="El Gringo"><?php else: ?>
      <div class="brandtxt">EL GRINGO</div><?php endif; ?>
  </div>

  <div class="banner-body">
    <?php if (empty($secs)): ?>
      <div style="text-align:center;color:var(--muted);font-size:6mm;padding:20mm 0">Sin productos disponibles en esta ubicación.</div>
    <?php endif; ?>
    <?php foreach ($secs as $cat => $prods): ?>
    <div class="sec">
      <div class="sec-title"><?= clean($cat) ?></div>
      <?php foreach ($prods as $p): ?>
      <div class="row">
        <?php if ($p['pimg']): ?>
          <img class="row-foto" src="<?= htmlspecialchars(UPLOAD_URL . $p['pimg']) ?>" alt="">
        <?php else: ?>
          <div class="row-foto-ph"></div>
        <?php endif; ?>
        <div class="row-main">
          <div class="row-name"><?= clean($p['pname']) ?></div>
          <?php if (trim((string)$p['pdesc']) !== ''): ?>
            <div class="row-desc"><?= clean($p['pdesc']) ?></div>
          <?php endif; ?>
        </div>
        <div class="row-price"><?= formatMoney((float)$p['price']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <script>
    window.addEventListener('load', function () {
      var px = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
      var mm = Math.ceil(px * 25.4 / 96) + 2;
      var st = document.createElement('style');
      st.textContent = '@page { size: 420mm ' + mm + 'mm; margin: 0; }';
      document.head.appendChild(st);
    });
  </script>
</body>
</html>
