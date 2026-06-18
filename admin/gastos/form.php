<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/gastos.php';

requirePermission('gastos');

$admin = isAdmin();
$uid   = (int) (currentUser()['id'] ?? 0);
$id    = cleanInt($_GET['id'] ?? 0);
$g     = $id ? Database::fetch("SELECT * FROM gastos WHERE id = ?", [$id]) : null;
if ($id && !$g) { flashMessage('error', 'Gasto no encontrado.'); redirect('/admin/gastos/index.php'); }
if ($g && !$admin && ((int)$g['usuario_id'] !== $uid || $g['tipo'] !== 'prestamo')) {
    flashMessage('error', 'No tienes acceso a ese gasto.');
    redirect('/admin/gastos/index.php');
}
$isEdit = (bool) $g;
$ubis   = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, sort_order, nombre");
$invOk  = function_exists('inventarioListo') && inventarioListo();

/** Normaliza tags a slugs por coma. */
function normalizeTags(string $raw): string {
    $out = [];
    foreach (preg_split('/[,\s]+/', $raw) as $t) {
        $t = ltrim(trim($t), '#'); $t = strtolower($t);
        $t = preg_replace('/[^a-z0-9áéíóúñ]+/u', '-', $t); $t = trim($t, '-');
        if ($t !== '' && !in_array($t, $out, true)) $out[] = $t;
    }
    return implode(',', array_slice($out, 0, 12));
}

$data  = $g ?? ['tipo' => $admin ? 'empresa' : 'prestamo', 'concepto' => '', 'ubicacion_id' => null,
                'proveedor_id' => null, 'fecha' => date('Y-m-d'), 'tags' => '', 'foto' => null, 'nota' => ''];
$items = $isEdit ? gastoItems($id) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $tipo = $admin ? (in_array($_POST['tipo'] ?? '', ['empresa','prestamo'], true) ? $_POST['tipo'] : 'empresa') : 'prestamo';

    // Foto
    $foto = $data['foto'] ?? null;
    if (!empty($_FILES['foto']['name'])) { $up = uploadImage($_FILES['foto'], 'gastos'); if ($up) $foto = $up; }

    // Líneas (arrays paralelos)
    $L = [];
    $lc = $_POST['l_concepto']   ?? [];
    $lm = $_POST['l_monto']      ?? [];
    $lk = $_POST['categoria_id'] ?? [];
    $ls = $_POST['subcategoria_id'] ?? [];
    $li = $_POST['insumo_id']    ?? [];
    $lq = $_POST['l_cantidad']   ?? [];
    $n  = max(count($lm), count($lc));
    for ($i = 0; $i < $n; $i++) {
        $L[] = [
            'concepto'        => clean($lc[$i] ?? ''),
            'monto'           => cleanFloat($lm[$i] ?? 0),
            'categoria_id'    => cleanInt($lk[$i] ?? 0) ?: null,
            'subcategoria_id' => cleanInt($ls[$i] ?? 0) ?: null,
            'insumo_id'       => cleanInt($li[$i] ?? 0) ?: null,
            'cantidad'        => ($lq[$i] ?? '') !== '' ? cleanFloat($lq[$i]) : null,
        ];
    }

    $header = [
        'tipo' => $tipo, 'concepto' => clean($_POST['concepto'] ?? ''),
        'ubicacion_id' => cleanInt($_POST['ubicacion_id'] ?? 0) ?: null,
        'proveedor_id' => cleanInt($_POST['proveedor_id'] ?? 0) ?: null,
        'fecha' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d'),
        'tags' => normalizeTags($_POST['tags'] ?? ''), 'foto' => $foto, 'nota' => clean($_POST['nota'] ?? ''),
        'usuario_id' => $isEdit ? (int)$g['usuario_id'] : $uid,
    ];
    if ($isEdit) $header['estado'] = $g['estado'];

    $totalLineas = array_sum(array_map(fn($x) => (float)$x['monto'], $L));
    if ($totalLineas <= 0) {
        flashMessage('error', 'Agrega al menos una línea con monto mayor a 0.');
    } else {
        gastoGuardar($header, $L, $isEdit ? $id : null);
        flashMessage('success', $isEdit ? 'Gasto actualizado.' : 'Gasto registrado.');
        redirect('/admin/gastos/index.php');
    }
}

