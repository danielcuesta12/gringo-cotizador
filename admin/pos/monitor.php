<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
requireAdmin();

$ubis    = Database::fetchAll("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY nombre");
$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';

function inlineSvgGrip() {
  return '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">' .
    '<circle cx="4.5" cy="3.5" r="1.2" fill="currentColor"/>' .
    '<circle cx="9.5" cy="3.5" r="1.2" fill="currentColor"/>' .
    '<circle cx="4.5" cy="7"   r="1.2" fill="currentColor"/>' .
    '<circle cx="9.5" cy="7"   r="1.2" fill="currentColor"/>' .
    '<circle cx="4.5" cy="10.5" r="1.2" fill="currentColor"/>' .
    '<circle cx="9.5" cy="10.5" r="1.2" fill="currentColor"/>' .
    '</svg>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Ventas">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#161412">
<link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/img/favicon-180.png">
<link rel="manifest" href="<?= APP_URL ?>/manifest.php?app=monitor">
<title>Ventas en vivo · El Gringo</title>
<style>
/* ── Reset & tokens ──────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:       #161412;
  --surface:  #1f1c19;
  --surface2: #2a2622;
  --border:   #332e29;
  --yellow:   #FFDF00;
  --green:    #4ade80;
  --green-dk: #16a34a;
  --red:      #f87171;
  --red-brand:#C8102E;
  --blue:     #60a5fa;
  --muted:    #8a8078;
  --text:     #f5f0ea;
  --text2:    #b8b0a6;
  --radius:   12px;
  --radius-sm:8px;
  --topbar-h: 54px;
  --safe-t: env(safe-area-inset-top, 0px);
  --safe-b: env(safe-area-inset-bottom, 0px);
  --safe-l: env(safe-area-inset-left, 0px);
  --safe-r: env(safe-area-inset-right, 0px);
}
html{height:100%;-webkit-text-size-adjust:100%}
body{
  min-height:100vh;
  background:var(--bg);
  color:var(--text);
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  font-size:15px;
  padding-top:calc(var(--topbar-h) + var(--safe-t));
  padding-left:var(--safe-l);
  padding-right:var(--safe-r);
  padding-bottom:calc(24px + var(--safe-b));
}
button{cursor:pointer;border:none;background:none;font-family:inherit;color:inherit}
input,select{font-family:inherit;font-size:inherit;color:inherit}
a{color:inherit;text-decoration:none}

/* ── Topbar ─────────────────────────────────────────── */
#topbar{
  position:fixed;
  top:0;left:0;right:0;z-index:200;
  height:calc(var(--topbar-h) + var(--safe-t));
  padding-top:var(--safe-t);
  padding-left:calc(14px + var(--safe-l));
  padding-right:calc(14px + var(--safe-r));
  background:var(--surface);
  border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px;
}
.tb-brand{display:flex;align-items:center;gap:8px;flex:0 0 auto}
.tb-logo{height:26px;width:auto;display:block}
.tb-title{font-size:14px;font-weight:800;color:var(--yellow);letter-spacing:.05em;white-space:nowrap}
.tb-sep{color:var(--border);flex:0 0 auto}
.tb-ubi{
  background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);
  color:var(--text);padding:6px 26px 6px 10px;font-size:13px;flex:1;min-width:0;max-width:180px;
  -webkit-appearance:none;appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg width='10' height='7' viewBox='0 0 10 7' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%238a8078' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 9px center;
}
.tb-spacer{flex:1}
.tb-live{
  display:none;align-items:center;gap:5px;
  font-size:11px;font-weight:700;color:var(--green);
  background:rgba(74,222,128,.1);border:1px solid rgba(74,222,128,.25);
  border-radius:20px;padding:4px 10px;white-space:nowrap;flex-shrink:0;
}
.tb-live.visible{display:flex}
.tb-dot{width:7px;height:7px;border-radius:50%;background:var(--green);flex-shrink:0;animation:pulse 1.5s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* ── Main content ───────────────────────────────────── */
#main{
  max-width:540px;margin:0 auto;
  padding:16px 14px 0;
  display:flex;flex-direction:column;gap:12px;
}

