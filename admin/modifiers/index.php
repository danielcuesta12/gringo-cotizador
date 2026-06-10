<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    Database::execute("DELETE FROM grupos_modificadores WHERE id = ?", [cleanInt($_POST['delete_id'])]);
    flashMessage('success', 'Grupo de adicionales eliminado.');
    redirect('/admin/modifiers/index.php');
}

$grupos = Database::fetchAll(
    "SELECT g.*,
            (SELECT COUNT(*) FROM modificadores m WHERE m.grupo_id = g.id) AS n_opciones,
            (SELECT COUNT(*) FROM product_modifier_groups pmg WHERE pmg.grupo_id = g.id) AS n_productos
     FROM grupos_modificadores g
     ORDER BY g.orden, g.nombre"
);

$pageTitle  = 'Adicionales';
$activePage = 'modifiers';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Adicionales</h1>
    <p>Grupos de extras/opciones para los productos (ej. Salsas, Extras, Tamaño)</p>
  </div>
  <a href="<?= APP_URL ?>/admin/modifiers/form.php" class="btn btn-primary" style="gap:6px">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
    Nuevo grupo
  </a>
</div>

<div class="card">
  <?php if (empty($grupos)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></div>
      <h3>Sin adicionales</h3>
      <p>Crea grupos como "Salsas" o "Extras" y luego asígnalos a tus productos</p>
      <a href="<?= APP_URL ?>/admin/modifiers/form.php" class="btn btn-primary">+ Nuevo grupo</a>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th>Grupo</th><th>Tipo</th><th>Obligatorio</th><th>Opciones</th><th>Productos</th><th style="width:150px"></th></tr></thead>
      <tbody>
        <?php foreach ($grupos as $g): ?>
        <tr<?= $g['activo'] ? '' : ' style="opacity:.5"' ?>>
          <td><strong><?= clean($g['nombre']) ?></strong><?php if ($g['descripcion']): ?><div style="font-size:12px;color:var(--text-muted)"><?= clean($g['descripcion']) ?></div><?php endif; ?></td>
          <td><span class="badge badge-secondary"><?= $g['tipo'] === 'multiple' ? 'Varios' : 'Elige 1' ?></span></td>
          <td><?= $g['requerido'] ? '<span class="badge badge-info">Sí</span>' : '<span style="color:var(--text-muted)">No</span>' ?></td>
          <td><?= (int)$g['n_opciones'] ?></td>
          <td><?= (int)$g['n_productos'] ?></td>
          <td>
            <div class="td-actions">
              <a href="<?= APP_URL ?>/admin/modifiers/form.php?id=<?= $g['id'] ?>" class="btn btn-ghost btn-sm">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar
              </a>
              <form method="post" style="display:inline">
                <?= csrfField() ?><input type="hidden" name="delete_id" value="<?= $g['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" data-confirm="¿Eliminar el grupo «<?= clean($g['nombre']) ?>»? Se quitará de todos los productos.">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
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

<?php include __DIR__ . '/../layout-bottom.php'; ?>
