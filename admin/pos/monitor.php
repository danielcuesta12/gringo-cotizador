<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAdmin();

$pageTitle  = 'Ventas en vivo';
$activePage = 'pos-monitor';

$ubis = Database::fetchAll("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY nombre");


include __DIR__ . '/../layout-top.php';
?>
<style>
.mon-filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px}
.mon-filters label{display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em}
.mon-filters input[type=date],.mon-filters select{padding:9px 11px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:var(--bg-card);color:var(--text-primary)}
.mon-live{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--green);font-weight:600;margin-left:auto;align-self:center}
.mon-dot{width:8px;height:8px;border-radius:50%;background:var(--green);flex-shrink:0;animation:mon-pulse 1.5s ease-in-out infinite}
@keyframes mon-pulse{0%,100%{opacity:1}50%{opacity:.3}}
#mon-updated{font-size:12px;color:var(--text-muted);font-weight:400}

.kpi-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}
@media(max-width:600px){.kpi-grid{grid-template-columns:1fr}}
.kpi-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px 22px}
.kpi-label{font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px}
.kpi-value{font-size:26px;font-weight:800;color:var(--text-primary);letter-spacing:-.5px;line-height:1}
.kpi-card.kpi-primary .kpi-value{color:var(--red)}

.mon-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
@media(max-width:700px){.mon-cols{grid-template-columns:1fr}}
.mon-section-title{font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin:0 0 14px}