/* ── Date nav ───────────────────────────────────────── */
#date-nav{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  display:flex;align-items:center;gap:0;overflow:hidden;
}
.dn-arrow{
  flex:0 0 44px;height:44px;
  display:flex;align-items:center;justify-content:center;
  font-size:20px;font-weight:300;color:var(--text2);
  -webkit-tap-highlight-color:transparent;transition:background .15s,color .15s;
}
.dn-arrow:active,.dn-arrow:hover{background:var(--surface2);color:var(--text)}
.dn-arrow:disabled{color:var(--border);cursor:default}
.dn-arrow:disabled:hover{background:transparent}
.dn-mid{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;border-left:1px solid var(--border);border-right:1px solid var(--border)}
.dn-label{font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.dn-date-wrap{display:flex;align-items:center;gap:6px}
#mon-date{
  border:none;background:transparent;text-align:center;
  font-size:15px;font-weight:700;color:var(--text);
  padding:0;cursor:pointer;outline:none;
  -webkit-appearance:none;width:auto;
}
#mon-date::-webkit-calendar-picker-indicator{opacity:0;position:absolute;width:100%;height:100%}
.dn-date-btn{position:relative;display:flex;align-items:center;justify-content:center}
.dn-weekday{font-size:15px;font-weight:400;color:var(--muted)}

/* ── Section card ───────────────────────────────────── */
.sec-card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;
}
.sec-header{
  display:flex;align-items:center;gap:8px;
  padding:12px 14px;
  border-bottom:1px solid var(--border);
  cursor:grab;
  user-select:none;-webkit-user-select:none;
  touch-action:none;
}
.sec-header:active{cursor:grabbing}
.sec-handle{
  flex:0 0 16px;
  display:flex;align-items:center;justify-content:center;
  color:var(--border);
  transition:color .15s;
}
.sec-header:hover .sec-handle{color:var(--muted)}
.sec-title{
  font-size:11px;font-weight:700;color:var(--muted);
  text-transform:uppercase;letter-spacing:.06em;
  flex:1;
}
.sec-body{padding:14px}
.sec-card.drag-over{border-color:var(--yellow);box-shadow:0 0 0 1px var(--yellow)}
.sec-card.dragging{opacity:.45;transform:scale(.98);transition:opacity .15s,transform .15s}

