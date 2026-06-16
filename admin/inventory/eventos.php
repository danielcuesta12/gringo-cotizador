<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
requirePermission('inv_evento');
$eventos = Database::fetchAll(
  "SELECT e.*, u.nombre AS ubi_nombre, q.quote_number,
          (SELECT COUNT(*) FROM evento_insumos ei WHERE ei.evento_id=e.id) AS n_insumos
     FROM eventos e
     LEFT JOIN ubicaciones u ON u.id=e.ubicacion_id
     LEFT JOIN quotes q ON q.id=e.quote_id
    ORDER BY e.estado='abierto' DESC, e.fecha_inicio DESC"
);
$pageTitle = 'Eventos'; $activePage = 'inv-eventos';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header"><div class="page-header-left"><h1>Eventos</h1>
  <p>Inventario y liquidación por evento del food truck</p></div></div>
<div class="card"><div class="card-body" style="padding:0">
  <?php if (!$eventos): ?>
    <div class="empty-state" style="padding:40px;text-align:center"><h3>Sin eventos</h3><p>Crea uno desde «Salida a evento» asignando la salida a un evento.</p></div>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse;font-size:14px">
    <thead><tr>
      <th style="text-align:left;padding:10px">Evento</th><th style="text-align:left;padding:10px">Fechas</th>
      <th style="text-align:left;padding:10px">Local</th><th style="text-align:left;padding:10px">Cotización</th>
      <th style="text-align:center;padding:10px">Insumos</th><th style="text-align:center;padding:10px">Estado</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($eventos as $e): ?>
      <tr style="border-top:1px solid var(--border)">
        <td style="padding:10px;font-weight:700"><?= clean($e['nombre']) ?></td>
        <td style="padding:10px"><?= clean($e['fecha_inicio']) ?><?= $e['fecha_fin'] ? ' → ' . clean($e['fecha_fin']) : '' ?></td>
        <td style="padding:10px"><?= $e['ubi_nombre'] ? clean($e['ubi_nombre']) : '—' ?></td>
        <td style="padding:10px"><?= $e['quote_number'] ? clean($e['quote_number']) : '—' ?></td>
        <td style="padding:10px;text-align:center"><?= (int)$e['n_insumos'] ?></td>
        <td style="padding:10px;text-align:center"><span class="badge <?= $e['estado']==='abierto'?'badge-success':'badge-secondary' ?>"><?= $e['estado']==='abierto'?'Abierto':'Cerrado' ?></span></td>
        <td style="padding:10px;text-align:right"><a href="<?= APP_URL ?>/admin/inventory/evento_detalle.php?id=<?= (int)$e['id'] ?>" class="btn btn-secondary" style="padding:6px 14px;font-size:12px">Ver</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div></div>
<?php include __DIR__ . '/../layout-bottom.php'; ?>