.mon-row{display:grid;grid-template-columns:100px 1fr 72px 86px;gap:8px;align-items:center;padding:7px 0;border-bottom:1px solid var(--border)}
.mon-row:last-child{border-bottom:none}
.mon-label{font-size:13px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mon-bar-wrap{background:#f0f0f1;border-radius:4px;height:6px;overflow:hidden}
.mon-bar{height:100%;background:var(--red);border-radius:4px;transition:width .5s ease}
.mon-bar-blue{background:var(--blue)}
.mon-n{font-size:11px;color:var(--text-muted);text-align:right;white-space:nowrap}
.mon-val{font-size:13px;font-weight:700;text-align:right;color:var(--text-primary)}

.mon-table{width:100%;border-collapse:collapse;font-size:13px}
.mon-table th{text-align:left;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;padding:8px 10px;border-bottom:2px solid var(--border)}
.mon-table td{padding:9px 10px;border-bottom:1px solid var(--border);vertical-align:middle}
.mon-table tr:last-child td{border-bottom:none}
.mon-table tbody tr:hover{background:#fafafa}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1>Ventas en vivo</h1>
    <p>Monitor del POS — actualización automática cada 15 seg cuando es hoy</p>
  </div>
</div>

<!-- Filtros -->
<div class="mon-filters">
  <div>
    <label for="mon-date">Fecha</label>
    <input type="date" id="mon-date" value="<?= date('Y-m-d') ?>">
  </div>
  <?php if (!empty($ubis)): ?>
  <div>
    <label for="mon-ubi">Ubicación</label>
    <select id="mon-ubi">
      <option value="0">Todas las ubicaciones</option>
      <?php foreach ($ubis as $u): ?>
        <option value="<?= (int)$u['id'] ?>"><?= clean($u['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div id="mon-status" class="mon-live" style="display:none">
    <span class="mon-dot"></span>
    <span>En vivo</span>
    <span id="mon-updated"></span>
  </div>
</div>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi-card kpi-primary">
    <div class="kpi-label">Total vendido</div>
    <div class="kpi-value" id="kpi-total">—</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">N° de ventas</div>
    <div class="kpi-value" id="kpi-n">—</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Ticket promedio</div>
    <div class="kpi-value" id="kpi-avg">—</div>
  </div>
</div>

<!-- Breakdowns -->
<div class="mon-cols">
  <div class="card" style="padding:18px 20px">
    <p class="mon-section-title">Por método de pago</p>
    <div id="mon-metodos">
      <p style="font-size:13px;color:var(--text-muted)">Cargando…</p>
    </div>
  </div>
  <div class="card" style="padding:18px 20px">
    <p class="mon-section-title">Por ubicación</p>
    <div id="mon-ubis">
      <p style="font-size:13px;color:var(--text-muted)">Cargando…</p>
    </div>
  </div>
</div>

<!-- Últimas ventas -->
<div class="card" style="padding:18px 20px">
  <p class="mon-section-title">Últimas ventas</p>
  <div class="table-wrap" style="border:none;border-radius:0;padding:0">
    <table class="mon-table">
      <thead>
        <tr>
          <th>Hora</th>
          <th>Ubicación</th>
          <th>Método</th>
          <th style="text-align:right">Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="mon-recientes">
        <tr><td colspan="5" style="text-align:center;padding:16px;color:var(--text-muted)">Cargando…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
var API        = <?= json_encode(APP_URL . '/api/pos.php') ?>;
var TODAY      = <?= json_encode(date('Y-m-d')) ?>;
var TICKET_URL = <?= json_encode(APP_URL . '/pos/ticket.php') ?>;

(function () {
  "use strict";

  /* -------- helpers -------- */
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }
  function fmtMoney(v) {
    return "S/ " + (parseFloat(v) || 0).toLocaleString("es-PE", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }
  function fmtTime(dt) {
    if (!dt) return "—";
    var parts = String(dt).split(" ");
    var t = parts[1] || parts[0] || "";
    return t.substring(0, 5);
  }
  function nowHMS() {
    return new Date().toTimeString().substring(0, 8);
  }

  /* -------- state -------- */
  var pollTimer   = null;
  var errorStreak = 0;

  /* -------- DOM refs -------- */
  var elDate      = document.getElementById("mon-date");
  var elUbi       = document.getElementById("mon-ubi");
  var elStatus    = document.getElementById("mon-status");
  var elUpdated   = document.getElementById("mon-updated");
  var elKpiTotal  = document.getElementById("kpi-total");
  var elKpiN      = document.getElementById("kpi-n");
  var elKpiAvg    = document.getElementById("kpi-avg");
  var elMetodos   = document.getElementById("mon-metodos");
  var elUbisEl    = document.getElementById("mon-ubis");
  var elRecientes = document.getElementById("mon-recientes");

  /* -------- fetch -------- */
  function load() {
    var fecha = elDate.value || TODAY;
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
        elUpdated.textContent = "Error al actualizar…";
        if (errorStreak >= 3) stopPoll();
      });
  }

  /* -------- render -------- */
  function render(d) {
    var tot = d.total || {};
    var n   = parseInt(tot.n, 10) || 0;
    var t   = parseFloat(tot.t) || 0;
    var avg = n > 0 ? t / n : 0;

    elKpiTotal.textContent = fmtMoney(t);
    elKpiN.textContent     = n;
    elKpiAvg.textContent   = fmtMoney(avg);

    if (n === 0) {
      elMetodos.innerHTML   = "<p style=\"font-size:13px;color:var(--text-muted)\">Sin ventas para este día</p>";
      elUbisEl.innerHTML    = "<p style=\"font-size:13px;color:var(--text-muted)\">Sin ventas para este día</p>";
      elRecientes.innerHTML = "<tr><td colspan=\"5\" style=\"text-align:center;padding:16px;color:var(--text-muted)\">Sin ventas para este día</td></tr>";
      return;
    }

    /* metodos */
    var maxM = Math.max.apply(null, (d.metodos || []).map(function (m) { return parseFloat(m.t) || 0; })) || 1;
    var mHtml = "";
    (d.metodos || []).forEach(function (m) {
      var pct = Math.round(((parseFloat(m.t) || 0) / maxM) * 100);
      mHtml += buildRow(m, pct, false);
    });
    elMetodos.innerHTML = mHtml || "<p style=\"font-size:13px;color:var(--text-muted)\">Sin datos</p>";

    /* ubicaciones */
    var maxU = Math.max.apply(null, (d.ubicaciones || []).map(function (u) { return parseFloat(u.t) || 0; })) || 1;
    var uHtml = "";
    (d.ubicaciones || []).forEach(function (u) {
      var pct = Math.round(((parseFloat(u.t) || 0) / maxU) * 100);
      uHtml += buildRow(u, pct, true);
    });
    elUbisEl.innerHTML = uHtml || "<p style=\"font-size:13px;color:var(--text-muted)\">Sin datos</p>";

    /* recientes */
    var rHtml = "";
    (d.recientes || []).forEach(function (r) {
      rHtml += "<tr>" +
        "<td style=\"font-size:13px;font-weight:600\">" + esc(fmtTime(r.created_at)) + "</td>" +
        "<td style=\"font-size:13px\">" + esc(r.ubi) + "</td>" +
        "<td><span class=\"badge badge-secondary\" style=\"font-size:11px\">" + esc(r.metodo_pago || "—") + "</span></td>" +
        "<td style=\"text-align:right;font-weight:700;font-size:13px\">" + fmtMoney(r.total) + "</td>" +
        "<td><a href=\"" + esc(TICKET_URL) + "?id=" + parseInt(r.id, 10) + "\" target=\"_blank\" " +
             "class=\"btn btn-secondary btn-sm\" style=\"padding:3px 10px;font-size:11px\">" +
             "<svg width=\"11\" height=\"11\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\" style=\"vertical-align:-1px;margin-right:3px\">" +
             "<path d=\"M18 8h1a4 4 0 0 1 0 8h-1\"/><path d=\"M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8Z\"/>" +
             "<line x1=\"6\" y1=\"1\" x2=\"6\" y2=\"4\"/><line x1=\"10\" y1=\"1\" x2=\"10\" y2=\"4\"/><line x1=\"14\" y1=\"1\" x2=\"14\" y2=\"4\"/></svg>" +
             "Ticket</a></td>" +
        "</tr>";
    });
    elRecientes.innerHTML = rHtml || "<tr><td colspan=\"5\" style=\"text-align:center;padding:16px;color:var(--text-muted)\">Sin ventas</td></tr>";
  }

  function buildRow(item, pct, blue) {
    var nv = parseInt(item.n, 10);
    return "<div class=\"mon-row\">" +
      "<span class=\"mon-label\" title=\"" + esc(item.nombre) + "\">" + esc(item.nombre || "—") + "</span>" +
      "<div class=\"mon-bar-wrap\"><div class=\"mon-bar" + (blue ? " mon-bar-blue" : "") + "\" style=\"width:" + pct + "%\"></div></div>" +
      "<span class=\"mon-n\">" + nv + " venta" + (nv !== 1 ? "s" : "") + "</span>" +
      "<span class=\"mon-val\">" + fmtMoney(item.t) + "</span>" +
      "</div>";
  }

  function renderError(msg) {
    elMetodos.innerHTML   = "<p style=\"font-size:13px;color:var(--red)\">" + esc(msg) + "</p>";
    elUbisEl.innerHTML    = "";
    elRecientes.innerHTML = "<tr><td colspan=\"5\" style=\"text-align:center;padding:16px;color:var(--red)\">" + esc(msg) + "</td></tr>";
  }

  /* -------- polling -------- */
  function isToday() {
    return (elDate.value || TODAY) === TODAY;
  }

  function startPoll() {
    stopPoll();
    if (!isToday()) return;
    elStatus.style.display = "flex";
    pollTimer = setInterval(function () {
      if (errorStreak >= 3) { stopPoll(); return; }
      load();
    }, 15000);
  }

  function stopPoll() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    elStatus.style.display = "none";
  }

  /* -------- events -------- */
  function onChange() {
    errorStreak = 0;
    load();
    if (isToday()) { startPoll(); } else { stopPoll(); }
  }

  elDate.addEventListener("change", onChange);
  if (elUbi) elUbi.addEventListener("change", onChange);

  /* -------- init -------- */
  document.addEventListener("DOMContentLoaded", function () {
    load();
    startPoll();
  });

}());
</script>
<?php include __DIR__ . '/../layout-bottom.php'; ?>
