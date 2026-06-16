<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_movimientos');

$ready = inventarioListo();
$ubicaciones = $ready ? ubicacionesConInventario() : [];

$TIPOS = ['ingreso'=>'Ingreso','ajuste'=>'Ajuste','merma'=>'Merma','venta'=>'Venta','evento'=>'Evento','compra'=>'Compra','transferencia'=>'Transferencia'];

// rango por defecto con reloj de la BD (evita desfase de zona horaria)
$hoy = date('Y-m-d'); $defDesde = date('Y-m-d', strtotime('-29 days'));
if ($ready) { try { $clk = Database::fetch("SELECT CURDATE() hoy, DATE(NOW() - INTERVAL 29 DAY) hace29"); if ($clk) { $hoy=$clk['hoy']; $defDesde=$clk['hace29']; } } catch (Exception $e) {} }
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : $defDesde;
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : $hoy;
$ubiF  = cleanInt($_GET['ubi'] ?? 0);
$tipoF = isset($TIPOS[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';

$movs = [];
if ($ready) {
    $w = "m.created_at >= ? AND m.created_at <= ?";
    $p = [$desde.' 00:00:00', $hasta.' 23:59:59'];
    if ($ubiF)  { $w .= " AND m.ubicacion_id = ?"; $p[] = $ubiF; }
    if ($tipoF) { $w .= " AND m.tipo = ?"; $p[] = $tipoF; }
    $movs = Database::fetchAll(
        "SELECT m.*, i.nombre insumo, i.unidad, u.nombre ubicacion
         FROM inventario_movimientos m
         JOIN insumos i ON i.id = m.insumo_id
         LEFT JOIN ubicaciones u ON u.id = m.ubicacion_id
         WHERE $w ORDER BY m.created_at DESC, m.id DESC LIMIT 500",
        $p
    );
}
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = 'Movimientos';
$activePage = 'inv-movimientos';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left">
  <h1>Movimientos de inventario</h1>
  <p>Historial de entradas, salidas, ventas, eventos, mermas y ajustes</p>
</div></div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state"><h3>Falta crear el módulo de inventario</h3><p>Aplica <code>install/inventario.sql</code>.</p></div></div>
<?php else: ?>

<form method="get" class="card" style="margin-bottom:16px"><div class="card-body" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
  <div class="form-group" style="margin:0"><label>Desde</label><input type="date" name="desde" value="<?= clean($desde) ?>"></div>
  <div class="form-group" style="margin:0"><label>Hasta</label><input type="date" name="hasta" value="<?= clean($hasta) ?>"></div>
  <div class="form-group" style="margin:0"><label>Ubicación</label>
    <select name="ubi"><option value="0">Todas</option>
      <?php foreach ($ubicaciones as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $ubiF==$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group" style="margin:0"><label>Tipo</label>
    <select name="tipo"><option value="">Todos</option>
      <?php foreach ($TIPOS as $k=>$lbl): ?><option value="<?= $k ?>" <?= $tipoF===$k?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-primary">Filtrar</button>
</div></form>

<div class="card">
  <?php if (empty($movs)): ?>
    <div class="empty-state"><h3>Sin movimientos</h3><p>No hay movimientos en este rango.</p></div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th>Fecha</th><th>Ubicación</th><th>Insumo</th><th>Tipo</th><th style="text-align:right">Cantidad</th><th>Motivo</th></tr></thead>
      <tbody>
        <?php foreach ($movs as $m): $pos = $m['cantidad'] >= 0; ?>
        <tr>
          <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?= formatDatetime($m['created_at']) ?></td>
          <td style="font-size:13px"><?= clean($m['ubicacion'] ?: '—') ?></td>
          <td><strong><?= clean($m['insumo']) ?></strong></td>
          <td><span class="badge badge-secondary"><?= $TIPOS[$m['tipo']] ?? clean($m['tipo']) ?></span></td>
          <td style="text-align:right;font-weight:700;white-space:nowrap;color:<?= $pos?'#16a34a':'#dc2626' ?>"><?= $pos?'+':'' ?><?= nf($m['cantidad']) ?> <span style="color:var(--text-muted);font-weight:400;font-size:12px"><?= clean($m['unidad']) ?></span></td>
          <td style="font-size:13px;color:var(--text-secondary)"><?= clean($m['motivo'] ?: ($m['ref'] ?: '—')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($movs) >= 500): ?><div style="padding:10px 16px;font-size:12px;color:var(--text-muted)">Mostrando los 500 más recientes del rango.</div><?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
