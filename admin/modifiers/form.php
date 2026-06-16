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
.opt-ins{background:#eef0ff;border:none;color:#3a40a0;font-size:12px;font-weight:800;border-radius:8px;padding:6px 10px;cursor:pointer;flex-shrink:0}
.rec-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;background:#fafafb;border:1px solid #eee;border-radius:10px;padding:8px 10px}
.rec-nm{flex:1;font-weight:700}.rec-q{width:80px;text-align:right;padding:7px;border:1.5px solid #e7e7ec;border-radius:8px}.rec-u{width:42px;font-size:12px;color:#888}
.rec-del{background:none;border:none;color:#dc2626;cursor:pointer}.rec-opt{padding:10px 13px;cursor:pointer;display:flex;justify-content:space-between}.rec-opt:hover{background:#fffbe9}.rec-create{color:#1f9d55;font-weight:800;border-top:1px dashed #eee}
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
            <?php if (!empty($o['id'])): ?>
            <button type="button" class="opt-ins" onclick="modIns(<?= (int)$o['id'] ?>)" title="Insumos que consume">🧪 Insumos</button>
            <?php endif; ?>
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
  // Remove opt-ins button from cloned row (new option has no id yet)
  var insBtn = clone.querySelector('.opt-ins');
  if(insBtn) insBtn.remove();
  document.getElementById('optList').appendChild(clone);
  clone.querySelector('.opt-n').focus();
}
</script>

<div id="mi-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.5);z-index:60;align-items:center;justify-content:center;padding:18px">
  <div style="width:420px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden;max-height:90vh;display:flex;flex-direction:column">
    <div style="background:#fafafb;padding:13px 16px;border-bottom:1px solid var(--border,#eee);font-weight:800">Insumos del adicional</div>
    <div style="padding:16px;overflow-y:auto">
      <div id="mi-rows"></div>
      <div style="position:relative;margin-top:8px">
        <input type="text" id="mi-add" autocomplete="off" placeholder="🔍 Agregar insumo (busca o crea)…" oninput="miBuscar(this.value)" onfocus="miBuscar(this.value)" style="width:100%;padding:11px 13px;border:1.5px dashed #c9c9d2;border-radius:10px">
        <div id="mi-drop" style="display:none;position:absolute;left:0;right:0;top:48px;background:#fff;border:1px solid #eee;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:70;overflow:hidden"></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;padding:14px 16px;border-top:1px solid var(--border,#eee)">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="document.getElementById('mi-ov').style.display='none'">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="miGuardar()">Guardar insumos</button>
    </div>
  </div>
</div>
<div id="ins-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.55);z-index:80;align-items:center;justify-content:center;padding:18px">
  <div style="width:340px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden">
    <div style="background:#fafafb;padding:13px 16px;border-bottom:1px solid #eee;font-weight:800">Crear insumo: «<span id="ins-name"></span>»</div>
    <div style="padding:16px">
      <div class="form-group"><label>Unidad</label><select id="ins-unidad"><option value="unidad">unidad</option><option value="g">g</option><option value="ml">ml</option><option value="kg">kg</option><option value="l">l</option><option value="lonja">lonja</option><option value="porcion">porción</option></select></div>
      <div class="form-group"><label>Tipo</label><select id="ins-tipo"><option value="ingrediente">Ingrediente</option><option value="descartable">Descartable / papelería</option></select></div>
      <div class="form-group"><label>Costo (opcional)</label><input id="ins-costo" inputmode="decimal" placeholder="0.00"></div>
    </div>
    <div style="display:flex;gap:8px;padding:0 16px 16px">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="document.getElementById('ins-ov').style.display='none'">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="insCrear()">Crear y agregar</button>
    </div>
  </div>
</div>

<script>
const INS_API = '<?= APP_URL ?>/api/insumos.php';
const CSRF = '<?= csrfToken() ?>';
let miMid = 0, insPend = '';
function modIns(mid){
  miMid = mid;
  document.getElementById('mi-rows').innerHTML=''; document.getElementById('mi-add').value='';
  fetch(INS_API+'?action=receta_mod_get&modificador_id='+mid).then(r=>r.json()).then(d=>{ (d.items||[]).forEach(i=>miAgregar(i.insumo_id,i.nombre,i.unidad,i.cantidad)); });
  document.getElementById('mi-ov').style.display='flex';
}
function miBuscar(q){
  q=(q||'').trim(); const drop=document.getElementById('mi-drop');
  if(!q){ drop.style.display='none'; return; }
  fetch(INS_API+'?action=buscar&q='+encodeURIComponent(q)).then(r=>r.json()).then(d=>{
    let html=(d.items||[]).map(i=>`<div class="rec-opt" onclick="miAgregar(${i.id},'${i.nombre.replace(/'/g,"\\'")}','${i.unidad}',1)"><span>${i.nombre}</span><span class="rec-u">${i.unidad}</span></div>`).join('');
    const exacto=(d.items||[]).some(i=>i.nombre.toLowerCase()===q.toLowerCase());
    if(!exacto) html+=`<div class="rec-opt rec-create" onclick="insAbrir('${q.replace(/'/g,"\\'")}')">+ Crear «${q}»</div>`;
    drop.innerHTML=html; drop.style.display='block';
  });
}
function miAgregar(id,nombre,unidad,cant){
  if(document.querySelector('#mi-rows input[data-iid="'+id+'"]')){ document.getElementById('mi-drop').style.display='none'; document.getElementById('mi-add').value=''; return; }
  const row=document.createElement('div'); row.className='rec-row';
  row.innerHTML='<span class="rec-nm">'+nombre+'</span><input type="hidden" data-iid="'+id+'"><input type="text" inputmode="decimal" class="rec-q mi-q" value="'+(cant||1)+'"><span class="rec-u">'+unidad+'</span><button type="button" class="rec-del" onclick="this.closest(\'.rec-row\').remove()">✕</button>';
  document.getElementById('mi-rows').appendChild(row);
  document.getElementById('mi-add').value=''; document.getElementById('mi-drop').style.display='none';
}
function miGuardar(){
  const body=new URLSearchParams(); body.append('action','receta_mod_save'); body.append('modificador_id',miMid);
  document.querySelectorAll('#mi-rows .rec-row').forEach(row=>{ body.append('insumo_id[]',row.querySelector('input[data-iid]').dataset.iid); body.append('cantidad[]',row.querySelector('.mi-q').value||'0'); });
  fetch(INS_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body}).then(r=>r.json()).then(d=>{ if(d.ok){ document.getElementById('mi-ov').style.display='none'; } else alert(d.error||'Error'); });
}
function insAbrir(nombre){ insPend=nombre; document.getElementById('ins-name').textContent=nombre; document.getElementById('mi-drop').style.display='none'; document.getElementById('ins-ov').style.display='flex'; }
function insCrear(){
  const body=new URLSearchParams({action:'crear',nombre:insPend,unidad:document.getElementById('ins-unidad').value,tipo:document.getElementById('ins-tipo').value,costo_unitario:document.getElementById('ins-costo').value||'0'});
  fetch(INS_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body}).then(r=>r.json()).then(d=>{ if(d.ok){ miAgregar(d.insumo.id,d.insumo.nombre,d.insumo.unidad,1); document.getElementById('ins-costo').value=''; document.getElementById('ins-ov').style.display='none'; } else alert(d.error||'Error'); });
}
document.addEventListener('click',e=>{ if(!e.target.closest('#mi-add') && !e.target.closest('#mi-drop')){ const d=document.getElementById('mi-drop'); if(d) d.style.display='none'; } });
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
