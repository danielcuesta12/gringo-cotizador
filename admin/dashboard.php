<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();

$pageTitle = 'Dashboard';

// Stats
$statsCot = Database::fetch(
    "SELECT COUNT(*) as n FROM quotes
     WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"
);
// Facturado del mes = suma de TODOS los eventos aceptados cuya FECHA DE EVENTO cae en el mes actual.
// (Antes filtraba por accepted_at; un evento aceptado en mayo para una boda de junio no contaba en junio.)
$statsFacturado = Database::fetch(
    "SELECT COALESCE(SUM(total),0) as sum FROM quotes
     WHERE status='aceptada'
     AND event_date IS NOT NULL AND event_date != ''
     AND MONTH(event_date)=MONTH(NOW()) AND YEAR(event_date)=YEAR(NOW())"
);
$byStatus = Database::fetchAll("SELECT status, COUNT(*) as n FROM quotes GROUP BY status");
$smap     = array_column($byStatus, 'n', 'status');

// Solicitudes pendientes
$pendingReq = (int)Database::fetch("SELECT COUNT(*) as n FROM quote_requests WHERE status='pendiente'")['n'];

// Cotizaciones para calendario (enviadas y aceptadas con fecha de evento)
$calQuotes = Database::fetchAll(
    "SELECT q.id, q.quote_number, q.status, q.origin,
            q.event_date, q.event_type, q.event_time, q.event_duration,
            q.event_location, q.num_people, q.total,
            c.name as client_name
     FROM quotes q JOIN clients c ON c.id=q.client_id
     WHERE q.status IN ('enviada','aceptada') AND q.event_date IS NOT NULL AND q.event_date != ''
     ORDER BY q.event_date ASC"
);

// Items por cotización para tooltips
$calIds = array_column($calQuotes, 'id');
$calItemsMap = array();
if (!empty($calIds)) {
    $ph = implode(',', array_fill(0, count($calIds), '?'));
    $rows = Database::fetchAll(
        "SELECT quote_id, name, quantity FROM quote_items WHERE quote_id IN ($ph) ORDER BY sort_order",
        $calIds
    );
    foreach ($rows as $r) {
        $calItemsMap[$r['quote_id']][] = $r['name'] . ' × ' . number_format((float)$r['quantity'], 0);
    }
}
foreach ($calQuotes as &$q) {
    $q['items_summary'] = isset($calItemsMap[$q['id']]) ? implode(' · ', array_slice($calItemsMap[$q['id']], 0, 3)) : '';
}
unset($q);

// Agrupar por fecha
$calMap = array();
foreach ($calQuotes as $cq) {
    $calMap[$cq['event_date']][] = $cq;
}

// Últimas cotizaciones
$recentQuotes = Database::fetchAll(
    "SELECT q.*, c.name as client_name FROM quotes q
     JOIN clients c ON c.id=q.client_id
     ORDER BY q.created_at DESC LIMIT 6"
);

$activePage = 'dashboard';
include __DIR__ . '/layout-top.php';
?>

<?php
$todayStr = date('Y-m-d');
// Próximos eventos: enviadas/aceptadas/eventos con fecha futura o de hoy
$upcoming = array_slice(array_values(array_filter($calQuotes, function ($q) use ($todayStr) {
    return $q['event_date'] >= $todayStr;
})), 0, 6);

