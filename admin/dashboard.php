<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requirePermission('dashboard');

// --- Mes parametrizable ---
$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $mes = date('Y-m');
}
$mesParts  = explode('-', $mes);
$mesYear   = (int)$mesParts[0];
$mesMonth  = (int)$mesParts[1];
$mesNames  = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mesLabel  = $mesNames[$mesMonth] . ' ' . $mesYear;

// --- Genera los últimos 12 meses para el selector ---
$mesOpciones = [];
for ($i = 0; $i < 13; $i++) {
    $ts  = mktime(0, 0, 0, date('n') - $i, 1, date('Y'));
    $val = date('Y-m', $ts);
    $lbl = $mesNames[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    $mesOpciones[] = ['val' => $val, 'lbl' => $lbl];
}

// ============================================================
// DATOS — COTIZACIONES
// ============================================================

$facturadoMes = (float)Database::fetch(
    "SELECT COALESCE(SUM(total),0) s FROM quotes
     WHERE status='aceptada'
       AND event_date IS NOT NULL AND event_date <> ''
       AND DATE_FORMAT(event_date,'%Y-%m')=?",
    [$mes]
)['s'];

$cotMes = (int)Database::fetch(
    "SELECT COUNT(*) n FROM quotes WHERE DATE_FORMAT(created_at,'%Y-%m')=?",
    [$mes]
)['n'];

$aceptadasMes = (int)Database::fetch(
    "SELECT COUNT(*) n FROM quotes WHERE status='aceptada' AND DATE_FORMAT(created_at,'%Y-%m')=?",
    [$mes]
)['n'];

$pendingReq = (int)Database::fetch(
    "SELECT COUNT(*) n FROM quote_requests WHERE status='pendiente'"
)['n'];

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
$calItemsMap = [];
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
foreach ($calQuotes as &$cqr) {
    $cqr['items_summary'] = isset($calItemsMap[$cqr['id']]) ? implode(' · ', array_slice($calItemsMap[$cqr['id']], 0, 3)) : '';
}
unset($cqr);

// Agrupar por fecha
$calMap = [];
foreach ($calQuotes as $cqItem) {
    $calMap[$cqItem['event_date']][] = $cqItem;
}

// Agenda (eventos sin venta) para el mini-calendario. Tolerante si falta la migración.
try { $agendaDash = Database::fetchAll("SELECT id, fecha AS event_date, titulo, hora, lugar FROM agenda"); }
catch (Exception $e) { $agendaDash = array(); }

// ============================================================
// DATOS — OPERACIÓN (tabla pedidos; puede no existir)
// ============================================================
$opOk           = false;
$ventasHoyTotal = 0.0;
$ventasHoyN     = 0;
$ventasMes      = 0.0;
$canalCarta     = 0.0;
$canalPos       = 0.0;
$enPreparacion  = 0;
$ticketProm     = 0.0;
$opNote         = '';

try {
    $rowHoy = Database::fetch(
        "SELECT COALESCE(SUM(total),0) t, COUNT(*) n FROM pedidos
         WHERE estado<>'cancelado' AND DATE(created_at)=CURDATE()"
    );
    $ventasHoyTotal = (float)($rowHoy['t'] ?? 0);
    $ventasHoyN     = (int)($rowHoy['n'] ?? 0);

    $rowMes = Database::fetch(
        "SELECT COALESCE(SUM(total),0) t FROM pedidos
         WHERE estado<>'cancelado' AND DATE_FORMAT(created_at,'%Y-%m')=?",
        [$mes]
    );
    $ventasMes = (float)($rowMes['t'] ?? 0);

    $canales = Database::fetchAll(
        "SELECT origen, COALESCE(SUM(total),0) t FROM pedidos
         WHERE estado<>'cancelado' AND DATE_FORMAT(created_at,'%Y-%m')=?
         GROUP BY origen",
        [$mes]
    );
    foreach ($canales as $canal) {
        if ($canal['origen'] === 'carta') $canalCarta = (float)$canal['t'];
        if ($canal['origen'] === 'pos')   $canalPos   = (float)$canal['t'];
    }

    $rowPrep     = Database::fetch("SELECT COUNT(*) n FROM pedidos WHERE estado='en_preparacion'");
    $enPreparacion = (int)($rowPrep['n'] ?? 0);

    $ticketProm  = $ventasHoyN > 0 ? $ventasHoyTotal / $ventasHoyN : 0.0;
    $opOk        = true;
} catch (Exception $e) {
    $opNote = 'Módulo de operación no disponible en esta instalación.';
}

// ============================================================
// CONSOLIDADO
// ============================================================
$totalConsolidado = $facturadoMes + $ventasMes;
$pctEventos       = $totalConsolidado > 0 ? round(($facturadoMes / $totalConsolidado) * 100, 1) : 0;
$pctOperacion     = $totalConsolidado > 0 ? round(($ventasMes   / $totalConsolidado) * 100, 1) : 0;

// ============================================================
// PRÓXIMOS EVENTOS
// ============================================================
$todayStr = date('Y-m-d');
$upcoming = array_slice(array_values(array_filter($calQuotes, function ($q) use ($todayStr) {
    return $q['event_date'] >= $todayStr;
})), 0, 6);

function dashState(array $q): string {
    if (($q['origin'] ?? '') === 'event') return 'evento';
    return ($q['status'] ?? '') === 'aceptada' ? 'aceptada' : 'enviada';
}
function dashStateLabel(array $q): string {
    if (($q['origin'] ?? '') === 'event') return 'Evento';
    return ($q['status'] ?? '') === 'aceptada' ? 'Aceptada' : 'Enviada';
}

// Rango del mes para exportaciones
$desde = $mes . '-01';
$hasta = date('Y-m-t', strtotime($desde));

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
$extraHead  = '<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>';
include __DIR__ . '/layout-top.php';
?>

<style>
/* ============================================================
   BRAND TOKENS
   ============================================================ */
:root {
  --pink:       #ef7da6;
  --pink-soft:  rgba(255,187,200,.28);
  --pink-deep:  #d6457e;
  --yellow:     #FFDF00;
  --yellow-soft:rgba(255,223,0,.18);
  --yellow-deep:#8a7000;
  --black:      #1E1E1E;
}

/* ============================================================
   CONSOLIDADO CARD
   ============================================================ */
.cons-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 22px 24px 20px;
  margin-bottom: 20px;
  display: flex;
  gap: 28px;
  align-items: flex-start;
  flex-wrap: wrap;
}
.cons-left { flex: 1; min-width: 220px; }
.cons-eyebrow {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .6px;
  text-transform: uppercase;
  color: var(--text-muted);
  margin-bottom: 4px;
}
.cons-total {
  font-size: 32px;
  font-weight: 800;
  color: var(--black);
  letter-spacing: -.5px;
  line-height: 1.1;
  margin-bottom: 14px;
}
.cons-bar {
  height: 8px;
  border-radius: 99px;
  background: var(--border);
  overflow: hidden;
  display: flex;
  margin-bottom: 10px;
}
.cons-bar-pink   { background: var(--pink);   transition: width .4s; }
.cons-bar-yellow { background: var(--yellow); transition: width .4s; }
.cons-legend {
  display: flex;
  gap: 18px;
  flex-wrap: wrap;
}
.cons-leg {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--text-secondary);
}
.cons-leg-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.cons-leg strong { color: var(--text-primary); }