$pageTitle  = $isEdit ? 'Editar gasto' : 'Nuevo gasto';
$activePage = 'gastos';
$csrf       = csrfToken();
$extraHead  = '<style>
.gform{max-width:600px}
.seg{display:flex;background:var(--bg-page,#f1f1f4);border-radius:12px;padding:4px;margin-bottom:18px}
.seg label{flex:1;text-align:center;padding:11px;border-radius:9px;font-size:14px;font-weight:800;color:var(--text-muted,#888);cursor:pointer}
.seg input{position:absolute;opacity:0;pointer-events:none}
.seg input:checked + label{background:#fff;color:#1E1E1E;box-shadow:0 1px 4px rgba(0,0,0,.12)}
.seg input.prest:checked + label{background:#FFBBC8;color:#1E1E1E}
.gline{border:1.5px solid var(--border,#e3e3e3);border-radius:14px;padding:12px;margin-bottom:10px;background:#fff}
.gline-row{display:flex;gap:8px;margin-bottom:8px}
.gline-row > *{flex:1}
.gline-row .mini{flex:0 0 110px}
.gline-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.gline-head b{font-size:12px;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:1px}
.gline-del{border:none;background:none;color:#e23744;font-weight:800;cursor:pointer;font-size:13px}
.inv-toggle{font-size:12px;font-weight:700;color:#1E1E1E;background:#fffbe6;border:1px dashed var(--c-brand,#FFDF00);border-radius:9px;padding:7px 11px;cursor:pointer;display:inline-block}
.inv-box{display:none;gap:8px;margin-top:8px}
.inv-box.on{display:flex}
.gline-total{text-align:right;font-size:12px;color:var(--text-muted,#888)}
.g-addline{width:100%;border:1.5px dashed var(--border,#ccc);background:var(--bg-page,#fafafa);border-radius:11px;padding:12px;font-weight:800;color:#1E1E1E;cursor:pointer;margin-bottom:14px}
.g-grandtotal{display:flex;justify-content:space-between;align-items:center;background:#1E1E1E;color:var(--c-brand,#FFDF00);border-radius:12px;padding:14px 16px;font-weight:900;font-size:18px;margin-bottom:16px}
.tags-box{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1.5px solid var(--border,#ddd);border-radius:10px;padding:8px;background:#fff}
.tagchip{background:#1E1E1E;color:var(--c-brand,#FFDF00);font-size:12px;font-weight:800;padding:4px 9px;border-radius:7px;display:inline-flex;gap:5px;align-items:center}
.tagchip b{cursor:pointer;opacity:.7}
#tag-input{flex:1;min-width:100px;border:none;outline:none;font-size:14px;padding:4px;background:transparent}
.foto-btn{flex:1;min-width:130px;display:flex;flex-direction:column;align-items:center;gap:6px;border:1.5px dashed var(--border,#ddd);border-radius:12px;padding:16px 12px;background:var(--bg-page,#fafafa);color:var(--text-muted,#666);font-size:13px;font-weight:600;cursor:pointer}
.foto-btn svg{width:26px;height:26px}
.foto-prev{margin-top:10px}.foto-prev img{max-width:100%;border-radius:10px}
</style>';
include __DIR__ . '/../layout-top.php';

/** Render de un combobox EGCombo. */
function egcombo(string $name, string $search, string $create, string $csrf, string $ph, $valId = '', string $valTxt = '', string $dep = '', string $depKey = ''): string {
    $h  = '<div class="egc" data-egc data-search="' . $search . '" data-create="' . $create . '" data-csrf="' . clean($csrf) . '"';
    $h .= ' data-dep="' . clean($dep) . '" data-dep-create-key="' . clean($depKey) . '">';
    $h .= '<input type="text" class="egc-input" placeholder="' . clean($ph) . '" autocomplete="off" value="' . clean($valTxt) . '">';
    $h .= '<input type="hidden" class="egc-id" name="' . clean($name) . '" value="' . clean((string)$valId) . '">';
    $h .= '<div class="egc-menu"></div></div>';
    return $h;
}
?>

<div class="page-header"><div class="page-header-left"><h1><?= $pageTitle ?></h1></div></div>

<div class="card gform"><div class="card-body">
<form method="post" enctype="multipart/form-data" id="gform">
  <?= csrfField() ?>

  <?php if ($admin): ?>
  <div class="seg">
    <input type="radio" name="tipo" id="tipo-emp" value="empresa" <?= ($data['tipo']??'')==='empresa'?'checked':'' ?>>
    <label for="tipo-emp">Empresa</label>
    <input type="radio" name="tipo" id="tipo-pre" value="prestamo" class="prest" <?= ($data['tipo']??'')==='prestamo'?'checked':'' ?>>
    <label for="tipo-pre">Préstamo</label>
  </div>
  <?php else: ?>
  <input type="hidden" name="tipo" value="prestamo">
  <div class="alert" style="background:#FFBBC8;color:#1E1E1E;border-radius:10px;padding:10px 14px;font-weight:700;margin-bottom:16px">Registrando un préstamo</div>
  <?php endif; ?>

  <div class="form-group">
    <label>Título / concepto general <span style="font-weight:400;color:#999">(opcional)</span></label>
    <input type="text" name="concepto" value="<?= clean($data['concepto'] ?? '') ?>" placeholder="Ej: Compra mercado, Pago de gas…">
  </div>

  <!-- LÍNEAS -->
  <div id="lines"></div>
  <button type="button" class="g-addline" onclick="addLine()">➕ Agregar línea</button>

  <div class="g-grandtotal"><span>Total</span><span id="grand">S/ 0.00</span></div>

  <div class="form-group">
    <label>Proveedor <span style="font-weight:400;color:#999">(opcional)</span></label>
    <?= egcombo('proveedor_id', 'buscar_proveedores', 'crear_proveedor', $csrf, 'Buscar o crear proveedor…', $data['proveedor_id'] ?? '', '') ?>
  </div>

  <?php if ($ubis): ?>
  <div class="form-group">
    <label>Tienda</label>
    <select name="ubicacion_id" id="ubic">
      <option value="">— Sin asignar —</option>
      <?php foreach ($ubis as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= (int)($data['ubicacion_id'] ?? 0)===(int)$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($invOk): ?><div style="font-size:11px;color:#999;margin-top:4px">El enganche con inventario requiere una tienda.</div><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="form-group">
    <label class="form-required">Fecha</label>
    <input type="date" name="fecha" value="<?= clean($data['fecha'] ?? date('Y-m-d')) ?>" required>
  </div>

  <div class="form-group">
    <label>Tags <span style="font-weight:400;color:#999">(para control / filtrar)</span></label>
    <div class="tags-box" id="tags-box" onclick="document.getElementById('tag-input').focus()">
      <input type="text" id="tag-input" placeholder="agregar tag…" autocomplete="off">
    </div>
    <input type="hidden" name="tags" id="tags-hidden" value="<?= clean($data['tags'] ?? '') ?>">
  </div>

  <div class="form-group">
    <label>Comprobante (foto)</label>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button type="button" class="foto-btn" onclick="fotoPick(true)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.66-.9l.82-1.2A2 2 0 0110.07 4h3.86a2 2 0 011.66.9l.82 1.2a2 2 0 001.66.9H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3"/></svg>
        Tomar foto
      </button>
      <button type="button" class="foto-btn" onclick="fotoPick(false)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.5-4.5a2 2 0 012.83 0L16 16m-2-2l1.5-1.5a2 2 0 012.83 0L21 16M3 6a2 2 0 012-2h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V6z"/></svg>
        Subir de galería
      </button>
    </div>
    <div style="font-size:11px;margin-top:6px;color:#888">Se elimina automáticamente a los 2 meses</div>
    <input type="file" id="foto-input" name="foto" accept="image/*" style="display:none" onchange="previewFoto(this)">
    <div class="foto-prev" id="foto-prev"><?php if (!empty($data['foto'])): ?><img src="<?= UPLOAD_URL . clean($data['foto']) ?>" alt="comprobante"><?php endif; ?></div>
  </div>

  <div class="form-group">
    <label>Nota <span style="font-weight:400;color:#999">(opcional)</span></label>
    <input type="text" name="nota" value="<?= clean($data['nota'] ?? '') ?>" placeholder="Detalle adicional…">
  </div>

  <div style="display:flex;gap:10px;margin-top:8px">
    <a href="<?= APP_URL ?>/admin/gastos/index.php" class="btn btn-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary" style="flex:1"><?= $isEdit ? 'Guardar cambios' : 'Guardar gasto' ?></button>
  </div>
</form>
</div></div>

<!-- Plantilla de línea -->
<template id="line-tpl">
  <div class="gline">
    <div class="gline-head"><b>Línea</b><button type="button" class="gline-del" onclick="delLine(this)">Quitar</button></div>
    <div class="gline-row">
      <input type="text" name="l_concepto[]" placeholder="Concepto (opcional)">
      <input class="mini" type="text" name="l_monto[]" inputmode="decimal" placeholder="Monto S/" oninput="recalc()">
    </div>
    <div class="gline-row">
      <?= egcombo('categoria_id[]', 'buscar_categorias', 'crear_categoria', $csrf, 'Categoría…') ?>
      <?= egcombo('subcategoria_id[]', 'buscar_subcategorias', 'crear_subcategoria', $csrf, 'Subcategoría…', '', '', '.egc-cat .egc-id', 'categoria_id') ?>
    </div>
    <?php if ($invOk): ?>
    <span class="inv-toggle" onclick="this.nextElementSibling.classList.toggle('on')">📦 Vincular a insumo (alimenta stock)</span>
    <div class="inv-box">
      <?= egcombo('insumo_id[]', 'buscar_insumos', 'crear_insumo', $csrf, 'Insumo…') ?>
      <input class="mini" type="text" name="l_cantidad[]" inputmode="decimal" placeholder="Cantidad">
    </div>
    <?php else: ?>
    <input type="hidden" name="insumo_id[]" value=""><input type="hidden" name="l_cantidad[]" value="">
    <?php endif; ?>
  </div>
</template>

<script>
var CSRF = <?= json_encode($csrf) ?>;
var EXISTING = <?= json_encode(array_map(function($it){ return [
  'concepto'=>$it['concepto'], 'monto'=>$it['monto'], 'categoria_id'=>$it['categoria_id'], 'cat_nombre'=>$it['cat_nombre'],
  'subcategoria_id'=>$it['subcategoria_id'], 'sub_nombre'=>$it['sub_nombre'], 'insumo_id'=>$it['insumo_id'],
  'insumo_nombre'=>$it['insumo_nombre'], 'cantidad'=>$it['cantidad'] ]; }, $items), JSON_UNESCAPED_UNICODE) ?>;

function markCat(line){ // marca el combo de categoría para que la subcategoría lo encuentre
  var combos = line.querySelectorAll('.egc');
  combos[0].setAttribute('data-egc-scope-cat','1');
  combos[0].classList.add('egc-cat');
  line.setAttribute('data-egc-scope','1');
}
function addLine(data){
  var tpl = document.getElementById('line-tpl').content.cloneNode(true);
  var line = tpl.querySelector('.gline');
  document.getElementById('lines').appendChild(tpl);
  var added = document.getElementById('lines').lastElementChild;
  markCat(added);
  if (data){
    added.querySelector('[name="l_concepto[]"]').value = data.concepto || '';
    added.querySelector('[name="l_monto[]"]').value = data.monto || '';
    setCombo(added, 0, data.categoria_id, data.cat_nombre);
    setCombo(added, 1, data.subcategoria_id, data.sub_nombre);
    var insBox = added.querySelector('.inv-box');
    if (insBox && data.insumo_id){ insBox.classList.add('on'); setCombo(added, 2, data.insumo_id, data.insumo_nombre);
      var q = added.querySelector('[name="l_cantidad[]"]'); if (q) q.value = data.cantidad || ''; }
  }
  if (window.EGCombo) window.EGCombo.init(added);
  recalc();
}
function setCombo(line, idx, id, txt){
  var combos = line.querySelectorAll('.egc');
  if (!combos[idx] || !id) return;
  combos[idx].querySelector('.egc-id').value = id;
  combos[idx].querySelector('.egc-input').value = txt || '';
}
function delLine(btn){ var l = btn.closest('.gline'); if (document.querySelectorAll('#lines .gline').length > 1) l.remove(); else { l.querySelectorAll('input').forEach(function(i){i.value='';}); l.querySelectorAll('.egc-id').forEach(function(i){i.value='';}); } recalc(); }
function recalc(){
  var t = 0;
  document.querySelectorAll('[name="l_monto[]"]').forEach(function(i){ var v = parseFloat((i.value||'').replace(',','.')); if(!isNaN(v)) t += v; });
  document.getElementById('grand').textContent = 'S/ ' + t.toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
}

// init
if (EXISTING.length) EXISTING.forEach(function(d){ addLine(d); }); else addLine();

// ── foto ──
function fotoPick(cam){ var inp = document.getElementById('foto-input'); if(cam) inp.setAttribute('capture','environment'); else inp.removeAttribute('capture'); inp.click(); }
function previewFoto(inp){ if(!inp.files||!inp.files[0])return; var p=document.getElementById('foto-prev'); p.innerHTML='<img src="'+URL.createObjectURL(inp.files[0])+'">'; }

// ── tags ──
var tags = (document.getElementById('tags-hidden').value||'').split(',').filter(Boolean);
function slugTag(t){ return (t||'').toLowerCase().replace(/^#+/,'').replace(/[^a-z0-9áéíóúñ]+/g,'-').replace(/^-+|-+$/g,''); }
function syncTags(){ document.getElementById('tags-hidden').value = tags.join(','); renderTags(); }
function renderTags(){ var box=document.getElementById('tags-box'); box.querySelectorAll('.tagchip').forEach(function(c){c.remove();}); var inp=document.getElementById('tag-input');
  tags.forEach(function(t){ var s=document.createElement('span'); s.className='tagchip'; s.innerHTML='#'+t+' <b>&times;</b>'; s.querySelector('b').onclick=function(){ tags=tags.filter(function(x){return x!==t;}); syncTags(); }; box.insertBefore(s,inp); }); }
function addTag(t){ t=slugTag(t); if(t&&tags.indexOf(t)===-1){ tags.push(t); syncTags(); } document.getElementById('tag-input').value=''; }
document.getElementById('tag-input').addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===','){ e.preventDefault(); addTag(this.value);} else if(e.key==='Backspace'&&this.value===''&&tags.length){ tags.pop(); syncTags(); } });
document.getElementById('tag-input').addEventListener('blur', function(){ if(this.value.trim()) addTag(this.value); });
renderTags();
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
