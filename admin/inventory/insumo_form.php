<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

$id  = cleanInt($_GET['id'] ?? 0);
$ins = $id ? Database::fetch("SELECT * FROM insumos WHERE id = ?", [$id]) : null;
if ($id && !$ins) { flashMessage('error', 'Insumo no encontrado.'); redirect('/admin/inventory/insumos.php'); }

$isEdit = (bool)$ins;
$errors = [];
$data   = $ins ?? ['nombre'=>'','unidad'=>'g','costo_unitario'=>'','activo'=>1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'nombre'         => clean($_POST['nombre'] ?? ''),
        'unidad'         => clean($_POST['unidad'] ?? 'unidad') ?: 'unidad',
        'costo_unitario' => max(0, cleanFloat($_POST['costo_unitario'] ?? 0)),
        'activo'         => isset($_POST['activo']) ? 1 : 0,
    ];
    if (!$data['nombre']) $errors[] = 'El nombre es obligatorio.';

    if (empty($errors)) {
        if ($isEdit) {
            Database::execute("UPDATE insumos SET nombre=?,unidad=?,costo_unitario=?,activo=? WHERE id=?",
                [$data['nombre'],$data['unidad'],$data['costo_unitario'],$data['activo'],$id]);
            flashMessage('success', 'Insumo actualizado.');
        } else {
            Database::insert("INSERT INTO insumos (nombre,unidad,costo_unitario,activo) VALUES (?,?,?,?)",
                [$data['nombre'],$data['unidad'],$data['costo_unitario'],$data['activo']]);
            flashMessage('success', 'Insumo creado.');
        }
        redirect('/admin/inventory/insumos.php');
    }
}

$unidades = ['g'=>'Gramos (g)','kg'=>'Kilos (kg)','ml'=>'Mililitros (ml)','l'=>'Litros (l)','unidad'=>'Unidad','lonja'=>'Lonja','porcion'=>'Porción'];

$pageTitle  = $isEdit ? 'Editar insumo' : 'Nuevo insumo';
$activePage = 'inv-insumos';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/inventory/insumos.php">Insumos</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= $isEdit ? 'Editar' : 'Nuevo' ?></span>
</div>

<div class="page-header"><div class="page-header-left"><h1><?= $pageTitle ?></h1></div></div>

<?php foreach ($errors as $e): ?><div class="alert alert-error">✗ <?= $e ?></div><?php endforeach; ?>

<div class="card" style="max-width:560px"><div class="card-body">
  <form method="post">
    <?= csrfField() ?>
    <div class="form-group">
      <label class="form-required">Nombre del insumo</label>
      <input type="text" name="nombre" value="<?= clean($data['nombre']) ?>" placeholder="Ej: Carne de res, Pan brioche, Queso americano" required autofocus>
    </div>
    <div class="form-row form-row-2">
      <div class="form-group">
        <label>Unidad base</label>
        <select name="unidad">
          <?php foreach ($unidades as $val=>$lbl): ?>
            <option value="<?= $val ?>" <?= ($data['unidad']??'')===$val?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-hint">Maneja el insumo siempre en esta unidad. Si compras en otra, registra el equivalente.</div>
      </div>
      <div class="form-group">
        <label>Costo por unidad (S/)</label>
        <input type="text" inputmode="decimal" name="costo_unitario" value="<?= $data['costo_unitario']!=='' ? number_format((float)$data['costo_unitario'],4,'.','') : '' ?>" placeholder="0.0000">
        <div class="form-hint">Ej: si 1 kg de carne cuesta S/24 y manejas en g → 0.024</div>
      </div>
    </div>
    <label class="toggle-wrap" style="cursor:pointer">
      <input type="checkbox" name="activo" value="1" <?= $data['activo']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)">
      <span class="toggle-label">Activo</span>
    </label>
    <div style="display:flex;gap:12px;margin-top:18px">
      <button type="submit" class="btn btn-primary" style="gap:6px">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>Guardar
      </button>
      <a href="<?= APP_URL ?>/admin/inventory/insumos.php" class="btn btn-ghost">Cancelar</a>
    </div>
  </form>
</div></div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
