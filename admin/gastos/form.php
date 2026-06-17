<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('gastos');

$admin = isAdmin();
$uid   = (int) (currentUser()['id'] ?? 0);
$id    = cleanInt($_GET['id'] ?? 0);
$g     = $id ? Database::fetch("SELECT * FROM gastos WHERE id = ?", [$id]) : null;
if ($id && !$g) { flashMessage('error', 'Gasto no encontrado.'); redirect('/admin/gastos/index.php'); }
// No-admin: solo puede editar sus propios préstamos.
if ($g && !$admin && ((int)$g['usuario_id'] !== $uid || $g['tipo'] !== 'prestamo')) {
    flashMessage('error', 'No tienes acceso a ese gasto.');
    redirect('/admin/gastos/index.php');
}
$isEdit = (bool) $g;

$cats = Database::fetchAll("SELECT id, nombre FROM gasto_categorias ORDER BY nombre");
$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, sort_order, nombre");

// Sugerencias de tags (de los ya usados)
$sug = [];
foreach (Database::fetchAll("SELECT tags FROM gastos WHERE tags IS NOT NULL AND tags <> ''") as $r) {
    foreach (explode(',', $r['tags']) as $t) { $t = trim($t); if ($t !== '') $sug[$t] = true; }
}
$sugTags = array_slice(array_keys($sug), 0, 24);

/** Normaliza una cadena de tags a slugs separados por coma, sin duplicados. */
function normalizeTags(string $raw): string {
    $out = [];
    foreach (preg_split('/[,\s]+/', $raw) as $t) {
        $t = ltrim(trim($t), '#');
        $t = strtolower($t);
        $t = preg_replace('/[^a-z0-9áéíóúñ]+/u', '-', $t);
        $t = trim($t, '-');
        if ($t !== '' && !in_array($t, $out, true)) $out[] = $t;
    }
    return implode(',', array_slice($out, 0, 12));
}

$data = $g ?? [
    'tipo' => $admin ? 'empresa' : 'prestamo',
    'concepto' => '', 'monto' => '', 'categoria_id' => null, 'ubicacion_id' => null,
    'fecha' => date('Y-m-d'), 'tags' => '', 'foto' => null, 'nota' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $tipo = $admin
        ? (in_array($_POST['tipo'] ?? '', ['empresa', 'prestamo'], true) ? $_POST['tipo'] : 'prestamo')
        : 'prestamo'; // los no-admin SIEMPRE préstamo (gate servidor)

    $concepto = clean($_POST['concepto'] ?? '');
    $monto    = cleanFloat($_POST['monto'] ?? 0);
    $ubiId    = cleanInt($_POST['ubicacion_id'] ?? 0) ?: null;
    $fecha    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
    $nota     = clean($_POST['nota'] ?? '');
    $tags     = normalizeTags($_POST['tags'] ?? '');

    // Categoría: existente o nueva (creación rápida).
    $catId    = cleanInt($_POST['categoria_id'] ?? 0) ?: null;
    $nuevaCat = clean($_POST['nueva_categoria'] ?? '');
    if ($nuevaCat !== '') {
        Database::execute("INSERT IGNORE INTO gasto_categorias (nombre) VALUES (?)", [$nuevaCat]);
        $cr = Database::fetch("SELECT id FROM gasto_categorias WHERE nombre = ?", [$nuevaCat]);
        if ($cr) $catId = (int) $cr['id'];
    }

    // Foto (opcional)
    $foto = $data['foto'] ?? null;
    if (!empty($_FILES['foto']['name'])) {
        $up = uploadImage($_FILES['foto'], 'gastos');
        if ($up) $foto = $up;
    }

    $errors = [];
    if ($concepto === '') $errors[] = 'El concepto es obligatorio.';
    if ($monto <= 0)      $errors[] = 'El monto debe ser mayor a 0.';

    if (!$errors) {
        if ($isEdit) {
            Database::execute(
                "UPDATE gastos SET tipo=?, concepto=?, monto=?, categoria_id=?, ubicacion_id=?, fecha=?, tags=?, foto=?, nota=? WHERE id=?",
                [$tipo, $concepto, $monto, $catId, $ubiId, $fecha, ($tags ?: null), $foto, ($nota ?: null), $id]
            );
            flashMessage('success', 'Gasto actualizado.');
        } else {
            $estado = $tipo === 'empresa' ? 'pagado' : 'pendiente';
            Database::insert(
                "INSERT INTO gastos (tipo, concepto, monto, categoria_id, ubicacion_id, usuario_id, fecha, tags, foto, nota, estado)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                [$tipo, $concepto, $monto, $catId, $ubiId, $uid, $fecha, ($tags ?: null), $foto, ($nota ?: null), $estado]
            );
            flashMessage('success', 'Gasto registrado.');
        }
        redirect('/admin/gastos/index.php');
    }
    foreach ($errors as $e) flashMessage('error', $e);
    $data = array_merge($data, [
        'tipo' => $tipo, 'concepto' => $concepto, 'monto' => $monto,
        'categoria_id' => $catId, 'ubicacion_id' => $ubiId, 'fecha' => $fecha, 'tags' => $tags, 'nota' => $nota, 'foto' => $foto,
    ]);
}

