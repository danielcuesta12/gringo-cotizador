<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$base = rtrim(preg_replace('#/cotizador/?$#', '', APP_URL), '/');

$ubis = Database::fetchAll(
    "SELECT * FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, sort_order, nombre"
);

$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';

/** Formatea una hora 0-24 a "6:00 pm". */
function horaLabel(int $h): string {
    $h = $h % 24;
    $suf = $h < 12 ? 'am' : 'pm';
    $h12 = $h % 12; if ($h12 === 0) $h12 = 12;
    return $h12 . ':00 ' . $suf;
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="noche">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>El Gringo — Elige tu tienda</title>
<link rel="icon" href="<?= appIcon(APP_URL.'/assets/img/favicon-180.png') ?>">
<script>
  // Tema (compartido con la carta) + auto-salto si ya eligió tienda antes.
  (function () {
    try {
      var saved = localStorage.getItem('carta_theme');
      var theme = (saved === 'dia' || saved === 'noche') ? saved : null;
      if (!theme) { var h = new Date().getHours(); theme = (h >= 18 || h < 7) ? 'noche' : 'dia'; }
      document.documentElement.setAttribute('data-theme', theme);
      var ubi = localStorage.getItem('carta_ubi');
      if (ubi) { location.replace(<?= json_encode($base) ?> + '/' + encodeURIComponent(ubi)); }
    } catch (e) {}
  })();
  function toggleTheme() {
    var el = document.documentElement;
    var t = el.getAttribute('data-theme') === 'dia' ? 'noche' : 'dia';
    el.setAttribute('data-theme', t);
    try { localStorage.setItem('carta_theme', t); } catch (e) {}
  }
</script>
<style>
  @font-face {
    font-family:'ArialNarrowBold';
    src: url('<?= APP_URL ?>/assets/fonts/Arial_Narrow_Bold.ttf') format('truetype');
    font-display: swap;
  }
  *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }

  html[data-theme="noche"] {
    --bg:#1A1A1A; --surface:#242424; --surface-2:#2a2a2a;
    --text:#FFFFFF; --text-soft:#aaaaaa; --muted:#999999;
    --accent:#FCDA13; --accent-ink:#1A1A1A; --accent-tint:rgba(252,218,19,.15);
    --green:#34d399; --green-tint:rgba(52,211,153,.15);
    --border:rgba(255,255,255,0.08);
    --header-bg:var(--accent); --header-text:var(--accent-ink);
  }
  html[data-theme="dia"] {
    --bg:#FFEFBC; --surface:#ffffff; --surface-2:#f1ede2;
    --text:#1E1E1E; --text-soft:#6f6750; --muted:#7a6f55;
    --accent:#1E1E1E; --accent-ink:#FFEFBC; --accent-tint:rgba(30,30,30,.08);
    --green:#1f8a4c; --green-tint:rgba(31,138,76,.14);
    --border:rgba(30,30,30,0.14);
    --header-bg:var(--accent); --header-text:var(--accent-ink);
  }

  body{
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
    background:var(--bg); color:var(--text); min-height:100vh;
  }

  /* Cabecera idéntica a la carta (barra con color de marca + logo) */
  header{
    height:64px; background:var(--header-bg); color:var(--header-text);
    display:flex; align-items:center; padding:0 16px; gap:12px;
    position:sticky; top:0; z-index:10;
  }
  .logo{ height:34px; width:auto; }
  html[data-theme="noche"] .logo{ filter:brightness(0); }       /* negro sobre amarillo */
  html[data-theme="dia"]   .logo{ filter:brightness(0) invert(1); } /* blanco sobre negro */
  .theme-toggle{
    margin-left:auto; display:inline-flex; align-items:center; gap:6px; cursor:pointer;
    font-size:12px; font-weight:800; color:var(--header-text);
    background:transparent; border:1.5px solid var(--header-text); border-radius:999px;
    padding:6px 12px; opacity:.85;
  }

  .wrap{ max-width:480px; margin:0 auto; padding:28px 16px 60px; }
  h1{
    font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif;
    text-transform:uppercase; letter-spacing:1.5px;
    font-size:30px; line-height:1.08; text-align:center; margin-bottom:8px;
  }
  .sub{ text-align:center; font-size:14px; color:var(--text-soft); margin-bottom:24px; }

  .stores{ display:flex; flex-direction:column; gap:13px; }
  .card{
    display:flex; align-items:center; gap:13px; text-decoration:none; color:inherit;
    background:var(--surface); border:1px solid var(--border); border-radius:14px; padding:15px;
    transition:transform .14s ease, border-color .14s ease;
  }
  .card:hover{ transform:translateY(-2px); border-color:var(--accent); }
  .card.closed{ opacity:.6; }
  .pin{ flex:0 0 44px; height:44px; border-radius:12px; display:grid; place-items:center;
    background:var(--accent-tint); color:var(--accent); }
  html[data-theme="dia"] .pin{ color:var(--text); }
  .pin svg{ width:21px; height:21px; }
  .info{ flex:1; min-width:0; }
  .name{
    font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif;
    text-transform:uppercase; letter-spacing:1px; font-size:18px; line-height:1.15;
  }
  .ref{ display:flex; align-items:center; gap:5px; font-size:12.5px; color:var(--text-soft); margin-top:4px; }
  .ref svg{ width:13px; height:13px; flex-shrink:0; }
  .meta{ display:flex; align-items:center; gap:8px; margin-top:8px; flex-wrap:wrap; }
  .badge{ font-size:11px; font-weight:800; padding:3px 9px; border-radius:999px; display:inline-flex; align-items:center; gap:5px; }
  .badge-open{ background:var(--green-tint); color:var(--green); }
  .badge-closed{ background:var(--accent-tint); color:var(--muted); }
  .dot{ width:6px; height:6px; border-radius:50%; background:currentColor; }
  .micro{ font-size:11.5px; font-weight:600; color:var(--text-soft); }
  .chev{ flex:0 0 auto; color:var(--text-soft); opacity:.6; }
  .chev svg{ width:20px; height:20px; }
  .foot{ text-align:center; font-size:12px; color:var(--muted); margin-top:26px; line-height:1.5; }
  .empty{ text-align:center; color:var(--text-soft); padding:40px 0; }
