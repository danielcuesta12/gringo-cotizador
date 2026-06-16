<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('modifiers');

$id    = cleanInt($_GET['id'] ?? 0);
$grupo = $id ? Database::fetch("SELECT * FROM grupos_modificadores WHERE id = ?", [$id]) : null;
if ($id && !$grupo) { flashMessage('error', 'Grupo no encontrado.'); redirect('/admin/modifiers/index.php'); }

$isEdit  = (bool)$grupo;
$errors  = [];
$data    = $grupo ?? ['nombre'=>'','descripcion'=>'','tipo'=>'unico','max_opciones'=>'','requerido'=>0,'orden'=>0,'activo'=>1];
$opciones = $id ? Database::fetchAll("SELECT * FROM modificadores WHERE grupo_id = ? ORDER BY orden, id", [$id]) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'nombre'       => clean($_POST['nombre'] ?? ''),
        'descripcion'  => clean($_POST['descripcion'] ?? ''),
        'tipo'         => in_array($_POST['tipo'] ?? '', ['unico','multiple']) ? $_POST['tipo'] : 'unico',
        'max_opciones' => ($_POST['max_opciones'] ?? '') !== '' ? max(1, cleanInt($_POST['max_opciones'])) : null,
        'requerido'    => isset($_POST['requerido']) ? 1 : 0,
        'orden'        => cleanInt($_POST['orden'] ?? 0),
        'activo'       => isset($_POST['activo']) ? 1 : 0,
    ];
    $optNombres = $_POST['opt_nombre'] ?? [];
    $optPrecios = $_POST['opt_precio'] ?? [];
    if (!$data['nombre']) $errors[] = 'El nombre del grupo es obligatorio.';

    // reconstruir opciones para re-render si hay error
    $opciones = [];
    foreach ($optNombres as $i => $n) {
        if (trim($n) === '') continue;
        $opciones[] = ['nombre' => clean($n), 'precio_adicional' => max(0, cleanFloat($optPrecios[$i] ?? 0))];
    }
    if (empty($opciones)) $errors[] = 'Agrega al menos una opción al grupo.';

    if (empty($errors)) {
        if ($isEdit) {
            Database::execute(
                "UPDATE grupos_modificadores SET nombre=?,descripcion=?,tipo=?,max_opciones=?,requerido=?,orden=?,activo=? WHERE id=?",
                [$data['nombre'],$data['descripcion'],$data['tipo'],$data['max_opciones'],$data['requerido'],$data['orden'],$data['activo'],$id]
            );
            $gid = $id;
        } else {
            $gid = Database::insert(
                "INSERT INTO grupos_modificadores (nombre,descripcion,tipo,max_opciones,requerido,orden,activo) VALUES (?,?,?,?,?,?,?)",
                [$data['nombre'],$data['descripcion'],$data['tipo'],$data['max_opciones'],$data['requerido'],$data['orden'],$data['activo']]
            );
        }
        // Upsert de opciones (IDs estables → no rompe receta_modificadores)
        $optIds = $_POST['opt_id'] ?? [];
        $optNom = $_POST['opt_nombre'] ?? [];
        $optPre = $_POST['opt_precio'] ?? [];
        $kept = [];
        foreach ($optNom as $i => $nombre) {
            $nombre = clean($nombre);
            if ($nombre === '') continue;
            $precio = max(0, cleanFloat($optPre[$i] ?? 0));
            $oid = (int)($optIds[$i] ?? 0);
            if ($oid > 0) {
                Database::execute(
                    "UPDATE modificadores SET nombre=?, precio_adicional=?, orden=? WHERE id=? AND grupo_id=?",
                    [$nombre, $precio, $i, $oid, $gid]
                );
                $kept[] = $oid;
            } else {
                $kept[] = (int) Database::insert(
                    "INSERT INTO modificadores (grupo_id,nombre,precio_adicional,orden) VALUES (?,?,?,?)",
                    [$gid, $nombre, $precio, $i]
                );
            }
        }
        $existentes = Database::fetchAll("SELECT id FROM modificadores WHERE grupo_id=?", [$gid]);
        foreach ($existentes as $e) {
            if (!in_array((int)$e['id'], $kept, true)) {
                Database::execute("DELETE FROM receta_modificadores WHERE modificador_id=?", [(int)$e['id']]);
                Database::execute("DELETE FROM modificadores WHERE id=?", [(int)$e['id']]);
            }
        }
        flashMessage('success', 'Grupo de adicionales guardado.');
        redirect('/admin/modifiers/index.php');
    }
}

