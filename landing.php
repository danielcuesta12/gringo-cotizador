<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/landing_icons.php';

$links = Database::fetchAll("SELECT * FROM landing_links WHERE active = 1 ORDER BY sort_order, id");

$logoRel = getSetting('company_logo', '') ?: getSetting('company_logo_b', '');  // logo oscuro para fondo amarillo
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';
$tagline = getSetting('landing_tagline', 'Smash burgers · Pollo crispy · Salchipapas');
$ig      = getSetting('instagram_handle', 'elgringoburger');

$bgRel     = getSetting('landing_bg_image', '');
$bgUrl     = $bgRel ? UPLOAD_URL . $bgRel : '';
$transpar  = getSetting('landing_cards_transparent', '0') === '1';
$ov        = max(0, min(100, (int) getSetting('landing_bg_overlay', '28'))) / 100;  // intensidad del oscurecido

$bgColor   = getSetting('landing_bg_color',   '#FCDA13');  // fondo de página (si no hay foto)
$cardColor = getSetting('landing_card_color', '#181613');  // fondo de las tarjetas
$textColor = getSetting('landing_text_color', '#FFFFFF');  // texto de las tarjetas
$footColor = getSetting('landing_footer_color', '#666666'); // texto del pie de página
$tcRgb     = sscanf($textColor, "#%02x%02x%02x");
$mutedRgba = $tcRgb ? sprintf('rgba(%d,%d,%d,.55)', $tcRgb[0], $tcRgb[1], $tcRgb[2]) : 'rgba(255,255,255,.55)';

