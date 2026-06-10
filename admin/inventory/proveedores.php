<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

$ready = comprasListo();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (isset($_POST['delete_id'])) {
        Database::execute("DELETE FROM proveedores WHERE id = ?", [cleanInt($_POST['delete_id'])]);
        flashMessage('success', 'Proveedor eliminado.');
    } else {
        $pid    = cleanInt($_POST['id'] ?? 0);
        $nombre = clean($_POST['nombre'] ?? '');
        $cont   = clean($_POST['contacto'] ?? '');
        $tel    = clean($_POST['telefono'] ?? '');
        $act    = isset($_POST['activo']) ? 1 : 0;
        if ($nombre) {
            if ($pid) Database::execute("UPDATE proveedores SET nombre=?,contacto=?,telefono=?,activo=? WHERE id=?", [$nombre,$cont,$tel,$act,$pid]);
            else      Database::insert("INSERT INTO proveedores (nombre,contacto,telefono,activo) VALUES (?,?,?,?)", [$nombre,$cont,$tel,$act]);
            flashMessage('success', 'Proveedor guardado.');
        }
    }
    redirect('/admin/inventory/proveedores.php');
}

$proveedores = $ready ? Database::fetchAll("SELECT * FROM proveedores ORDER BY activo DESC, nombre") : [];

$pageTitle  = 'Proveedores';
$activePage = 'inv-compras';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left"><h1>Proveedores</h1><p>Quiénes te abastecen de insumos</p></div></div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state"><h3>Falta crear el módulo de compras</h3><p>Aplica <code>install/inventario_c.sql</code> en phpMyAdmin.</p></div></div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">
  <div class="card">
    <?php if (empty($proveedores)): ?>
      <div class="empty-state"><h3>Sin proveedores</h3><p>Agrega tu primer proveedor en el formulario de la derecha.</p></div>
    <?php else: ?>
    <div class="table-wrap" style="border:none;border-radius:0"><table class="data-table">
      <thead><tr><th>Proveedor</th><th>Contacto</th><th>Teléfono</th><th style="width:90px"></th></tr></thead>
      <tbody>
        <?php foreach ($proveedores as $p): ?>
        <tr<?= $p['activo']?'':' style="opacity:.5"' ?>>
          <td><strong><?= clean($p['nombre']) ?></strong></td>
          <td><?= clean($p['contacto'] ?: '—') ?></td>
          <td><?= clean($p['telefono'] ?: '—') ?></td>
          <td><div class="td-actions">
            <button type="button" class="btn btn-ghost btn-sm" onclick='editProv(<?= json_encode($p) ?>)'>Editar</button>
            <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" data-confirm="¿Eliminar «<?= clean($p['nombre']) ?>»?"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>
            </form>
          </div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="card"><div class="card-header"><span class="card-title" id="provFormTitle">Nuevo proveedor</span></div><div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="id" id="provId" value="">
      <div class="form-group"><label class="form-required">Nombre</label><input type="text" name="nombre" id="provNombre" required></div>
      <div class="form-group"><label>Contacto</label><input type="text" name="contacto" id="provContacto" placeholder="Nombre de contacto"></div>
      <div class="form-group"><label>Teléfono</label><input type="text" name="telefono" id="provTelefono"></div>
      <label class="toggle-wrap" style="cursor:pointer"><input type="checkbox" name="activo" id="provActivo" value="1" checked style="width:18px;height:18px;accent-color:var(--brand)"><span class="toggle-label">Activo</span></label>
      <div style="display:flex;gap:10px;margin-top:16px">
        <button type="submit" class="btn btn-primary">Guardar</button>
        <button type="button" class="btn btn-ghost" onclick="resetProv()">Limpiar</button>
      </div>
    </form>
  </div></div>
</div>

<script>
function editProv(p){
  document.getElementById('provId').value = p.id;
  document.getElementById('provNombre').value = p.nombre || '';
  document.getElementById('provContacto').value = p.contacto || '';
  document.getElementById('provTelefono').value = p.telefono || '';
  document.getElementById('provActivo').checked = p.activo == 1;
  document.getElementById('provFormTitle').textContent = 'Editar proveedor';
  window.scrollTo({top:0,behavior:'smooth'});
}
function resetProv(){
  document.getElementById('provId').value = '';
  document.querySelectorAll('#provNombre,#provContacto,#provTelefono').forEach(function(i){i.value='';});
  document.getElementById('provActivo').checked = true;
  document.getElementById('provFormTitle').textContent = 'Nuevo proveedor';
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
