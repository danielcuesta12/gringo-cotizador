<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requirePermission('calendar');

// Cotizaciones y eventos para el calendario. Tolerante: si falta la migración 50, cae sin esas columnas.
$eventoColsOk  = (bool) Database::fetch("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quotes' AND column_name='evento_atendido'");
$agendaVentaOk = (bool) Database::fetch("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='agenda' AND column_name='venta_real'");
$esAdmin       = isAdmin();
$calSelect = "SELECT q.id, q.quote_number, q.status, q.origin,
            q.event_date, q.event_type,
            q.event_time, q.event_duration, q.event_location,
            q.num_people, q.total, q.price_per_person,"
    . ($eventoColsOk ? " q.evento_nombre, COALESCE(q.evento_atendido,0) evento_atendido," : "")
    . " c.name as client_name
     FROM quotes q JOIN clients c ON c.id=q.client_id
     WHERE q.status IN ('enviada','aceptada')
       AND q.event_date IS NOT NULL AND q.event_date != ''
     ORDER BY q.event_date ASC";
$calQuotes = Database::fetchAll($calSelect);

// Ítems por cotización para el tooltip
$quoteIds = array_column($calQuotes, 'id');
$itemsMap = array();
if (!empty($quoteIds)) {
    $ph   = implode(',', array_fill(0, count($quoteIds), '?'));
    $rows = Database::fetchAll(
        "SELECT quote_id, name, quantity FROM quote_items WHERE quote_id IN ($ph) ORDER BY sort_order",
        $quoteIds
    );
    foreach ($rows as $r) {
        $itemsMap[$r['quote_id']][] = $r['name'] . ' × ' . number_format((float)$r['quantity'], 0);
    }
}

// Agregar items a cada quote
foreach ($calQuotes as &$q) {
    $q['items_summary'] = isset($itemsMap[$q['id']]) ? implode(' · ', array_slice($itemsMap[$q['id']], 0, 4)) : '';
}
unset($q);

// Agenda (eventos sin venta — solo disponibilidad). Tolerante si falta la migración.
$agendaColsOk = (bool) Database::fetch("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='agenda' AND column_name='atendido'");
$agSel = "SELECT id, fecha AS event_date, fecha_fin, titulo, hora, hora_fin, lugar, notas, bloquea"
    . ($agendaColsOk ? ", COALESCE(atendido,0) atendido" : "")
    . ($agendaVentaOk ? ", venta_real" : "")
    . " FROM agenda ORDER BY fecha ASC";
try {
    $agenda = Database::fetchAll($agSel);
}
catch (Exception $e) { $agenda = array(); }

// Token del feed ICS (suscripción desde el celular). Se genera una sola vez.
$icsToken = getSetting('ics_token', '');
if ($icsToken === '') { $icsToken = bin2hex(random_bytes(20)); setSetting('ics_token', $icsToken); }
$icsHttps  = APP_URL . '/calendario.php?token=' . $icsToken;          // URL https
$icsWebcal = preg_replace('#^https?://#', 'webcal://', $icsHttps);    // webcal:// para suscribir

$pageTitle  = 'Calendario';
$activePage = 'calendar';
include __DIR__ . '/layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Calendario de eventos</h1>
    <p>Cotizaciones enviadas, aceptadas y eventos directos</p>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <button type="button" onclick="openSyncModal()" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:7px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 0 1 9.5 6.9"/><path d="M22 4v5h-5"/><path d="M12 22a10 10 0 0 1-9.5-6.9"/><path d="M2 20v-5h5"/></svg>
      Sincronizar con mi celular
    </button>
    <a href="<?php echo APP_URL; ?>/admin/events/create" class="btn btn-secondary" style="color:#7c3aed;border-color:#7c3aed;display:inline-flex;align-items:center;gap:7px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg> Nuevo evento
    </a>
  </div>
</div>

