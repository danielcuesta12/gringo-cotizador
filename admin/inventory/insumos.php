<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_insumos');

$ready = inventarioListo();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    Database::execute("DELETE FROM insumos WHERE id = ?", [cleanInt($_POST['delete_id'])]);
    flashMessage('success', 'Insumo eliminado.');
    redirect('/admin/inventory/insumos.php');
}

$insumos = [];
if ($ready) {
    $nRecetasSub = recetaComponentesListo()
        ? "(SELECT COUNT(*) FROM receta_componentes rc WHERE rc.tipo='insumo' AND rc.ref_id = i.id)"
        : "(SELECT COUNT(*) FROM recetas r WHERE r.insumo_id = i.id)";
    $insumos = Database::fetchAll(
        "SELECT i.*,
                (SELECT COALESCE(SUM(s.stock),0) FROM insumo_stock s WHERE s.insumo_id = i.id) AS stock_total,
                $nRecetasSub AS n_recetas
         FROM insumos i ORDER BY i.activo DESC, i.nombre"
    );
}

$pageTitle  = 'Insumos';
$activePage = 'inv-insumos';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Insumos</h1>
    <p>Catálogo de ingredientes y materiales, con su unidad y costo</p>
  </div>
  <?php if ($ready): ?>
  <a href="<?= APP_URL ?>/admin/inventory/insumo_form.php" class="btn btn-primary" style="gap:6px">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
    Nuevo insumo
  </a>
  <?php endif; ?>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg></div>
    <h3>Falta crear el módulo de inventario</h3>
    <p>Aplica <code>install/inventario.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php else: ?>

<div class="card">
  <?php if (empty($insumos)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/></svg></div>
      <h3>Sin insumos</h3>
      <p>Crea tus ingredientes (pan, carne, queso, papas…) para armar recetas y controlar stock</p>
      <a href="<?= APP_URL ?>/admin/inventory/insumo_form.php" class="btn btn-primary">+ Nuevo insumo</a>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th>Insumo</th><th>Tipo</th><th>Unidad</th><th>Costo unit.</th><th>Stock total</th><th>En recetas</th><th style="width:150px"></th></tr></thead>
      <tbody>
        <?php foreach ($insumos as $i): ?>
        <tr<?= $i['activo'] ? '' : ' style="opacity:.5"' ?>>
          <td><strong><?= clean($i['nombre']) ?></strong></td>
          <td><?php
            $tipoBadge = ($i['tipo'] ?? 'ingrediente') === 'descartable'
              ? '<span class="badge" style="background:var(--text-muted);color:#fff;font-size:11px;padding:2px 7px;border-radius:10px">Descartable</span>'
              : '<span class="badge" style="background:var(--brand);color:#1e1e1e;font-size:11px;padding:2px 7px;border-radius:10px">Ingrediente</span>';
            echo $tipoBadge;
          ?></td>
          <td><?= clean($i['unidad']) ?></td>
          <td><?= formatMoney($i['costo_unitario']) ?></td>
          <td><?= rtrim(rtrim(number_format($i['stock_total'], 3, '.', ''), '0'), '.') ?> <span style="color:var(--text-muted);font-size:12px"><?= clean($i['unidad']) ?></span></td>
          <td><?= (int)$i['n_recetas'] ?> producto<?= $i['n_recetas']==1?'':'s' ?></td>
          <td>
            <div class="td-actions">
              <a href="<?= APP_URL ?>/admin/inventory/insumo_form.php?id=<?= $i['id'] ?>" class="btn btn-ghost btn-sm">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar
              </a>
              <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="delete_id" value="<?= $i['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" data-confirm="¿Eliminar «<?= clean($i['nombre']) ?>»? Se quitará de las recetas y del stock.">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                </button>
              </form>
            </div>
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
