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

// Apariencia del landing (foto de fondo + transparencia de cards)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_appearance'])) {
    verifyCsrf();

    setSetting('landing_cards_transparent', isset($_POST['cards_transparent']) ? '1' : '0');

    if (!empty($_FILES['landing_bg']['name'])) {
        $uploaded = uploadImage($_FILES['landing_bg'], 'landing');
        if ($uploaded) {
            $old = getSetting('landing_bg_image');
            if ($old && file_exists(UPLOAD_PATH . $old)) @unlink(UPLOAD_PATH . $old);
            setSetting('landing_bg_image', $uploaded);
            flashMessage('success', 'Apariencia actualizada.');
        } else {
            flashMessage('error', 'Error al subir la foto. Usa JPG, PNG o WebP (máx. 2MB).');
        }
    } else {
        flashMessage('success', 'Apariencia actualizada.');
    }

    if (isset($_POST['remove_bg'])) {
        $old = getSetting('landing_bg_image');
        if ($old && file_exists(UPLOAD_PATH . $old)) @unlink(UPLOAD_PATH . $old);
        setSetting('landing_bg_image', '');
    }
    redirect('/admin/landing/index.php');
}

$bgRel         = getSetting('landing_bg_image', '');
$bgUrl         = $bgRel ? UPLOAD_URL . $bgRel : '';
$cardsTranspar = getSetting('landing_cards_transparent', '0') === '1';

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
  <div style="display:flex;gap:8px">
    <a href="<?= APP_URL ?>/admin/landing/qr.php" class="btn btn-ghost" style="gap:6px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><line x1="14" y1="14" x2="14" y2="14.01"/><line x1="21" y1="14" x2="21" y2="14.01"/><line x1="14" y1="21" x2="21" y2="21"/><line x1="17.5" y1="17.5" x2="17.5" y2="17.51"/><line x1="21" y1="17.5" x2="21" y2="17.51"/></svg>
      QR de la landing
    </a>
    <a href="<?= APP_URL ?>/admin/landing/form.php" class="btn btn-primary" style="gap:6px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      Nuevo botón
    </a>
  </div>
</div>

<div class="card" style="margin-bottom:18px">
  <h2 style="font-size:16px;margin-bottom:4px">Apariencia</h2>
  <p style="font-size:13px;color:var(--text-muted);margin-bottom:18px">Foto de fondo y estilo de las tarjetas de tu landing.</p>
  <form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="save_appearance" value="1">
    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
      <div style="flex:1;min-width:260px">
        <label class="form-label">Foto de fondo</label>
        <?php if ($bgUrl): ?>
          <div style="margin-bottom:10px">
            <img src="<?= htmlspecialchars($bgUrl) ?>" alt="Fondo actual" style="width:120px;height:200px;object-fit:cover;border-radius:10px;border:1px solid var(--border);display:block">
          </div>
          <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--text-secondary);margin-bottom:10px;cursor:pointer">
            <input type="checkbox" name="remove_bg" value="1"> Quitar foto actual
          </label>
        <?php endif; ?>
        <input type="file" name="landing_bg" accept="image/jpeg,image/png,image/webp" class="form-input">
        <p style="font-size:12px;color:var(--text-muted);margin-top:6px">JPG, PNG o WebP · máx. 2MB. Sin foto se usa el fondo amarillo.</p>
      </div>
      <div style="flex:1;min-width:260px">
        <label class="form-label">Tarjetas</label>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:12px 0">
          <input type="checkbox" name="cards_transparent" value="1" <?= $cardsTranspar ? 'checked' : '' ?>>
          <span style="font-size:14px">Tarjetas translúcidas <span style="color:var(--text-muted)">(efecto vidrio sobre la foto)</span></span>
        </label>
      </div>
    </div>
    <div style="margin-top:16px">
      <button type="submit" class="btn btn-primary">Guardar apariencia</button>
    </div>
  </form>
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
