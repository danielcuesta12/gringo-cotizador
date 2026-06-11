<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

// Crear carta nueva (POST) → redirige al editor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nueva'])) {
    verifyCsrf();
    $nombre = clean($_POST['nombre'] ?? '') ?: 'Carta sin nombre';
    $id = Database::insert("INSERT INTO cartas (nombre) VALUES (?)", [$nombre]);
    redirect('/admin/cartas/editor.php?id=' . (int)$id);
}
// Eliminar carta (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    Database::execute("DELETE FROM cartas WHERE id = ?", [cleanInt($_POST['delete_id'])]);
    flashMessage('success', 'Carta eliminada.');
    redirect('/admin/cartas/index.php');
}

$cartas = Database::fetchAll(
    "SELECT c.*, (SELECT COUNT(*) FROM carta_items i WHERE i.carta_id = c.id) AS item_count
     FROM cartas c ORDER BY c.updated_at DESC, c.id DESC");

$pageTitle  = 'Generador de cartas PDF';
$activePage = 'cartas-pdf';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Generador de cartas PDF</h1>
    <p>Arma cartas a medida (desde una ubicación o libres) y genera el banner imprimible</p>
  </div>
  <form method="post" style="margin:0">
    <?= csrfField() ?>
    <input type="hidden" name="nueva" value="1">
    <button type="submit" class="btn btn-primary" style="gap:6px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      Nueva carta
    </button>
  </form>
</div>

<div class="card">
  <?php if (empty($cartas)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg></div>
      <h3>Sin cartas</h3>
      <p>Crea tu primera carta para el generador</p>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th>Carta</th><th>Tema</th><th>Ítems</th><th>Actualizada</th><th style="width:170px"></th></tr></thead>
      <tbody>
        <?php foreach ($cartas as $c): ?>
        <tr>
          <td><strong><?= clean($c['nombre']) ?></strong></td>
          <td><span class="badge badge-secondary"><?= $c['tema'] === 'dia' ? 'Crema' : 'Nocturna' ?></span></td>
          <td><?= (int)$c['item_count'] ?></td>
          <td style="font-size:12px;color:var(--text-secondary)"><?= formatDate($c['updated_at']) ?></td>
          <td>
            <div class="td-actions">
              <a href="<?= APP_URL ?>/admin/cartas/editor.php?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar
              </a>
              <form method="post" style="display:inline">
                <?= csrfField() ?><input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" data-confirm="¿Eliminar la carta «<?= clean($c['nombre']) ?>»? Esta acción no se puede deshacer.">
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