$iconBg = ['delivery'=>'rgba(0,0,0,.12)','whatsapp'=>'rgba(37,211,102,.15)','wa'=>'rgba(37,211,102,.15)'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#FCDA13">
<link rel="icon" type="image/png" href="/img/favicon.png">
<title>El Gringo Burger Joint</title>
<style>
  :root{ --bg:<?= htmlspecialchars($bgColor) ?>; --card:<?= htmlspecialchars($cardColor) ?>; --line:rgba(0,0,0,.14); --brand:#FCDA13; --brand-dark:#e6c400; --pink:#FAB8C0; --green:#25D366; --ink:#1a1a1a; --txt:<?= htmlspecialchars($textColor) ?>; --muted:<?= $mutedRgba ?>; }
  *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);min-height:100vh;display:flex;justify-content:center;padding:36px 20px 28px;-webkit-font-smoothing:antialiased}
  .wrap{width:100%;max-width:440px;display:flex;flex-direction:column;align-items:center}
  .logo{width:178px;margin-bottom:18px;animation:pop .5s cubic-bezier(.2,.8,.25,1) both}
  .logo-fallback{font-size:30px;font-weight:900;color:#1a1a1a;margin-bottom:14px;letter-spacing:-1px}
  @keyframes pop{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
  .tagline{font-size:13.5px;color:var(--muted);text-align:center;letter-spacing:.3px;margin-bottom:26px}
  .links{width:100%;display:flex;flex-direction:column;gap:12px}
  .lnk{display:flex;align-items:center;gap:14px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:15px 16px;text-decoration:none;color:var(--txt);transition:transform .12s ease,border-color .2s,background .2s}
  .lnk:hover{transform:translateY(-2px);border-color:rgba(255,255,255,.18)}
  .lnk:active{transform:translateY(0) scale(.99)}
  .lnk-ico{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:rgba(255,255,255,.07);color:var(--brand)}
  .lnk-ico svg{width:21px;height:21px}
  .lnk-tx{flex:1;min-width:0;display:flex;flex-direction:column}
  .lnk-title{display:block;font-size:15px;font-weight:700;line-height:1.2}
  .lnk-sub{display:block;font-size:12px;color:var(--muted);margin-top:2px}
  .lnk-arrow{color:var(--muted);flex-shrink:0;display:flex}
  .lnk-arrow svg{width:18px;height:18px}
  /* estilos */
  .lnk.primary{background:var(--card);border-color:var(--card)}
  .lnk.primary .lnk-ico{background:var(--brand);color:#1a1a1a}
  .lnk.wa   .lnk-ico{background:rgba(37,211,102,.15);color:var(--green)}
  .lnk.pink .lnk-ico{background:rgba(250,184,192,.16);color:var(--pink)}
  .lnk.dark .lnk-ico{background:rgba(255,255,255,.10);color:#fff}
  /* card desplegable (cotizar evento) */
  .lnk-expand{width:100%;border:none;text-align:left;font:inherit;cursor:pointer}
  .lnk-chev svg{transition:transform .28s ease}
  .lnk-expand.open .lnk-chev svg{transform:rotate(180deg)}
  .lnk-wrap{display:flex;flex-direction:column}
  .quote-panel{max-height:0;overflow:hidden;transition:max-height .4s ease;border-radius:16px}
  .quote-panel.open{max-height:1600px;margin-top:12px}
  .quote-panel iframe{width:100%;border:0;display:block;background:#f4f4f0;border-radius:16px}
  .foot{margin-top:26px;font-size:11px;color:rgba(0,0,0,.45);text-align:center}
</style>
<?php if ($bgUrl): ?>
<style>
  body{
    background:#181613 url('<?= htmlspecialchars($bgUrl) ?>') center/cover no-repeat fixed;
    position:relative;
  }
  body::before{
    content:'';position:fixed;inset:0;z-index:0;
    background:linear-gradient(180deg, rgba(0,0,0,<?= round($ov*0.18,3) ?>) 0%, rgba(0,0,0,<?= round($ov*0.45,3) ?>) 55%, rgba(0,0,0,<?= round($ov,3) ?>) 100%);
  }
  .wrap{position:relative;z-index:1}
  .logo-fallback{color:#fff}
  .foot{color:rgba(255,255,255,.6)}
</style>
<?php endif; ?>
<?php if ($transpar): ?>
<style>
  .lnk, .lnk.primary{
    background:rgba(20,18,15,.42);
    border-color:rgba(255,255,255,.16);
    -webkit-backdrop-filter:blur(13px) saturate(1.2);
    backdrop-filter:blur(13px) saturate(1.2);
  }
  .lnk.primary .lnk-ico{background:var(--brand);color:#1a1a1a}
  .lnk:hover{border-color:rgba(255,255,255,.28)}
  .quote-panel iframe{background:rgba(244,244,240,.96)}
</style>
<?php endif; ?>
<style>.foot{color:<?= htmlspecialchars($footColor) ?>}</style>
</head>
<body>
<div class="wrap">
  <?php if ($logoUrl): ?>
    <img class="logo" src="<?= htmlspecialchars($logoUrl) ?>" alt="El Gringo Burger Joint">
  <?php else: ?>
    <div class="logo-fallback">EL GRINGO</div>
  <?php endif; ?>

  <div class="links" style="margin-top:26px">
    <?php foreach ($links as $l):
      $tab = $l['new_tab'] ? ' target="_blank" rel="noopener"' : '';
      $isQuote = strpos($l['url'], 'solicitud') !== false;
    ?>
      <?php if ($isQuote):
        $embedUrl = $l['url'] . (strpos($l['url'], '?') !== false ? '&' : '?') . 'embed=1';
      ?>
      <div class="lnk-wrap">
        <button type="button" class="lnk <?= clean($l['style']) ?> lnk-expand" onclick="toggleQuote(this)" aria-expanded="false" data-link-id="<?= (int)$l['id'] ?>" data-label="<?= clean($l['label']) ?>">
          <span class="lnk-ico"><?= landingIconSvg($l['icon'], 21) ?></span>
          <span class="lnk-tx">
            <span class="lnk-title"><?= clean($l['label']) ?></span>
            <?php if ($l['sublabel']): ?><span class="lnk-sub"><?= clean($l['sublabel']) ?></span><?php endif; ?>
          </span>
          <span class="lnk-arrow lnk-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>
        </button>
        <div class="quote-panel" id="quotePanel"><iframe id="quoteFrame" data-src="<?= clean($embedUrl) ?>" title="Cotiza tu evento" loading="lazy"></iframe></div>
      </div>
      <?php else: ?>
      <a class="lnk <?= clean($l['style']) ?>" href="<?= clean($l['url']) ?>"<?= $tab ?> data-link-id="<?= (int)$l['id'] ?>" data-label="<?= clean($l['label']) ?>">
        <span class="lnk-ico"><?= landingIconSvg($l['icon'], 21) ?></span>
        <span class="lnk-tx">
          <span class="lnk-title"><?= clean($l['label']) ?></span>
          <?php if ($l['sublabel']): ?><span class="lnk-sub"><?= clean($l['sublabel']) ?></span><?php endif; ?>
        </span>
        <span class="lnk-arrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></span>
      </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="foot">© 2013 El Gringo Burger Joint · Lima, Perú</div>
</div>
<script>
  var quotePoll = null;
  function fitFrame(){
    var frame = document.getElementById('quoteFrame');
    if (!frame || !frame.contentWindow) return;
    try {
      var d = frame.contentWindow.document;
      var h = Math.max(d.body ? d.body.scrollHeight : 0, d.documentElement.scrollHeight);
      if (h > 0) frame.style.height = h + 'px';
    } catch(e){}
  }
  function toggleQuote(btn){
    var panel = document.getElementById('quotePanel'), frame = document.getElementById('quoteFrame');
    var open = !panel.classList.contains('open');           // estado real del DOM (no variable)
    panel.classList.toggle('open', open);
    btn.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open);
    clearInterval(quotePoll);
    if (open){
      if (!frame.src){ frame.addEventListener('load', fitFrame); frame.src = frame.dataset.src; }
      else fitFrame();
      quotePoll = setInterval(fitFrame, 250);  // reajusta al cambiar de paso (mismo dominio = medición directa)
      setTimeout(function(){ btn.scrollIntoView({behavior:'smooth', block:'start'}); }, 90);
    }
  }
</script>
<script>window.TRACK_URL = '<?= APP_URL ?>/api/track.php';</script>
<script src="<?= APP_URL ?>/assets/js/track.js?v=<?= @filemtime(__DIR__ . '/assets/js/track.js') ?>"></script>
<script>
  track('page_view', 'landing');
  document.querySelectorAll('.lnk').forEach(function (el) {
    el.addEventListener('click', function () {
      track('link_click', 'landing', { meta: { label: el.dataset.label || '', link_id: el.dataset.linkId || '' } });
    });
  });
</script>
</body>
</html>
