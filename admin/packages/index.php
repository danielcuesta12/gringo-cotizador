<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('packages');

// Eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    $id = cleanInt($_POST['delete_id']);
    Database::execute("DELETE FROM package_products WHERE package_id=?",[$id]);
    Database::execute("DELETE FROM packages WHERE id=?",[$id]);
    flashMessage('success','Paquete eliminado.');
    redirect('/admin/packages/index.php');
}

$packages = Database::fetchAll(
    "SELECT p.*,
       COUNT(pp.id) as product_count
     FROM packages p
     LEFT JOIN package_products pp ON pp.package_id = p.id
     GROUP BY p.id
     ORDER BY p.name"
);

$pageTitle  = 'Paquetes';
$activePage = 'packages';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 style="display:flex;align-items:center;gap:9px"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg>Paquetes</h1>
    <p>Combos de productos con precio especial</p>
  </div>
  <a href="<?= APP_URL ?>/admin/packages/form.php" class="btn btn-primary"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px"><path d="M12 5v14M5 12h14"/></svg>Nuevo paquete</a>
</div>

<div class="alert alert-info" style="display:flex;gap:9px;align-items:flex-start">
  <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.2 1 2h6c0-.8.4-1.5 1-2A7 7 0 0 0 12 2Z"/></svg>
  <span>Los paquetes son combos de productos que puedes agregar en una sola línea al cotizar. Útil para menús completos o servicios agrupados.</span>
</div>

<div class="card">
  <?php if (empty($packages)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></svg></div>
      <h3>Sin paquetes</h3>
      <p>Crea combos de productos para cotizar más rápido</p>
      <a href="<?= APP_URL ?>/admin/packages/form.php" class="btn btn-primary"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px"><path d="M12 5v14M5 12h14"/></svg>Nuevo paquete</a>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead>
        <tr>
          <th>Nombre del paquete</th>
          <th>Descripción</th>
          <th>Precio</th>
          <th>Productos incluidos</th>
          <th>Estado</th>
          <th style="width:140px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($packages as $pkg): ?>
        <tr>
          <td><strong><?= clean($pkg['name']) ?></strong></td>
          <td style="color:var(--text-muted);font-size:13px"><?= clean(mb_substr($pkg['description']??'',0,60)) ?></td>
          <td><strong><?= formatMoney((float)$pkg['price']) ?></strong></td>
          <td><span class="badge badge-info"><?= $pkg['product_count'] ?> items</span></td>
          <td><span class="badge <?= $pkg['active']?'badge-success':'badge-secondary' ?>"><?= $pkg['active']?'Activo':'Inactivo' ?></span></td>
          <td>
            <div class="td-actions">
              <a href="form.php?id=<?= $pkg['id'] ?>" class="btn btn-ghost btn-sm"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar</a>
              <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="delete_id" value="<?= $pkg['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"
                        data-confirm="¿Eliminar paquete «<?= clean($pkg['name']) ?>»?">✕</button>
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

<?php include __DIR__ . '/../layout-bottom.php'; ?>