<!-- Modal: Sincronizar con mi celular -->
<div id="syncOverlay" onclick="closeSyncModal(event)" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.45);padding:20px;overflow-y:auto">
  <div onclick="event.stopPropagation()" style="max-width:520px;margin:40px auto;background:var(--bg-card);border-radius:var(--radius-lg);box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border)">
      <h2 style="font-size:17px;font-weight:700;color:var(--text-primary);margin:0">Sincronizar con mi celular</h2>
      <button type="button" onclick="closeSyncModal()" class="btn btn-ghost btn-sm" style="font-size:18px;line-height:1;padding:4px 9px">&times;</button>
    </div>
    <div style="padding:20px">
      <p style="font-size:13px;color:var(--text-secondary);margin:0 0 16px">Agrega este calendario a tu celular y se actualiza solo. Verás tus cotizaciones, eventos y agenda al día.</p>

      <!-- QR -->
      <div style="display:flex;justify-content:center;margin-bottom:18px">
        <div style="background:#fff;padding:12px;border:1px solid var(--border);border-radius:var(--radius)">
          <div id="syncQr"></div>
        </div>
      </div>

      <!-- Suscribir Apple -->
      <a href="<?php echo htmlspecialchars($icsWebcal, ENT_QUOTES); ?>" class="btn btn-primary" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;margin-bottom:16px;text-decoration:none">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M12 19v-5M9.5 16.5 12 19l2.5-2.5"/></svg>
        Suscribirme (Apple)
      </a>

      <!-- URL para Google -->
      <label style="display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);margin-bottom:6px">Enlace para Google Calendar</label>
      <div style="display:flex;gap:8px;margin-bottom:14px">
        <input id="syncUrl" type="text" readonly value="<?php echo htmlspecialchars($icsHttps, ENT_QUOTES); ?>" onclick="this.select()" style="flex:1;min-width:0;padding:9px 11px;font-size:12px;border:1px solid var(--border);border-radius:var(--radius);background:var(--bg-input,#f9f9f9);color:var(--text-primary)">
        <button type="button" id="syncCopyBtn" onclick="copySyncUrl()" class="btn btn-secondary" style="flex-shrink:0">Copiar</button>
      </div>

      <!-- Instrucciones -->
      <div style="background:var(--bg-input,#f9f9f9);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:14px">
        <div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:6px">iPhone / iPad</div>
        <ol style="margin:0 0 14px;padding-left:18px;font-size:12px;color:var(--text-secondary);line-height:1.7">
          <li>Toca <strong>Suscribirme (Apple)</strong> arriba, o escanea el QR.</li>
          <li>O manual: Ajustes &rarr; Calendario &rarr; Cuentas &rarr; Añadir cuenta &rarr; Otra &rarr; Añadir calendario suscrito.</li>
          <li>Pega el enlace y confirma.</li>
        </ol>
        <div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-bottom:6px">Google Calendar</div>
        <ol style="margin:0;padding-left:18px;font-size:12px;color:var(--text-secondary);line-height:1.7">
          <li>Copia el enlace de arriba.</li>
          <li>En Google Calendar (web): Otros calendarios &rarr; <strong>+</strong> &rarr; Desde URL.</li>
          <li>Pega el enlace y agrega.</li>
        </ol>
      </div>

      <p style="font-size:11px;color:var(--text-muted);margin:0;display:flex;align-items:flex-start;gap:6px">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span>El enlace es privado (token secreto). No lo compartas.</span>
      </p>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
var SYNC_WEBCAL = <?php echo json_encode($icsWebcal); ?>;
var _syncQrDone = false;
function openSyncModal(){
  document.getElementById('syncOverlay').style.display = 'block';
  document.body.style.overflow = 'hidden';
  if (!_syncQrDone && window.QRCode){
    try {
      new QRCode(document.getElementById('syncQr'), { text: SYNC_WEBCAL, width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M });
      _syncQrDone = true;
    } catch(e){}
  }
}
function closeSyncModal(ev){
  if (ev && ev.type==='click' && ev.target.id!=='syncOverlay') return;
  document.getElementById('syncOverlay').style.display = 'none';
  document.body.style.overflow = '';
}
function copySyncUrl(){
  var inp = document.getElementById('syncUrl');
  var btn = document.getElementById('syncCopyBtn');
  var done = function(){ var t=btn.textContent; btn.textContent='Copiado'; setTimeout(function(){ btn.textContent=t; }, 1600); };
  if (navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(inp.value).then(done).catch(function(){ inp.select(); document.execCommand('copy'); done(); });
  } else {
    inp.select(); document.execCommand('copy'); done();
  }
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeSyncModal(); });
</script>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px">
    <span style="font-size:16px;font-weight:700;color:var(--text-primary)" id="calTitle">Cargando...</span>
    <div style="display:flex;gap:6px;align-items:center">
      <button onclick="calNav(-1)" class="btn btn-ghost btn-sm">&#8249;</button>
      <button onclick="calToday()" class="btn btn-ghost btn-sm">Hoy</button>
      <button onclick="calNav(1)"  class="btn btn-ghost btn-sm">&#8250;</button>
      <div style="width:1px;height:20px;background:var(--border);margin:0 4px"></div>
      <button onclick="setView('month')" id="btnMonth" class="btn btn-ghost btn-sm">Mes</button>
      <button onclick="setView('list')"  id="btnList"  class="btn btn-ghost btn-sm">Lista</button>
    </div>
  </div>

  <!-- Vista Mes -->
  <div id="viewMonth">
    <div style="display:grid;grid-template-columns:repeat(7,1fr);border-bottom:1px solid var(--border)">
      <?php foreach (array('Lun','Mar','Mié','Jue','Vie','Sáb','Dom') as $d): ?>
      <div style="text-align:center;font-size:11px;font-weight:600;color:var(--text-muted);padding:8px 4px;background:#fafafa"><?php echo $d; ?></div>
      <?php endforeach; ?>
    </div>
    <div id="calGrid" style="display:grid;grid-template-columns:repeat(7,1fr)"></div>
  </div>

  <!-- Vista Lista -->
  <div id="viewList" style="display:none">
    <div id="listContent"></div>
  </div>

  <!-- Leyenda -->
  <div style="display:flex;gap:14px;padding:10px 16px;border-top:1px solid var(--border);flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-muted)">
      <span style="width:10px;height:10px;border-radius:2px;background:#dbeafe;display:inline-block"></span>Enviada
    </div>
    <div style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-muted)">
      <span style="width:10px;height:10px;border-radius:2px;background:#dcfce7;display:inline-block"></span>Aceptada
    </div>
    <div style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-muted)">
      <span style="width:10px;height:10px;border-radius:2px;background:#ede9fe;display:inline-block"></span>Evento directo
    </div>
    <div style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-muted)">
      <span style="width:10px;height:10px;border-radius:2px;background:#ffedd5;display:inline-block"></span>Agenda · sin venta
    </div>
    <div style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-muted)">
      <span style="width:10px;height:10px;border-radius:50%;background:#dc2626;display:inline-block"></span>No disponible (bloqueado)
    </div>
  </div>
