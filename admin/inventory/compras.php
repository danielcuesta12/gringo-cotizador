<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_compras');

$ready = comprasListo();
$compras = [];
if ($ready) {
    $compras = Database::fetchAll(
        "SELECT c.*, p.nombre proveedor, u.nombre ubicacion,
                (SELECT COUNT(*) FROM compra_items ci WHERE ci.compra_id = c.id) n_items
         FROM compras c
         LEFT JOIN proveedores p ON p.id = c.proveedor_id
         LEFT JOIN ubicaciones u ON u.id = c.ubicacion_id
         ORDER BY c.fecha DESC, c.id DESC LIMIT 200"
    );
}

$pageTitle  = 'Compras';
$activePage = 'inv-compras';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left"><h1>Compras</h1><p>Entradas de insumos al inventario</p></div>
  <?php if ($ready): ?>
  <div style="display:flex;gap:8px">
    <a href="<?= APP_URL ?>/admin/inventory/proveedores.php" class="btn btn-ghost" style="gap:6px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg>Proveedores
    </a>
    <a href="<?= APP_URL ?>/admin/inventory/compra_form.php" class="btn btn-primary" style="gap:6px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Nueva compra
    </a>
  </div>
  <?php endif; ?>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state"><h3>Falta crear el módulo de compras</h3><p>Aplica <code>install/inventario_c.sql</code> en phpMyAdmin.</p></div></div>
<?php else: ?>

<div class="card">
  <?php if (empty($compras)): ?>
    <div class="empty-state">
      <h3>Sin compras</h3><p>Registra tu primera compra para sumar stock y recalcular costos.</p>
      <a href="<?= APP_URL ?>/admin/inventory/compra_form.php" class="btn btn-primary">+ Nueva compra</a>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0"><table class="data-table">
    <thead><tr><th>Fecha</th><th>Proveedor</th><th>Ubicación</th><th>Insumos</th><th style="text-align:right">Total</th></tr></thead>
    <tbody>
      <?php foreach ($compras as $c): ?>
      <tr>
        <td style="white-space:nowrap"><?= formatDate($c['fecha']) ?></td>
        <td><strong><?= clean($c['proveedor'] ?: '—') ?></strong><?php if ($c['nota']): ?><div style="font-size:11px;color:var(--text-muted)"><?= clean($c['nota']) ?></div><?php endif; ?></td>
        <td style="font-size:13px"><?= clean($c['ubicacion'] ?: '—') ?></td>
        <td><?= (int)$c['n_items'] ?> insumo<?= $c['n_items']==1?'':'s' ?></td>
        <td style="text-align:right;font-weight:700"><?= formatMoney($c['total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
