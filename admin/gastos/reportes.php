<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/gastos.php';

requirePermission('gastos');

// Fix C — graceful degradation guard
if (!gastosListo()) {
    $pageTitle  = 'Reportes de gastos';
    $activePage = 'gastos_rep';
    include __DIR__ . '/../layout-top.php';
    ?>
    <div class="page-header"><div class="page-header-left"><h1>Reportes de gastos</h1></div></div>
    <div class="card"><div class="card-body">
      <p>El módulo de gastos v2 necesita su migración. Aplica <code>install/55_gastos_v2.sql</code> en phpMyAdmin y recarga.</p>
    </div></div>
    <?php
    include __DIR__ . '/../layout-bottom.php';
    return;
}

$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-t');
$fTipo   = in_array($_GET['tipo'] ?? '', ['empresa','prestamo'], true) ? $_GET['tipo'] : '';
$fOrigen = in_array($_GET['origen'] ?? '', ['manual','pos','evento'], true) ? $_GET['origen'] : '';
$fUbi    = cleanInt($_GET['ubicacion_id'] ?? 0);

$where = ["g.fecha BETWEEN ? AND ?"]; $params = [$desde, $hasta];
if ($fTipo)   { $where[] = "g.tipo = ?";        $params[] = $fTipo; }
if ($fOrigen) { $where[] = "g.origen = ?";      $params[] = $fOrigen; }
if ($fUbi)    { $where[] = "g.ubicacion_id = ?"; $params[] = $fUbi; }
$wsql = 'WHERE ' . implode(' AND ', $where);

