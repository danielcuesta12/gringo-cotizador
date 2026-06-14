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
<link rel="icon" href="<?= APP_URL ?>/assets/img/favicon-180.png">
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
  :root{ --yellow:#FFDF00; --pink:#FFBBC8; --black:#1E1E1E; --cream:#FFEFBC; }
  *{box-sizing:border-box;margin:0;padding:0;-webkit-font-smoothing:antialiased}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    min-height:100vh;display:flex;flex-direction:column;align-items:center;
    padding:24px 16px 60px;transition:background .3s,color .3s;
  }
  html[data-theme="dia"] body{
    background:radial-gradient(1200px 420px at 50% -120px, rgba(255,187,200,.5), transparent 70%),
      linear-gradient(180deg,#FFF6D6 0%, #FFEFBC 100%);
    color:var(--black);
  }
  html[data-theme="dia"] .card{background:#fff;border:1px solid rgba(30,30,30,.08);box-shadow:0 6px 22px rgba(30,30,30,.06)}
  html[data-theme="dia"] .ref{color:#7a6f4d}
  html[data-theme="dia"] .micro{color:#8a7f5c}
  html[data-theme="dia"] .badge-closed{background:#efe7cf;color:#9a8f6c}
  html[data-theme="noche"] body{
    background:radial-gradient(1100px 420px at 50% -140px, rgba(255,187,200,.16), transparent 70%), #1A1A1A;
    color:#fff;
  }
  html[data-theme="noche"] .card{background:#262626;border:1px solid #333;box-shadow:0 8px 24px rgba(0,0,0,.35)}
  html[data-theme="noche"] .ref{color:#b9b2a0}
  html[data-theme="noche"] .micro{color:#8c8576}
  html[data-theme="noche"] .badge-closed{background:#333;color:#9b9b9b}

  .wrap{width:100%;max-width:440px}
  .theme-row{display:flex;justify-content:flex-end;margin-bottom:14px}
  .theme-toggle{display:inline-flex;align-items:center;gap:7px;cursor:pointer;font-size:12px;font-weight:700;
    padding:7px 13px;border-radius:999px;border:1px solid currentColor;opacity:.55;background:transparent;color:inherit}
  .brand{text-align:center;margin-bottom:8px}
  .brand img{max-height:64px;width:auto}
  .brand .logo-txt{display:inline-block;font-weight:900;font-size:28px;letter-spacing:-.5px;
    background:var(--yellow);color:var(--black);padding:9px 15px;border-radius:12px;transform:rotate(-2deg)}
  h1{font-size:25px;font-weight:900;letter-spacing:-.4px;margin:22px 0 6px;text-align:center;line-height:1.15}
  .sub{text-align:center;font-size:14px;opacity:.7;margin-bottom:22px}
  .stores{display:flex;flex-direction:column;gap:13px}
  .card{border-radius:18px;padding:17px;cursor:pointer;display:flex;align-items:center;gap:13px;
    text-decoration:none;color:inherit;transition:transform .14s ease, box-shadow .14s ease, border-color .14s ease}
  .card:hover{transform:translateY(-3px);border-color:var(--yellow)}
  .card.closed{opacity:.6}
  .pin{flex:0 0 44px;height:44px;border-radius:13px;display:grid;place-items:center;
    background:linear-gradient(135deg,var(--pink),#ff9fb3);color:var(--black)}
  .pin svg{width:21px;height:21px}
  .card.closed .pin{background:#cfccc4;color:#fff}
  .info{flex:1;min-width:0}
  .name{font-weight:800;font-size:16.5px;line-height:1.2}
  .ref{display:flex;align-items:center;gap:5px;font-size:12.5px;margin-top:4px}
  .ref svg{width:13px;height:13px;flex-shrink:0}
  .meta{display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap}
  .badge{font-size:11px;font-weight:800;padding:3px 9px;border-radius:999px;display:inline-flex;align-items:center;gap:5px}
  .badge-open{background:rgba(34,197,94,.16);color:#16a34a}
  html[data-theme="noche"] .badge-open{background:rgba(34,197,94,.2);color:#4ade80}
  .dot{width:6px;height:6px;border-radius:50%;background:currentColor}
  .micro{font-size:11.5px;font-weight:600}
  .chev{flex:0 0 auto;opacity:.4}
  .chev svg{width:20px;height:20px}
  .foot{text-align:center;font-size:12px;opacity:.5;margin-top:24px;line-height:1.5}
  .empty{text-align:center;opacity:.6;padding:40px 0}
</style>
</head>
<body>
  <div class="wrap">
    <div class="theme-row">
      <button class="theme-toggle" onclick="toggleTheme()" type="button">☀︎ / ☾ Tema</button>
    </div>

    <div class="brand">
      <?php if ($logoUrl): ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="El Gringo Burger Joint">
      <?php else: ?>
        <span class="logo-txt">EL GRINGO</span>
      <?php endif; ?>
    </div>

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
