<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
requireAdmin();

$id     = cleanInt($_GET['id'] ?? 0);
$isSelf = ($id === (int)$_SESSION['user_id']);
$user   = $id ? Database::fetch("SELECT * FROM users WHERE id=?",[$id]) : null;
if ($id && !$user) { flashMessage('error','Usuario no encontrado.'); redirect('/admin/users/index.php'); }

$isEdit = (bool)$user;
$errors = [];
$data   = $user ?? ['name'=>'','email'=>'','role'=>'asistente','active'=>1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'name'   => clean($_POST['name']  ?? ''),
        'email'  => clean($_POST['email'] ?? ''),
        'role'   => in_array($_POST['role']??'',['admin','asistente']) ? $_POST['role'] : 'asistente',
        'active' => isset($_POST['active']) ? 1 : 0,
    ];
    $pass    = $_POST['password']         ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (!$data['name'])  $errors[] = 'El nombre es obligatorio.';
    if (!$data['email'] || !filter_var($data['email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'El email no es válido.';

    // Verificar email duplicado
    $dup = Database::fetch("SELECT id FROM users WHERE email=? AND id!=?",[$data['email'],$id]);
    if ($dup) $errors[] = 'Ya existe un usuario con ese email.';

    if (!$isEdit && !$pass) $errors[] = 'La contraseña es obligatoria para nuevos usuarios.';
    if ($pass) {
        if (strlen($pass) < 8) $errors[] = 'La contraseña debe tener mínimo 8 caracteres.';
        if ($pass !== $confirm) $errors[] = 'Las contraseñas no coinciden.';
    }

    if (empty($errors)) {
        if ($isEdit) {
            $sql = "UPDATE users SET name=?,email=?,role=?,active=?";
            $p   = [$data['name'],$data['email'],$data['role'],$data['active']];
            if ($pass) { $sql .= ',password=?'; $p[] = password_hash($pass, PASSWORD_BCRYPT, ['cost'=>12]); }
            $sql .= ' WHERE id=?'; $p[] = $id;
            Database::execute($sql,$p);

            // Actualizar sesión si se edita a sí mismo
            if ($isSelf) {
                $_SESSION['user_name']  = $data['name'];
                $_SESSION['user_email'] = $data['email'];
            }
            flashMessage('success','Usuario actualizado.');
        } else {
            Database::insert(
                "INSERT INTO users (name,email,password,role,active) VALUES (?,?,?,?,?)",
                [$data['name'],$data['email'],
                 password_hash($pass,PASSWORD_BCRYPT,['cost'=>12]),
                 $data['role'],$data['active']]
            );
            flashMessage('success','Usuario creado. Ya puede iniciar sesión.');
        }
        redirect('/admin/users/index.php');
    }
}

$pageTitle  = $isEdit ? 'Editar usuario' : 'Nuevo usuario';
$activePage = 'users';
$extraHead = '<style>
.btn .btn-ico{display:inline-flex;vertical-align:-2px;margin-right:5px}
.btn .btn-ico svg{width:15px;height:15px}
.hint-ico{display:inline-flex;vertical-align:-2px;margin-right:4px}
.hint-ico svg{width:14px;height:14px}
.pass-toggle{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-muted);display:inline-flex;padding:0}
.pass-toggle svg{width:18px;height:18px}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/users/index.php">Usuarios</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= $isEdit ? 'Editar' : 'Nuevo' ?></span>
</div>
<div class="page-header">
  <div class="page-header-left"><h1><?= $pageTitle ?></h1></div>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-error">✗ <?= $e ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:560px">
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>

      <div class="form-group">
        <label class="form-required">Nombre completo</label>
        <input type="text" name="name" value="<?= clean($data['name']) ?>"
               placeholder="Nombre del usuario" required autofocus>
      </div>

      <div class="form-group">
        <label class="form-required">Email</label>
        <input type="email" name="email" value="<?= clean($data['email']) ?>"
               placeholder="usuario@elgringo.pe" required>
        <div class="form-hint">Este email se usa para iniciar sesión</div>
      </div>

      <div class="form-group">
        <label>Rol</label>
        <select name="role" <?= $isSelf ? 'disabled' : '' ?>>
          <option value="admin"     <?= $data['role']==='admin'     ?'selected':'' ?>>
            Administrador — acceso total
          </option>
          <option value="asistente" <?= $data['role']==='asistente' ?'selected':'' ?>>
            Asistente — solo cotizaciones y clientes
          </option>
        </select>
        <?php if ($isSelf): ?>
          <input type="hidden" name="role" value="<?= clean($data['role']) ?>">
          <div class="form-hint" style="color:var(--yellow)"><span class="hint-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg></span>No puedes cambiar tu propio rol</div>
        <?php endif; ?>
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

      <div class="form-group">
        <label><?= $isEdit ? 'Nueva contraseña' : 'Contraseña' ?> <small style="font-weight:400;color:var(--text-muted)"><?= $isEdit ? '(dejar vacío para no cambiar)' : '' ?></small></label>
        <div style="position:relative">
          <input type="password" name="password" id="passInput"
                 placeholder="Mínimo 8 caracteres"
                 <?= !$isEdit ? 'required' : '' ?>
                 autocomplete="new-password">
          <button type="button" onclick="togglePass('passInput',this)" class="pass-toggle" aria-label="Mostrar u ocultar contraseña">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>

      <div class="form-group">
        <label>Confirmar contraseña</label>
        <input type="password" name="password_confirm"
               placeholder="Repetir contraseña"
               autocomplete="new-password">
      </div>

      <label class="toggle-wrap" style="cursor:pointer;margin-bottom:24px">
        <input type="checkbox" name="active" value="1"
               <?= $data['active'] ? 'checked' : '' ?>
               <?= $isSelf ? 'disabled' : '' ?>
               style="width:18px;height:18px;accent-color:var(--red)">
        <span class="toggle-label">Usuario activo</span>
      </label>
      <?php if ($isSelf): ?>
        <input type="hidden" name="active" value="1">
      <?php endif; ?>

      <div style="display:flex;gap:12px">
        <button type="submit" class="btn btn-primary">
          <?php if ($isEdit): ?>
            <span class="btn-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg></span>Guardar cambios
          <?php else: ?>
            <span class="btn-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></span>Crear usuario
          <?php endif; ?>
        </button>
        <a href="<?= APP_URL ?>/admin/users/index.php" class="btn btn-ghost">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<script>
var EYE_ICON     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
var EYE_OFF_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>';
function togglePass(id, btn) {
  const inp = document.getElementById(id);
  inp.type  = inp.type === 'password' ? 'text' : 'password';
  btn.innerHTML = inp.type === 'password' ? EYE_ICON : EYE_OFF_ICON;
}
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
