<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_recetas');
if (!inventarioListo()) { flashMessage('error', 'Aplica install/inventario.sql primero.'); redirect('/admin/inventory/recetas.php'); }

$pid  = cleanInt($_GET['product_id'] ?? 0);
$prod = $pid ? Database::fetch("SELECT * FROM products WHERE id=?", [$pid]) : null;
if (!$prod) { flashMessage('error', 'Producto no encontrado.'); redirect('/admin/inventory/recetas.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (recetaComponentesListo()) {
        $tipos = $_POST['comp_tipo'] ?? [];
        $refs  = $_POST['comp_ref'] ?? [];
        $cant  = $_POST['cantidad'] ?? [];
        Database::execute("DELETE FROM receta_componentes WHERE product_id = ?", [$pid]);
        $seen = [];
        foreach ($refs as $idx => $rid) {
            $rid = (int)$rid;
            $tipo = (($tipos[$idx] ?? 'insumo') === 'subreceta') ? 'subreceta' : 'insumo';
            $c = (float)($cant[$idx] ?? 0);
            $key = $tipo . ':' . $rid;
            if ($rid <= 0 || $c <= 0 || isset($seen[$key])) continue;
            $seen[$key] = true;
            Database::insert("INSERT INTO receta_componentes (product_id,tipo,ref_id,cantidad) VALUES (?,?,?,?)", [$pid, $tipo, $rid, $c]);
        }
        // Ficha técnica (upsert)
        $porciones = max(1, cleanInt($_POST['porciones'] ?? 1));
        $proc = clean($_POST['procedimiento'] ?? '');
        $mont = clean($_POST['montaje'] ?? '');
        $nota = clean($_POST['notas'] ?? '');
        Database::execute(
            "INSERT INTO receta_ficha (product_id,porciones,procedimiento,montaje,notas) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE porciones=VALUES(porciones), procedimiento=VALUES(procedimiento), montaje=VALUES(montaje), notas=VALUES(notas)",
            [$pid, $porciones, $proc, $mont, $nota]
        );
    } else {
        // Degradado: tabla nueva no aplicada, guardar como antes en recetas (solo insumos)
        $refs = $_POST['comp_ref'] ?? [];
        $tipos = $_POST['comp_tipo'] ?? [];
        $cant = $_POST['cantidad'] ?? [];
        Database::execute("DELETE FROM recetas WHERE product_id = ?", [$pid]);
        $seen = [];
        foreach ($refs as $idx => $rid) {
            $rid = (int)$rid; $c = (float)($cant[$idx] ?? 0);
            if (($tipos[$idx] ?? 'insumo') !== 'insumo' || $rid <= 0 || $c <= 0 || isset($seen[$rid])) continue;
            $seen[$rid] = true;
            Database::insert("INSERT INTO recetas (product_id,insumo_id,cantidad) VALUES (?,?,?)", [$pid, $rid, $c]);
        }
    }
    flashMessage('success', 'Receta guardada.');
    redirect('/admin/inventory/recetas.php');
}

// Cargar componentes existentes con nombre/unidad/costo unitario por tipo
$comps = [];
foreach (recetaComponentes($pid) as $c) {
    $tipo = ($c['tipo'] ?? 'insumo') === 'subreceta' ? 'subreceta' : 'insumo';
    $ref  = (int)$c['ref_id'];
    if ($tipo === 'subreceta') {
        $s = Database::fetch("SELECT nombre, unidad FROM subrecetas WHERE id=?", [$ref]);
        if (!$s) continue;
        $comps[] = ['tipo'=>'subreceta','ref'=>$ref,'nombre'=>$s['nombre'],'unidad'=>$s['unidad'],'costo'=>subrecetaCostoUM($ref),'cantidad'=>(float)$c['cantidad']];
    } else {
        $i = Database::fetch("SELECT nombre, unidad, costo_unitario FROM insumos WHERE id=?", [$ref]);
        if (!$i) continue;
        $comps[] = ['tipo'=>'insumo','ref'=>$ref,'nombre'=>$i['nombre'],'unidad'=>$i['unidad'],'costo'=>(float)$i['costo_unitario'],'cantidad'=>(float)$c['cantidad']];
    }
}

