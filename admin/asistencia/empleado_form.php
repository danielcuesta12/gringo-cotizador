<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('asistencia');

$id       = cleanInt($_GET['id'] ?? 0);
$existing = $id ? Database::fetch("SELECT * FROM empleados WHERE id = ?", [$id]) : null;
if ($id && !$existing) {
    flashMessage('error', 'Empleado no encontrado.');
    redirect('/admin/asistencia/empleados');
}
$isEdit = (bool) $existing;

$ubicaciones = Database::fetchAll("SELECT id, nombre FROM ubicaciones ORDER BY nombre");
$usuarios    = Database::fetchAll("SELECT id, name FROM users WHERE active = 1 ORDER BY name");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $nombre     = clean($_POST['nombre'] ?? '');
    $ubicacionId = cleanInt($_POST['ubicacion_id'] ?? 0) ?: null;
    $userId     = cleanInt($_POST['user_id'] ?? 0) ?: null;
    $cargo      = clean($_POST['cargo'] ?? '');
    $activo     = !empty($_POST['activo']) ? 1 : 0;
    $quitarPin  = !empty($_POST['quitar_pin']);
    $pin        = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    $pinHash    = ($pin !== '') ? password_hash($pin, PASSWORD_DEFAULT) : null;

    $foto = $existing['foto_referencia'] ?? null;
    if (!empty($_FILES['foto']['name'])) {
        $up = uploadImage($_FILES['foto'], 'empleados');
        if ($up) {
            $foto = $up;
        } else {
            flashMessage('error', 'No se pudo subir la foto (revisa formato/tamaño, máx 2MB).');
            redirect('/admin/asistencia/empleado_form' . ($id ? ('?id=' . $id) : ''));
        }
    }

    if ($nombre === '') {
        flashMessage('error', 'El nombre es obligatorio.');
        redirect('/admin/asistencia/empleado_form' . ($id ? ('?id=' . $id) : ''));
    }

    if ($id > 0) {
        if ($quitarPin) {
            Database::execute(
                "UPDATE empleados SET nombre=?, foto_referencia=?, ubicacion_id=?, user_id=?, pin_hash=NULL, cargo=?, activo=? WHERE id=?",
                [$nombre, $foto, $ubicacionId, $userId, $cargo, $activo, $id]
            );
        } elseif ($pinHash !== null) {
            Database::execute(
                "UPDATE empleados SET nombre=?, foto_referencia=?, ubicacion_id=?, user_id=?, pin_hash=?, cargo=?, activo=? WHERE id=?",
                [$nombre, $foto, $ubicacionId, $userId, $pinHash, $cargo, $activo, $id]
            );
        } else {
            Database::execute(
                "UPDATE empleados SET nombre=?, foto_referencia=?, ubicacion_id=?, user_id=?, cargo=?, activo=? WHERE id=?",
                [$nombre, $foto, $ubicacionId, $userId, $cargo, $activo, $id]
            );
        }
    } else {
        Database::insert(
            "INSERT INTO empleados (nombre, foto_referencia, ubicacion_id, user_id, pin_hash, cargo, activo) VALUES (?,?,?,?,?,?,?)",
            [$nombre, $foto, $ubicacionId, $userId, $pinHash, $cargo, $activo]
        );
    }

    flashMessage('success', 'Empleado guardado.');
    redirect('/admin/asistencia/empleados');
}

$data = $existing ?? [
    'nombre'          => '',
    'foto_referencia' => null,
    'ubicacion_id'    => null,
    'user_id'         => null,
    'cargo'           => '',
    'activo'          => 1,
];

$pageTitle  = $isEdit ? 'Editar empleado' : 'Nuevo empleado';
$activePage = 'asistencia';
$extraHead  = '<style>
.eform{max-width:520px}
.foto-drop{border:1.5px dashed var(--border,#ddd);border-radius:12px;padding:20px;text-align:center;color:var(--text-muted,#888);background:var(--bg-page,#fafafa);cursor:pointer}
.foto-drop svg{width:28px;height:28px;display:block;margin:0 auto 8px}
.foto-prev{margin-top:10px}
.foto-prev img{max-width:100%;max-height:180px;border-radius:10px;object-fit:cover}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left"><h1><?= $pageTitle ?></h1></div>
</div>

<div class="card eform">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>

      <div class="form-group">
        <label class="form-required">Nombre</label>
        <input type="text" name="nombre" value="<?= clean($data['nombre']) ?>" placeholder="Nombre completo" required>
      </div>

      <div class="form-group">
        <label>Foto de referencia</label>
        <?php if (!empty($data['foto_referencia'])): ?>
          <div class="foto-prev" style="margin-bottom:10px">
            <img src="<?= UPLOAD_URL . clean($data['foto_referencia']) ?>" alt="Foto actual">
            <div style="font-size:11px;color:var(--text-muted,#888);margin-top:5px">Foto actual — sube una nueva para reemplazarla</div>
          </div>
        <?php endif; ?>
        <div class="foto-drop" onclick="document.getElementById('foto-input').click()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.66-.9l.82-1.2A2 2 0 0110.07 4h3.86a2 2 0 011.66.9l.82 1.2a2 2 0 001.66.9H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3"/></svg>
          Tomar foto o subir imagen
        </div>
        <input type="file" id="foto-input" name="foto" accept="image/*" capture="environment" style="display:none" onchange="previewFoto(this)">
        <div class="foto-prev" id="foto-prev" style="display:none"></div>
      </div>

      <div class="form-group">
        <label>Local</label>
        <select name="ubicacion_id">
          <option value="">— Sin asignar —</option>
          <?php foreach ($ubicaciones as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($data['ubicacion_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= clean($u['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Vincular a usuario del sistema</label>
        <select name="user_id">
          <option value="">— Ninguno —</option>
          <?php foreach ($usuarios as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($data['user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= clean($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>PIN de marcado</label>
        <input type="text" inputmode="numeric" name="pin" maxlength="4" placeholder="4 dígitos" autocomplete="off">
        <div style="font-size:12px;color:var(--text-muted,#888);margin-top:4px">
          <?= $isEdit ? 'Déjalo vacío para no cambiar el PIN actual.' : 'Opcional; 4 dígitos.' ?>
        </div>
        <?php if ($isEdit && !empty($existing['pin_hash'])): ?>
        <div style="margin-top:8px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
            <input type="checkbox" name="quitar_pin" value="1" style="width:16px;height:16px;cursor:pointer">
            <span>Quitar PIN (este empleado podrá marcar sin PIN)</span>
          </label>
        </div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label>Cargo</label>
        <input type="text" name="cargo" value="<?= clean($data['cargo'] ?? '') ?>" placeholder="Ej. cajero, cocinero…">
      </div>

      <div class="form-group">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
          <input type="checkbox" name="activo" value="1" <?= !empty($data['activo']) ? 'checked' : '' ?> style="width:18px;height:18px;cursor:pointer">
          <span>Empleado activo</span>
        </label>
      </div>

      <div style="display:flex;gap:10px;margin-top:8px">
        <a href="<?= APP_URL ?>/admin/asistencia/empleados" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary" style="flex:1"><?= $isEdit ? 'Guardar cambios' : 'Crear empleado' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function previewFoto(inp) {
  if (!inp.files || !inp.files[0]) return;
  var prev = document.getElementById('foto-prev');
  var url = URL.createObjectURL(inp.files[0]);
  prev.innerHTML = '<img src="' + url + '" alt="preview">';
  prev.style.display = 'block';
}
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
