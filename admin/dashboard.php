<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requirePermission('dashboard');

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

    <!-- Mini calendario (interactivo) -->
    <div class="card">
      <div class="mc-head">
        <span class="mc-title" id="mcTitle">—</span>
        <div class="mc-nav">
          <button type="button" onclick="mcNav(-1)" aria-label="Mes anterior">&#8249;</button>
          <button type="button" onclick="mcNav(1)" aria-label="Mes siguiente">&#8250;</button>
        </div>
      </div>
      <div class="mc-grid" id="mcGrid"></div>
      <div class="mc-legend">
        <span><i style="background:#FCDA13"></i>Enviada</span>
        <span><i style="background:#16a34a"></i>Aceptada</span>
        <span><i style="background:#7c3aed"></i>Evento</span>
      </div>
    </div>

  </div><!-- /side-panel -->
</div><!-- /dash-grid -->

<style>
.mc-day.has-ev{cursor:pointer}
.mc-day.has-ev:hover{background:var(--brand-soft)}
.mc-legend{display:flex;gap:12px;padding:8px 14px 14px;flex-wrap:wrap}
.mc-legend span{display:flex;align-items:center;gap:5px;font-size:10.5px;color:var(--text-muted)}
.mc-legend i{width:9px;height:9px;border-radius:2px;display:inline-block}
#mcPop{position:fixed;z-index:9999;width:248px;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.14);overflow:hidden}
#mcPop .pop-head{padding:9px 12px;border-bottom:1px solid var(--border);font-size:12px;font-weight:700;color:var(--text-primary);display:flex;justify-content:space-between;align-items:center}
#mcPop .pop-close{background:none;border:none;cursor:pointer;color:#999;font-size:16px;line-height:1}
#mcPop .pop-row{display:flex;align-items:center;gap:9px;padding:9px 12px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit}
#mcPop .pop-row:last-child{border-bottom:none}
#mcPop .pop-row:hover{background:#fafafa}
#mcPop .pop-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
#mcPop .pop-info{display:flex;flex-direction:column;min-width:0;flex:1}
#mcPop .pop-name{font-size:12.5px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#mcPop .pop-meta{font-size:11px;color:var(--text-muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#mcPop .pop-amt{margin-left:auto;font-size:12px;font-weight:700;color:var(--text-primary);flex-shrink:0}
</style>

<script>
var MC_EVENTS = <?php echo json_encode($calQuotes); ?>;
var MC_APP    = '<?php echo APP_URL; ?>';
var MC_TODAY  = new Date();
var mcCur     = new Date(MC_TODAY.getFullYear(), MC_TODAY.getMonth(), 1);
var MC_MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

function mcState(e){ if(e.origin==='event') return 'evento'; return e.status==='aceptada' ? 'aceptada' : 'enviada'; }
function mcColor(st){ return st==='aceptada' ? '#16a34a' : (st==='evento' ? '#7c3aed' : '#FCDA13'); }
function mcByDate(ds){ return MC_EVENTS.filter(function(e){ return e.event_date === ds; }); }
function mcPad(n){ return (n<10?'0':'')+n; }

function mcRender(){
  var y = mcCur.getFullYear(), m = mcCur.getMonth();
  document.getElementById('mcTitle').textContent = MC_MONTHS[m] + ' ' + y;
  var lead   = (new Date(y, m, 1).getDay() + 6) % 7;   // Lunes = 0
  var days   = new Date(y, m+1, 0).getDate();
  var todayS = MC_TODAY.getFullYear()+'-'+mcPad(MC_TODAY.getMonth()+1)+'-'+mcPad(MC_TODAY.getDate());
  var html = '';
  ['L','M','X','J','V','S','D'].forEach(function(d){ html += '<div class="mc-dow">'+d+'</div>'; });
  for (var i=0;i<lead;i++) html += '<div class="mc-day muted"></div>';
  for (var d=1; d<=days; d++){
    var ds  = y+'-'+mcPad(m+1)+'-'+mcPad(d);
    var evs = mcByDate(ds);
    var cls = 'mc-day' + (ds===todayS?' today':'') + (evs.length?' has-ev':'');
    var dot = evs.length ? '<span class="mk" style="background:'+mcColor(mcState(evs[0]))+'"></span>' : '';
    var clk = evs.length ? ' onclick="mcShowDay(event,\''+ds+'\')"' : '';
    html += '<div class="'+cls+'"'+clk+'>'+d+dot+'</div>';
  }
  document.getElementById('mcGrid').innerHTML = html;
}

function mcNav(dir){ mcCur.setMonth(mcCur.getMonth()+dir); mcClosePop(); mcRender(); }
function mcClosePop(){ var p=document.getElementById('mcPop'); if(p) p.style.display='none'; }

function mcShowDay(ev, ds){
  ev.stopPropagation();
  var evs = mcByDate(ds);
  if (!evs.length) return;
  var p = document.getElementById('mcPop');
  if (!p){
    p = document.createElement('div'); p.id = 'mcPop'; document.body.appendChild(p);
    document.addEventListener('click', function(e){ if(p && !p.contains(e.target)) p.style.display='none'; });
  }
  var parts = ds.split('-');
  var head  = parts[2].replace(/^0/,'') + ' ' + MC_MONTHS[parseInt(parts[1],10)-1].toLowerCase();
  var rows = evs.map(function(e){
    var st   = mcState(e);
    var meta = (e.event_type||'Evento') + (e.event_time?' · '+e.event_time:'') + (e.num_people>0?' · '+e.num_people+' pers.':'');
    var amt  = 'S/ ' + parseFloat(e.total).toLocaleString('es-PE',{minimumFractionDigits:2});
    return '<a class="pop-row" href="'+MC_APP+'/quotes/edit.php?id='+e.id+'">'
      + '<span class="pop-dot" style="background:'+mcColor(st)+'"></span>'
      + '<span class="pop-info"><span class="pop-name">'+e.client_name+'</span><span class="pop-meta">'+meta+'</span></span>'
      + '<span class="pop-amt">'+amt+'</span></a>';
  }).join('');
  p.innerHTML = '<div class="pop-head"><span>'+head+' · '+evs.length+' evento'+(evs.length>1?'s':'')+'</span>'
    + '<button class="pop-close" type="button" onclick="mcClosePop()">&times;</button></div>' + rows;
  p.style.display = 'block';
  var rect = ev.currentTarget.getBoundingClientRect();
  var left = Math.min(rect.left, window.innerWidth - 256);
  var top  = rect.bottom + 6;
  if (top + p.offsetHeight > window.innerHeight - 8) top = Math.max(8, rect.top - p.offsetHeight - 6);
  p.style.left = Math.max(8, left) + 'px';
  p.style.top  = top + 'px';
}

mcRender();
</script>

<?php include __DIR__ . '/layout-bottom.php'; ?>