// Datos del mini-calendario (mes actual)
$mcMonths = array('', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre');
$mcYear   = (int)date('Y');
$mcMonth  = (int)date('n');
$mcToday  = (int)date('j');
$mcLead   = (int)date('N', mktime(0, 0, 0, $mcMonth, 1, $mcYear)) - 1; // 0 = Lunes
$mcDays   = (int)date('t', mktime(0, 0, 0, $mcMonth, 1, $mcYear));

// Clase de color por estado (amarillo=enviada, verde=aceptada, morado=evento)
function dashState($q) {
    if (($q['origin'] ?? '') === 'event') return 'evento';
    return ($q['status'] ?? '') === 'aceptada' ? 'aceptada' : 'enviada';
}
function dashStateLabel($q) {
    if (($q['origin'] ?? '') === 'event') return 'Evento';
    return ($q['status'] ?? '') === 'aceptada' ? 'Aceptada' : 'Enviada';
}
?>

<!-- STATS -->
<div class="dash-stats">
  <div class="dstat dstat-cot">
    <div class="dstat-label">Cot. este mes</div>
    <div class="dstat-num"><?php echo (int)($statsCot['n'] ?? 0); ?></div>
    <div class="dstat-sub">cotizaciones</div>
  </div>
  <div class="dstat dstat-fact">
    <div class="dstat-label">Facturado mes</div>
    <div class="dstat-num money"><?php echo formatMoney((float)($statsFacturado['sum'] ?? 0)); ?></div>
    <div class="dstat-sub">eventos del mes</div>
  </div>
  <div class="dstat dstat-acc">
    <div class="dstat-label">Aceptadas</div>
    <div class="dstat-num"><?php echo (int)(isset($smap['aceptada']) ? $smap['aceptada'] : 0); ?></div>
    <div class="dstat-sub">histórico</div>
  </div>
  <a class="dstat dstat-req" href="<?php echo APP_URL; ?>/admin/requests/index.php" style="text-decoration:none">
    <div class="dstat-label">Solicitudes</div>
    <div class="dstat-num" style="color:#fb923c"><?php echo $pendingReq; ?></div>
    <div class="dstat-sub">pendientes</div>
  </a>
</div>

<div class="dash-grid">
  <div class="dash-main">

<!-- CALENDARIO -->
<div class="card" style="margin-bottom:20px">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:10px">
    <span style="font-size:16px;font-weight:700" id="calTitle">Cargando...</span>
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
      <?php foreach (array('L','M','X','J','V','S','D') as $d): ?>
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
  <div style="display:flex;gap:14px;padding:8px 16px;border-top:1px solid var(--border);flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-muted)">
      <span style="width:10px;height:10px;border-radius:2px;background:#FCDA13;display:inline-block"></span>Enviada
    </div>
    <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-muted)">
      <span style="width:10px;height:10px;border-radius:2px;background:#16a34a;display:inline-block"></span>Aceptada
    </div>
    <div style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-muted)">
      <span style="width:10px;height:10px;border-radius:2px;background:#7c3aed;display:inline-block"></span>Evento directo
    </div>
  </div>
</div>

<!-- PRÓXIMOS EVENTOS -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Próximos eventos</span>
    <a href="<?php echo APP_URL; ?>/quotes/list.php" class="btn btn-ghost btn-sm">Ver todas &rarr;</a>
  </div>
  <?php if (empty($upcoming)): ?>
  <div class="empty-state" style="padding:36px 20px">
    <p>Sin eventos próximos</p>
    <a href="<?php echo APP_URL; ?>/quotes/create.php" class="btn btn-primary">+ Nueva cotización</a>
  </div>
  <?php else: foreach ($upcoming as $q): $st = dashState($q); ?>
  <a class="ev-row" href="<?php echo APP_URL; ?>/quotes/edit.php?id=<?php echo $q['id']; ?>">
    <span class="ev-dot ev-dot-<?php echo $st; ?>"></span>
    <div class="ev-info">
      <div class="ev-name"><?php echo clean($q['client_name']); ?></div>
      <div class="ev-meta"><?php echo clean($q['event_type'] ?: 'Evento'); ?> · <?php echo formatDate($q['event_date']); ?><?php echo (int)$q['num_people'] > 0 ? ' · ' . (int)$q['num_people'] . ' pers.' : ''; ?></div>
    </div>
    <span class="ev-badge ev-badge-<?php echo $st; ?>"><?php echo dashStateLabel($q); ?></span>
    <span class="ev-amount"><?php echo formatMoney((float)$q['total']); ?></span>
  </a>
  <?php endforeach; endif; ?>
