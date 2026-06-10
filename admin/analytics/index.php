<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

// ── Filtros ──
$hoy    = date('Y-m-d');
$desde  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-d', strtotime('-29 days'));
$hasta  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : $hoy;
$ubiF   = cleanInt($_GET['ubi'] ?? 0);

$ready = true;
$ubicaciones = [];
$kpi = ['visitas'=>0,'unicos'=>0,'pedidos'=>0,'ingresos'=>0.0];
$porPagina = []; $porDispositivo = []; $porDia = []; $fuentes = [];
$embudo = ['carta'=>0,'add'=>0,'checkout'=>0,'order'=>0];
$clicksLanding = []; $topVistos = []; $busquedas = []; $topLikes = [];

try {
    $ubicaciones = Database::fetchAll("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, nombre");

    // WHERE común para analytics_events
    $w = "created_at >= ? AND created_at <= ?";
    $p = [$desde.' 00:00:00', $hasta.' 23:59:59'];
    if ($ubiF) { $w .= " AND ubicacion_id = ?"; $p[] = $ubiF; }

    // KPIs
    $kpi['visitas'] = (int)(Database::fetch("SELECT COUNT(*) n FROM analytics_events WHERE event_type='page_view' AND $w", $p)['n'] ?? 0);
    $kpi['unicos']  = (int)(Database::fetch("SELECT COUNT(DISTINCT session_id) n FROM analytics_events WHERE event_type='page_view' AND $w", $p)['n'] ?? 0);
    $kpi['pedidos'] = (int)(Database::fetch("SELECT COUNT(*) n FROM analytics_events WHERE event_type='order_placed' AND $w", $p)['n'] ?? 0);

    // Ingresos reales desde pedidos (no cancelados)
    $wp = "created_at >= ? AND created_at <= ? AND estado <> 'cancelado'";
    $pp = [$desde.' 00:00:00', $hasta.' 23:59:59'];
    if ($ubiF) { $wp .= " AND ubicacion_id = ?"; $pp[] = $ubiF; }
    $kpi['ingresos'] = (float)(Database::fetch("SELECT COALESCE(SUM(total),0) s FROM pedidos WHERE $wp", $pp)['s'] ?? 0);

    // Visitas por página
    $porPagina = Database::fetchAll("SELECT page, COUNT(*) n FROM analytics_events WHERE event_type='page_view' AND $w GROUP BY page ORDER BY n DESC", $p);
    // Dispositivos
    $porDispositivo = Database::fetchAll("SELECT device, COUNT(*) n FROM analytics_events WHERE event_type='page_view' AND $w GROUP BY device ORDER BY n DESC", $p);
    // Fuentes (src)
    $fuentes = Database::fetchAll("SELECT COALESCE(NULLIF(src,''),'(directo)') src, COUNT(*) n FROM analytics_events WHERE event_type='page_view' AND $w GROUP BY src ORDER BY n DESC LIMIT 10", $p);
    // Visitas por día
    $porDia = Database::fetchAll("SELECT DATE(created_at) d, COUNT(*) n FROM analytics_events WHERE event_type='page_view' AND $w GROUP BY DATE(created_at) ORDER BY d", $p);

    // Embudo de la carta
    $embudo['carta']    = (int)(Database::fetch("SELECT COUNT(*) n FROM analytics_events WHERE event_type='page_view' AND page='carta' AND $w", $p)['n'] ?? 0);
    $embudo['add']      = (int)(Database::fetch("SELECT COUNT(*) n FROM analytics_events WHERE event_type='add_to_cart' AND $w", $p)['n'] ?? 0);
    $embudo['checkout'] = (int)(Database::fetch("SELECT COUNT(*) n FROM analytics_events WHERE event_type='checkout_open' AND $w", $p)['n'] ?? 0);
    $embudo['order']    = (int)(Database::fetch("SELECT COUNT(*) n FROM analytics_events WHERE event_type='order_placed' AND $w", $p)['n'] ?? 0);

    // Clics de la landing
    $clicksLanding = Database::fetchAll(
        "SELECT COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.label')),''),'(sin nombre)') label, COUNT(*) n
         FROM analytics_events WHERE event_type='link_click' AND $w GROUP BY label ORDER BY n DESC LIMIT 12", $p);

    // Top productos vistos
    $topVistos = Database::fetchAll(
        "SELECT p.name nombre, COUNT(*) n
         FROM analytics_events e
         JOIN products p ON p.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(e.meta_json,'$.product_id')) AS UNSIGNED)
         WHERE e.event_type='product_view' AND $w
         GROUP BY p.id, p.name ORDER BY n DESC LIMIT 10",
        $p);

    // Búsquedas
    $busquedas = Database::fetchAll(
        "SELECT LOWER(JSON_UNQUOTE(JSON_EXTRACT(meta_json,'$.term'))) term, COUNT(*) n
         FROM analytics_events WHERE event_type='search' AND $w
         GROUP BY term ORDER BY n DESC LIMIT 12", $p);

    // Likes (no dependen del rango de fechas, son acumulados)
    $likeWhere = $ubiF ? "WHERE pl.ubicacion_id = ?" : "";
    $likeParams = $ubiF ? [$ubiF] : [];
    $topLikes = Database::fetchAll(
        "SELECT p.name nombre, SUM(pl.total) n FROM product_likes pl JOIN products p ON p.id = pl.product_id $likeWhere
         GROUP BY p.id, p.name HAVING n > 0 ORDER BY n DESC LIMIT 10", $likeParams);

} catch (Exception $e) {
    $ready = false;
}

function pct($n, $base) { return $base > 0 ? round($n * 100 / $base) : 0; }
$maxDia = 0; foreach ($porDia as $d) { if ($d['n'] > $maxDia) $maxDia = $d['n']; }

$pageTitle  = 'Analítica';
$activePage = 'analytics';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 style="display:flex;align-items:center;gap:8px">
      <span style="display:inline-flex;color:var(--text-secondary)"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></span>
      Analítica
    </h1>
    <p>Comportamiento de las páginas públicas (landing, cartas, cotización)</p>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg></div>
    <h3>Falta crear las tablas de analítica</h3>
    <p>Aplica <code>install/analytics.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php else: ?>

<!-- Filtros -->
<form method="get" class="card" style="margin-bottom:18px">
  <div class="card-body" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
    <div class="form-group" style="margin:0"><label>Desde</label><input type="date" name="desde" value="<?= clean($desde) ?>"></div>
    <div class="form-group" style="margin:0"><label>Hasta</label><input type="date" name="hasta" value="<?= clean($hasta) ?>"></div>
    <?php if (count($ubicaciones) > 0): ?>
    <div class="form-group" style="margin:0"><label>Ubicación</label>
      <select name="ubi">
        <option value="0">Todas</option>
        <?php foreach ($ubicaciones as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $ubiF==$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Aplicar</button>
  </div>
</form>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px">
  <?php
  $cards = [
    ['Visitas', number_format($kpi['visitas']), 'visitas de página'],
    ['Visitantes únicos', number_format($kpi['unicos']), 'sesiones distintas'],
    ['Pedidos', number_format($kpi['pedidos']), 'desde la carta'],
    ['Ingresos', formatMoney($kpi['ingresos']), 'pedidos no cancelados'],
  ];
  foreach ($cards as $c): ?>
  <div class="card"><div class="card-body">
    <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px"><?= $c[0] ?></div>
    <div style="font-size:26px;font-weight:800;margin:4px 0;color:var(--ink)"><?= $c[1] ?></div>
    <div style="font-size:11px;color:var(--text-muted)"><?= $c[2] ?></div>
  </div></div>
  <?php endforeach; ?>
</div>

<!-- Visitas por día -->
<div class="card" style="margin-bottom:20px">
  <div class="card-header"><span class="card-title">Visitas por día</span></div>
  <div class="card-body">
    <?php if (empty($porDia)): ?><p style="color:var(--text-muted);font-size:14px;margin:0">Sin datos en este rango.</p>
    <?php else: ?>
    <div style="display:flex;align-items:flex-end;gap:3px;height:140px;overflow-x:auto">
      <?php foreach ($porDia as $d): $h = $maxDia ? max(4, round($d['n']*120/$maxDia)) : 4; ?>
        <div style="flex:1;min-width:8px;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px" title="<?= clean($d['d']) ?>: <?= $d['n'] ?>">
          <div style="font-size:10px;color:var(--text-muted)"><?= $d['n'] ?></div>
          <div style="width:100%;max-width:26px;height:<?= $h ?>px;background:var(--brand);border-radius:4px 4px 0 0"></div>
          <div style="font-size:9px;color:var(--text-muted);white-space:nowrap"><?= date('d/m', strtotime($d['d'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px">

  <!-- Embudo -->
  <div class="card"><div class="card-header"><span class="card-title">Embudo de la carta</span></div>
    <div class="card-body" style="display:flex;flex-direction:column;gap:10px">
      <?php
      $pasos = [
        ['Visitaron la carta', $embudo['carta'], '#FCDA13'],
        ['Agregaron al carrito', $embudo['add'], '#f59e0b'],
        ['Abrieron checkout', $embudo['checkout'], '#3b82f6'],
        ['Hicieron pedido', $embudo['order'], '#16a34a'],
      ];
      $baseE = max(1, $embudo['carta']);
      foreach ($pasos as $i => $ps): $w2 = max(3, pct($ps[1], $baseE)); ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px">
          <span><?= $ps[0] ?></span><span style="font-weight:700"><?= number_format($ps[1]) ?> <span style="color:var(--text-muted);font-weight:400">(<?= pct($ps[1],$baseE) ?>%)</span></span>
        </div>
        <div style="height:10px;background:var(--bg-page);border-radius:6px;overflow:hidden"><div style="height:100%;width:<?= $w2 ?>%;background:<?= $ps[2] ?>"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Visitas por página + dispositivo -->
  <div class="card"><div class="card-header"><span class="card-title">Visitas por página</span></div>
    <div class="card-body">
      <?php $pageLabels=['landing'=>'Landing','carta'=>'Carta venta','menu'=>'Menú','solicitud'=>'Cotización'];
      if (empty($porPagina)): ?><p style="color:var(--text-muted);font-size:14px;margin:0">Sin datos.</p><?php else:
      foreach ($porPagina as $pg): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:14px">
          <span><?= $pageLabels[$pg['page']] ?? clean($pg['page'] ?: '—') ?></span><strong><?= number_format($pg['n']) ?></strong>
        </div>
      <?php endforeach; endif; ?>
      <div style="display:flex;gap:18px;margin-top:14px;font-size:13px;color:var(--text-secondary)">
        <?php foreach ($porDispositivo as $dv): ?>
          <span><strong style="color:var(--ink)"><?= number_format($dv['n']) ?></strong> <?= $dv['device']==='mobile'?'📱 móvil':'🖥 desktop' ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Fuentes -->
  <div class="card"><div class="card-header"><span class="card-title">Fuentes de tráfico (?src)</span></div>
    <div class="card-body">
      <?php if (empty($fuentes)): ?><p style="color:var(--text-muted);font-size:14px;margin:0">Sin datos.</p><?php else:
      foreach ($fuentes as $f): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:14px">
          <span style="font-family:monospace"><?= clean($f['src']) ?></span><strong><?= number_format($f['n']) ?></strong>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Clics de la landing -->
  <div class="card"><div class="card-header"><span class="card-title">Clics en la landing</span></div>
    <div class="card-body">
      <?php if (empty($clicksLanding)): ?><p style="color:var(--text-muted);font-size:14px;margin:0">Sin datos.</p><?php else:
      foreach ($clicksLanding as $c): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:14px">
          <span><?= clean($c['label']) ?></span><strong><?= number_format($c['n']) ?></strong>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Top productos vistos -->
  <div class="card"><div class="card-header"><span class="card-title">Productos más vistos</span></div>
    <div class="card-body">
      <?php if (empty($topVistos)): ?><p style="color:var(--text-muted);font-size:14px;margin:0">Sin datos.</p><?php else:
      foreach ($topVistos as $t): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:14px">
          <span><?= clean($t['nombre']) ?></span><strong><?= number_format($t['n']) ?></strong>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Likes -->
  <div class="card"><div class="card-header"><span class="card-title">Productos con más ❤️</span></div>
    <div class="card-body">
      <?php if (empty($topLikes)): ?><p style="color:var(--text-muted);font-size:14px;margin:0">Sin likes todavía.</p><?php else:
      foreach ($topLikes as $t): ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:14px">
          <span><?= clean($t['nombre']) ?></span><strong><?= number_format($t['n']) ?></strong>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Búsquedas -->
  <div class="card"><div class="card-header"><span class="card-title">Búsquedas en la carta</span></div>
    <div class="card-body">
      <?php if (empty($busquedas)): ?><p style="color:var(--text-muted);font-size:14px;margin:0">Sin búsquedas en este rango.</p><?php else:
      foreach ($busquedas as $b): if (!$b['term']) continue; ?>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:14px">
          <span>"<?= clean($b['term']) ?>"</span><strong><?= number_format($b['n']) ?></strong>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

</div>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