$ficha = Database::fetch("SELECT * FROM receta_ficha WHERE product_id=?", [$pid]) ?: ['porciones'=>1,'procedimiento'=>'','montaje'=>'','notas'=>''];
$precioRef = (float)(Database::fetch(
    "SELECT lp.price FROM location_products lp JOIN ubicaciones u ON u.id=lp.location_id WHERE lp.product_id=? ORDER BY u.es_principal DESC, u.nombre LIMIT 1",
    [$pid]
)['price'] ?? 0);
$igvPct = (float) getSetting('igv_pct', '18');
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = 'Receta · ' . $prod['name'];
$activePage = 'inv-recetas';
$extraHead  = '<style>
.rec-row{display:flex;gap:8px;margin-bottom:8px;align-items:center}
.rec-nm{flex:1;font-weight:700;color:var(--black,#1E1E1E)}
.rec-tag{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;padding:2px 6px;border-radius:5px;background:#FFEFBC;color:#1E1E1E}
.rec-tag.sub{background:var(--pink,#FFBBC8)}
.rec-row .rec-q{width:110px}
.rec-row .rec-u{width:46px;font-size:12px;color:var(--text-muted)}
.rec-row .rec-del{background:none;border:none;color:#dc2626;cursor:pointer;padding:6px;flex-shrink:0;font-size:16px}
.rec-opt{padding:10px 13px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px}
.rec-opt:hover{background:#fffbe9}
.rec-create{color:#1f9d55;font-weight:800;border-top:1px dashed #eee}
.fc-badge{font-weight:800}
.fc-ok{color:#16a34a}.fc-warn{color:#ca8a04}.fc-bad{color:#dc2626}
@media print{.no-print{display:none!important}}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb no-print">
  <a href="<?= APP_URL ?>/admin/inventory/recetas.php">Recetas</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= clean($prod['name']) ?></span>
</div>

<div class="page-header"><div class="page-header-left"><h1>Receta · <?= clean($prod['name']) ?></h1>
  <p>Insumos y subrecetas que consume una unidad, ficha técnica y food cost</p></div></div>

<form method="post">
  <?= csrfField() ?>
  <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">
    <div style="display:flex;flex-direction:column;gap:20px">
      <div class="card"><div class="card-header"><span class="card-title">Componentes</span></div><div class="card-body">
        <div id="rec-rows">
          <?php foreach ($comps as $c): ?>
          <div class="rec-row">
            <span class="rec-tag <?= $c['tipo']==='subreceta'?'sub':'' ?>"><?= $c['tipo']==='subreceta'?'Sub':'Insumo' ?></span>
            <span class="rec-nm"><?= clean($c['nombre']) ?></span>
            <input type="hidden" name="comp_tipo[]" value="<?= $c['tipo'] ?>">
            <input type="hidden" name="comp_ref[]" value="<?= (int)$c['ref'] ?>" data-costo="<?= (float)$c['costo'] ?>">
            <input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="<?= nf($c['cantidad']) ?>" oninput="recalc()">
            <span class="rec-u"><?= clean($c['unidad']) ?></span>
            <button type="button" class="rec-del no-print" onclick="this.closest('.rec-row').remove();recalc()">&#x2715;</button>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="add-wrap no-print" style="position:relative;margin-top:8px">
          <input type="text" id="rec-add" autocomplete="off" placeholder="Agregar insumo o subreceta (busca o crea)…"
                 oninput="recBuscar(this.value)" onfocus="recBuscar(this.value)"
                 style="width:100%;padding:11px 13px;border:1.5px dashed #c9c9d2;border-radius:10px">
          <div id="rec-drop" style="display:none;position:absolute;left:0;right:0;top:48px;background:#fff;border:1px solid var(--border,#eee);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:30;overflow:hidden"></div>
        </div>
      </div></div>

      <div class="card"><div class="card-header"><span class="card-title">Ficha técnica</span></div><div class="card-body">
        <div class="form-group"><label>Porciones que rinde</label>
          <input type="text" inputmode="numeric" name="porciones" id="fc-porc" value="<?= (int)$ficha['porciones'] ?>" oninput="recalc()" style="max-width:120px"></div>
        <div class="form-group"><label>Procedimiento</label>
          <textarea name="procedimiento" rows="4"><?= clean($ficha['procedimiento']) ?></textarea></div>
        <div class="form-group"><label>Montaje / emplatado</label>
          <textarea name="montaje" rows="3"><?= clean($ficha['montaje']) ?></textarea></div>
        <div class="form-group" style="margin-bottom:0"><label>Notas / alérgenos</label>
          <textarea name="notas" rows="2"><?= clean($ficha['notas']) ?></textarea></div>
      </div></div>

      <div style="display:flex;gap:12px" class="no-print">
        <button type="submit" class="btn btn-primary">Guardar receta</button>
        <a href="<?= APP_URL ?>/admin/inventory/recetas.php" class="btn btn-ghost">Cancelar</a>
        <button type="button" class="btn btn-ghost" onclick="window.print()">Imprimir ficha</button>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="card"><div class="card-body">
        <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Costo de la receta</div>
        <div id="costoTotal" style="font-size:28px;font-weight:800;margin-top:2px">S/ 0.00</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Costo por porción: <strong id="costoPorc">S/ 0.00</strong></div>
      </div></div>

      <div class="card no-print"><div class="card-header"><span class="card-title">Simulador de food cost</span></div><div class="card-body">
        <div class="form-group"><label>Precio de venta (con IGV)</label>
          <input type="text" inputmode="decimal" id="fc-precio" value="<?= $precioRef > 0 ? nf($precioRef) : '' ?>" placeholder="0.00" oninput="recalc()"></div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
          <span>Precio sin IGV</span><strong id="fc-neto">&#x2014;</strong></div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
          <span>Food cost</span><strong id="fc-fc" class="fc-badge">&#x2014;</strong></div>
        <div style="display:flex;justify-content:space-between;padding:6px 0">
          <span>Margen</span><strong id="fc-margen">&#x2014;</strong></div>
        <div style="margin-top:14px;padding-top:12px;border-top:1px dashed var(--border)">
          <label style="font-size:13px">Food cost objetivo</label>
          <div style="display:flex;align-items:center;gap:8px;margin-top:4px">
            <input type="text" inputmode="decimal" id="fc-obj" value="35" oninput="recalc()" style="width:70px">
            <span style="color:var(--text-muted)">% &#x2192;</span>
            <strong id="fc-sugerido" style="color:var(--c-brand,#FFDF00);-webkit-text-stroke:.3px #1E1E1E">S/ 0.00</strong>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Precio sugerido para ese food cost (informativo, no se guarda)</div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:12px">Simulación: el precio no se guarda. IGV <?= nf($igvPct) ?>%.</div>
      </div></div>
    </div>
  </div>
</form>

<script>
const INS_API = '<?= APP_URL ?>/api/insumos.php';
const CSRF = '<?= csrfToken() ?>';
const IGV = <?= json_encode($igvPct) ?>;
let insPend = '';

function recBuscar(q){
  q = (q||'').trim();
  const drop = document.getElementById('rec-drop');
  if(!q){ drop.style.display='none'; return; }
  fetch(INS_API + '?action=componentes_buscar&q=' + encodeURIComponent(q))
    .then(r=>r.json()).then(d=>{
      drop.innerHTML = '';
      (d.items||[]).forEach(i => {
        const o = document.createElement('div'); o.className = 'rec-opt';
        const left = document.createElement('span'); left.style.display='flex'; left.style.alignItems='center'; left.style.gap='8px';
        const tag = document.createElement('span'); tag.className = 'rec-tag' + (i.tipo==='subreceta'?' sub':''); tag.textContent = i.tipo==='subreceta'?'Sub':'Insumo';
        const n = document.createElement('span'); n.textContent = i.nombre;
        left.appendChild(tag); left.appendChild(n);
        const u = document.createElement('span'); u.className = 'rec-u'; u.textContent = i.unidad;
        o.appendChild(left); o.appendChild(u);
        o.addEventListener('click', () => recAgregar(i.tipo, i.id, i.nombre, i.unidad, parseFloat(i.costo)||0));
        drop.appendChild(o);
      });
      const exacto = (d.items||[]).some(i => i.nombre.toLowerCase() === q.toLowerCase());
      if(!exacto){
        const c = document.createElement('div'); c.className = 'rec-opt rec-create';
        c.textContent = '+ Crear insumo «' + q + '»';
        c.addEventListener('click', () => insAbrir(q));
        drop.appendChild(c);
      }
      drop.style.display = 'block';
    });
}

function recAgregar(tipo, id, nombre, unidad, costo){
  tipo = tipo === 'subreceta' ? 'subreceta' : 'insumo';
  costo = parseFloat(costo)||0;
  // Evitar duplicar el mismo componente (mismo tipo + mismo ref)
  let dup = false;
  document.querySelectorAll('#rec-rows .rec-row').forEach(function(row){
    const t = row.querySelector('input[name="comp_tipo[]"]');
    const r = row.querySelector('input[name="comp_ref[]"]');
    if (t && r && t.value === tipo && String(r.value) === String(id)) dup = true;
  });
  if (dup) { document.getElementById('rec-drop').style.display='none'; document.getElementById('rec-add').value=''; return; }
  const tag = tipo==='subreceta' ? 'Sub' : 'Insumo';
  const cls = tipo==='subreceta' ? 'rec-tag sub' : 'rec-tag';
  const row = document.createElement('div'); row.className='rec-row';
  row.innerHTML = '<span class="'+cls+'">'+tag+'</span>'+
    '<span class="rec-nm">'+nombre+'</span>'+
    '<input type="hidden" name="comp_tipo[]" value="'+tipo+'">'+
    '<input type="hidden" name="comp_ref[]" value="'+id+'" data-costo="'+costo+'">'+
    '<input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="1" oninput="recalc()">'+
    '<span class="rec-u">'+unidad+'</span>'+
    '<button type="button" class="rec-del no-print" onclick="this.closest(\'.rec-row\').remove();recalc()">&#x2715;</button>';
  document.getElementById('rec-rows').appendChild(row);
  document.getElementById('rec-add').value='';
  document.getElementById('rec-drop').style.display='none';
  recalc();
}

function insAbrir(nombre){
  insPend = nombre;
  document.getElementById('ins-name').textContent = nombre;
  document.getElementById('rec-drop').style.display='none';
  document.getElementById('ins-ov').style.display='flex';
}
function insCerrar(){ document.getElementById('ins-ov').style.display='none'; }
function insCrear(){
  const body = new URLSearchParams({action:'crear', nombre:insPend, unidad:document.getElementById('ins-unidad').value, tipo:'ingrediente', costo_unitario:document.getElementById('ins-costo').value||'0'});
  fetch(INS_API, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF}, body})
    .then(r=>r.json()).then(d=>{
      if(d.ok){ recAgregar('insumo', d.insumo.id, d.insumo.nombre, d.insumo.unidad, parseFloat(d.insumo.costo_unitario)||0); document.getElementById('ins-costo').value=''; insCerrar(); }
      else { alert(d.error||'No se pudo crear'); }
    });
}

function recalc(){
  let total = 0;
  document.querySelectorAll('#rec-rows .rec-row').forEach(function(row){
    const hid = row.querySelector('input[name="comp_ref[]"]');
    const q = parseFloat(row.querySelector('.rec-q').value) || 0;
    const costo = hid ? parseFloat(hid.dataset.costo)||0 : 0;
    total += costo * q;
  });
  const porc = Math.max(1, parseInt(document.getElementById('fc-porc').value) || 1);
  const costoPorc = total / porc;
  document.getElementById('costoTotal').textContent = 'S/ ' + total.toFixed(2);
  document.getElementById('costoPorc').textContent = 'S/ ' + costoPorc.toFixed(2);

  const precio = parseFloat(document.getElementById('fc-precio').value) || 0;
  const neto = precio > 0 ? precio / (1 + IGV/100) : 0;
  const elFc = document.getElementById('fc-fc');
  if (neto > 0) {
    const fc = costoPorc / neto;        // fracción
    const margen = (neto - costoPorc) / neto;
    document.getElementById('fc-neto').textContent = 'S/ ' + neto.toFixed(2);
    elFc.textContent = Math.round(fc*100) + '%';
    elFc.className = 'fc-badge ' + (fc<=0.35?'fc-ok':(fc<=0.42?'fc-warn':'fc-bad'));
    document.getElementById('fc-margen').textContent = 'S/ ' + (neto - costoPorc).toFixed(2) + ' \xb7 ' + Math.round(margen*100) + '%';
  } else {
    document.getElementById('fc-neto').textContent = '—';
    elFc.textContent = '—'; elFc.className = 'fc-badge';
    document.getElementById('fc-margen').textContent = '—';
  }

  const obj = parseFloat(document.getElementById('fc-obj').value) || 0;
  const sug = obj > 0 ? (costoPorc / (obj/100)) * (1 + IGV/100) : 0;
  document.getElementById('fc-sugerido').textContent = 'S/ ' + sug.toFixed(2);
}

document.addEventListener('click', e=>{ if(!e.target.closest('.add-wrap')){ const d=document.getElementById('rec-drop'); if(d) d.style.display='none'; } });
recalc();
</script>

<div id="ins-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.5);z-index:50;align-items:center;justify-content:center;padding:18px">
  <div style="width:340px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden">
    <div style="background:#fafafb;padding:13px 16px;border-bottom:1px solid var(--border,#eee);font-weight:800">Crear insumo: &#xab;<span id="ins-name"></span>&#xbb;</div>
    <div style="padding:16px">
      <div class="form-group"><label>Unidad</label>
        <select id="ins-unidad"><option value="unidad">unidad</option><option value="g">gramos (g)</option><option value="ml">ml</option><option value="kg">kg</option><option value="l">l</option><option value="lonja">lonja</option><option value="porcion">porción</option></select></div>
      <div class="form-group"><label>Costo por unidad (opcional)</label><input id="ins-costo" inputmode="decimal" placeholder="0.00"></div>
    </div>
    <div style="display:flex;gap:8px;padding:0 16px 16px">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="insCerrar()">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="insCrear()">Crear y agregar</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