/* ── KPI grid ───────────────────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.kpi-cell{display:flex;flex-direction:column;gap:4px}
.kpi-cell:first-child{grid-column:1/-1;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 16px}
.kpi-lbl{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.kpi-val{font-size:22px;font-weight:800;color:var(--text);letter-spacing:-.5px;line-height:1}
.kpi-val.yellow{color:var(--yellow)}
.kpi-sub{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:11px 12px}

/* ── Bar rows ───────────────────────────────────────── */
.mon-row{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--border)}
.mon-row:last-child{border-bottom:none}
.mon-name{font-size:13px;font-weight:600;color:var(--text);flex:0 0 96px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mon-bar-wrap{flex:1;background:rgba(255,255,255,.06);border-radius:3px;height:5px;overflow:hidden}
.mon-bar{height:100%;background:var(--yellow);border-radius:3px;transition:width .5s ease}
.mon-bar.blue{background:var(--blue)}
.mon-n{font-size:11px;color:var(--muted);text-align:right;white-space:nowrap;flex:0 0 56px}
.mon-val{font-size:13px;font-weight:700;color:var(--text);text-align:right;flex:0 0 88px;white-space:nowrap}

/* ── Recent sales ───────────────────────────────────── */
.rec-list{display:flex;flex-direction:column;gap:0}
.rec-row{
  display:flex;align-items:center;gap:10px;
  padding:10px 0;border-bottom:1px solid var(--border);
}
.rec-row:last-child{border-bottom:none}
.rec-time{font-size:14px;font-weight:700;color:var(--text);flex:0 0 42px}
.rec-info{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.rec-ubi{font-size:12px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rec-method{
  display:inline-block;font-size:10px;font-weight:700;
  background:rgba(255,255,255,.08);border-radius:6px;
  padding:2px 7px;color:var(--muted);
}
.rec-total{font-size:15px;font-weight:800;color:var(--yellow);text-align:right;white-space:nowrap;flex:0 0 auto}
.rec-link{
  flex:0 0 auto;
  display:flex;align-items:center;justify-content:center;
  width:32px;height:32px;border-radius:var(--radius-sm);
  background:var(--surface2);border:1px solid var(--border);
  color:var(--muted);transition:color .15s,background .15s;
}
.rec-link:hover,.rec-link:active{background:var(--surface);color:var(--text)}

/* ── Chart ──────────────────────────────────────────── */
.chart-header{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:12px}
.chart-totals{display:flex;flex-direction:column;gap:3px}
.chart-main-total{font-size:18px;font-weight:800;color:var(--yellow);letter-spacing:-.3px}
.chart-comp-total{font-size:12px;color:var(--muted)}
.chart-delta{
  font-size:12px;font-weight:700;
  padding:3px 9px;border-radius:12px;
  flex-shrink:0;
}
.chart-delta.up{background:rgba(74,222,128,.12);color:var(--green)}
.chart-delta.down{background:rgba(248,113,113,.12);color:var(--red)}
.chart-delta.neutral{background:rgba(255,255,255,.06);color:var(--muted)}
.chart-legend{display:flex;align-items:center;gap:14px;margin-bottom:10px}
.legend-item{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--muted)}
.legend-dot{width:10px;height:3px;border-radius:2px}
.legend-dot.yellow{background:var(--yellow)}
.legend-dot.gray{background:var(--muted)}
#comp-chart{display:block;width:100%;overflow:visible}
.chart-empty{font-size:13px;color:var(--muted);text-align:center;padding:24px 0}

/* ── Empty / error states ───────────────────────────── */
.empty{font-size:13px;color:var(--muted);padding:8px 0}
.err{font-size:13px;color:var(--red)}

/* ── Updated time ───────────────────────────────────── */
#mon-updated{font-size:11px;color:var(--muted);text-align:center;padding-top:4px}
</style>
</head>
<body>

<!-- Topbar -->
<div id="topbar">
  <div class="tb-brand">
    <?php if ($logoUrl): ?>
    <img src="<?= htmlspecialchars(UPLOAD_URL . $logoRel) ?>" class="tb-logo" alt="Logo">
    <?php endif; ?>
    <span class="tb-title">Ventas en vivo</span>
  </div>
  <span class="tb-sep">|</span>
  <?php if (!empty($ubis)): ?>
  <select id="mon-ubi" class="tb-ubi">
    <option value="0">Todas</option>
    <?php foreach ($ubis as $u): ?>
    <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php else: ?>
  <span style="font-size:13px;color:var(--muted)">Sin ubicaciones</span>
  <?php endif; ?>
  <span class="tb-spacer"></span>
  <div id="tb-live" class="tb-live">
    <span class="tb-dot"></span>
    <span>En vivo</span>
  </div>
</div>

<!-- Main -->
<div id="main">

  <!-- Date nav -->
  <div id="date-nav">
    <button class="dn-arrow" id="dn-prev" title="Día anterior">&#8249;</button>
    <div class="dn-mid" style="padding:10px 0">
      <span class="dn-label" id="dn-weekday">—</span>
      <div class="dn-date-btn">
        <input type="date" id="mon-date" value="<?= date('Y-m-d') ?>">
      </div>
    </div>
    <button class="dn-arrow" id="dn-next" title="Día siguiente">&#8250;</button>
  </div>

  <!-- Sections container (reorderable) -->
  <div id="sections-wrap">

    <!-- KPIs -->
    <div class="sec-card" data-sec="kpis">
      <div class="sec-header">
        <span class="sec-handle"><?= inlineSvgGrip() ?></span>
        <span class="sec-title">Resumen del día</span>
      </div>
      <div class="sec-body">
        <div class="kpi-grid">
          <div class="kpi-cell">
            <div class="kpi-lbl">Total vendido</div>
            <div class="kpi-val yellow" id="kpi-total">—</div>
          </div>
          <div class="kpi-sub kpi-cell">
            <div class="kpi-lbl">Ventas</div>
            <div class="kpi-val" id="kpi-n">—</div>
          </div>
          <div class="kpi-sub kpi-cell">
            <div class="kpi-lbl">Ticket prom.</div>
            <div class="kpi-val" id="kpi-avg">—</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Chart -->
    <div class="sec-card" data-sec="chart">
      <div class="sec-header">
        <span class="sec-handle"><?= inlineSvgGrip() ?></span>
        <span class="sec-title">Comparativo semanal</span>
      </div>
      <div class="sec-body">
        <div id="chart-wrap">
          <div class="chart-empty">Cargando...</div>
        </div>
      </div>
    </div>

    <!-- Por método -->
    <div class="sec-card" data-sec="metodos">
      <div class="sec-header">
        <span class="sec-handle"><?= inlineSvgGrip() ?></span>
        <span class="sec-title">Por método de pago</span>
      </div>
      <div class="sec-body" id="mon-metodos">
        <p class="empty">Cargando...</p>
      </div>
    </div>

    <!-- Por ubicación -->
    <div class="sec-card" data-sec="ubicaciones">
      <div class="sec-header">
        <span class="sec-handle"><?= inlineSvgGrip() ?></span>
        <span class="sec-title">Por ubicación</span>
      </div>
      <div class="sec-body" id="mon-ubis">
        <p class="empty">Cargando...</p>
      </div>
    </div>

    <!-- Últimas ventas -->
    <div class="sec-card" data-sec="recientes">
      <div class="sec-header">
        <span class="sec-handle"><?= inlineSvgGrip() ?></span>
        <span class="sec-title">Últimas ventas</span>
      </div>
      <div class="sec-body">
        <div id="mon-recientes" class="rec-list">
          <p class="empty">Cargando...</p>
        </div>
      </div>
    </div>

  </div><!-- #sections-wrap -->

  <div id="mon-updated"></div>

</div><!-- #main -->

<script>
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('<?= APP_URL ?>/sw.js').catch(function () {});
}

var API        = <?= json_encode(APP_URL . '/api/pos.php') ?>;
var TODAY      = <?= json_encode(date('Y-m-d')) ?>;
var TICKET_URL = <?= json_encode(APP_URL . '/pos/ticket.php') ?>;
var SEC_ORDER_KEY = 'monitor_sec_order';

(function () {
  "use strict";

  /* ── helpers ────────────────────────────────────────── */
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }
  function fmtMoney(v) {
    return "S/ " + (parseFloat(v) || 0).toLocaleString("es-PE", {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    });
  }
  function fmtTime(dt) {
    if (!dt) return "—";
    var p = String(dt).split(" ");
    var t = p[1] || p[0] || "";
    return t.substring(0, 5);
  }
  function nowHMS() { return new Date().toTimeString().substring(0, 8); }

  var DAYS_ES = ["Dom","Lun","Mar","Mié","Jue","Vie","Sáb"];
  var MONTHS_ES = ["ene","feb","mar","abr","may","jun","jul","ago","sep","oct","nov","dic"];
  function fmtDateLabel(ymd) {
    var parts = ymd.split("-");
    var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
    return DAYS_ES[d.getDay()] + " " + d.getDate() + " " + MONTHS_ES[d.getMonth()];
  }
  function addDays(ymd, n) {
    var parts = ymd.split("-");
    var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
    d.setDate(d.getDate() + n);
    var y2 = d.getFullYear();
    var m2 = String(d.getMonth() + 1).padStart(2, "0");
    var day2 = String(d.getDate()).padStart(2, "0");
    return y2 + "-" + m2 + "-" + day2;
  }

  /* ── DOM refs ───────────────────────────────────────── */
  var elDate     = document.getElementById("mon-date");
  var elUbi      = document.getElementById("mon-ubi");
  var elStatus   = document.getElementById("tb-live");
  var elUpdated  = document.getElementById("mon-updated");
  var elWeekday  = document.getElementById("dn-weekday");
  var elPrev     = document.getElementById("dn-prev");
  var elNext     = document.getElementById("dn-next");
  var elKpiTotal = document.getElementById("kpi-total");
  var elKpiN     = document.getElementById("kpi-n");
  var elKpiAvg   = document.getElementById("kpi-avg");
  var elMetodos  = document.getElementById("mon-metodos");
  var elUbisEl   = document.getElementById("mon-ubis");
  var elRecientes= document.getElementById("mon-recientes");
  var elChartWrap= document.getElementById("chart-wrap");

  /* ── State ──────────────────────────────────────────── */
  var pollTimer   = null;
  var errorStreak = 0;

  /* ── Date nav ───────────────────────────────────────── */
  function getDate() { return elDate.value || TODAY; }
  function setDate(ymd) {
    elDate.value = ymd;
    updateDateLabel(ymd);
    updateNextArrow(ymd);
  }
  function updateDateLabel(ymd) {
    elWeekday.textContent = fmtDateLabel(ymd);
  }
  function updateNextArrow(ymd) {
    elNext.disabled = ymd >= TODAY;
  }

  elPrev.addEventListener("click", function () {
    setDate(addDays(getDate(), -1));
    onChange();
  });
  elNext.addEventListener("click", function () {
    if (getDate() < TODAY) {
      setDate(addDays(getDate(), 1));
      onChange();
    }
  });
  elDate.addEventListener("change", function () {
    if (elDate.value > TODAY) elDate.value = TODAY;
    updateDateLabel(elDate.value);
    updateNextArrow(elDate.value);
    onChange();
  });
  if (elUbi) elUbi.addEventListener("change", onChange);

  /* ── Fetch ──────────────────────────────────────────── */
  function load() {
    var fecha = getDate();
    var ubi   = elUbi ? elUbi.value : "0";
    var url   = API + "?action=monitor&fecha=" + encodeURIComponent(fecha)
                    + "&ubicacion_id=" + encodeURIComponent(ubi);
    fetch(url, { credentials: "same-origin" })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        errorStreak = 0;
        if (!d.ok) { renderError(d.error || "Error de API"); return; }
        render(d);
        elUpdated.textContent = "Actualizado " + nowHMS();
      })
      .catch(function () {
        errorStreak++;
        elUpdated.textContent = "Error al actualizar...";
        if (errorStreak >= 3) stopPoll();
      });
  }

  /* ── Render ─────────────────────────────────────────── */
  function render(d) {
    var tot = d.total || {};
    var n   = parseInt(tot.n, 10) || 0;
    var t   = parseFloat(tot.t) || 0;
    var avg = n > 0 ? t / n : 0;

    elKpiTotal.textContent = fmtMoney(t);
    elKpiN.textContent     = n;
    elKpiAvg.textContent   = fmtMoney(avg);

    if (n === 0) {
      elMetodos.innerHTML   = '<p class="empty">Sin ventas para este día</p>';
      elUbisEl.innerHTML    = '<p class="empty">Sin ventas para este día</p>';
      elRecientes.innerHTML = '<p class="empty">Sin ventas para este día</p>';
      renderChart([], [], d.fecha, d.comp);
      return;
    }

    /* metodos */
    var maxM = Math.max.apply(null, (d.metodos || []).map(function (m) { return parseFloat(m.t) || 0; })) || 1;
    var mHtml = "";
    (d.metodos || []).forEach(function (m) {
      var pct = Math.round(((parseFloat(m.t) || 0) / maxM) * 100);
      mHtml += buildBarRow(m, pct, false);
    });
    elMetodos.innerHTML = mHtml || '<p class="empty">Sin datos</p>';

    /* ubicaciones */
    var maxU = Math.max.apply(null, (d.ubicaciones || []).map(function (u) { return parseFloat(u.t) || 0; })) || 1;
    var uHtml = "";
    (d.ubicaciones || []).forEach(function (u) {
      var pct = Math.round(((parseFloat(u.t) || 0) / maxU) * 100);
      uHtml += buildBarRow(u, pct, true);
    });
    elUbisEl.innerHTML = uHtml || '<p class="empty">Sin datos</p>';

    /* recientes */
    var rHtml = "";
    (d.recientes || []).forEach(function (r) {
      rHtml +=
        '<div class="rec-row">' +
          '<span class="rec-time">' + esc(fmtTime(r.created_at)) + '</span>' +
          '<div class="rec-info">' +
            '<span class="rec-ubi">' + esc(r.ubi || "—") + '</span>' +
            '<span class="rec-method">' + esc(r.metodo_pago || "—") + '</span>' +
          '</div>' +
          '<span class="rec-total">' + fmtMoney(r.total) + '</span>' +
          '<a href="' + esc(TICKET_URL) + '?id=' + parseInt(r.id, 10) + '" target="_blank" class="rec-link" title="Ver ticket">' +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
              '<path d="M18 8h1a4 4 0 0 1 0 8h-1"/>' +
              '<path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8Z"/>' +
              '<line x1="6" y1="1" x2="6" y2="4"/>' +
              '<line x1="10" y1="1" x2="10" y2="4"/>' +
              '<line x1="14" y1="1" x2="14" y2="4"/>' +
            '</svg>' +
          '</a>' +
        '</div>';
    });
    elRecientes.innerHTML = rHtml || '<p class="empty">Sin ventas</p>';

    /* chart */
    renderChart(d.por_hora || [], d.fecha, d.comp);
  }

  function buildBarRow(item, pct, blue) {
    var nv = parseInt(item.n, 10);
    return '<div class="mon-row">' +
      '<span class="mon-name" title="' + esc(item.nombre) + '">' + esc(item.nombre || "—") + '</span>' +
      '<div class="mon-bar-wrap"><div class="mon-bar' + (blue ? ' blue' : '') + '" style="width:' + pct + '%"></div></div>' +
      '<span class="mon-n">' + nv + ' vta' + (nv !== 1 ? 's' : '') + '</span>' +
      '<span class="mon-val">' + fmtMoney(item.t) + '</span>' +
      '</div>';
  }

  function renderError(msg) {
    elMetodos.innerHTML   = '<p class="err">' + esc(msg) + '</p>';
    elUbisEl.innerHTML    = '';
    elRecientes.innerHTML = '<p class="err">' + esc(msg) + '</p>';
    elChartWrap.innerHTML = '<div class="chart-empty err">' + esc(msg) + '</div>';
  }

  /* ── Chart ──────────────────────────────────────────── */
  function buildCumulative(porHora) {
    var arr = new Array(24).fill(0);
    (porHora || []).forEach(function (h) { arr[parseInt(h.h, 10)] = parseFloat(h.t) || 0; });
    var cum = 0;
    return arr.map(function (v) { cum += v; return cum; });
  }

  function renderChart(porHora, fechaStr, comp) {
    var cumA = buildCumulative(porHora);
    var cumB = comp ? buildCumulative((comp.por_hora || [])) : new Array(24).fill(0);
    var maxA = cumA[23] || 0;
    var maxB = comp ? (cumB[23] || 0) : 0;
    var maxV = Math.max(maxA, maxB, 1);

    var fechaLabel = fechaStr ? fmtDateLabel(fechaStr) : "Hoy";
    var compLabel  = comp ? fmtDateLabel(comp.fecha) : "Sem. pasada";
    var totalA = maxA;
    var totalB = maxB;

    var deltaHtml = "";
    if (totalB > 0) {
      var pct = Math.round(((totalA - totalB) / totalB) * 100);
      if (pct > 0) {
        deltaHtml = '<span class="chart-delta up">&#9650; ' + pct + '%</span>';
      } else if (pct < 0) {
        deltaHtml = '<span class="chart-delta down">&#9660; ' + Math.abs(pct) + '%</span>';
      } else {
        deltaHtml = '<span class="chart-delta neutral">= 0%</span>';
      }
    }

    /* Build SVG */
    var W = 320; var H = 120; var PL = 36; var PR = 8; var PT = 8; var PB = 18;
    var chartW = W - PL - PR; var chartH = H - PT - PB;
    var hours  = [];
    for (var i = 0; i < 24; i++) hours.push(i);

    function xPx(h)  { return PL + (h / 23) * chartW; }
    function yPx(v)  { return PT + chartH - (v / maxV) * chartH; }

    function polyline(cum, color, opacity) {
      var pts = cum.map(function (v, i) { return xPx(i) + "," + yPx(v); }).join(" ");
      /* filled area */
      var areaD = "M" + xPx(0) + "," + yPx(0) +
        cum.map(function (v, i) { return " L" + xPx(i) + "," + yPx(v); }).join("") +
        " L" + xPx(23) + "," + (PT + chartH) +
        " L" + xPx(0)  + "," + (PT + chartH) + " Z";
      return '<path d="' + areaD + '" fill="' + color + '" fill-opacity="' + opacity + '"/>' +
             '<polyline points="' + pts + '" fill="none" stroke="' + color + '" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>';
    }

    /* y-axis ticks */
    var yTicks = "";
    var nTicks = 3;
    for (var ti = 0; ti <= nTicks; ti++) {
      var tv = (maxV / nTicks) * ti;
      var ty = yPx(tv);
      yTicks += '<line x1="' + PL + '" y1="' + ty + '" x2="' + (W - PR) + '" y2="' + ty + '" stroke="#332e29" stroke-width="1"/>';
      if (ti > 0) {
        var label = tv >= 1000 ? Math.round(tv / 100) / 10 + "k" : Math.round(tv);
        yTicks += '<text x="' + (PL - 3) + '" y="' + (ty + 4) + '" text-anchor="end" fill="#8a8078" font-size="9">' + label + '</text>';
      }
    }

    /* x-axis labels */
    var xLabels = "";
    [6, 12, 18].forEach(function (h) {
      xLabels += '<text x="' + xPx(h) + '" y="' + (H - 2) + '" text-anchor="middle" fill="#8a8078" font-size="9">' + h + 'h</text>';
    });

    var svgContent = '<svg id="comp-chart" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">' +
      yTicks + xLabels;

    /* Draw comp first (behind) */
    if (comp && totalB > 0) {
      svgContent += polyline(cumB, "#8a8078", 0.12);
    }
    /* Draw main on top */
    if (totalA > 0) {
      svgContent += polyline(cumA, "#FFDF00", 0.18);
    }
    svgContent += '</svg>';

    var html =
      '<div class="chart-header">' +
        '<div class="chart-totals">' +
          '<div class="chart-main-total">' + fmtMoney(totalA) + '</div>' +
          (comp && totalB > 0 ? '<div class="chart-comp-total">vs ' + fmtMoney(totalB) + ' · ' + esc(compLabel) + '</div>' : '') +
        '</div>' +
        deltaHtml +
      '</div>' +
      '<div class="chart-legend">' +
        '<span class="legend-item"><span class="legend-dot yellow"></span>' + esc(fechaLabel) + '</span>' +
        (comp && totalB > 0 ? '<span class="legend-item"><span class="legend-dot gray"></span>' + esc(compLabel) + '</span>' : '') +
      '</div>';

    if (totalA === 0 && (!comp || totalB === 0)) {
      html += '<div class="chart-empty">Sin ventas para comparar</div>';
    } else {
      html += svgContent;
    }

    elChartWrap.innerHTML = html;
  }

  /* ── Polling ─────────────────────────────────────────── */
  function isToday() { return getDate() === TODAY; }

  function startPoll() {
    stopPoll();
    if (!isToday()) return;
    elStatus.classList.add("visible");
    pollTimer = setInterval(function () {
      if (errorStreak >= 3) { stopPoll(); return; }
      load();
    }, 15000);
  }

  function stopPoll() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    elStatus.classList.remove("visible");
  }

  function onChange() {
    errorStreak = 0;
    load();
    if (isToday()) { startPoll(); } else { stopPoll(); }
  }

  /* ── Section reorder ─────────────────────────────────── */
  var wrap = document.getElementById("sections-wrap");

  function getSectionOrder() {
    try {
      var raw = localStorage.getItem(SEC_ORDER_KEY);
      if (raw) {
        var arr = JSON.parse(raw);
        if (Array.isArray(arr) && arr.length > 0) return arr;
      }
    } catch (e) { /* ignore */ }
    return null;
  }

  function applySectionOrder(order) {
    if (!order || !order.length) return;
    var cards = Array.from(wrap.querySelectorAll(".sec-card[data-sec]"));
    var map = {};
    cards.forEach(function (c) { map[c.getAttribute("data-sec")] = c; });
    order.forEach(function (key) {
      if (map[key]) wrap.appendChild(map[key]);
    });
  }

  function saveSectionOrder() {
    var cards = Array.from(wrap.querySelectorAll(".sec-card[data-sec]"));
    var order = cards.map(function (c) { return c.getAttribute("data-sec"); });
    try { localStorage.setItem(SEC_ORDER_KEY, JSON.stringify(order)); } catch (e) { /* ignore */ }
  }

  /* Drag reorder via Pointer Events */
  var dragEl = null;
  var dragGhost = null;
  var dragStartY = 0;
  var dragOffsetY = 0;

  function getCardFromHandle(el) {
    while (el && el !== wrap) {
      if (el.classList.contains("sec-card")) return el;
      el = el.parentElement;
    }
    return null;
  }

  function getSiblingAtY(y) {
    var cards = Array.from(wrap.querySelectorAll(".sec-card[data-sec]"));
    for (var i = 0; i < cards.length; i++) {
      var c = cards[i];
      if (c === dragEl) continue;
      var r = c.getBoundingClientRect();
      if (y < r.top + r.height / 2) return c;
    }
    return null;
  }

  wrap.addEventListener("pointerdown", function (e) {
    var handle = e.target.closest ? e.target.closest(".sec-handle") : null;
    if (!handle) return;
    var card = getCardFromHandle(handle);
    if (!card) return;

    e.preventDefault();
    dragEl = card;
    var rect = card.getBoundingClientRect();
    dragOffsetY = e.clientY - rect.top;
    dragStartY  = e.clientY;

    dragEl.classList.add("dragging");
    wrap.setPointerCapture && wrap.setPointerCapture(e.pointerId);
  }, { passive: false });

  wrap.addEventListener("pointermove", function (e) {
    if (!dragEl) return;
    e.preventDefault();
    var y = e.clientY;
    var sibling = getSiblingAtY(y);
    var cards = Array.from(wrap.querySelectorAll(".sec-card[data-sec]"));
    cards.forEach(function (c) { c.classList.remove("drag-over"); });
    if (sibling) {
      sibling.classList.add("drag-over");
      wrap.insertBefore(dragEl, sibling);
    } else {
      wrap.appendChild(dragEl);
    }
  }, { passive: false });

  function endDrag() {
    if (!dragEl) return;
    dragEl.classList.remove("dragging");
    var cards = Array.from(wrap.querySelectorAll(".sec-card[data-sec]"));
    cards.forEach(function (c) { c.classList.remove("drag-over"); });
    dragEl = null;
    saveSectionOrder();
  }

  wrap.addEventListener("pointerup", endDrag);
  wrap.addEventListener("pointercancel", endDrag);

  /* ── Init ────────────────────────────────────────────── */
  applySectionOrder(getSectionOrder());
  setDate(elDate.value || TODAY);
  load();
  startPoll();

}());
</script>
</body>
</html>
