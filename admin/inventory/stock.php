<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_stock');

$ready = inventarioListo();
$ubicaciones = $ready ? ubicacionesConInventario() : [];
$ubiF = cleanInt($_GET['ubi'] ?? 0) ?: ($ubicaciones[0]['id'] ?? 0);

$rows = []; $valorTotal = 0; $nAlertas = 0;
if ($ready && $ubiF) {
    $rows = Database::fetchAll(
        "SELECT i.id, i.nombre, i.unidad, i.costo_unitario,
                COALESCE(s.stock,0) stock, COALESCE(s.stock_min,0) stock_min
         FROM insumos i
         LEFT JOIN insumo_stock s ON s.insumo_id = i.id AND s.ubicacion_id = ?
         WHERE i.activo = 1
         ORDER BY (COALESCE(s.stock,0) <= COALESCE(s.stock_min,0)) DESC, i.nombre",
        [$ubiF]
    );
    foreach ($rows as $r) { $valorTotal += $r['stock'] * $r['costo_unitario']; if ($r['stock'] <= $r['stock_min']) $nAlertas++; }
}
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = 'Stock';
$activePage = 'inv-stock';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left">
  <h1>Stock por ubicación</h1>
  <p>Existencias actuales, con alerta cuando bajan del mínimo</p>
</div></div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <h3>Falta crear el módulo de inventario</h3>
    <p>Aplica <code>install/inventario.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php elseif (empty($ubicaciones)): ?>
  <div class="card"><div class="empty-state"><h3>Sin ubicaciones</h3><p>Crea una ubicación primero.</p></div></div>
<?php else: ?>

<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:16px">
  <select onchange="location.href='?ubi='+this.value" style="padding:8px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;background:#fff">
    <?php foreach ($ubicaciones as $u): ?>
      <option value="<?= (int)$u['id'] ?>" <?= $ubiF==$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <div style="margin-left:auto;display:flex;gap:18px;font-size:13px">
    <span style="color:var(--text-secondary)">Valor del inventario: <strong style="color:var(--ink)"><?= formatMoney($valorTotal) ?></strong></span>
    <?php if ($nAlertas): ?><span style="color:#dc2626;font-weight:700"><?= $nAlertas ?> bajo mínimo</span><?php endif; ?>
  </div>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty-state"><h3>Sin insumos activos</h3><p>Crea insumos en la sección «Insumos».</p></div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th>Insumo</th><th>Stock</th><th>Mínimo</th><th>Costo unit.</th><th>Valor</th><th style="width:120px"></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): $low = $r['stock'] <= $r['stock_min']; ?>
        <tr<?= $low ? ' style="background:rgba(220,38,38,.05)"' : '' ?>>
          <td>
            <strong><?= clean($r['nombre']) ?></strong>
            <?php if ($low): ?><span class="badge badge-danger" style="margin-left:6px;font-size:10px">Bajo mínimo</span><?php endif; ?>
          </td>
          <td><strong style="<?= $low?'color:#dc2626':'' ?>"><?= nf($r['stock']) ?></strong> <span style="color:var(--text-muted);font-size:12px"><?= clean($r['unidad']) ?></span></td>
          <td style="color:var(--text-secondary)"><?= nf($r['stock_min']) ?></td>
          <td><?= formatMoney($r['costo_unitario']) ?></td>
          <td><?= formatMoney($r['stock'] * $r['costo_unitario']) ?></td>
          <td>
            <a href="<?= APP_URL ?>/admin/inventory/ajuste.php?insumo=<?= $r['id'] ?>&ubi=<?= $ubiF ?>" class="btn btn-ghost btn-sm">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 5v14M5 12h14"/></svg>Ingreso/ajuste
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