</div>

  </div><!-- /dash-main -->

  <!-- PANEL LATERAL -->
  <div class="side-panel">

    <!-- Acciones rápidas -->
    <div class="card">
      <div class="card-header"><span class="card-title">Acciones rápidas</span></div>
      <div class="qa-grid">
        <a class="qa" href="<?php echo APP_URL; ?>/quotes/create.php">
          <span class="qa-ico qa-ico-y"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>
          <span class="qa-txt">Nueva cotización</span>
        </a>
        <a class="qa" href="<?php echo APP_URL; ?>/admin/events/create">
          <span class="qa-ico qa-ico-p"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg></span>
          <span class="qa-txt">Nuevo evento</span>
        </a>
        <a class="qa" href="<?php echo APP_URL; ?>/admin/clients/form.php">
          <span class="qa-ico qa-ico-g"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
          <span class="qa-txt">Nuevo cliente</span>
        </a>
        <a class="qa" href="<?php echo APP_URL; ?>/admin/requests/index.php">
          <span class="qa-ico qa-ico-o"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/></svg></span>
          <span class="qa-txt">Solicitudes</span>
        </a>
      </div>
    </div>

    <!-- Mini calendario -->
    <div class="card">
      <div class="mc-head">
        <span class="mc-title"><?php echo $mcMonths[$mcMonth] . ' ' . $mcYear; ?></span>
      </div>
      <div class="mc-grid">
        <?php foreach (array('L','M','X','J','V','S','D') as $d): ?><div class="mc-dow"><?php echo $d; ?></div><?php endforeach; ?>
        <?php for ($i = 0; $i < $mcLead; $i++): ?><div class="mc-day muted"></div><?php endfor; ?>
        <?php for ($d = 1; $d <= $mcDays; $d++):
          $ds        = sprintf('%04d-%02d-%02d', $mcYear, $mcMonth, $d);
          $dayEvents = isset($calMap[$ds]) ? $calMap[$ds] : array();
          $isToday   = ($d === $mcToday);
        ?>
        <div class="mc-day<?php echo $isToday ? ' today' : ''; ?>"><?php echo $d; ?><?php if (!empty($dayEvents)): $st = dashState($dayEvents[0]); $dotcol = $st === 'aceptada' ? '#16a34a' : ($st === 'evento' ? '#7c3aed' : '#FCDA13'); ?><span class="mk" style="background:<?php echo $dotcol; ?>"></span><?php endif; ?></div>
        <?php endfor; ?>
      </div>
    </div>

  </div><!-- /side-panel -->
</div><!-- /dash-grid -->