$pageTitle  = $isEdit ? 'Editar grupo de adicionales' : 'Nuevo grupo de adicionales';
$activePage = 'modifiers';
$extraHead  = '<style>
.opt-row{display:flex;gap:8px;margin-bottom:8px;align-items:center}
.opt-row input.opt-n{flex:1}
.opt-row .opt-p{width:120px;position:relative}
.opt-row .opt-p input{padding-left:30px;text-align:right}
.opt-row .opt-p .cur{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px}
.opt-row .opt-del{background:none;border:none;color:#dc2626;cursor:pointer;padding:6px;flex-shrink:0}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/modifiers/index.php">Adicionales</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= $isEdit ? 'Editar' : 'Nuevo' ?></span>
</div>

<div class="page-header"><div class="page-header-left"><h1><?= $pageTitle ?></h1></div></div>

<?php foreach ($errors as $e): ?><div class="alert alert-error">✗ <?= $e ?></div><?php endforeach; ?>

<div class="card" style="max-width:620px">
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>

      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-required">Nombre del grupo</label>
          <input type="text" name="nombre" value="<?= clean($data['nombre']) ?>" placeholder="Ej: Salsas, Extras, Tamaño" required autofocus>
        </div>
        <div class="form-group">
          <label>Descripción <small style="font-weight:400;color:var(--text-muted)">(opcional)</small></label>
          <input type="text" name="descripcion" value="<?= clean($data['descripcion'] ?? '') ?>" placeholder="Ej: Elige tu salsa favorita">
        </div>
      </div>

      <div class="form-row form-row-3">
        <div class="form-group">
          <label>Tipo de selección</label>
          <select name="tipo">
            <option value="unico"    <?= $data['tipo']==='unico'?'selected':'' ?>>Elige 1 (radio)</option>
            <option value="multiple" <?= $data['tipo']==='multiple'?'selected':'' ?>>Varios (checkbox)</option>
          </select>
        </div>
        <div class="form-group">
          <label>Máx. opciones <small style="font-weight:400;color:var(--text-muted)">(si varios)</small></label>
          <input type="number" name="max_opciones" value="<?= $data['max_opciones'] !== null && $data['max_opciones'] !== '' ? (int)$data['max_opciones'] : '' ?>" min="1" placeholder="sin tope">
        </div>
        <div class="form-group">
          <label>Opciones</label>
          <div style="padding-top:6px;display:flex;flex-direction:column;gap:8px">
            <label class="toggle-wrap" style="cursor:pointer"><input type="checkbox" name="requerido" value="1" <?= $data['requerido']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)"><span class="toggle-label">Obligatorio</span></label>
            <label class="toggle-wrap" style="cursor:pointer"><input type="checkbox" name="activo" value="1" <?= $data['activo']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)"><span class="toggle-label">Activo</span></label>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>Opciones del grupo</label>
        <div class="form-hint" style="margin-bottom:8px">Cada opción con su precio adicional (0 si no cuesta extra).</div>
        <div id="optList">
          <?php if (empty($opciones)) $opciones = [['nombre'=>'','precio_adicional'=>'']]; ?>
          <?php foreach ($opciones as $o): ?>
          <div class="opt-row">
            <input type="hidden" name="opt_id[]" value="<?= (int)($o['id'] ?? 0) ?>">
            <input type="text" name="opt_nombre[]" class="opt-n" value="<?= clean($o['nombre']) ?>" placeholder="Ej: Mayonesa de la casa">
            <div class="opt-p"><span class="cur">S/</span><input type="text" inputmode="decimal" name="opt_precio[]" value="<?= $o['precio_adicional']!=='' ? number_format((float)$o['precio_adicional'],2,'.','') : '' ?>" placeholder="0.00"></div>
            <button type="button" class="opt-del" onclick="this.closest('.opt-row').remove()" title="Quitar">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="addOpt()" style="margin-top:4px;gap:6px">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Agregar opción
        </button>
      </div>

      <div style="display:flex;gap:12px;margin-top:8px">
        <button type="submit" class="btn btn-primary" style="gap:6px">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>Guardar grupo
        </button>
        <a href="<?= APP_URL ?>/admin/modifiers/index.php" class="btn btn-ghost">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<script>
function addOpt(){
  var row = document.querySelector('#optList .opt-row');
  var clone = row.cloneNode(true);
  clone.querySelectorAll('input').forEach(function(i){ i.value=''; });
  document.getElementById('optList').appendChild(clone);
  clone.querySelector('.opt-n').focus();
}
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
