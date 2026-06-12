<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('categories');

// Eliminar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    $id = cleanInt($_POST['delete_id']);
    // Verificar si tiene productos
    $count = Database::fetch("SELECT COUNT(*) as n FROM products WHERE category_id = ?", [$id]);
    if ((int)$count['n'] > 0) {
        flashMessage('error', 'No puedes eliminar una categoría que tiene productos asignados.');
    } else {
        Database::execute("DELETE FROM categories WHERE id = ?", [$id]);
        flashMessage('success', 'Categoría eliminada.');
    }
    redirect('/admin/categories/index.php');
}

// Cambiar orden (drag simple via POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
    verifyCsrf();
    $ids = array_map('intval', $_POST['ids'] ?? []);
    foreach ($ids as $i => $id) {
        Database::execute("UPDATE categories SET sort_order = ? WHERE id = ?", [$i, $id]);
    }
    http_response_code(200);
    exit;
}

$search = clean($_GET['q'] ?? '');
$params = [];
$where  = '';
if ($search) {
    $where    = 'WHERE name LIKE ?';
    $params[] = '%' . $search . '%';
}

$categories = Database::fetchAll(
    "SELECT c.*, COUNT(p.id) as product_count
     FROM categories c
     LEFT JOIN products p ON p.category_id = c.id AND p.active = 1
     $where
     GROUP BY c.id
     ORDER BY c.sort_order, c.name",
    $params
);

$pageTitle  = 'Categorías';
$activePage = 'categories';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Categorías</h1>
    <p>Organiza tus productos en categorías</p>
  </div>
  <?php if (isAdmin()): ?>
  <a href="<?= APP_URL ?>/admin/categories/form.php" class="btn btn-primary"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px"><path d="M12 5v14M5 12h14"/></svg>Nueva categoría</a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="toolbar">
    <div class="search-bar">
      <span class="search-icon"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>
      <form method="get">
        <input type="text" name="q" value="<?= clean($search) ?>" placeholder="Buscar categoría…" autocomplete="off">
      </form>
    </div>
    <span style="color:var(--text-muted);font-size:13px"><?= count($categories) ?> categorías</span>
  </div>

  <div id="liveResults">
  <?php if (empty($categories)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.41l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42Z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor" stroke="none"/></svg></div>
      <h3>Sin categorías</h3>
      <p>Crea tu primera categoría para organizar los productos</p>
      <a href="<?= APP_URL ?>/admin/categories/form.php" class="btn btn-primary"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px"><path d="M12 5v14M5 12h14"/></svg>Nueva categoría</a>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:40px">#</th>
          <th>Nombre</th>
          <th>Descripción</th>
          <th>Productos activos</th>
          <th>Estado</th>
          <th style="width:140px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($categories as $i => $cat): ?>
        <tr>
          <td style="color:var(--text-muted)"><?= $i+1 ?></td>
          <td><strong><?= clean($cat['name']) ?></strong></td>
          <td style="color:var(--text-muted)"><?= clean($cat['description'] ?? '—') ?></td>
          <td>
            <span class="badge badge-info"><?= (int)$cat['product_count'] ?> productos</span>
          </td>
          <td>
            <?php if ($cat['active']): ?>
              <span class="badge badge-success">Activa</span>
            <?php else: ?>
              <span class="badge badge-secondary">Inactiva</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="td-actions">
              <a href="form.php?id=<?= $cat['id'] ?>" class="btn btn-ghost btn-sm"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar</a>
              <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="delete_id" value="<?= $cat['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm"
                  data-confirm="¿Eliminar la categoría «<?= clean($cat['name']) ?>»?">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>Eliminar
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
  </div><!-- /liveResults -->
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