.cons-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
  min-width: 180px;
}
.cons-mes-select {
  padding: 7px 12px;
  font-size: 13px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text-primary);
  cursor: pointer;
  outline: none;
}
.cons-mes-select:focus { border-color: var(--black); }
.btn-export {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  background: var(--black);
  color: #fff;
  border-radius: var(--radius);
  font-size: 12.5px;
  font-weight: 600;
  text-decoration: none;
  transition: opacity .15s;
  white-space: nowrap;
}
.btn-export:hover { opacity: .82; }
.btn-export svg { width: 14px; height: 14px; }
.btn-export-sm {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 10px;
  background: var(--black);
  color: #fff;
  border-radius: 6px;
  font-size: 11.5px;
  font-weight: 600;
  text-decoration: none;
  transition: opacity .15s;
}
.btn-export-sm:hover { opacity: .82; }

/* ============================================================
   SECTION HEADERS
   ============================================================ */
.world-section { margin-bottom: 24px; }

.world-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.world-chip {
  width: 34px; height: 34px;
  border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.world-chip svg { width: 17px; height: 17px; }
.world-chip-pink   { background: var(--pink-soft); color: var(--pink-deep); }
.world-chip-yellow { background: var(--yellow-soft); color: var(--yellow-deep); }
.world-titles { flex: 1; }
.world-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--text-primary);
  line-height: 1.2;
}
.world-sub {
  font-size: 12px;
  color: var(--text-muted);
  margin-top: 1px;
}
.world-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

.world-body {
  padding-left: 14px;
}
.world-body-pink   { border-left: 3px solid var(--pink); }
.world-body-yellow { border-left: 3px solid var(--yellow); }

