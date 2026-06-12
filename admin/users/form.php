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

$isEdit   = (bool)$user;
$errors   = [];
$data     = $user ?? ['name'=>'','email'=>'','role'=>'asistente','active'=>1,'permissions'=>null];
$userPerms = json_decode($data['permissions'] ?? '[]', true) ?: [];

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

    // Permisos granulares: NULL para admin (acceso total), JSON para asistente
    $permsJson = ($data['role'] === 'admin') ? null : sanitizePermissions($_POST['perms'] ?? []);

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
            $sql = "UPDATE users SET name=?,email=?,role=?,active=?,permissions=?";
            $p   = [$data['name'],$data['email'],$data['role'],$data['active'],$permsJson];
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
                "INSERT INTO users (name,email,password,role,active,permissions) VALUES (?,?,?,?,?,?)",
                [$data['name'],$data['email'],
                 password_hash($pass,PASSWORD_BCRYPT,['cost'=>12]),
                 $data['role'],$data['active'],$permsJson]
            );
            flashMessage('success','Usuario creado. Ya puede iniciar sesión.');
        }
        redirect('/admin/users/index.php');
    }

    // Re-populate perms on error
    $userPerms = is_array($_POST['perms'] ?? null) ? $_POST['perms'] : [];
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
/* Plantillas rápidas */
.tpl-row{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:4px}
.tpl-btn{padding:5px 12px;border:1.5px solid var(--border);background:#fff;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;transition:border-color .15s,background .15s,color .15s;color:var(--text-secondary)}
.tpl-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-light)}
.tpl-btn.active{border-color:var(--red);background:var(--red-light);color:var(--red)}
/* Permisos */
#perms-box{margin-top:4px}
.perms-admin-note{padding:10px 14px;background:#f8f8f8;border:1.5px solid var(--border);border-radius:8px;font-size:13px;color:var(--text-secondary);display:none}
.perm-groups{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:4px}
.perm-group{border:1px solid var(--border);border-radius:8px;padding:12px 14px}
.perm-group h4{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0 0 10px}
.perm-item{display:flex;align-items:center;gap:7px;font-size:13px;color:var(--text-primary);cursor:pointer;padding:3px 0;user-select:none}
.perm-item input{width:15px;height:15px;accent-color:var(--red);flex-shrink:0;cursor:pointer}
.perm-group-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.perm-group-header h4{margin:0}
.perm-toggle-all{font-size:11px;color:var(--text-muted);cursor:pointer;background:none;border:none;padding:0;text-decoration:underline;text-underline-offset:2px}
.perm-toggle-all:hover{color:var(--red)}
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

<div class="card" style="max-width:680px">
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
        <select name="role" id="roleSelect" <?= $isSelf ? 'disabled' : '' ?>>
          <option value="admin"     <?= $data['role']==='admin'     ?'selected':'' ?>>
            Administrador · acceso total
          </option>
          <option value="asistente" <?= $data['role']==='asistente' ?'selected':'' ?>>
            Personalizado · accesos a la medida
          </option>
        </select>
        <?php if ($isSelf): ?>
          <input type="hidden" name="role" value="<?= clean($data['role']) ?>">
          <div class="form-hint" style="color:var(--yellow)"><span class="hint-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg></span>No puedes cambiar tu propio rol</div>
        <?php endif; ?>
      </div>

      <!-- Plantillas rápidas -->
      <div class="form-group">
        <label>Plantilla rápida</label>
        <div class="tpl-row" id="tplRow">
          <?php foreach (permissionTemplateLabels() as $tplKey => $tplLabel): ?>
            <button type="button" class="tpl-btn" data-tpl="<?= $tplKey ?>"><?= clean($tplLabel) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="form-hint">Elige una plantilla para preseleccionar accesos, o configura manualmente abajo</div>
      </div>

      <!-- Sección de permisos -->
      <div class="form-group" id="perms-box">
        <label>Accesos permitidos</label>
        <div class="perms-admin-note" id="permsAdminNote">
          Acceso total a todo el sistema. No se requiere selección de permisos individuales.
        </div>
        <div class="perm-groups" id="permGroups">
          <?php foreach (permissionCatalog() as $grupo => $items): ?>
            <div class="perm-group">
              <div class="perm-group-header">
                <h4><?= clean($grupo) ?></h4>
                <button type="button" class="perm-toggle-all" data-group="<?= clean($grupo) ?>">Todo</button>
              </div>
              <?php foreach ($items as $k => $label): ?>
                <label class="perm-item">
                  <input type="checkbox" name="perms[]" value="<?= $k ?>"
                         <?= in_array($k, $userPerms, true) ? 'checked' : '' ?>
                         data-group="<?= clean($grupo) ?>">
                  <?= clean($label) ?>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
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
var PERM_TEMPLATES = <?= json_encode(permissionTemplates()) ?>;
var EYE_ICON     = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
var EYE_OFF_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>';

function togglePass(id, btn) {
  var inp = document.getElementById(id);
  inp.type = inp.type === 'password' ? 'text' : 'password';
  btn.innerHTML = inp.type === 'password' ? EYE_ICON : EYE_OFF_ICON;
}

function updatePermsVisibility(role) {
  var adminNote  = document.getElementById('permsAdminNote');
  var permGroups = document.getElementById('permGroups');
  var checkboxes = document.querySelectorAll('#permGroups input[type="checkbox"]');
  if (role === 'admin') {
    adminNote.style.display  = 'block';
    permGroups.style.display = 'none';
    checkboxes.forEach(function(cb) { cb.disabled = true; });
  } else {
    adminNote.style.display  = 'none';
    permGroups.style.display = 'grid';
    checkboxes.forEach(function(cb) { cb.disabled = false; });
  }
}

function applyTemplate(tpl) {
  var roleSelect = document.getElementById('roleSelect');
  if (!roleSelect) return;

  if (tpl === 'admin') {
    roleSelect.value = 'admin';
    updatePermsVisibility('admin');
  } else {
    roleSelect.value = 'asistente';
    updatePermsVisibility('asistente');
    var keys = PERM_TEMPLATES[tpl] || [];
    document.querySelectorAll('#permGroups input[type="checkbox"]').forEach(function(cb) {
      cb.checked = keys.indexOf(cb.value) !== -1;
    });
  }

  // Update active template button highlight
  document.querySelectorAll('.tpl-btn').forEach(function(btn) {
    btn.classList.toggle('active', btn.dataset.tpl === tpl);
  });
}

// Role select change handler
var roleSelectEl = document.getElementById('roleSelect');
if (roleSelectEl) {
  roleSelectEl.addEventListener('change', function() {
    updatePermsVisibility(this.value);
    // Clear active template when role changes manually
    document.querySelectorAll('.tpl-btn').forEach(function(btn) { btn.classList.remove('active'); });
  });
}

// Template buttons
document.querySelectorAll('.tpl-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    applyTemplate(this.dataset.tpl);
  });
});

// Per-group "Todo" toggle
document.querySelectorAll('.perm-toggle-all').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var group = this.dataset.group;
    var cbs   = document.querySelectorAll('#permGroups input[data-group="' + group + '"]');
    var allChecked = Array.prototype.every.call(cbs, function(cb) { return cb.checked; });
    cbs.forEach(function(cb) { cb.checked = !allChecked; });
  });
});

// Initial state on page load
(function() {
  var roleSelectEl = document.getElementById('roleSelect');
  var currentRole = roleSelectEl ? roleSelectEl.value : 'asistente';
  updatePermsVisibility(currentRole);
})();
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
