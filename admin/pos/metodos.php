<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nuevo'])) {
    verifyCsrf();
    $nombre = clean($_POST['nombre'] ?? '');
    $tipo = in_array($_POST['tipo'] ?? 'otros', ['efectivo','tarjeta','qr','otros'], true) ? $_POST['tipo'] : 'otros';
    if ($nombre !== '') {
        $ord = (int) (Database::fetch("SELECT COALESCE(MAX(orden),0)+1 AS n FROM pos_metodos_pago")['n'] ?? 1);
        Database::insert("INSERT INTO pos_metodos_pago (nombre,tipo,orden) VALUES (?,?,?)", [$nombre, $tipo, $ord]);
        flashMessage('success', 'Método agregado.');
    }
    redirect('/admin/pos/metodos.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    verifyCsrf();
    Database::execute("UPDATE pos_metodos_pago SET activo = NOT activo WHERE id = ?", [cleanInt($_POST['toggle_id'])]);
    redirect('/admin/pos/metodos.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    Database::execute("DELETE FROM pos_metodos_pago WHERE id = ?", [cleanInt($_POST['delete_id'])]);
    flashMessage('success', 'Método eliminado.');
    redirect('/admin/pos/metodos.php');
}

$metodos   = Database::fetchAll("SELECT * FROM pos_metodos_pago ORDER BY orden, id");
$tipoLabel = ['efectivo'=>'Efectivo','tarjeta'=>'Tarjeta','qr'=>'QR / Yape','otros'=>'Otros'];

$pageTitle  = 'POS · Métodos de pago';
$activePage = 'pos-metodos';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1>Métodos de pago (POS)</h1><p>Los que aparecen al cobrar en el terminal</p></div>
</div>

<div class="card" style="margin-bottom:18px;padding:18px 20px">
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <?= csrfField() ?><input type="hidden" name="nuevo" value="1">
    <div><label class="form-label">Nombre</label><br>
      <input type="text" name="nombre" required placeholder="Ej: Plin" style="padding:9px 11px;border:1px solid var(--border);border-radius:8px;min-width:180px;font-size:14px"></div>
    <div><label class="form-label">Tipo</label><br>
      <select name="tipo" style="padding:9px 11px;border:1px solid var(--border);border-radius:8px;font-size:14px">
        <option value="efectivo">Efectivo</option><option value="tarjeta">Tarjeta</option><option value="qr">QR / Yape</option><option value="otros" selected>Otros</option>
      </select></div>
    <button type="submit" class="btn btn-primary">Agregar</button>
  </form>
</div>

<div class="card">
  <?php if (empty($metodos)): ?>
    <div class="empty-state"><h3>Sin métodos</h3><p>Agrega tu primer método de pago</p></div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th>Método</th><th>Tipo</th><th>Estado</th><th style="width:120px"></th></tr></thead>
      <tbody>
        <?php foreach ($metodos as $m): ?>
        <tr<?= $m['activo'] ? '' : ' style="opacity:.5"' ?>>
          <td><strong><?= clean($m['nombre']) ?></strong></td>
          <td><span class="badge badge-secondary"><?= $tipoLabel[$m['tipo']] ?? $m['tipo'] ?></span></td>
          <td>
            <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="toggle_id" value="<?= $m['id'] ?>">
              <button type="submit" class="badge <?= $m['activo'] ? 'badge-success' : 'badge-secondary' ?>" style="border:none;cursor:pointer"><?= $m['activo'] ? 'Activo' : 'Inactivo' ?></button>
            </form>
          </td>
          <td><div class="td-actions">
            <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="delete_id" value="<?= $m['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm="¿Eliminar «<?= clean($m['nombre']) ?>»?">Eliminar</button>
            </form>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
