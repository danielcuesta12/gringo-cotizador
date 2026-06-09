<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/landing_icons.php';

$links = Database::fetchAll("SELECT * FROM landing_links WHERE active = 1 ORDER BY sort_order, id");

$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';
$tagline = getSetting('landing_tagline', 'Smash burgers · Pollo crispy · Salchipapas');
$ig      = getSetting('instagram_handle', 'elgringoburger');

$iconBg = ['delivery'=>'rgba(0,0,0,.12)','whatsapp'=>'rgba(37,211,102,.15)','wa'=>'rgba(37,211,102,.15)'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#141210">
<link rel="icon" type="image/png" href="/img/favicon.png">
<title>El Gringo Burger Joint</title>
<style>
  :root{ --bg:#141210; --card:#1f1c19; --line:rgba(255,255,255,.08); --brand:#FCDA13; --brand-dark:#e6c400; --pink:#FAB8C0; --green:#25D366; --ink:#1a1a1a; --txt:#fff; --muted:rgba(255,255,255,.5); }
  *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;background:var(--bg);background-image:radial-gradient(circle at 50% 0%, rgba(252,218,19,.10), transparent 55%),radial-gradient(circle at 50% 100%, rgba(250,184,192,.06), transparent 50%);color:var(--txt);min-height:100vh;display:flex;justify-content:center;padding:36px 20px 28px;-webkit-font-smoothing:antialiased}
  .wrap{width:100%;max-width:440px;display:flex;flex-direction:column;align-items:center}
  .logo{width:178px;margin-bottom:18px;animation:pop .5s cubic-bezier(.2,.8,.25,1) both}
  .logo-fallback{font-size:30px;font-weight:900;color:var(--brand);margin-bottom:14px;letter-spacing:-1px}
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
  .lnk.primary{background:var(--brand);border-color:var(--brand)}
  .lnk.primary .lnk-title,.lnk.primary .lnk-sub,.lnk.primary .lnk-arrow{color:var(--ink)}
  .lnk.primary .lnk-sub{opacity:.7}
  .lnk.primary .lnk-ico{background:rgba(0,0,0,.12);color:var(--ink)}
  .lnk.primary:hover{background:var(--brand-dark)}
  .lnk.wa   .lnk-ico{background:rgba(37,211,102,.15);color:var(--green)}
  .lnk.pink .lnk-ico{background:rgba(250,184,192,.16);color:var(--pink)}
  .lnk.dark .lnk-ico{background:rgba(255,255,255,.10);color:#fff}
  /* card desplegable (cotizar evento) */
  .lnk-expand{width:100%;border:none;text-align:left;font:inherit;cursor:pointer}
  .lnk-chev svg{transition:transform .28s ease}
  .lnk-expand.open .lnk-chev svg{transform:rotate(180deg)}
  .lnk-wrap{display:flex;flex-direction:column}
  .quote-panel{height:0;overflow:hidden;transition:height .34s ease;border-radius:16px}
  .quote-panel.open{margin-top:12px}
  .quote-panel iframe{width:100%;border:0;display:block;background:#f4f4f0;border-radius:16px}
  .foot{margin-top:26px;font-size:11px;color:rgba(255,255,255,.3);text-align:center}
</style>
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
        <button type="button" class="lnk <?= clean($l['style']) ?> lnk-expand" onclick="toggleQuote(this)" aria-expanded="false">
          <span class="lnk-ico"><?= landingIconSvg($l['icon'], 21) ?></span>
          <span class="lnk-tx">
            <span class="lnk-title"><?= clean($l['label']) ?></span>
            <?php if ($l['sublabel']): ?><span class="lnk-sub"><?= clean($l['sublabel']) ?></span><?php endif; ?>
          </span>
          <span class="lnk-arrow lnk-chev"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></span>
        </button>
        <div class="quote-panel" id="quotePanel"><iframe id="quoteFrame" data-src="<?= clean($embedUrl) ?>" title="Cotiza tu evento" loading="lazy" scrolling="no"></iframe></div>
      </div>
      <?php else: ?>
      <a class="lnk <?= clean($l['style']) ?>" href="<?= clean($l['url']) ?>"<?= $tab ?> data-link-id="<?= (int)$l['id'] ?>">
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
  var quoteOpen = false;
  function toggleQuote(btn){
    var panel = document.getElementById('quotePanel'), frame = document.getElementById('quoteFrame');
    quoteOpen = !quoteOpen;
    btn.classList.toggle('open', quoteOpen);
    panel.classList.toggle('open', quoteOpen);
    btn.setAttribute('aria-expanded', quoteOpen);
    if (quoteOpen){
      if (!frame.src) {
        frame.src = frame.dataset.src;                        // carga diferida (1ª vez)
      } else {
        try { frame.contentWindow.postMessage({eg_request_height: 1}, '*'); } catch(e){}  // re-pide altura al reabrir
      }
      panel.style.height = (frame._h || 560) + 'px';
      setTimeout(function(){ btn.scrollIntoView({behavior:'smooth', block:'start'}); }, 60);
    } else {
      panel.style.height = '0px';
    }
  }
  window.addEventListener('message', function(e){
    if (!e.data || !e.data.eg_quote_height) return;
    var frame = document.getElementById('quoteFrame'), panel = document.getElementById('quotePanel');
    if (!frame) return;
    frame._h = e.data.eg_quote_height;
    frame.style.height = e.data.eg_quote_height + 'px';
    if (quoteOpen) panel.style.height = e.data.eg_quote_height + 'px';
  });
</script>
</body>
</html>