</style>
<?= brandHead() ?>
</head>
<body>
  <header>
    <?php if ($logoUrl): ?>
      <img class="logo" src="<?= htmlspecialchars($logoUrl) ?>" alt="El Gringo Burger Joint">
    <?php else: ?>
      <strong style="font-size:20px;letter-spacing:.5px">EL GRINGO</strong>
    <?php endif; ?>
    <button class="theme-toggle" onclick="toggleTheme()" type="button">☀︎ / ☾ Tema</button>
  </header>

  <div class="wrap">
    <h1>¿De qué tienda<br>quieres pedir?</h1>
    <p class="sub">Elige tu local y te mostramos su carta.</p>

    <div class="stores">
      <?php if (!$ubis): ?>
        <div class="empty">No hay tiendas disponibles por ahora.</div>
      <?php endif; ?>
      <?php foreach ($ubis as $u):
        $abierta = ubicacionAbierta($u);
        $ref     = trim((string)($u['referencia'] ?? ''));
        $href    = $base . '/' . rawurlencode($u['slug']);
        $pinSvg  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>';
      ?>
      <a class="card <?= $abierta ? '' : 'closed' ?>" href="<?= htmlspecialchars($href) ?>"
         onclick="try{localStorage.setItem('carta_ubi','<?= htmlspecialchars($u['slug'], ENT_QUOTES) ?>')}catch(e){}">
        <div class="pin"><?= $pinSvg ?></div>
        <div class="info">
          <div class="name"><?= clean($u['nombre']) ?></div>
          <?php if ($ref !== ''): ?>
            <div class="ref"><?= $pinSvg ?> <?= clean($ref) ?></div>
          <?php endif; ?>
          <div class="meta">
            <?php if ($abierta): ?>
              <span class="badge badge-open"><span class="dot"></span>Abierto</span>
              <span class="micro"><?= $u['sales_mode'] === 'menu' ? 'Solo menú' : 'Delivery y recojo' ?></span>
            <?php else: ?>
              <span class="badge badge-closed"><span class="dot"></span>Cerrado · abre <?= horaLabel((int)($u['hora_apertura'] ?? 0)) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg></div>
      </a>
      <?php endforeach; ?>
    </div>

    <p class="foot">Recordaremos tu elección.<br>Podrás cambiar de tienda cuando quieras.</p>
  </div>
</body>
</html>