/* ============================================================
   KPI GRID
   ============================================================ */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}
@media (max-width: 900px) {
  .kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 500px) {
  .kpi-grid { grid-template-columns: 1fr 1fr; }
}
.kpi-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px 16px 12px;
}
.kpi-label {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .5px;
  color: var(--text-muted);
  margin-bottom: 6px;
}
.kpi-val {
  font-size: 22px;
  font-weight: 800;
  color: var(--text-primary);
  line-height: 1;
  margin-bottom: 2px;
}
.kpi-val-pink   { color: var(--pink-deep); }
.kpi-val-yellow { color: var(--yellow-deep); }
.kpi-val-orange { color: #d97706; }
.kpi-sub {
  font-size: 11px;
  color: var(--text-muted);
  margin-top: 3px;
}

/* ============================================================
   DASH GRID (2 col)
   ============================================================ */
.dash-grid {
  display: grid;
  grid-template-columns: 1fr 300px;
  gap: 16px;
  align-items: start;
}
@media (max-width: 860px) {
  .dash-grid { grid-template-columns: 1fr; }
}

/* ============================================================
   PRÓXIMOS EVENTOS
   ============================================================ */
.ev-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 11px 16px;
  border-bottom: 1px solid var(--border);
  text-decoration: none;
  color: inherit;
  transition: background .12s;
}
.ev-row:last-child { border-bottom: none; }
.ev-row:hover { background: #fafafa; }
.ev-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.ev-dot-enviada  { background: #FCDA13; }
.ev-dot-aceptada { background: #16a34a; }
.ev-dot-evento   { background: #7c3aed; }
.ev-info { flex: 1; min-width: 0; }
.ev-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-primary);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ev-meta { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
.ev-badge {
  font-size: 10.5px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 99px;
  flex-shrink: 0;
}
.ev-badge-enviada  { background: rgba(252,218,19,.2); color: #7a6200; }
.ev-badge-aceptada { background: rgba(22,163,74,.1);  color: #15803d; }
.ev-badge-evento   { background: rgba(124,58,237,.1); color: #6d28d9; }
.ev-amount { font-size: 12.5px; font-weight: 700; color: var(--text-primary); flex-shrink: 0; margin-left: 6px; }

/* ============================================================
   QUICK ACTIONS
   ============================================================ */
.qa-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  padding: 12px 14px 14px;
}
.qa {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 10px;
  border-radius: var(--radius);
  background: #fafafa;
  border: 1px solid var(--border);
  text-decoration: none;
  color: var(--text-primary);
  font-size: 12.5px;
  font-weight: 600;
  transition: background .12s, border-color .12s;
}
.qa:hover { background: #f0f0f0; border-color: #ccc; }
.qa-ico {
  width: 26px; height: 26px;
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.qa-ico svg { width: 13px; height: 13px; }
.qa-ico-pink   { background: var(--pink-soft);   color: var(--pink-deep); }
.qa-ico-yellow { background: var(--yellow-soft); color: var(--yellow-deep); }
.qa-ico-g      { background: rgba(37,99,235,.1); color: #2563eb; }
.qa-ico-o      { background: rgba(234,88,12,.1); color: #ea580c; }

/* ============================================================
   CANAL SPLIT CARD
   ============================================================ */
.canal-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px 16px;
  margin-bottom: 12px;
}
.canal-title {
  font-size: 12px;
  font-weight: 700;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: .5px;
  margin-bottom: 10px;
}
.canal-bar {
  height: 7px;
  border-radius: 99px;
  background: var(--border);
  overflow: hidden;
  display: flex;
  margin-bottom: 8px;
}
.canal-bar-carta { background: #3b82f6; }
.canal-bar-pos   { background: var(--yellow); }
.canal-legend {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
}
.canal-leg {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11.5px;
  color: var(--text-secondary);
}
.canal-leg-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.canal-leg strong { color: var(--text-primary); }

.prep-note {
  margin-top: 10px;
  font-size: 12px;
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  gap: 6px;
}
.prep-note a { color: var(--yellow-deep); font-weight: 600; text-decoration: none; }
.prep-note a:hover { text-decoration: underline; }
.prep-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--yellow);
  color: var(--yellow-deep);
  font-size: 11px;
  font-weight: 700;
  min-width: 20px;
  height: 20px;
  border-radius: 10px;
  padding: 0 5px;
}

/* ============================================================
   MINI CALENDAR (unchanged styles)
   ============================================================ */
.mc-day.has-ev { cursor: pointer; }
.mc-day.has-ev:hover { background: var(--brand-soft, #fef2f2); }
.mc-legend { display:flex; gap:12px; padding:8px 14px 14px; flex-wrap:wrap; }
.mc-legend span { display:flex; align-items:center; gap:5px; font-size:10.5px; color:var(--text-muted); }
.mc-legend i { width:9px; height:9px; border-radius:2px; display:inline-block; }
#mcPop { position:fixed; z-index:9999; width:248px; background:#fff; border:1px solid var(--border); border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,.14); overflow:hidden; }
#mcPop .pop-head { padding:9px 12px; border-bottom:1px solid var(--border); font-size:12px; font-weight:700; color:var(--text-primary); display:flex; justify-content:space-between; align-items:center; }
#mcPop .pop-close { background:none; border:none; cursor:pointer; color:#999; font-size:16px; line-height:1; }
#mcPop .pop-row { display:flex; align-items:center; gap:9px; padding:9px 12px; border-bottom:1px solid var(--border); text-decoration:none; color:inherit; }
#mcPop .pop-row:last-child { border-bottom:none; }
#mcPop .pop-row:hover { background:#fafafa; }
#mcPop .pop-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
#mcPop .pop-info { display:flex; flex-direction:column; min-width:0; flex:1; }
#mcPop .pop-name { font-size:12.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#mcPop .pop-meta { font-size:11px; color:var(--text-muted); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
#mcPop .pop-amt { margin-left:auto; font-size:12px; font-weight:700; color:var(--text-primary); flex-shrink:0; }

.op-note {
  font-size: 12px;
  color: var(--text-muted);
  background: #fafafa;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 10px 14px;
  margin-bottom: 12px;
}

/* Responsive: hide cons-right actions on tiny screens */
@media (max-width: 480px) {
  .cons-right { align-items: flex-start; }
}
</style>

<?php
// ============================================================
// CONSOLIDADO (admin only)
// ============================================================
if (isAdmin()):
?>
<div class="cons-card">
  <div class="cons-left">
    <div class="cons-eyebrow">Negocio &middot; <?php echo $mesLabel; ?></div>
    <div class="cons-total"><?php echo formatMoney($totalConsolidado); ?></div>
    <div class="cons-bar">
      <div class="cons-bar-pink"   style="width:<?php echo $pctEventos; ?>%"></div>
      <div class="cons-bar-yellow" style="width:<?php echo $pctOperacion; ?>%"></div>
    </div>
    <div class="cons-legend">
      <span class="cons-leg">
        <span class="cons-leg-dot" style="background:var(--pink)"></span>
        Eventos &nbsp;<strong><?php echo formatMoney($facturadoMes); ?></strong>
      </span>
      <span class="cons-leg">
        <span class="cons-leg-dot" style="background:var(--yellow)"></span>
        Operación (POS+Carta) &nbsp;<strong><?php echo formatMoney($ventasMes); ?></strong>
      </span>
    </div>
  </div>
  <div class="cons-right">
    <select class="cons-mes-select" onchange="location.href='?mes='+this.value">
      <?php foreach ($mesOpciones as $opt): ?>
        <option value="<?php echo $opt['val']; ?>" <?php echo $opt['val'] === $mes ? 'selected' : ''; ?>>
          <?php echo $opt['lbl']; ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn-export" type="button" onclick="exportConsolidado()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar a Excel
    </button>
  </div>
</div>
<?php endif; ?>

<?php
// ============================================================
// SECCIÓN COTIZACIONES Y EVENTOS
// ============================================================
if (can('quotes') || can('events') || can('calendar') || can('requests')):
?>
<div class="world-section">
  <div class="world-header">
    <span class="world-chip world-chip-pink">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
    </span>
    <div class="world-titles">
      <div class="world-title">Cotizaciones y eventos</div>
      <div class="world-sub"><?php echo $mesLabel; ?> &middot; Facturación y seguimiento</div>
    </div>
    <div class="world-actions">
      <?php if (can('quotes')): ?>
        <button class="btn-export-sm" type="button" onclick="exportCotizaciones()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Excel
        </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="world-body world-body-pink">
    <!-- KPIs -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Facturado mes</div>
        <div class="kpi-val kpi-val-pink"><?php echo formatMoney($facturadoMes); ?></div>
        <div class="kpi-sub">eventos del mes</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Cotizaciones</div>
        <div class="kpi-val"><?php echo $cotMes; ?></div>
        <div class="kpi-sub">creadas este mes</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Aceptadas</div>
        <div class="kpi-val"><?php echo $aceptadasMes; ?></div>
        <div class="kpi-sub">este mes</div>
      </div>
      <a class="kpi-card" href="<?php echo APP_URL; ?>/admin/requests/index.php" style="text-decoration:none">
        <div class="kpi-label">Solicitudes</div>
        <div class="kpi-val kpi-val-orange"><?php echo $pendingReq; ?></div>
        <div class="kpi-sub">pendientes</div>
      </a>
    </div>

    <!-- Próximos eventos + mini-calendario -->
    <div class="dash-grid">
      <div class="dash-main">
        <div class="card">
          <div class="card-header">
            <span class="card-title">Próximos eventos</span>
            <?php if (can('quotes')): ?>
              <a href="<?php echo APP_URL; ?>/quotes/list.php" class="btn btn-ghost btn-sm">Ver todas &rarr;</a>
            <?php endif; ?>
          </div>
          <?php if (empty($upcoming)): ?>
            <div class="empty-state" style="padding:36px 20px">
              <p>Sin eventos próximos</p>
              <?php if (can('quotes')): ?>
                <a href="<?php echo APP_URL; ?>/quotes/create.php" class="btn btn-primary">+ Nueva cotización</a>
              <?php endif; ?>
            </div>
          <?php else: foreach ($upcoming as $uq): $st = dashState($uq); ?>
            <a class="ev-row" href="<?php echo APP_URL; ?>/quotes/edit.php?id=<?php echo $uq['id']; ?>">
              <span class="ev-dot ev-dot-<?php echo $st; ?>"></span>
              <div class="ev-info">
                <div class="ev-name"><?php echo clean($uq['client_name']); ?></div>
                <div class="ev-meta"><?php echo clean($uq['event_type'] ?: 'Evento'); ?> &middot; <?php echo formatDate($uq['event_date']); ?><?php echo (int)$uq['num_people'] > 0 ? ' &middot; ' . (int)$uq['num_people'] . ' pers.' : ''; ?></div>
              </div>
              <span class="ev-badge ev-badge-<?php echo $st; ?>"><?php echo dashStateLabel($uq); ?></span>
              <span class="ev-amount"><?php echo formatMoney((float)$uq['total']); ?></span>
            </a>
          <?php endforeach; endif; ?>
        </div>

        <!-- Quick actions cotizaciones -->
        <div class="card" style="margin-top:14px">
          <div class="card-header"><span class="card-title">Acciones rápidas</span></div>
          <div class="qa-grid">
            <?php if (can('quotes')): ?>
              <a class="qa" href="<?php echo APP_URL; ?>/quotes/create.php">
                <span class="qa-ico qa-ico-pink">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </span>
                <span class="qa-txt">Nueva cotización</span>
              </a>
            <?php endif; ?>
            <?php if (can('events')): ?>
              <a class="qa" href="<?php echo APP_URL; ?>/admin/events/create">
                <span class="qa-ico qa-ico-pink">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg>
                </span>
                <span class="qa-txt">Nuevo evento</span>
              </a>
            <?php endif; ?>
            <?php if (can('clients')): ?>
              <a class="qa" href="<?php echo APP_URL; ?>/admin/clients/form.php">
                <span class="qa-ico qa-ico-g">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
                <span class="qa-txt">Nuevo cliente</span>
              </a>
            <?php endif; ?>
            <?php if (can('calendar')): ?>
              <a class="qa" href="<?php echo APP_URL; ?>/admin/calendar">
                <span class="qa-ico qa-ico-pink">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </span>
                <span class="qa-txt">Calendario</span>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Mini calendario -->
      <div class="side-panel">
        <div class="card">
          <div class="mc-head">
            <span class="mc-title" id="mcTitle">—</span>
            <div class="mc-nav">
              <button type="button" onclick="mcNav(-1)" aria-label="Mes anterior">&#8249;</button>
              <button type="button" onclick="mcNav(1)"  aria-label="Mes siguiente">&#8250;</button>
            </div>
          </div>
          <div class="mc-grid" id="mcGrid"></div>
          <div class="mc-legend">
            <span><i style="background:#FCDA13"></i>Enviada</span>
            <span><i style="background:#16a34a"></i>Aceptada</span>
            <span><i style="background:#7c3aed"></i>Evento</span>
            <span><i style="background:#f97316"></i>Agenda</span>
          </div>
        </div>
      </div>
    </div><!-- /dash-grid -->
  </div><!-- /world-body -->
</div><!-- /world-section cotizaciones -->
<?php endif; ?>

<?php
// ============================================================
// SECCIÓN OPERACIÓN
// ============================================================
if (can('pedidos') || can('pos_terminal') || can('kds') || can('pos_monitor')):
?>
<div class="world-section">
  <div class="world-header">
    <span class="world-chip world-chip-yellow">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
    </span>
    <div class="world-titles">
      <div class="world-title">Operación &middot; POS y Cartas</div>
      <div class="world-sub"><?php echo $mesLabel; ?> &middot; Pedidos y ventas de mostrador</div>
    </div>
    <div class="world-actions">
      <?php if (can('pedidos') || can('pos_terminal')): ?>
        <button class="btn-export-sm" type="button" onclick="exportOperacion()">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Excel
        </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="world-body world-body-yellow">
    <?php if (!$opOk && $opNote): ?>
      <div class="op-note"><?php echo clean($opNote); ?></div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Ventas hoy</div>
        <div class="kpi-val kpi-val-yellow"><?php echo formatMoney($ventasHoyTotal); ?></div>
        <div class="kpi-sub"><?php echo $ventasHoyN; ?> pedidos</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Ventas mes</div>
        <div class="kpi-val kpi-val-yellow"><?php echo formatMoney($ventasMes); ?></div>
        <div class="kpi-sub"><?php echo $mesLabel; ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Pedidos hoy</div>
        <div class="kpi-val"><?php echo $ventasHoyN; ?></div>
        <div class="kpi-sub">no cancelados</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Ticket prom.</div>
        <div class="kpi-val"><?php echo formatMoney($ticketProm); ?></div>
        <div class="kpi-sub">por pedido hoy</div>
      </div>
    </div>

    <div class="dash-grid">
      <div class="dash-main">
        <!-- Canal split -->
        <div class="canal-card">
          <div class="canal-title">Ventas del mes por canal</div>
          <?php
            $totalCanal = $canalCarta + $canalPos;
            $pctCarta   = $totalCanal > 0 ? round(($canalCarta / $totalCanal) * 100, 1) : 0;
            $pctPos     = $totalCanal > 0 ? round(($canalPos   / $totalCanal) * 100, 1) : 0;
          ?>
          <div class="canal-bar">
            <div class="canal-bar-carta" style="width:<?php echo $pctCarta; ?>%"></div>
            <div class="canal-bar-pos"   style="width:<?php echo $pctPos; ?>%"></div>
          </div>
          <div class="canal-legend">
            <span class="canal-leg">
              <span class="canal-leg-dot" style="background:#3b82f6"></span>
              Carta &nbsp;<strong><?php echo formatMoney($canalCarta); ?></strong>
            </span>
            <span class="canal-leg">
              <span class="canal-leg-dot" style="background:var(--yellow)"></span>
              POS &nbsp;<strong><?php echo formatMoney($canalPos); ?></strong>
            </span>
          </div>
          <div class="prep-note">
            <span class="prep-badge"><?php echo $enPreparacion; ?></span>
            pedidos en preparación ahora
            <?php if (can('kds')): ?>
              &middot; <a href="<?php echo APP_URL; ?>/admin/kds/index.php" target="_blank">Ver KDS &rarr;</a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Quick actions operación -->
        <div class="card" style="margin-top:14px">
          <div class="card-header"><span class="card-title">Acciones rápidas</span></div>
          <div class="qa-grid">
            <?php if (can('pos_terminal')): ?>
              <a class="qa" href="<?php echo APP_URL; ?>/pos/terminal.php" target="_blank">
                <span class="qa-ico qa-ico-yellow">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                </span>
                <span class="qa-txt">Abrir POS</span>
              </a>
            <?php endif; ?>
            <?php if (can('kds')): ?>
              <a class="qa" href="<?php echo APP_URL; ?>/admin/kds/index.php" target="_blank">
                <span class="qa-ico qa-ico-yellow">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                </span>
                <span class="qa-txt">KDS</span>
              </a>
            <?php endif; ?>
            <?php if (can('pedidos')): ?>
              <a class="qa" href="<?php echo APP_URL; ?>/admin/pedidos/index.php">
                <span class="qa-ico qa-ico-yellow">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                </span>
                <span class="qa-txt">Pedidos</span>
              </a>
            <?php endif; ?>
            <?php if (can('pos_monitor')): ?>
              <a class="qa" href="<?php echo APP_URL; ?>/admin/pos/monitor.php" target="_blank">
                <span class="qa-ico qa-ico-yellow">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                </span>
                <span class="qa-txt">En vivo</span>
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- placeholder side for visual balance on wide screens -->
      <div></div>
    </div><!-- /dash-grid operación -->
  </div><!-- /world-body-yellow -->
</div><!-- /world-section operación -->
<?php endif; ?>

<script>
// ── Export config (injected from PHP) ─────────────────────────────────────────
var REP_DESDE = '<?php echo $desde; ?>';
var REP_HASTA = '<?php echo $hasta; ?>';
var REP_API   = '<?php echo APP_URL; ?>/api/reportes.php';
var REP_MES   = '<?php echo $mes; ?>';

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtFechaJS(s) {
  if (!s) return '';
  var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? m[3] + '/' + m[2] + '/' + m[1] : s;
}
function fmtHoraJS(s) {
  if (!s) return '';
  var m = String(s).match(/(\d{2}:\d{2})/);
  return m ? m[1] : '';
}
function dlWb(wb, name) {
  if (typeof XLSX === 'undefined') { alert('SheetJS no está disponible. Recargue la página.'); return; }
  XLSX.writeFile(wb, name);
}

// ── Exportar Consolidado ──────────────────────────────────────────────────────
function exportConsolidado() {
  if (typeof XLSX === 'undefined') { alert('SheetJS no está disponible. Recargue la página.'); return; }
  fetch(REP_API + '?action=consolidado&desde=' + REP_DESDE + '&hasta=' + REP_HASTA)
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (!d.ok) { alert('Error al obtener datos: ' + (d.error || 'desconocido')); return; }
      var wb  = XLSX.utils.book_new();
      var rows = [
        ['Periodo',                        REP_MES],
        ['Eventos (cotizaciones aceptadas)', d.eventos],
        ['Operación (POS + Cartas)',          d.operacion],
        ['TOTAL NEGOCIO',                    d.total]
      ];
      var ws = XLSX.utils.aoa_to_sheet(rows);
      XLSX.utils.book_append_sheet(wb, ws, 'Resumen');
      dlWb(wb, 'consolidado-' + REP_MES + '.xlsx');
    })
    .catch(function(e){ alert('Error de red: ' + e.message); });
}

// ── Exportar Cotizaciones ─────────────────────────────────────────────────────
function exportCotizaciones() {
  if (typeof XLSX === 'undefined') { alert('SheetJS no está disponible. Recargue la página.'); return; }
  fetch(REP_API + '?action=cotizaciones&desde=' + REP_DESDE + '&hasta=' + REP_HASTA)
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (!d.ok) { alert('Error al obtener datos: ' + (d.error || 'desconocido')); return; }
      if (!d.cotizaciones || d.cotizaciones.length === 0) {
        alert('No hay datos para este mes.'); return;
      }
      var headers = ['N° Cotización','Cliente','Estado','Tipo de evento','Fecha del evento','Personas','Total','Creada'];
      var rows = [headers];
      d.cotizaciones.forEach(function(q) {
        rows.push([
          q.quote_number,
          q.cliente,
          q.status,
          q.event_type || '',
          fmtFechaJS(q.event_date),
          q.num_people ? parseInt(q.num_people, 10) : '',
          parseFloat(q.total) || 0,
          fmtFechaJS(q.created_at)
        ]);
      });
      var ws = XLSX.utils.aoa_to_sheet(rows);
      var wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Cotizaciones');
      dlWb(wb, 'cotizaciones-' + REP_MES + '.xlsx');
    })
    .catch(function(e){ alert('Error de red: ' + e.message); });
}

// ── Exportar Operación ────────────────────────────────────────────────────────
function exportOperacion() {
  if (typeof XLSX === 'undefined') { alert('SheetJS no está disponible. Recargue la página.'); return; }
  fetch(REP_API + '?action=pedidos&desde=' + REP_DESDE + '&hasta=' + REP_HASTA)
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (!d.ok) { alert('Error al obtener datos: ' + (d.error || 'desconocido')); return; }
      if (!d.pedidos || d.pedidos.length === 0) {
        alert('No hay datos para este mes.'); return;
      }
      var headers = ['#Pedido','Fecha','Hora','Origen','Ubicación','Cliente','Productos','Total','Método pago','Estado'];
      var rows = [headers];
      d.pedidos.forEach(function(p) {
        var items = [];
        try {
          var parsed = typeof p.items_json === 'string' ? JSON.parse(p.items_json) : (p.items_json || []);
          if (Array.isArray(parsed)) {
            parsed.forEach(function(it) {
              var qty    = parseInt(it.qty || 1, 10);
              var nombre = String(it.nombre || it.name || '?');
              items.push(qty + 'x ' + nombre);
            });
          }
        } catch(e2) {}
        rows.push([
          p.id,
          fmtFechaJS(p.created_at),
          fmtHoraJS(p.created_at),
          p.origen || '',
          p.ubicacion || '',
          p.cliente || '',
          items.join('; '),
          parseFloat(p.total) || 0,
          p.metodo_pago || '',
          p.estado || ''
        ]);
      });
      var ws = XLSX.utils.aoa_to_sheet(rows);
      var wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Pedidos');
      dlWb(wb, 'operacion-' + REP_MES + '.xlsx');
    })
    .catch(function(e){ alert('Error de red: ' + e.message); });
}
</script>

<script>
var MC_EVENTS = <?php echo json_encode($calQuotes); ?>;
var MC_AGENDA = <?php echo json_encode($agendaDash); ?>;
var MC_APP    = '<?php echo APP_URL; ?>';
var MC_TODAY  = new Date();
var mcCur     = new Date(MC_TODAY.getFullYear(), MC_TODAY.getMonth(), 1);
var MC_MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

function mcEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function mcState(e){ if(e.origin==='event') return 'evento'; return e.status==='aceptada' ? 'aceptada' : 'enviada'; }
function mcColor(st){ return st==='aceptada' ? '#16a34a' : (st==='evento' ? '#7c3aed' : '#FCDA13'); }
function mcByDate(ds){ return MC_EVENTS.filter(function(e){ return e.event_date === ds; }); }
function mcAgendaByDate(ds){ return MC_AGENDA.filter(function(a){ return a.event_date === ds; }); }
function mcPad(n){ return (n<10?'0':'')+n; }

function mcRender(){
  var y = mcCur.getFullYear(), m = mcCur.getMonth();
  document.getElementById('mcTitle').textContent = MC_MONTHS[m] + ' ' + y;
  var lead   = (new Date(y, m, 1).getDay() + 6) % 7;
  var days   = new Date(y, m+1, 0).getDate();
  var todayS = MC_TODAY.getFullYear()+'-'+mcPad(MC_TODAY.getMonth()+1)+'-'+mcPad(MC_TODAY.getDate());
  var html = '';
  ['L','M','X','J','V','S','D'].forEach(function(d){ html += '<div class="mc-dow">'+d+'</div>'; });
  for (var i=0;i<lead;i++) html += '<div class="mc-day muted"></div>';
  for (var d=1; d<=days; d++){
    var ds  = y+'-'+mcPad(m+1)+'-'+mcPad(d);
    var evs = mcByDate(ds);
    var ags = mcAgendaByDate(ds);
    var hasAny = evs.length || ags.length;
    var cls = 'mc-day' + (ds===todayS?' today':'') + (hasAny?' has-ev':'');
    var dotColor = evs.length ? mcColor(mcState(evs[0])) : '#f97316';
    var dot = hasAny ? '<span class="mk" style="background:'+dotColor+'"></span>' : '';
    var clk = hasAny ? ' onclick="mcShowDay(event,\''+ds+'\')"' : '';
    html += '<div class="'+cls+'"'+clk+'>'+d+dot+'</div>';
  }
  document.getElementById('mcGrid').innerHTML = html;
}

function mcNav(dir){ mcCur.setMonth(mcCur.getMonth()+dir); mcClosePop(); mcRender(); }
function mcClosePop(){ var p=document.getElementById('mcPop'); if(p) p.style.display='none'; }

function mcShowDay(ev, ds){
  ev.stopPropagation();
  var evs = mcByDate(ds);
  var ags = mcAgendaByDate(ds);
  if (!evs.length && !ags.length) return;
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
      + '<span class="pop-info"><span class="pop-name">'+mcEsc(e.client_name)+'</span><span class="pop-meta">'+mcEsc(meta)+'</span></span>'
      + '<span class="pop-amt">'+amt+'</span></a>';
  }).join('');
  rows += ags.map(function(a){
    var meta = 'Sin venta' + (a.hora?' · '+a.hora:'') + (a.lugar?' · '+a.lugar:'');
    return '<a class="pop-row" href="'+MC_APP+'/admin/events/create?agenda='+a.id+'">'
      + '<span class="pop-dot" style="background:#f97316"></span>'
      + '<span class="pop-info"><span class="pop-name">'+mcEsc(a.titulo)+'</span><span class="pop-meta">'+mcEsc(meta)+'</span></span></a>';
  }).join('');
  var n = evs.length + ags.length;
  p.innerHTML = '<div class="pop-head"><span>'+head+' &middot; '+n+' evento'+(n>1?'s':'')+'</span>'
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
