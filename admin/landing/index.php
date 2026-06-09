<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/landing_icons.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    Database::execute("DELETE FROM landing_links WHERE id = ?", [cleanInt($_POST['delete_id'])]);
    flashMessage('success', 'Botón eliminado.');
    redirect('/admin/landing/index.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    verifyCsrf();
    Database::execute("UPDATE landing_links SET active = NOT active WHERE id = ?", [cleanInt($_POST['toggle_id'])]);
    redirect('/admin/landing/index.php');
}

$links = Database::fetchAll("SELECT * FROM landing_links ORDER BY sort_order, id");
$styleLabel = ['primary'=>'Amarillo','wa'=>'WhatsApp','dark'=>'Oscuro','pink'=>'Rosa','neutral'=>'Neutro'];

$pageTitle  = 'Landing';
$activePage = 'landing';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Landing</h1>
    <p>Botones de tu página pública <a href="<?= APP_URL ?>/.." target="_blank" style="color:var(--ink)">elgringo.pe</a> — arrastra el orden con el campo "Orden"</p>
  </div>
  <a href="<?= APP_URL ?>/admin/landing/form.php" class="btn btn-primary" style="gap:6px">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
    Nuevo botón
  </a>
</div>

<div class="card">
  <?php if (empty($links)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></div>
      <h3>Sin botones</h3>
      <p>Crea el primer botón de tu landing</p>
      <a href="<?= APP_URL ?>/admin/landing/form.php" class="btn btn-primary">+ Nuevo botón</a>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead>
        <tr><th style="width:50px"></th><th>Botón</th><th>Enlace</th><th>Estilo</th><th>Orden</th><th>Estado</th><th style="width:150px"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($links as $l): ?>
        <tr<?= $l['active'] ? '' : ' style="opacity:.5"' ?>>
          <td><span style="display:inline-flex;color:var(--text-secondary)"><?= landingIconSvg($l['icon'], 22) ?></span></td>
          <td><strong><?= clean($l['label']) ?></strong><?php if ($l['sublabel']): ?><div style="font-size:12px;color:var(--text-muted)"><?= clean($l['sublabel']) ?></div><?php endif; ?></td>
          <td style="font-size:12px;color:var(--text-secondary);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= clean($l['url']) ?></td>
          <td><span class="badge badge-secondary"><?= $styleLabel[$l['style']] ?? $l['style'] ?></span></td>
          <td><?= (int)$l['sort_order'] ?></td>
          <td>
            <form method="post" style="display:inline">
              <?= csrfField() ?><input type="hidden" name="toggle_id" value="<?= $l['id'] ?>">
              <button type="submit" class="badge <?= $l['active'] ? 'badge-success' : 'badge-secondary' ?>" style="border:none;cursor:pointer"><?= $l['active'] ? 'Activo' : 'Oculto' ?></button>
            </form>
          </td>
          <td>
            <div class="td-actions">
              <a href="<?= APP_URL ?>/admin/landing/form.php?id=<?= $l['id'] ?>" class="btn btn-ghost btn-sm">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar
              </a>
              <form method="post" style="display:inline">
                <?= csrfField() ?><input type="hidden" name="delete_id" value="<?= $l['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" data-confirm="¿Eliminar el botón «<?= clean($l['label']) ?>»?">
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
