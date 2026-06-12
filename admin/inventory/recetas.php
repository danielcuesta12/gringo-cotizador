<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_recetas');

$ready = inventarioListo();
$prods = [];
$ubiPrincipal = null;
if ($ready) {
    $ubiPrincipal = Database::fetch("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, nombre LIMIT 1");
    $pid = $ubiPrincipal['id'] ?? 0;
    $prods = Database::fetchAll(
        "SELECT p.id, p.name,
                (SELECT COUNT(*) FROM recetas r WHERE r.product_id = p.id) AS n_insumos,
                (SELECT COALESCE(SUM(r.cantidad*i.costo_unitario),0) FROM recetas r JOIN insumos i ON i.id=r.insumo_id WHERE r.product_id=p.id) AS costo,
                (SELECT lp.price FROM location_products lp WHERE lp.product_id=p.id AND lp.location_id=? LIMIT 1) AS precio
         FROM products p WHERE p.active=1 ORDER BY p.name",
        [$pid]
    );
}

$pageTitle  = 'Recetas y costos';
$activePage = 'inv-recetas';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left">
  <h1>Recetas y costos</h1>
  <p>Ficha técnica de cada producto y su costo de insumos<?= $ubiPrincipal ? ' · margen vs. precio en '.clean($ubiPrincipal['nombre']) : '' ?></p>
</div></div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <h3>Falta crear el módulo de inventario</h3>
    <p>Aplica <code>install/inventario.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php else: ?>

<div class="card">
  <?php if (empty($prods)): ?>
    <div class="empty-state"><h3>Sin productos activos</h3><p>Crea productos en Catálogo → Productos.</p></div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th>Producto</th><th>Insumos</th><th>Costo</th><th>Precio</th><th>Margen</th><th>Food cost</th><th style="width:120px"></th></tr></thead>
      <tbody>
        <?php foreach ($prods as $p):
          $costo = (float)$p['costo']; $precio = $p['precio'] !== null ? (float)$p['precio'] : null;
          $margen = ($precio && $precio > 0) ? ($precio - $costo) : null;
          $margenPct = ($precio && $precio > 0) ? round(($precio - $costo) * 100 / $precio) : null;
          $foodPct = ($precio && $precio > 0) ? round($costo * 100 / $precio) : null;
          $fcColor = $foodPct === null ? '' : ($foodPct <= 35 ? '#16a34a' : ($foodPct <= 45 ? '#ca8a04' : '#dc2626'));
        ?>
        <tr<?= $p['n_insumos']==0 ? ' style="opacity:.6"' : '' ?>>
          <td><strong><?= clean($p['name']) ?></strong></td>
          <td><?= $p['n_insumos']==0 ? '<span style="color:var(--text-muted)">Sin receta</span>' : (int)$p['n_insumos'].' insumo'.($p['n_insumos']==1?'':'s') ?></td>
          <td><?= formatMoney($costo) ?></td>
          <td><?= $precio !== null ? formatMoney($precio) : '<span style="color:var(--text-muted)">—</span>' ?></td>
          <td><?= $margen !== null ? formatMoney($margen).' <span style="color:var(--text-muted);font-size:12px">('.$margenPct.'%)</span>' : '—' ?></td>
          <td><?= $foodPct !== null ? '<strong style="color:'.$fcColor.'">'.$foodPct.'%</strong>' : '—' ?></td>
          <td>
            <a href="<?= APP_URL ?>/admin/inventory/receta_form.php?product_id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar receta
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:12px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-muted)">
    Food cost: <span style="color:#16a34a;font-weight:700">≤35% bueno</span> · <span style="color:#ca8a04;font-weight:700">36–45% cuidado</span> · <span style="color:#dc2626;font-weight:700">&gt;45% revisar</span>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