</div>

<!-- Tooltip global -->
<div id="globalTooltip" style="display:none;position:fixed;z-index:9999;width:260px;
     background:var(--bg-card);border:1.5px solid var(--border);border-radius:12px;
     box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden">
  <div id="ttHeader" style="padding:10px 14px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <span id="ttName" style="font-size:13px;font-weight:700;color:var(--text-primary)"></span>
    <span id="ttBadge" style="font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600"></span>
  </div>
  <div style="padding:10px 14px;display:flex;flex-direction:column;gap:6px" id="ttBody"></div>
  <div id="ttProds" style="font-size:11px;color:var(--text-muted);padding:6px 14px;border-top:1px solid var(--border);background:var(--bg-input)"></div>
  <div id="ttEdit" style="display:none;padding:10px 14px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:8px">
    <input type="text" id="ttEvNombre" placeholder="Nombre del evento (para la salida a evento)" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;box-sizing:border-box">
    <label id="ttEvAtLabel" style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer">
      <input type="checkbox" id="ttEvAtendido" style="width:16px;height:16px;accent-color:var(--brand)"> Atendida (la oculta del selector de salida a evento)
    </label>
    <label id="ttEvVentaWrap" style="display:none;font-size:12px;font-weight:600">
      <span style="display:block;margin-bottom:4px">Venta del evento (S/) <span style="font-weight:400;color:var(--text-muted)">— solo admin</span></span>
      <input type="text" id="ttEvVenta" inputmode="decimal" placeholder="0.00" style="width:140px;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:13px;box-sizing:border-box">
    </label>
    <button type="button" id="ttEvSave" style="align-self:flex-start;font-size:12px;font-weight:700;background:var(--brand,#FFDF00);color:#1e1e1e;border:none;border-radius:8px;padding:7px 14px;cursor:pointer">Guardar</button>
  </div>
  <div style="padding:8px 14px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
    <span id="ttTotal" style="font-size:13px;font-weight:700;color:var(--text-primary)"></span>
    <a id="ttLink" href="#" style="font-size:12px;font-weight:600;color:#2563eb;text-decoration:none">
      Ver detalle →
    </a>
  </div>
</div>
<div id="tooltipOverlay" onclick="closeTooltip()" style="display:none;position:fixed;inset:0;z-index:9998"></div>

