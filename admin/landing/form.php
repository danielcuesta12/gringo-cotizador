<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/landing_icons.php';

requirePermission('landing');

$id   = cleanInt($_GET['id'] ?? 0);
$link = $id ? Database::fetch("SELECT * FROM landing_links WHERE id = ?", [$id]) : null;
if ($id && !$link) { flashMessage('error', 'Botón no encontrado.'); redirect('/admin/landing/index.php'); }

$isEdit = (bool)$link;
$errors = [];
$data   = $link ?? ['label'=>'','sublabel'=>'','url'=>'','tipo'=>'link','icon'=>'link','style'=>'neutral','new_tab'=>1,'active'=>1,'sort_order'=>0];
if (!isset($data['tipo'])) $data['tipo'] = 'link';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $iconKeys = array_keys(landingIcons());
    $tipo = in_array($_POST['tipo'] ?? '', ['link','cotizacion','reserva'], true) ? $_POST['tipo'] : 'link';
    $url  = trim($_POST['url'] ?? '');
    // Los tipos embebidos fuerzan su propia URL (form embebido).
    if ($tipo === 'cotizacion') $url = 'solicitud.php';
    elseif ($tipo === 'reserva') $url = 'reserva.php';
    $data = [
        'label'      => clean($_POST['label'] ?? ''),
        'sublabel'   => clean($_POST['sublabel'] ?? ''),
        'url'        => $url,
        'tipo'       => $tipo,
        'icon'       => in_array($_POST['icon'] ?? '', $iconKeys) ? $_POST['icon'] : 'link',
        'style'      => in_array($_POST['style'] ?? '', ['primary','wa','dark','pink','neutral']) ? $_POST['style'] : 'neutral',
        'new_tab'    => isset($_POST['new_tab']) ? 1 : 0,
        'active'     => isset($_POST['active']) ? 1 : 0,
        'sort_order' => cleanInt($_POST['sort_order'] ?? 0),
    ];
    if (!$data['label']) $errors[] = 'El texto del botón es obligatorio.';
    if (!$data['url'])   $errors[] = 'El enlace es obligatorio.';

    if (empty($errors)) {
        if ($isEdit) {
            Database::execute(
                "UPDATE landing_links SET label=?,sublabel=?,url=?,tipo=?,icon=?,style=?,new_tab=?,active=?,sort_order=? WHERE id=?",
                [$data['label'],$data['sublabel'],$data['url'],$data['tipo'],$data['icon'],$data['style'],$data['new_tab'],$data['active'],$data['sort_order'],$id]
            );
            flashMessage('success', 'Botón actualizado.');
        } else {
            Database::insert(
                "INSERT INTO landing_links (label,sublabel,url,tipo,icon,style,new_tab,active,sort_order) VALUES (?,?,?,?,?,?,?,?,?)",
                [$data['label'],$data['sublabel'],$data['url'],$data['tipo'],$data['icon'],$data['style'],$data['new_tab'],$data['active'],$data['sort_order']]
            );
            flashMessage('success', 'Botón creado.');
        }
        redirect('/admin/landing/index.php');
    }
}