// Export CSV — must run BEFORE any layout include
if (($_GET['export'] ?? '') === 'csv') {
    $rows = Database::fetchAll(
        "SELECT g.fecha, g.tipo, g.origen, c.nombre AS categoria, s.nombre AS subcategoria, gi.concepto, gi.monto
         FROM gasto_items gi JOIN gastos g ON g.id = gi.gasto_id
         LEFT JOIN gasto_categorias c ON c.id = gi.categoria_id
         LEFT JOIN gasto_subcategorias s ON s.id = gi.subcategoria_id
         $wsql ORDER BY g.fecha, g.id", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gastos-' . $desde . '_' . $hasta . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fecha','Tipo','Origen','Categoría','Subcategoría','Concepto','Monto']);
    foreach ($rows as $r) fputcsv($out, [$r['fecha'],$r['tipo'],$r['origen'],$r['categoria'],$r['subcategoria'],$r['concepto'],$r['monto']]);
    fclose($out); exit;
}

$total = (float)(Database::fetch("SELECT COALESCE(SUM(gi.monto),0) t FROM gasto_items gi JOIN gastos g ON g.id=gi.gasto_id $wsql", $params)['t'] ?? 0);

// Fix A — Comparativa vs periodo anterior (mismo rango, desplazado 1 mes atrás)
$prevParams    = $params;
$prevParams[0] = date('Y-m-d', strtotime($desde . ' -1 month'));
$prevParams[1] = date('Y-m-d', strtotime($hasta . ' -1 month'));
$totalPrev = (float)(Database::fetch("SELECT COALESCE(SUM(gi.monto),0) t FROM gasto_items gi JOIN gastos g ON g.id=gi.gasto_id $wsql", $prevParams)['t'] ?? 0);
$deltaPct  = $totalPrev > 0 ? round(($total - $totalPrev) / $totalPrev * 100) : null;

$porCat = Database::fetchAll(
    "SELECT COALESCE(c.nombre,'(sin categoría)') categoria, COALESCE(gi.categoria_id,0) cid,
            COUNT(*) n, COALESCE(SUM(gi.monto),0) monto
     FROM gasto_items gi JOIN gastos g ON g.id=gi.gasto_id
     LEFT JOIN gasto_categorias c ON c.id=gi.categoria_id
     $wsql GROUP BY gi.categoria_id, c.nombre ORDER BY monto DESC", $params);

$porSub = [];
foreach (Database::fetchAll(
    "SELECT COALESCE(gi.categoria_id,0) cid, COALESCE(s.nombre,'(sin subcategoría)') subcategoria,
            COUNT(*) n, COALESCE(SUM(gi.monto),0) monto
     FROM gasto_items gi JOIN gastos g ON g.id=gi.gasto_id
     LEFT JOIN gasto_subcategorias s ON s.id=gi.subcategoria_id
     $wsql GROUP BY gi.categoria_id, gi.subcategoria_id, s.nombre ORDER BY monto DESC", $params) as $r) {
    $porSub[(int)$r['cid']][] = $r;
}

// Fix B — Top subcategorías global
$topSub = Database::fetchAll(
    "SELECT COALESCE(s.nombre,'(sin subcategoría)') subcategoria, COALESCE(c.nombre,'(sin categoría)') categoria, COALESCE(SUM(gi.monto),0) monto
     FROM gasto_items gi JOIN gastos g ON g.id=gi.gasto_id
     LEFT JOIN gasto_subcategorias s ON s.id=gi.subcategoria_id
     LEFT JOIN gasto_categorias c ON c.id=gi.categoria_id
     $wsql GROUP BY gi.subcategoria_id, s.nombre, c.nombre ORDER BY monto DESC LIMIT 8", $params);

$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones ORDER BY es_principal DESC, nombre");
$expQs = http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>$fTipo,'origen'=>$fOrigen,'ubicacion_id'=>$fUbi?:'','export'=>'csv']);

$pageTitle = 'Reportes de gastos';
$activePage = 'gastos_rep';
$extraHead = '<style>
.rep-f{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end}
.rep-f .fg{display:flex;flex-direction:column;gap:3px}
.rep-f label{font-size:11px;font-weight:800;text-transform:uppercase;color:#888}
.rep-f input,.rep-f select{padding:8px 10px;border:1.5px solid var(--border,#ddd);border-radius:8px;font-size:13px}
.rep-tot{background:#1E1E1E;color:var(--c-brand,#FFDF00);border-radius:14px;padding:16px;font-weight:900;font-size:22px;margin-bottom:16px}
.rep-cat{background:#fff;border:1px solid var(--border,#eee);border-radius:14px;margin-bottom:10px;overflow:hidden}
.rep-cat-h{display:flex;justify-content:space-between;align-items:center;padding:14px;cursor:pointer}
.rep-cat-h .nm{font-weight:800;font-size:15px}
.rep-bar{height:6px;background:var(--bg-page,#f1f1f4);border-radius:6px;margin-top:6px;overflow:hidden}
.rep-bar i{display:block;height:100%;background:var(--c-brand,#FFDF00)}
.rep-subs{display:none;border-top:1px solid var(--border,#eee);background:var(--bg-page,#fafafa)}
.rep-subs.on{display:block}
.rep-sub{display:flex;justify-content:space-between;padding:9px 16px;font-size:13px;border-bottom:1px dashed var(--border,#eee)}
</style>';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header"><div class="page-header-left"><h1>Reportes de gastos</h1></div>
  <div class="page-header-right"><a class="btn btn-secondary" href="<?= APP_URL ?>/admin/gastos/reportes.php?<?= clean($expQs) ?>">Exportar CSV</a></div>
</div>

<form method="get" class="rep-f">
  <div class="fg"><label>Desde</label><input type="date" name="desde" value="<?= clean($desde) ?>"></div>
  <div class="fg"><label>Hasta</label><input type="date" name="hasta" value="<?= clean($hasta) ?>"></div>
  <div class="fg"><label>Tipo</label><select name="tipo"><option value="">Todos</option><option value="empresa" <?= $fTipo==='empresa'?'selected':'' ?>>Empresa</option><option value="prestamo" <?= $fTipo==='prestamo'?'selected':'' ?>>Préstamo</option></select></div>
  <div class="fg"><label>Origen</label><select name="origen"><option value="">Todos</option><option value="manual" <?= $fOrigen==='manual'?'selected':'' ?>>Manual</option><option value="pos" <?= $fOrigen==='pos'?'selected':'' ?>>POS</option><option value="evento" <?= $fOrigen==='evento'?'selected':'' ?>>Evento</option></select></div>
  <div class="fg"><label>Tienda</label><select name="ubicacion_id"><option value="">Todas</option><?php foreach ($ubis as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $fUbi===(int)$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-primary" type="submit">Aplicar</button>
</form>

<div class="rep-tot">
  Total: <?= formatMoney($total) ?>
  <div style="font-size:13px;font-weight:600;opacity:.85;margin-top:4px">Periodo anterior: <?= formatMoney($totalPrev) ?><?php if ($deltaPct !== null): ?> · <span style="color:<?= $deltaPct<=0?'#bbf7d0':'#fecaca' ?>"><?= $deltaPct>0?'+':'' ?><?= $deltaPct ?>%</span><?php endif; ?></div>
</div>

<?php if (!$porCat): ?>
  <p style="color:#888;text-align:center;padding:30px">Sin gastos en el periodo.</p>
<?php else: foreach ($porCat as $c): $cid=(int)$c['cid']; $pct = $total>0 ? round($c['monto']/$total*100) : 0; ?>
<div class="rep-cat">
  <div class="rep-cat-h" onclick="this.parentNode.querySelector('.rep-subs').classList.toggle('on')">
    <div style="flex:1">
      <div class="nm"><?= clean($c['categoria']) ?> <span style="color:#999;font-weight:600;font-size:12px">· <?= (int)$c['n'] ?></span></div>
      <div class="rep-bar"><i style="width:<?= $pct ?>%"></i></div>
    </div>
    <div style="text-align:right;margin-left:12px"><div style="font-weight:900"><?= formatMoney((float)$c['monto']) ?></div><div style="font-size:11px;color:#999"><?= $pct ?>%</div></div>
  </div>
  <div class="rep-subs">
    <?php foreach (($porSub[$cid] ?? []) as $s): ?>
    <div class="rep-sub"><span><?= clean($s['subcategoria']) ?> <span style="color:#aaa">· <?= (int)$s['n'] ?></span></span><b><?= formatMoney((float)$s['monto']) ?></b></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; endif; ?>

<?php if ($topSub): ?>
<div class="rep-cat" style="margin-top:16px">
  <div class="rep-cat-h" style="cursor:default">
    <div class="nm">Top subcategorías</div>
  </div>
  <div class="rep-subs on">
    <?php foreach ($topSub as $ts): ?>
    <div class="rep-sub">
      <span><?= clean($ts['subcategoria']) ?> <span style="color:#aaa">· <?= clean($ts['categoria']) ?></span></span>
      <b><?= formatMoney((float)$ts['monto']) ?></b>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