$pageTitle  = $isEdit ? 'Editar gasto' : 'Nuevo gasto';
$activePage = 'gastos';
$extraHead  = '<style>
.gform{max-width:520px}
.seg{display:flex;background:var(--bg-page,#f1f1f4);border-radius:12px;padding:4px;margin-bottom:18px}
.seg label{flex:1;text-align:center;padding:11px;border-radius:9px;font-size:14px;font-weight:800;color:var(--text-muted,#888);cursor:pointer}
.seg input{position:absolute;opacity:0;pointer-events:none}
.seg input:checked + label{background:#fff;color:var(--text-primary,#1E1E1E);box-shadow:0 1px 4px rgba(0,0,0,.12)}
.seg input.prest:checked + label{background:#FFBBC8;color:#1E1E1E}
.monto-wrap{position:relative}
.monto-wrap .sol{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:18px;font-weight:800;color:var(--text-muted,#999)}
.monto-wrap input{padding-left:42px;font-size:22px;font-weight:800}
.cat-row{display:flex;gap:8px}
.cat-row select{flex:1}
.cat-add{flex:0 0 auto;border:1.5px dashed var(--border,#ddd);background:#fff;border-radius:9px;padding:0 15px;font-size:20px;font-weight:800;cursor:pointer;color:var(--text-primary,#1E1E1E)}
.cat-new{display:none;gap:8px;margin-top:8px;background:#fffbe6;border:1px solid var(--c-brand,#FFDF00);border-radius:10px;padding:10px}
.cat-new.on{display:flex}
.cat-new input{flex:1}
.tags-box{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1.5px solid var(--border,#ddd);border-radius:10px;padding:8px;background:#fff}
.tagchip{background:#1E1E1E;color:var(--c-brand,#FFDF00);font-size:12px;font-weight:800;padding:4px 9px;border-radius:7px;display:inline-flex;gap:5px;align-items:center}
.tagchip b{cursor:pointer;opacity:.7}
#tag-input{flex:1;min-width:100px;border:none;outline:none;font-size:14px;padding:4px;background:transparent}
.tag-sug{font-size:12px;color:var(--text-muted,#888);margin-top:7px;display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.tag-sug .sug{background:var(--bg-page,#f1f1f4);border-radius:6px;padding:3px 9px;cursor:pointer;font-weight:700}
.foto-drop{border:1.5px dashed var(--border,#ddd);border-radius:12px;padding:20px;text-align:center;color:var(--text-muted,#888);background:var(--bg-page,#fafafa);cursor:pointer}
.foto-drop svg{width:28px;height:28px;display:block;margin:0 auto 8px}
.foto-btn{flex:1;min-width:130px;display:flex;flex-direction:column;align-items:center;gap:6px;border:1.5px dashed var(--border,#ddd);border-radius:12px;padding:16px 12px;background:var(--bg-page,#fafafa);color:var(--text-muted,#666);font-size:13px;font-weight:600;cursor:pointer}
.foto-btn svg{width:26px;height:26px}
.foto-btn:active{transform:scale(.98)}
.foto-prev{margin-top:10px;display:none}
.foto-prev img{max-width:100%;border-radius:10px}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left"><h1><?= $pageTitle ?></h1></div>
</div>

<div class="card gform">
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrfField() ?>

      <?php if ($admin): ?>
      <div class="seg">
        <input type="radio" name="tipo" id="tipo-emp" value="empresa" <?= $data['tipo']==='empresa'?'checked':'' ?>>
        <label for="tipo-emp">Empresa</label>
        <input type="radio" name="tipo" id="tipo-pre" value="prestamo" class="prest" <?= $data['tipo']==='prestamo'?'checked':'' ?>>
        <label for="tipo-pre">Préstamo</label>
      </div>
      <?php else: ?>
      <input type="hidden" name="tipo" value="prestamo">
      <div class="alert" style="background:#FFBBC8;color:#1E1E1E;border-radius:10px;padding:10px 14px;font-weight:700;margin-bottom:16px">Registrando un préstamo</div>
      <?php endif; ?>

      <div class="form-group">
        <label class="form-required">Monto</label>
        <div class="monto-wrap"><span class="sol">S/</span>
          <input type="text" name="monto" inputmode="decimal" value="<?= clean((string)($data['monto'] ?: '')) ?>" placeholder="0.00" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-required">Concepto</label>
        <input type="text" name="concepto" value="<?= clean($data['concepto']) ?>" placeholder="¿En qué se gastó?" required>
      </div>

      <div class="form-group">
        <label>Categoría</label>
        <div class="cat-row">
          <select name="categoria_id" id="cat-select">
            <option value="">— Sin categoría —</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)($data['categoria_id'] ?? 0)===(int)$c['id']?'selected':'' ?>><?= clean($c['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="cat-add" onclick="toggleCatNew()" title="Nueva categoría">+</button>
        </div>
        <div class="cat-new" id="cat-new">
          <input type="text" name="nueva_categoria" id="nueva-cat" placeholder="Nueva categoría…" maxlength="80">
        </div>
      </div>

      <div class="form-group">
        <label>Tags <span style="font-weight:400;color:var(--text-muted,#999)">(para control / filtrar)</span></label>
        <div class="tags-box" id="tags-box" onclick="document.getElementById('tag-input').focus()">
          <input type="text" id="tag-input" placeholder="agregar tag…" autocomplete="off">
        </div>
        <input type="hidden" name="tags" id="tags-hidden" value="<?= clean($data['tags'] ?? '') ?>">
        <?php if ($sugTags): ?>
        <div class="tag-sug">Sugerencias:
          <?php foreach ($sugTags as $t): ?><span class="sug" onclick="addTag('<?= clean($t) ?>')">#<?= clean($t) ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($ubis): ?>
      <div class="form-group">
        <label>Tienda</label>
        <select name="ubicacion_id">
          <option value="">— Sin asignar —</option>
          <?php foreach ($ubis as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($data['ubicacion_id'] ?? 0)===(int)$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="form-group">
        <label class="form-required">Fecha</label>
        <input type="date" name="fecha" value="<?= clean($data['fecha']) ?>" required>
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
        <div style="font-size:11px;margin-top:6px;color:var(--text-muted,#888)">Se elimina automáticamente a los 2 meses</div>
        <input type="file" id="foto-input" name="foto" accept="image/*" style="display:none" onchange="previewFoto(this)">
        <div class="foto-prev" id="foto-prev">
          <?php if (!empty($data['foto'])): ?><img src="<?= UPLOAD_URL . clean($data['foto']) ?>" alt="comprobante"><?php endif; ?>
        </div>
      </div>

      <div class="form-group">
        <label>Nota <span style="font-weight:400;color:var(--text-muted,#999)">(opcional)</span></label>
        <input type="text" name="nota" value="<?= clean($data['nota'] ?? '') ?>" placeholder="Detalle adicional…">
      </div>

      <div style="display:flex;gap:10px;margin-top:8px">
        <a href="<?= APP_URL ?>/admin/gastos/index.php" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary" style="flex:1"><?= $isEdit ? 'Guardar cambios' : 'Guardar gasto' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleCatNew() {
  var n = document.getElementById('cat-new');
  n.classList.toggle('on');
  if (n.classList.contains('on')) { document.getElementById('cat-select').value=''; document.getElementById('nueva-cat').focus(); }
  else { document.getElementById('nueva-cat').value=''; }
}
function fotoPick(cam) {
  var inp = document.getElementById('foto-input');
  if (cam) inp.setAttribute('capture', 'environment'); else inp.removeAttribute('capture');
  inp.click();
}
function previewFoto(inp) {
  if (!inp.files || !inp.files[0]) return;
  var prev = document.getElementById('foto-prev');
  var url = URL.createObjectURL(inp.files[0]);
  prev.innerHTML = '<img src="'+url+'" alt="comprobante">';
  prev.style.display = 'block';
}

// ── Tags chips ──
var tags = (document.getElementById('tags-hidden').value || '').split(',').filter(Boolean);
function slugTag(t){ return (t||'').toLowerCase().replace(/^#+/,'').replace(/[^a-z0-9áéíóúñ]+/g,'-').replace(/^-+|-+$/g,''); }
function syncTags(){ document.getElementById('tags-hidden').value = tags.join(','); renderTags(); }
function renderTags(){
  var box = document.getElementById('tags-box');
  box.querySelectorAll('.tagchip').forEach(function(c){ c.remove(); });
  var inp = document.getElementById('tag-input');
  tags.forEach(function(t){
    var s = document.createElement('span'); s.className='tagchip';
    s.innerHTML = '#'+t+' <b>&times;</b>';
    s.querySelector('b').onclick = function(){ tags = tags.filter(function(x){return x!==t;}); syncTags(); };
    box.insertBefore(s, inp);
  });
}
function addTag(t){ t = slugTag(t); if (t && tags.indexOf(t)===-1){ tags.push(t); syncTags(); } document.getElementById('tag-input').value=''; }
document.getElementById('tag-input').addEventListener('keydown', function(e){
  if (e.key==='Enter' || e.key===',' ){ e.preventDefault(); addTag(this.value); }
  else if (e.key==='Backspace' && this.value==='' && tags.length){ tags.pop(); syncTags(); }
});
document.getElementById('tag-input').addEventListener('blur', function(){ if(this.value.trim()) addTag(this.value); });
renderTags();
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
