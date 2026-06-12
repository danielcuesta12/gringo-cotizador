<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('locations');

// Eliminar ubicación (location_products se borra en cascada por FK)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    $delId = cleanInt($_POST['delete_id']);
    Database::execute("DELETE FROM ubicaciones WHERE id = ?", [$delId]);
    flashMessage('success', 'Ubicación eliminada.');
    redirect('/admin/locations/index.php');
}

$locations = Database::fetchAll(
    "SELECT u.*,
            (SELECT COUNT(*) FROM location_products lp WHERE lp.location_id = u.id) AS item_count
     FROM ubicaciones u
     ORDER BY u.sort_order, u.nombre"
);

$modeBadge = [
    'menu'     => ['badge-secondary', 'Solo menú'],
    'whatsapp' => ['badge-success',   'WhatsApp'],
    'izipay'   => ['badge-info',      'Izipay'],
];

$pageTitle  = 'Ubicaciones';
$activePage = 'locations';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Ubicaciones</h1>
    <p>Cada ubicación tiene su carta, con sus ítems, precios y modalidad de venta</p>
  </div>
  <a href="<?= APP_URL ?>/admin/locations/form.php" class="btn btn-primary" style="gap:6px">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
    Nueva ubicación
  </a>
</div>

<div class="card">
  <?php if (empty($locations)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></div>
      <h3>Sin ubicaciones</h3>
      <p>Crea tu primera ubicación (local o food truck) para armar su carta</p>
      <a href="<?= APP_URL ?>/admin/locations/form.php" class="btn btn-primary" style="gap:6px">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        Nueva ubicación
      </a>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead>
        <tr>
          <th>Ubicación</th>
          <th>Slug (URL)</th>
          <th>Modalidad de venta</th>
          <th>Ítems</th>
          <th>Estado</th>
          <th style="width:200px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($locations as $u): ?>
        <tr>
          <td>
            <span style="display:inline-flex;align-items:center;gap:8px">
              <span style="width:14px;height:14px;border-radius:4px;background:<?= clean($u['color_header']) ?>;flex-shrink:0"></span>
              <strong><?= clean($u['nombre']) ?></strong>
              <?php if ($u['es_principal']): ?><span class="badge badge-warning" style="font-size:10px">Principal</span><?php endif; ?>
            </span>
          </td>
          <td style="font-family:monospace;font-size:13px;color:var(--text-secondary)">
            /<?= clean($u['slug']) ?>
          </td>
          <td>
            <?php $mb = $modeBadge[$u['sales_mode']] ?? ['badge-secondary', $u['sales_mode']]; ?>
            <span class="badge <?= $mb[0] ?>"><?= $mb[1] ?></span>
          </td>
          <td>
            <a href="<?= APP_URL ?>/admin/locations/items.php?id=<?= $u['id'] ?>" style="text-decoration:none;color:var(--ink);font-weight:600">
              <?= (int)$u['item_count'] ?> ítems
            </a>
          </td>
          <td>
            <?php if ($u['activa']): ?>
              <span class="badge badge-success">Activa</span>
            <?php else: ?>
              <span class="badge badge-secondary">Inactiva</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="td-actions">
              <a href="<?= APP_URL ?>/admin/locations/items.php?id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm" title="Gestionar ítems de la carta">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>Ítems
              </a>
              <a href="<?= APP_URL ?>/admin/locations/qr.php?id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm" title="QR del menú">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3M21 21v.01M17 21h.01M21 17h.01"/></svg>QR
              </a>
              <a href="<?= APP_URL ?>/admin/locations/banner.php?id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm" title="Banner imprimible 42cm">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>Banner
              </a>
              <a href="<?= APP_URL ?>/admin/locations/form.php?id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar
              </a>
              <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="delete_id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" title="Eliminar"
                        data-confirm="¿Eliminar la ubicación «<?= clean($u['nombre']) ?>» y su carta? Esta acción no se puede deshacer.">
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
