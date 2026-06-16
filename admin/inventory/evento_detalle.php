<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
requirePermission('inv_evento');
$id = cleanInt($_GET['id'] ?? 0);
$ev = $id ? Database::fetch(
  "SELECT e.*, u.nombre AS ubi_nombre, q.quote_number FROM eventos e
     LEFT JOIN ubicaciones u ON u.id=e.ubicacion_id LEFT JOIN quotes q ON q.id=e.quote_id WHERE e.id=?",
  [$id]
) : null;
if (!$ev) { flashMessage('error','Evento no encontrado.'); redirect('/admin/inventory/eventos.php'); }
$insumos = Database::fetchAll(
  "SELECT ei.*, i.nombre, i.unidad, i.tipo FROM evento_insumos ei JOIN insumos i ON i.id=ei.insumo_id WHERE ei.evento_id=? ORDER BY i.tipo, i.nombre",
  [$id]
);
$costoTotal = 0; foreach ($insumos as $r) { $costoTotal += (float)$r['cantidad_inicial'] * (float)$r['costo_unitario']; }
$pageTitle = 'Evento · ' . $ev['nombre']; $activePage = 'inv-eventos';
include __DIR__ . '/../layout-top.php';
?>
<div class="breadcrumb"><a href="<?= APP_URL ?>/admin/inventory/eventos.php">Eventos</a><span class="breadcrumb-sep">›</span><span class="breadcrumb-current"><?= clean($ev['nombre']) ?></span></div>
<div class="page-header"><div class="page-header-left"><h1><?= clean($ev['nombre']) ?></h1>
  <p><?= clean($ev['fecha_inicio']) ?><?= $ev['fecha_fin'] ? ' → ' . clean($ev['fecha_fin']) : '' ?>
     <?= $ev['ubi_nombre'] ? ' · ' . clean($ev['ubi_nombre']) : '' ?>
     <?= $ev['quote_number'] ? ' · ' . clean($ev['quote_number']) : '' ?>
     · <span class="badge <?= $ev['estado']==='abierto'?'badge-success':'badge-secondary' ?>"><?= $ev['estado']==='abierto'?'Abierto':'Cerrado' ?></span></p></div></div>
<div class="card"><div class="card-header"><span class="card-title">Inventario inicial (apertura)</span></div>
<div class="card-body" style="padding:0">
  <?php if (!$insumos): ?>
    <div class="empty-state" style="padding:30px;text-align:center"><p>Este evento no tiene inventario inicial cargado.</p></div>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse;font-size:14px">
    <thead><tr><th style="text-align:left;padding:9px 12px">Insumo</th><th style="padding:9px 12px">Tipo</th><th style="text-align:right;padding:9px 12px">Cantidad</th><th style="text-align:right;padding:9px 12px">Costo</th></tr></thead>
    <tbody>
    <?php foreach ($insumos as $r): $sub = (float)$r['cantidad_inicial'] * (float)$r['costo_unitario']; ?>
      <tr style="border-top:1px solid var(--border)">
        <td style="padding:9px 12px;font-weight:600"><?= clean($r['nombre']) ?></td>
        <td style="padding:9px 12px;text-align:center"><span class="badge <?= ($r['tipo']??'ingrediente')==='descartable'?'badge-secondary':'badge-info' ?>"><?= ($r['tipo']??'ingrediente')==='descartable'?'Descartable':'Ingrediente' ?></span></td>
        <td style="padding:9px 12px;text-align:right"><?= rtrim(rtrim(number_format((float)$r['cantidad_inicial'],3,'.',''),'0'),'.') ?> <?= clean($r['unidad']) ?></td>
        <td style="padding:9px 12px;text-align:right"><?= formatMoney($sub) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr style="border-top:2px solid var(--border);font-weight:800"><td colspan="3" style="padding:10px 12px;text-align:right">Costo de mercadería inicial</td><td style="padding:10px 12px;text-align:right"><?= formatMoney($costoTotal) ?></td></tr></tfoot>
  </table>
  <?php endif; ?>
</div></div>
<div class="card"><div class="card-body" style="color:var(--text-muted);font-size:13px">El control diario (consumo, conteo) y la liquidación se agregan en los siguientes pasos.</div></div>
<?php include __DIR__ . '/../layout-bottom.php'; ?>