<style>
.cal-day-cell { min-height:70px;padding:4px 3px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);vertical-align:top;position:relative }
.cal-day-cell:nth-child(7n){ border-right:none }
.cal-ev { border-radius:3px;padding:2px 5px;font-size:10px;font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;display:block;border:none;width:100%;text-align:left;transition:opacity .1s }
.cal-ev:hover{ opacity:.8 }
.cal-ev-e { background:#dbeafe;color:#1e40af }
.cal-ev-a { background:#dcfce7;color:#166534 }
.cal-ev-v { background:#ede9fe;color:#5b21b6 }
.cal-ev-g { background:#ffedd5;color:#9a3412 }
.cal-day-cell.blocked { box-shadow:inset 0 0 0 2px #dc2626;background:#fee2e2!important }
.cal-blocked-tag { display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:700;color:#dc2626;background:#fee2e2;border:1px solid #fecaca;border-radius:8px;padding:1px 5px;line-height:1.4;white-space:nowrap }
.cal-blocked-tag svg { width:9px;height:9px;flex-shrink:0 }
.list-item { display:flex;align-items:stretch;border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s }
.list-item:last-child{ border-bottom:none }
.list-item:hover { background:var(--bg-input) }
.list-main { display:flex;align-items:center;gap:12px;padding:13px 18px;flex:1 }
.list-detail { background:var(--bg-input);padding:10px 18px 12px 76px;border-top:1px solid var(--border);display:none }
.list-detail.open { display:block }
.detail-grid2 { display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px }
.detail-lbl2 { font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px }
.detail-val2 { font-size:12px;font-weight:600;color:var(--text-primary) }
.list-month-hdr { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 18px 6px;background:#fafafa;border-bottom:1px solid var(--border) }
.tt-info-row { display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--text-muted) }
.tt-info-row span { color:var(--text-primary) }
</style>

<script>
var QUOTES = <?php echo json_encode($calQuotes); ?>;
var AGENDA = <?php echo json_encode($agenda); ?>;
var APP    = '<?php echo APP_URL; ?>';
var CSRF           = '<?php echo csrfToken(); ?>';
var EV_COLS_OK     = <?php echo json_encode($eventoColsOk); ?>;
var AGENDA_COLS_OK = <?php echo json_encode($agendaColsOk); ?>;
var AGENDA_VENTA_OK = <?php echo json_encode($agendaVentaOk); ?>;
var IS_ADMIN        = <?php echo json_encode($esAdmin); ?>;

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
// Un evento de agenda aparece en cada día de su rango [fecha, fecha_fin||fecha]
function agendaEnd(a){ return a.fecha_fin || a.event_date; }
function agendaByDate(d){ return AGENDA.filter(function(a){ return a.event_date<=d && agendaEnd(a)>=d; }); }
var today  = new Date();
var cur    = new Date(today.getFullYear(), today.getMonth(), 1);
var view   = 'month';
var MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
var DAYS   = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
var DAYS_SHORT = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
var MONTHS_SHORT = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

// "Sáb 13 jun" desde 'YYYY-MM-DD' (parseado en local, sin TZ shift)
function fmtDayLabel(iso){
  if(!iso) return '';
  var p = iso.split('-');
  var dt = new Date(parseInt(p[0],10), parseInt(p[1],10)-1, parseInt(p[2],10));
  return DAYS_SHORT[dt.getDay()] + ' ' + parseInt(p[2],10) + ' ' + MONTHS_SHORT[parseInt(p[1],10)-1];
}
// Rango legible: un día -> "Sáb 13 jun"; varios -> "Sáb 13 – Dom 14 jun"
function agendaRangeLabel(a){
  var start = a.event_date, end = a.fecha_fin;
  if(!end || end===start) return fmtDayLabel(start);
  return fmtDayLabel(start) + ' – ' + fmtDayLabel(end);
}
// Rango de hora legible: "11:00 – 15:00" o solo "11:00"
function agendaTimeLabel(a){
  if(!a.hora) return '';
  return a.hora_fin ? (a.hora + ' – ' + a.hora_fin) : a.hora;
}

function fmtMoney(n) { return 'S/ ' + parseFloat(n||0).toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2}); }

function setView(v) {
  view = v;
  document.getElementById('viewMonth').style.display = v==='month' ? '' : 'none';
  document.getElementById('viewList').style.display  = v==='list'  ? '' : 'none';
  document.getElementById('btnMonth').style.cssText  = v==='month' ? 'color:var(--ink);border-color:var(--red);background:var(--red-light)' : '';
  document.getElementById('btnList').style.cssText   = v==='list'  ? 'color:var(--ink);border-color:var(--red);background:var(--red-light)' : '';
  if (v==='month') renderMonth(); else renderList();
}

function calNav(dir) { cur.setMonth(cur.getMonth()+dir); setView(view); }
function calToday()  { cur = new Date(today.getFullYear(),today.getMonth(),1); setView(view); }

function quotesByDate(d) { return QUOTES.filter(function(q){ return q.event_date===d; }); }

function evClass(q) {
  if (q.origin==='event') return 'cal-ev-v';
  return q.status==='aceptada' ? 'cal-ev-a' : 'cal-ev-e';
}
// Etiqueta del evento en el calendario: nombre personalizado si lo tiene, si no el código.
function evLabel(q) { return (q.evento_nombre && (''+q.evento_nombre).trim()) ? q.evento_nombre : q.quote_number; }
function badgeStyle(q) {
  if (q.origin==='event') return 'background:#ede9fe;color:#5b21b6';
  return q.status==='aceptada' ? 'background:#dcfce7;color:#166534' : 'background:#dbeafe;color:#1e40af';
}
function badgeLabel(q) {
  if (q.origin==='event') return 'Evento';
  return q.status==='aceptada' ? 'Aceptada' : 'Enviada';
}
function barColor(q) {
  if (q.origin==='event') return '#7c3aed';
  return q.status==='aceptada' ? '#16a34a' : '#2563eb';
}
function numColor(q) { return barColor(q); }

// Clave de orden por hora: 'HH:MM' (24h); sin hora → al final
function timeKey(t){
  if(!t) return '99:99';
  var m=String(t).match(/(\d{1,2}):(\d{2})/);
  if(!m) return '99:99';
  return (m[1].length<2?'0':'')+m[1]+':'+m[2];
}

function renderMonth() {
  document.getElementById('calTitle').textContent = MONTHS[cur.getMonth()] + ' ' + cur.getFullYear();
  var grid = document.getElementById('calGrid');
  var year = cur.getFullYear(), month = cur.getMonth();
  var firstDow = (new Date(year,month,1).getDay()+6)%7;
  var daysInM  = new Date(year,month+1,0).getDate();
  var daysInP  = new Date(year,month,0).getDate();
  var todayStr = today.toISOString().split('T')[0];
  var html = '';

  for (var i=firstDow-1;i>=0;i--) {
    html += '<div class="cal-day-cell" style="opacity:.3"><div style="font-size:11px;color:#999;padding-left:2px">'+(daysInP-i)+'</div></div>';
  }
  for (var d=1;d<=daysInM;d++) {
    var mm = String(month+1).padStart(2,'0');
    var dd = String(d).padStart(2,'0');
    var dateS = year+'-'+mm+'-'+dd;
    var isT   = dateS===todayStr;
    var qs    = quotesByDate(dateS);
    var ags   = agendaByDate(dateS);
    var blocked = ags.some(function(a){ return Number(a.bloquea)===1; });
    var bg    = isT ? 'background:#eff6ff' : '';
    var nc    = isT ? 'color:#1d4ed8;font-weight:600' : 'color:#999';
    html += '<div class="cal-day-cell'+(blocked?' blocked':'')+'" style="'+bg+'">';
    html += '<div style="font-size:11px;'+nc+';padding-left:2px;margin-bottom:2px">'+d+'</div>';
    if (blocked) html += '<div class="cal-blocked-tag" style="margin-bottom:2px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>No disponible</div>';
    var dayEv = [];
    qs.forEach(function(q){ dayEv.push({k:timeKey(q.event_time), t:'q', d:q}); });
    ags.forEach(function(a){ dayEv.push({k:timeKey(a.hora), t:'a', d:a}); });
    dayEv.sort(function(x,y){ return x.k<y.k?-1:(x.k>y.k?1:0); });
    dayEv.forEach(function(e){
      if (e.t==='q') html += '<button class="cal-ev '+evClass(e.d)+'" onclick="showTooltip(event,'+e.d.id+')">'+esc(evLabel(e.d))+'</button>';
      else html += '<button class="cal-ev cal-ev-g" onclick="showAgendaTooltip(event,'+e.d.id+')">'+esc(e.d.titulo)+'</button>';
    });
    html += '</div>';
  }
  var total = firstDow+daysInM;
  var rem   = total%7;
  if (rem>0) for (var x=1;x<=(7-rem);x++) html += '<div class="cal-day-cell" style="opacity:.3"><div style="font-size:11px;color:#999;padding-left:2px">'+x+'</div></div>';
  grid.innerHTML = html;
}

function showTooltip(e, qid) {
  e.stopPropagation();
  var q = QUOTES.find(function(x){ return x.id===qid; });
  if (!q) return;

  document.getElementById('ttName').textContent   = q.client_name;
  document.getElementById('ttBadge').setAttribute('style', badgeStyle(q));
  document.getElementById('ttBadge').textContent  = badgeLabel(q);

  var body = '';
  var icoClock = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
  var icoPin = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>';
  var icoPeople = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
  if (q.event_time) body += '<div class="tt-info-row">'+icoClock+'<span>'+q.event_time+(q.event_duration?' · '+q.event_duration:'')+'</span></div>';
  if (q.event_location) body += '<div class="tt-info-row">'+icoPin+'<span>'+q.event_location+'</span></div>';
  if (q.num_people>0) body += '<div class="tt-info-row">'+icoPeople+'<span>'+q.num_people+' personas</span></div>';
  document.getElementById('ttBody').innerHTML = body;
  document.getElementById('ttProds').textContent = q.items_summary || 'Sin productos';
  document.getElementById('ttTotal').textContent = fmtMoney(q.total);
  document.getElementById('ttLink').href = APP+'/quotes/edit.php?id='+q.id;
  document.getElementById('ttLink').textContent = (q.origin==='event'?'Ver evento':'Ver cotización')+' →';

  // Editor de cotización: nombre/atendida (la venta de cotización ya es el total). La venta solo aplica a eventos libres (agenda).
  var edit = document.getElementById('ttEdit');
  if (EV_COLS_OK && (q.status==='aceptada' || q.origin==='event')) {
    document.getElementById('ttEvNombre').style.display = '';
    document.getElementById('ttEvNombre').value = q.evento_nombre || '';
    document.getElementById('ttEvNombre').placeholder = 'Nombre del evento (para la salida a evento)';
    document.getElementById('ttEvAtendido').checked = Number(q.evento_atendido)===1;
    document.getElementById('ttEvAtLabel').style.display = 'flex';
    document.getElementById('ttEvVentaWrap').style.display = 'none';
    var btn = document.getElementById('ttEvSave');
    btn.textContent = 'Guardar';
    btn.onclick = function(){ guardarEvento(q.id); };
    edit.style.display = 'flex';
  } else {
    edit.style.display = 'none';
  }

  var tip = document.getElementById('globalTooltip');
  var rect = e.target.getBoundingClientRect();
  var top  = rect.bottom+window.scrollY+6;
  var left = Math.min(rect.left+window.scrollX, window.innerWidth-280);

  tip.style.display = 'block';
  tip.style.top     = top+'px';
  tip.style.left    = Math.max(8,left)+'px';
  tip.style.position = 'absolute';
  document.getElementById('tooltipOverlay').style.display = 'block';
}

function guardarEvento(qid) {
  var q = QUOTES.find(function(x){ return x.id===qid; });
  if (!q) return;
  var nombre = document.getElementById('ttEvNombre').value.trim();
  var atendido = document.getElementById('ttEvAtendido').checked ? 1 : 0;
  var btn = document.getElementById('ttEvSave');
  btn.disabled = true; btn.textContent = 'Guardando…';
  var body = new URLSearchParams({ action:'set_evento', id:qid, evento_nombre:nombre, evento_atendido:atendido });
  fetch(APP+'/api/quotes.php', { method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-Token':CSRF }, body:body })
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Guardar';
      if (d && d.ok) { q.evento_nombre = nombre; q.evento_atendido = atendido; closeTooltip(); }
      else { alert((d && d.error) || 'No se pudo guardar'); }
    })
    .catch(function(){ btn.disabled = false; btn.textContent = 'Guardar'; alert('Error de red'); });
}

function guardarAgenda(aid) {
  var a = AGENDA.find(function(x){ return x.id===aid; });
  if (!a) return;
  var nombre = document.getElementById('ttEvNombre').value.trim();
  var atendido = (AGENDA_COLS_OK && document.getElementById('ttEvAtendido').checked) ? 1 : 0;
  var ventaVisible = document.getElementById('ttEvVentaWrap').style.display !== 'none';
  var venta = ventaVisible ? (document.getElementById('ttEvVenta').value || '').replace(',', '.').trim() : null;
  var btn = document.getElementById('ttEvSave');
  btn.disabled = true; btn.textContent = 'Guardando…';
  var params = { action:'set_agenda', id:aid, titulo:nombre, atendido:atendido };
  if (ventaVisible) params.venta_real = venta;
  var body = new URLSearchParams(params);
  fetch(APP+'/api/quotes.php', { method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-Token':CSRF }, body:body })
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Guardar';
      if (d && d.ok) { if (nombre) a.titulo = nombre; a.atendido = atendido; if (ventaVisible) a.venta_real = venta; closeTooltip(); setView(view); }
      else { alert((d && d.error) || 'No se pudo guardar'); }
    })
    .catch(function(){ btn.disabled = false; btn.textContent = 'Guardar'; alert('Error de red'); });
}

function showAgendaTooltip(e, aid) {
  e.stopPropagation();
  var a = AGENDA.find(function(x){ return x.id===aid; });
  if (!a) return;

  var aBlocked = Number(a.bloquea)===1;
  document.getElementById('ttName').textContent = a.titulo;
  if (aBlocked) {
    document.getElementById('ttBadge').setAttribute('style', 'background:#fee2e2;color:#dc2626');
    document.getElementById('ttBadge').textContent = 'No disponible';
  } else {
    document.getElementById('ttBadge').setAttribute('style', 'background:#ffedd5;color:#9a3412');
    document.getElementById('ttBadge').textContent = 'Agenda';
  }

  var body = '';
  if (aBlocked) body += '<div class="tt-info-row" style="color:#dc2626;font-weight:600"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><span style="color:#dc2626">Día no disponible (bloqueado)</span></div>';
  var icoCal = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
  var icoClock = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
  var icoPin = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>';
  if (a.fecha_fin && a.fecha_fin!==a.event_date) body += '<div class="tt-info-row">'+icoCal+'<span>'+esc(agendaRangeLabel(a))+'</span></div>';
  var tLbl = agendaTimeLabel(a);
  if (tLbl) body += '<div class="tt-info-row">'+icoClock+'<span>'+esc(tLbl)+'</span></div>';
  if (a.lugar) body += '<div class="tt-info-row">'+icoPin+'<span>'+esc(a.lugar)+'</span></div>';
  if (a.notas) body += '<div class="tt-info-row"><span>'+esc(a.notas)+'</span></div>';
  document.getElementById('ttBody').innerHTML = body;
  // Editor inline del evento libre (renombrar + atendida + venta real solo admin)
  document.getElementById('ttEvNombre').style.display = '';
  document.getElementById('ttEvNombre').value = a.titulo || '';
  document.getElementById('ttEvNombre').placeholder = 'Nombre del evento libre';
  var aPuedeVenta = IS_ADMIN && AGENDA_VENTA_OK;
  document.getElementById('ttEvVentaWrap').style.display = aPuedeVenta ? 'block' : 'none';
  if (aPuedeVenta) document.getElementById('ttEvVenta').value = (a.venta_real!=null && a.venta_real!=='') ? a.venta_real : '';
  if (AGENDA_COLS_OK) {
    document.getElementById('ttEvAtendido').checked = Number(a.atendido)===1;
    document.getElementById('ttEvAtLabel').style.display = 'flex';
  } else {
    document.getElementById('ttEvAtendido').checked = false;
    document.getElementById('ttEvAtLabel').style.display = 'none';
  }
  var aBtn = document.getElementById('ttEvSave');
  aBtn.textContent = 'Guardar';
  aBtn.onclick = function(){ guardarAgenda(a.id); };
  document.getElementById('ttEdit').style.display = 'flex';
  document.getElementById('ttProds').textContent = 'Sin venta · solo disponibilidad';
  document.getElementById('ttTotal').textContent = '';
  document.getElementById('ttLink').href = APP+'/admin/events/create?agenda='+a.id;
  document.getElementById('ttLink').textContent = 'Editar →';

  var tip = document.getElementById('globalTooltip');
  var rect = e.target.getBoundingClientRect();
  var top  = rect.bottom+window.scrollY+6;
  var left = Math.min(rect.left+window.scrollX, window.innerWidth-280);

  tip.style.display = 'block';
  tip.style.top     = top+'px';
  tip.style.left    = Math.max(8,left)+'px';
  tip.style.position = 'absolute';
  document.getElementById('tooltipOverlay').style.display = 'block';
}

function closeTooltip() {
  document.getElementById('globalTooltip').style.display = 'none';
  document.getElementById('tooltipOverlay').style.display = 'none';
}

function renderList() {
  var year=cur.getFullYear(), month=cur.getMonth();
  document.getElementById('calTitle').textContent = MONTHS[month]+' '+year+' — Lista';
  var startS = year+'-'+String(month+1).padStart(2,'0')+'-01';
  var endDate = new Date(year,month+3,0);
  var endS   = endDate.getFullYear()+'-'+String(endDate.getMonth()+1).padStart(2,'0')+'-'+String(endDate.getDate()).padStart(2,'0');
  var filtered = QUOTES.filter(function(q){ return q.event_date>=startS && q.event_date<=endS; })
    .map(function(q){ return q; })
    .concat(AGENDA.filter(function(a){ return agendaEnd(a)>=startS && a.event_date<=endS; })
      .map(function(a){ return {__agenda:true, id:a.id, event_date:a.event_date, fecha_fin:a.fecha_fin, titulo:a.titulo, hora:a.hora, hora_fin:a.hora_fin, lugar:a.lugar, notas:a.notas, bloquea:a.bloquea, venta_real:a.venta_real}; }));
  filtered.sort(function(a,b){
    if (a.event_date !== b.event_date) return a.event_date<b.event_date?-1:1;
    var ka=timeKey(a.__agenda?a.hora:a.event_time), kb=timeKey(b.__agenda?b.hora:b.event_time);
    return ka<kb?-1:(ka>kb?1:0);
  });

  if (!filtered.length) {
    document.getElementById('listContent').innerHTML = '<div style="padding:32px;text-align:center;color:var(--text-muted);font-size:14px">Sin eventos en este periodo</div>';
    return;
  }

  var html=''; var lastM='';
  filtered.forEach(function(q) {
    var parts=q.event_date.split('-');
    var mKey=parts[0]+'-'+parts[1];
    var mIdx=parseInt(parts[1])-1;
    if (mKey!==lastM) { html+='<div class="list-month-hdr">'+MONTHS[mIdx]+' '+parts[0]+'</div>'; lastM=mKey; }
    var d   = parseInt(parts[2]);
    var dow = DAYS[new Date(q.event_date).getDay()];

    if (q.__agenda) {
      var aBlk = Number(q.bloquea)===1;
      var aMulti = q.fecha_fin && q.fecha_fin!==q.event_date;
      var aTime = agendaTimeLabel(q);
      var aDetail = '';
      if (aTime||q.lugar||q.notas||aMulti) {
        aDetail  = '<div class="list-detail" id="ld_a'+q.id+'">';
        aDetail += '<div class="detail-grid2">';
        if (aMulti)  aDetail += '<div><div class="detail-lbl2">Fechas</div><div class="detail-val2">'+esc(agendaRangeLabel(q))+'</div></div>';
        if (aTime)   aDetail += '<div><div class="detail-lbl2">Hora</div><div class="detail-val2">'+esc(aTime)+'</div></div>';
        if (q.lugar) aDetail += '<div style="grid-column:1/-1"><div class="detail-lbl2">Lugar</div><div class="detail-val2">'+esc(q.lugar)+'</div></div>';
        aDetail += '</div>';
        if (q.notas) aDetail += '<div style="font-size:11px;color:var(--text-muted);margin-bottom:8px">'+esc(q.notas)+'</div>';
        aDetail += '<a href="'+APP+'/admin/events/create?agenda='+q.id+'" style="font-size:12px;font-weight:600;color:#c2410c;text-decoration:none">Editar →</a>';
        aDetail += '</div>';
      }
      html += '<div class="list-item" id="li_a'+q.id+'">';
      html += '<div class="list-main" onclick="toggleListDetailA('+q.id+')">';
      html += '<div style="text-align:center;min-width:38px;flex-shrink:0"><div style="font-size:20px;font-weight:700;line-height:1;color:var(--text-primary)">'+d+'</div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">'+dow+'</div></div>';
      html += '<div style="width:3px;border-radius:2px;align-self:stretch;min-height:36px;flex-shrink:0;background:'+(aBlk?'#dc2626':'#f97316')+'"></div>';
      html += '<div style="flex:1;min-width:0"><div style="font-size:11px;font-weight:600;color:'+(aBlk?'#dc2626':'#c2410c')+'">'+(aBlk?'Agenda · bloqueado':'Agenda')+(aMulti?' · '+esc(agendaRangeLabel(q)):'')+'</div><div style="font-size:13px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+esc(q.titulo)+'</div>';
      if (q.lugar||aTime) html += '<div style="font-size:11px;color:var(--text-muted)">'+esc(q.lugar||'')+(aTime?(q.lugar?' · ':'')+esc(aTime):'')+'</div>';
      html += '</div>';
      var aTieneVenta = q.venta_real!=null && q.venta_real!=='' && parseFloat(q.venta_real)>0;
      if (aBlk) html += '<div style="text-align:right;flex-shrink:0"><span style="font-size:10px;padding:2px 7px;border-radius:8px;font-weight:600;background:#fee2e2;color:#dc2626">No disponible</span></div>';
      else if (aTieneVenta) html += '<div style="text-align:right;flex-shrink:0"><div><span style="font-size:10px;padding:2px 7px;border-radius:8px;font-weight:600;background:#dcfce7;color:#166534">Venta</span></div><div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-top:3px">'+fmtMoney(q.venta_real)+'</div></div>';
      else html += '<div style="text-align:right;flex-shrink:0"><span style="font-size:10px;padding:2px 7px;border-radius:8px;font-weight:600;background:#ffedd5;color:#9a3412">Sin venta</span></div>';
      html += '<span style="font-size:16px;color:var(--text-muted);margin-left:8px;transition:transform .2s" id="ch_a'+q.id+'">&#8250;</span>';
      html += '</div>';
      html += aDetail;
      html += '</div>';
      return;
    }

    var bc  = barColor(q);
    var bl  = badgeLabel(q);
    var bs  = badgeStyle(q);
    var nc  = numColor(q);
    var nBadge = '<span style="font-size:10px;padding:2px 7px;border-radius:8px;font-weight:600;'+bs+'">'+bl+'</span>';
    var detail = '';
    if (q.event_time||q.event_location||q.num_people||q.items_summary) {
      detail  = '<div class="list-detail" id="ld'+q.id+'">';
      detail += '<div class="detail-grid2">';
      if (q.event_time) detail += '<div><div class="detail-lbl2">Hora</div><div class="detail-val2">'+q.event_time+(q.event_duration?' · '+q.event_duration:'')+'</div></div>';
      if (q.num_people>0) detail += '<div><div class="detail-lbl2">Personas</div><div class="detail-val2">'+q.num_people+' pers.</div></div>';
      if (q.event_location) detail += '<div style="grid-column:1/-1"><div class="detail-lbl2">Lugar</div><div class="detail-val2">'+q.event_location+'</div></div>';
      detail += '</div>';
      if (q.items_summary) detail += '<div style="font-size:11px;color:var(--text-muted);margin-bottom:8px">'+q.items_summary+'</div>';
      detail += '<a href="'+APP+'/quotes/edit.php?id='+q.id+'" style="font-size:12px;font-weight:600;color:#2563eb;text-decoration:none">'+(q.origin==='event'?'Ver evento':'Ver cotización')+' →</a>';
      detail += '</div>';
    }
    html += '<div class="list-item" id="li'+q.id+'">';
    html += '<div class="list-main" onclick="toggleListDetail('+q.id+')">';
    html += '<div style="text-align:center;min-width:38px;flex-shrink:0"><div style="font-size:20px;font-weight:700;line-height:1;color:var(--text-primary)">'+d+'</div><div style="font-size:10px;color:var(--text-muted);text-transform:uppercase">'+dow+'</div></div>';
    html += '<div style="width:3px;border-radius:2px;align-self:stretch;min-height:36px;flex-shrink:0;background:'+bc+'"></div>';
    html += '<div style="flex:1;min-width:0"><div style="font-size:11px;font-weight:600;color:'+nc+'">'+esc(evLabel(q))+'</div><div style="font-size:13px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+q.client_name+'</div>';
    if (q.event_type||q.event_time) html += '<div style="font-size:11px;color:var(--text-muted)">'+(q.event_type||'')+(q.event_time?' · '+q.event_time:'')+'</div>';
    html += '</div>';
    html += '<div style="text-align:right;flex-shrink:0"><div>'+nBadge+'</div><div style="font-size:13px;font-weight:700;color:var(--text-primary);margin-top:3px">'+fmtMoney(q.total)+'</div></div>';
    html += '<span style="font-size:16px;color:var(--text-muted);margin-left:8px;transition:transform .2s" id="ch'+q.id+'">&#8250;</span>';
    html += '</div>';
    html += detail;
    html += '</div>';
  });
  document.getElementById('listContent').innerHTML = html;
}

function toggleListDetail(id) {
  var detail = document.getElementById('ld'+id);
  var ch     = document.getElementById('ch'+id);
  if (!detail) return;
  var open = detail.classList.contains('open');
  document.querySelectorAll('.list-detail').forEach(function(d){ d.classList.remove('open'); });
  document.querySelectorAll('[id^="ch"]').forEach(function(c){ c.style.transform=''; });
  if (!open) { detail.classList.add('open'); if(ch) ch.style.transform='rotate(90deg)'; }
}

function toggleListDetailA(id) {
  var detail = document.getElementById('ld_a'+id);
  var ch     = document.getElementById('ch_a'+id);
  if (!detail) return;
  var open = detail.classList.contains('open');
  document.querySelectorAll('.list-detail').forEach(function(d){ d.classList.remove('open'); });
  document.querySelectorAll('[id^="ch"]').forEach(function(c){ c.style.transform=''; });
  if (!open) { detail.classList.add('open'); if(ch) ch.style.transform='rotate(90deg)'; }
}

setView('month');
</script>

<?php include __DIR__ . '/layout-bottom.php'; ?>