$pageTitle  = $isEdit ? 'Editar botón' : 'Nuevo botón';
$activePage = 'landing';
$extraHead  = '<style>
.icon-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(56px,1fr));gap:8px}
.icon-grid input{position:absolute;opacity:0;pointer-events:none}
.icon-grid label{display:flex;align-items:center;justify-content:center;height:48px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;color:var(--text-secondary);transition:all .12s}
.icon-grid label:hover{border-color:#d8d5cc}
.icon-grid input:checked + label{border-color:var(--brand);background:var(--brand-soft);color:var(--ink)}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/landing/index.php">Landing</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= $isEdit ? 'Editar' : 'Nuevo' ?></span>
</div>

<div class="page-header"><div class="page-header-left"><h1><?= $pageTitle ?></h1></div></div>

<?php foreach ($errors as $e): ?><div class="alert alert-error">✗ <?= $e ?></div><?php endforeach; ?>

<div class="card" style="max-width:600px">
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>

      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-required">Texto del botón</label>
          <input type="text" name="label" value="<?= clean($data['label']) ?>" placeholder="Ej: Pedir delivery" required autofocus>
        </div>
        <div class="form-group">
          <label>Subtítulo <small style="font-weight:400;color:var(--text-muted)">(opcional)</small></label>
          <input type="text" name="sublabel" value="<?= clean($data['sublabel']) ?>" placeholder="Ej: PedidosYa · llega a tu casa">
        </div>
      </div>

      <div class="form-group">
        <label class="form-required">Tipo de botón</label>
        <select name="tipo" id="tipoSelect" onchange="syncTipo()">
          <option value="link"       <?= $data['tipo']==='link'?'selected':'' ?>>Enlace normal</option>
          <option value="cotizacion" <?= $data['tipo']==='cotizacion'?'selected':'' ?>>Formulario de cotización (embebido)</option>
          <option value="reserva"    <?= $data['tipo']==='reserva'?'selected':'' ?>>Formulario de reserva (embebido)</option>
        </select>
        <div class="form-hint">Los formularios embebidos se abren como panel desplegable dentro de la landing (no abren otra página).</div>
      </div>

      <div class="form-group" id="urlGroup">
        <label class="form-required">Enlace (URL)</label>
        <input type="text" name="url" id="urlInput" value="<?= clean($data['url']) ?>" placeholder="https://...">
        <div class="form-hint">A dónde lleva el botón (WhatsApp, PedidosYa, /principal/menu, /cotizador/solicitud, Instagram…)</div>
      </div>

      <div class="form-group">
        <label>Icono</label>
        <div class="icon-grid">
          <?php foreach (array_keys(landingIcons()) as $key): ?>
          <div style="position:relative">
            <input type="radio" name="icon" id="ic_<?= $key ?>" value="<?= $key ?>" <?= $data['icon']===$key?'checked':'' ?>>
            <label for="ic_<?= $key ?>" title="<?= $key ?>"><?= landingIconSvg($key, 20) ?></label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-row form-row-3">
        <div class="form-group">
          <label>Estilo / color</label>
          <select name="style">
            <option value="primary" <?= $data['style']==='primary'?'selected':'' ?>>Amarillo (destacado)</option>
            <option value="wa"      <?= $data['style']==='wa'?'selected':'' ?>>Verde (WhatsApp)</option>
            <option value="pink"    <?= $data['style']==='pink'?'selected':'' ?>>Rosa (evento)</option>
            <option value="dark"    <?= $data['style']==='dark'?'selected':'' ?>>Oscuro</option>
            <option value="neutral" <?= $data['style']==='neutral'?'selected':'' ?>>Neutro</option>
          </select>
        </div>
        <div class="form-group">
          <label>Orden</label>
          <input type="number" name="sort_order" value="<?= (int)$data['sort_order'] ?>" min="0" step="1">
        </div>
        <div class="form-group">
          <label>Opciones</label>
          <div style="padding-top:6px;display:flex;flex-direction:column;gap:8px">
            <label class="toggle-wrap" style="cursor:pointer"><input type="checkbox" name="new_tab" value="1" <?= $data['new_tab']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)"><span class="toggle-label">Abrir en pestaña nueva</span></label>
            <label class="toggle-wrap" style="cursor:pointer"><input type="checkbox" name="active" value="1" <?= $data['active']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)"><span class="toggle-label">Activo (visible)</span></label>
          </div>
        </div>
      </div>

      <div style="display:flex;gap:12px;margin-top:8px">
        <button type="submit" class="btn btn-primary" style="gap:6px">
          <?php if ($isEdit): ?><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>Guardar<?php else: ?><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Crear botón<?php endif; ?>
        </button>
        <a href="<?= APP_URL ?>/admin/landing/index.php" class="btn btn-ghost">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<script>
function syncTipo(){
  var tipo  = document.getElementById('tipoSelect').value;
  var group = document.getElementById('urlGroup');
  var input = document.getElementById('urlInput');
  if (tipo === 'cotizacion' || tipo === 'reserva'){
    input.value = (tipo === 'cotizacion') ? 'solicitud.php' : 'reserva.php';
    input.readOnly = true;
    group.style.display = 'none';   // la URL se autocompleta al guardar
  } else {
    input.readOnly = false;
    group.style.display = '';
  }
}
syncTipo();
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