<style>
.dash-desktop{display:block}.dash-mobile{display:none}
@media(max-width:768px){.dash-desktop{display:none}.dash-mobile{display:block}}
.cal-day-cell{min-height:60px;padding:4px 3px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);vertical-align:top}
.cal-day-cell:nth-child(7n){border-right:none}
.cal-ev{border-radius:3px;padding:1px 4px;font-size:9px;font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;display:block;text-decoration:none}
.cal-ev-e{background:rgba(252,218,19,.28);color:#7a6800}
.cal-ev-a{background:#dcfce7;color:#166534}
.cal-ev-v{background:#ede9fe;color:#5b21b6}
.list-item{display:flex;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid var(--border);text-decoration:none;-webkit-tap-highlight-color:transparent}
.list-item:last-child{border-bottom:none}
.list-item:hover{background:#fafafa}
.list-month-hdr{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 18px 6px;background:#fafafa;border-bottom:1px solid var(--border)}
</style>

<script>
var QUOTES = <?php echo json_encode($calQuotes); ?>;
var APP    = '<?php echo APP_URL; ?>';
var today  = new Date();
var cur    = new Date(today.getFullYear(), today.getMonth(), 1);
var view   = 'month';

var MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

function setView(v) {
  view = v;
  document.getElementById('viewMonth').style.display = v==='month' ? '' : 'none';
  document.getElementById('viewList').style.display  = v==='list'  ? '' : 'none';
  document.getElementById('btnMonth').style.cssText  = v==='month' ? 'color:var(--ink);border-color:var(--brand);background:var(--brand-soft)' : '';
  document.getElementById('btnList').style.cssText   = v==='list'  ? 'color:var(--ink);border-color:var(--brand);background:var(--brand-soft)' : '';
  if (v==='month') renderMonth();
  else renderList();
}

function calNav(dir) {
  cur.setMonth(cur.getMonth() + dir);
  if (view==='month') renderMonth(); else renderList();
}
function calToday() {
  cur = new Date(today.getFullYear(), today.getMonth(), 1);
  if (view==='month') renderMonth(); else renderList();
}

function quotesByDate(dateStr) {
  return QUOTES.filter(function(q){ return q.event_date === dateStr; });
}

function evClass(q) {
  if (q.origin==='event') return 'cal-ev-v';
  return q.status==='aceptada' ? 'cal-ev-a' : 'cal-ev-e';
}
function barColor(q) {
  if (q.origin==='event') return '#7c3aed';
  return q.status==='aceptada' ? '#16a34a' : '#2563eb';
}
function badgeLabel(q) {
  if (q.origin==='event') return 'Evento';
  return q.status==='aceptada' ? 'Aceptada' : 'Enviada';
}
function badgeStyle(q) {
  if (q.origin==='event') return 'background:#ede9fe;color:#5b21b6';
  return q.status==='aceptada' ? 'background:#dcfce7;color:#166534' : 'background:#dbeafe;color:#1e40af';
}

function showDashTooltip(e, qid) {
  e.stopPropagation();
  var q = QUOTES.find(function(x){ return x.id==qid; });
  if (!q) return;
  var tip = document.getElementById('dashTip');
  if (!tip) {
    tip = document.createElement('div');
    tip.id = 'dashTip';
    tip.style.cssText = 'position:fixed;z-index:9999;width:240px;background:var(--bg-card,#fff);border:1.5px solid #e5e5e5;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden;font-family:inherit';
    document.body.appendChild(tip);
    document.addEventListener('click', function(ev){ if(!tip.contains(ev.target)) tip.style.display='none'; });
  }
  var body = '';
  if (q.event_time) body += '<div style="font-size:12px;color:#555;display:flex;gap:6px;margin-bottom:4px"><span>&#128336;</span><span>'+q.event_time+(q.event_duration?' · '+q.event_duration:'')+'</span></div>';
  if (q.event_location) body += '<div style="font-size:12px;color:#555;display:flex;gap:6px;margin-bottom:4px"><span>&#128205;</span><span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+q.event_location+'</span></div>';
  if (q.num_people>0) body += '<div style="font-size:12px;color:#555;display:flex;gap:6px"><span>&#128101;</span><span>'+q.num_people+' personas</span></div>';
  tip.innerHTML = '<div style="padding:9px 12px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center"><span style="font-size:12px;font-weight:700;color:#1a1a1a">'+q.client_name+'</span><span style="font-size:10px;padding:2px 7px;border-radius:10px;font-weight:600;'+badgeStyle(q)+'">'+badgeLabel(q)+'</span></div>'
    +'<div style="padding:9px 12px">'+body+'</div>'
    +(q.items_summary?'<div style="font-size:11px;color:#888;padding:6px 12px;border-top:1px solid #eee;background:#fafafa">'+q.items_summary+'</div>':'')
    +'<div style="padding:8px 12px;border-top:1px solid #eee;display:flex;justify-content:space-between;align-items:center"><span style="font-size:13px;font-weight:700;color:#1a1a1a">S/ '+parseFloat(q.total).toLocaleString("es-PE",{minimumFractionDigits:2})+'</span><a href="'+APP+'/quotes/edit.php?id='+q.id+'" style="font-size:12px;font-weight:600;color:#2563eb;text-decoration:none">'+(q.origin==='event'?'Ver evento':'Ver cot.')+'→</a></div>';
  var rect = e.target.getBoundingClientRect();
  var left = Math.min(rect.left, window.innerWidth-256);
  tip.style.display='block';
  tip.style.top=(rect.bottom+6)+'px';
  tip.style.left=Math.max(8,left)+'px';
}

function renderMonth() {
  document.getElementById('calTitle').textContent = MONTHS[cur.getMonth()] + ' ' + cur.getFullYear();
  var grid    = document.getElementById('calGrid');
  var year    = cur.getFullYear();
  var month   = cur.getMonth();
  var firstDow= (new Date(year, month, 1).getDay() + 6) % 7; // Lunes=0
  var daysInM = new Date(year, month+1, 0).getDate();
  var daysInP = new Date(year, month, 0).getDate();
  var html    = '';
  var todayStr= today.toISOString().split('T')[0];

  // Días del mes anterior
  for (var i=firstDow-1; i>=0; i--) {
    html += '<div class="cal-day-cell" style="opacity:.3"><div style="font-size:11px;color:#999;padding-left:2px">'+(daysInP-i)+'</div></div>';
  }
  // Días del mes
  for (var d=1; d<=daysInM; d++) {
    var mm    = String(month+1).padStart(2,'0');
    var dd    = String(d).padStart(2,'0');
    var dateS = year+'-'+mm+'-'+dd;
    var isToday = dateS === todayStr;
    var qs    = quotesByDate(dateS);
    var bg    = isToday ? 'background:var(--blue-bg)' : '';
    var numStyle = isToday ? 'color:var(--blue);font-weight:700' : 'color:#999';
    html += '<div class="cal-day-cell" style="'+bg+'">';
    html += '<div style="font-size:11px;'+numStyle+';padding-left:2px;margin-bottom:2px">'+d+'</div>';
    qs.forEach(function(q) {
      var cls = q.status==='aceptada' ? 'cal-ev-a' : 'cal-ev-e';
      var cls2=evClass(q); html += '<button onclick="showDashTooltip(event,'+q.id+')" class="cal-ev '+cls2+'" style="border:none;cursor:pointer">'+q.quote_number+'</button>';
    });
    html += '</div>';
  }
  // Completar última fila
  var total = firstDow + daysInM;
  var rem   = total % 7;
  if (rem > 0) {
    for (var x=1; x<=(7-rem); x++) {
      html += '<div class="cal-day-cell" style="opacity:.3"><div style="font-size:11px;color:#999;padding-left:2px">'+x+'</div></div>';
    }
  }
  grid.innerHTML = html;
}

function renderList() {
  var year  = cur.getFullYear();
  var month = cur.getMonth();
  document.getElementById('calTitle').textContent = MONTHS[month] + ' ' + year + ' — Lista';

  var start  = new Date(year, month, 1);
  var end    = new Date(year, month+3, 0);
  var startS = start.toISOString().split('T')[0];
  var endS   = end.toISOString().split('T')[0];
  var filtered = QUOTES.filter(function(q){ return q.event_date >= startS && q.event_date <= endS; });

  if (!filtered.length) {
    document.getElementById('listContent').innerHTML = '<div style="padding:32px;text-align:center;color:#999;font-size:14px">Sin eventos en este periodo</div>';
    return;
  }

  var html = '';
  var lastM = '';
  filtered.forEach(function(q) {
    var parts = q.event_date.split('-');
    var mKey  = parts[0]+'-'+parts[1];
    var mIdx  = parseInt(parts[1])-1;
    if (mKey !== lastM) {
      html += '<div class="list-month-hdr">'+MONTHS[mIdx]+' '+parts[0]+'</div>';
      lastM = mKey;
    }
    var d   = parseInt(parts[2]);
    var dow = ['Dom','Lun','Mar','Mie','Jue','Vie','Sab'][new Date(q.event_date).getDay()];
    var bc  = barColor(q);
    var bl  = badgeLabel(q);
    var bs  = badgeStyle(q);
    var nc  = (q.origin==='event') ? '#7c3aed' : (q.status==='aceptada' ? '#16a34a' : '#2563eb');

    // Detalle expandible
    var detail = '';
    if (q.event_time || q.event_location || q.num_people || q.items_summary) {
      detail += '<div id="ld'+q.id+'" style="display:none;background:#fafafa;padding:10px 18px 12px 72px;border-top:1px solid var(--border)">';
      detail += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px">';
      if (q.event_time) detail += '<div><div style="font-size:10px;color:#999;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px">Hora</div><div style="font-size:12px;font-weight:600;color:#1a1a1a">'+q.event_time+(q.event_duration?' · '+q.event_duration:'')+'</div></div>';
      if (q.num_people>0) detail += '<div><div style="font-size:10px;color:#999;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px">Personas</div><div style="font-size:12px;font-weight:600;color:#1a1a1a">'+q.num_people+' pers.</div></div>';
      if (q.event_location) detail += '<div style="grid-column:1/-1"><div style="font-size:10px;color:#999;text-transform:uppercase;letter-spacing:.4px;margin-bottom:2px">Lugar</div><div style="font-size:12px;font-weight:600;color:#1a1a1a">'+q.event_location+'</div></div>';
      detail += '</div>';
      if (q.items_summary) detail += '<div style="font-size:11px;color:#999;margin-bottom:8px">'+q.items_summary+'</div>';
      detail += '<a href="'+APP+'/quotes/edit.php?id='+q.id+'" style="font-size:12px;font-weight:600;color:#2563eb;text-decoration:none">'+(q.origin==='event'?'Ver evento':'Ver cotización')+' →</a>';
      detail += '</div>';
    }

    html += '<div style="border-bottom:1px solid var(--border)">';
    html += '<div onclick="toggleDashDetail('+q.id+')" style="display:flex;align-items:center;gap:12px;padding:13px 18px;cursor:pointer;-webkit-tap-highlight-color:transparent" id="li'+q.id+'">';
    html += '<div style="text-align:center;min-width:38px;flex-shrink:0"><div style="font-size:20px;font-weight:700;line-height:1;color:#1a1a1a">'+d+'</div><div style="font-size:10px;color:#999;text-transform:uppercase">'+dow+'</div></div>';
    html += '<div style="width:3px;border-radius:2px;align-self:stretch;min-height:36px;flex-shrink:0;background:'+bc+'"></div>';
    html += '<div style="flex:1;min-width:0">';
    html += '<div style="font-size:11px;font-weight:600;color:'+nc+'">'+q.quote_number+'</div>';
    html += '<div style="font-size:13px;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">'+q.client_name+'</div>';
    if (q.event_type || q.event_time) html += '<div style="font-size:11px;color:#999">'+(q.event_type||'')+(q.event_time?' · '+q.event_time:'')+'</div>';
    html += '</div>';
    html += '<span style="'+bs+';padding:3px 8px;border-radius:8px;font-size:11px;font-weight:600;flex-shrink:0">'+bl+'</span>';
    html += '<span id="ch'+q.id+'" style="font-size:16px;color:#999;margin-left:6px;transition:transform .2s;display:inline-block">›</span>';
    html += '</div>';
    html += detail;
    html += '</div>';
  });
  document.getElementById('listContent').innerHTML = html;
}

function toggleDashDetail(id) {
  var detail = document.getElementById('ld'+id);
  var ch     = document.getElementById('ch'+id);
  if (!detail) {
    window.location.href = APP+'/quotes/edit.php?id='+id;
    return;
  }
  var isOpen = detail.style.display !== 'none';
  // Cerrar todos
  document.querySelectorAll('[id^="ld"]').forEach(function(d){ d.style.display='none'; });
  document.querySelectorAll('[id^="ch"]').forEach(function(c){ c.style.transform=''; });
  if (!isOpen) {
    detail.style.display = 'block';
    if (ch) ch.style.transform = 'rotate(90deg)';
  }
}

// Agregar "Solicitudes" al nav si hay pendientes
<?php if ($pendingReq > 0): ?>
var navLinks = document.querySelectorAll('.nav-link');
<?php endif; ?>

setView('month');
</script>

<?php include __DIR__ . '/layout-bottom.php'; ?>
