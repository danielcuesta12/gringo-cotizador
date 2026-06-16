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

$insumos = Database::fetchAll("SELECT id,nombre,unidad,costo_unitario FROM insumos WHERE activo=1 ORDER BY nombre");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $ins = $_POST['insumo_id'] ?? [];
    $cant = $_POST['cantidad'] ?? [];
    Database::execute("DELETE FROM recetas WHERE product_id = ?", [$pid]);
    $seen = [];
    foreach ($ins as $idx => $iid) {
        $iid = (int)$iid; $c = (float)($cant[$idx] ?? 0);
        if ($iid <= 0 || $c <= 0 || isset($seen[$iid])) continue;
        $seen[$iid] = true;
        Database::insert("INSERT INTO recetas (product_id,insumo_id,cantidad) VALUES (?,?,?)", [$pid, $iid, $c]);
    }
    flashMessage('success', 'Receta guardada.');
    redirect('/admin/inventory/recetas.php');
}

$receta = Database::fetchAll("SELECT * FROM recetas WHERE product_id=? ORDER BY insumo_id", [$pid]);
// precios por ubicación para mostrar margen
$precios = Database::fetchAll(
    "SELECT u.nombre, lp.price FROM location_products lp JOIN ubicaciones u ON u.id=lp.location_id WHERE lp.product_id=? ORDER BY u.es_principal DESC, u.nombre",
    [$pid]
);
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = 'Receta · ' . $prod['name'];
$activePage = 'inv-recetas';
$extraHead  = '<style>
.rec-row{display:flex;gap:8px;margin-bottom:8px;align-items:center}
.rec-nm{flex:1;font-weight:700;color:var(--navy,#1B1F4B)}
.rec-row .rec-q{width:120px}
.rec-row .rec-u{width:48px;font-size:12px;color:var(--text-muted)}
.rec-row .rec-del{background:none;border:none;color:#dc2626;cursor:pointer;padding:6px;flex-shrink:0}
.rec-opt{padding:10px 13px;cursor:pointer;display:flex;justify-content:space-between;align-items:center}
.rec-opt:hover{background:#fffbe9}
.rec-create{color:#1f9d55;font-weight:800;border-top:1px dashed #eee}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/inventory/recetas.php">Recetas</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= clean($prod['name']) ?></span>
</div>

<div class="page-header"><div class="page-header-left"><h1>Receta · <?= clean($prod['name']) ?></h1>
  <p>Define cuánto de cada insumo consume una unidad de este producto</p></div></div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">
  <div class="card"><div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <div id="rec-rows">
        <?php foreach ($receta as $r):
            $ins = null; foreach ($insumos as $ix) { if ((int)$ix['id'] === (int)$r['insumo_id']) { $ins = $ix; break; } }
            if (!$ins) continue; ?>
        <div class="rec-row">
          <span class="rec-nm"><?= clean($ins['nombre']) ?></span>
          <input type="hidden" name="insumo_id[]" value="<?= (int)$ins['id'] ?>" data-costo="<?= (float)$ins['costo_unitario'] ?>" data-unidad="<?= clean($ins['unidad']) ?>">
          <input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="<?= nf($r['cantidad']) ?>" oninput="recalc()">
          <span class="rec-u"><?= clean($ins['unidad']) ?></span>
          <button type="button" class="rec-del" onclick="this.closest('.rec-row').remove();recalc()">✕</button>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="add-wrap" style="position:relative;margin-top:8px">
        <input type="text" id="rec-add" autocomplete="off" placeholder="🔍 Agregar insumo (busca o crea)…"
               oninput="recBuscar(this.value)" onfocus="recBuscar(this.value)"
               style="width:100%;padding:11px 13px;border:1.5px dashed #c9c9d2;border-radius:10px">
        <div id="rec-drop" style="display:none;position:absolute;left:0;right:0;top:48px;background:#fff;border:1px solid var(--border,#eee);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:30;overflow:hidden"></div>
      </div>
      <div style="display:flex;gap:12px;margin-top:18px">
        <button type="submit" class="btn btn-primary" style="gap:6px">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>Guardar receta
        </button>
        <a href="<?= APP_URL ?>/admin/inventory/recetas.php" class="btn btn-ghost">Cancelar</a>
      </div>
    </form>
  </div></div>

  <div style="display:flex;flex-direction:column;gap:16px">
    <div class="card"><div class="card-body" style="text-align:center">
      <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Costo de la receta</div>
      <div id="costoTotal" style="font-size:30px;font-weight:800;color:var(--ink);margin-top:4px">S/ 0.00</div>
    </div></div>
    <?php if (!empty($precios)): ?>
    <div class="card"><div class="card-header"><span class="card-title">Margen por ubicación</span></div>
      <div class="card-body" id="margenBox" style="font-size:13px">
        <?php foreach ($precios as $pr): ?>
          <div class="mg-row" data-precio="<?= (float)$pr['price'] ?>" style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
            <span><?= clean($pr['nombre']) ?> <span style="color:var(--text-muted)">(<?= formatMoney($pr['price']) ?>)</span></span>
            <span class="mg-val" style="font-weight:700">—</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const INS_API = '<?= APP_URL ?>/api/insumos.php';
const CSRF = '<?= csrfToken() ?>';
let insPend = '';

function recBuscar(q){
  q = (q||'').trim();
  const drop = document.getElementById('rec-drop');
  if(!q){ drop.style.display='none'; return; }
  fetch(INS_API + '?action=buscar&q=' + encodeURIComponent(q))
    .then(r=>r.json()).then(d=>{
      drop.innerHTML = '';
      (d.items||[]).forEach(i => {
        const o = document.createElement('div'); o.className = 'rec-opt';
        const n = document.createElement('span'); n.textContent = i.nombre;
        const u = document.createElement('span'); u.className = 'rec-u'; u.textContent = i.unidad;
        o.appendChild(n); o.appendChild(u);
        o.addEventListener('click', () => recAgregar(i.id, i.nombre, i.unidad, parseFloat(i.costo_unitario)||0));
        drop.appendChild(o);
      });
      const exacto = (d.items||[]).some(i => i.nombre.toLowerCase() === q.toLowerCase());
      if(!exacto){
        const c = document.createElement('div'); c.className = 'rec-opt rec-create';
        c.textContent = '+ Crear «' + q + '»';
        c.addEventListener('click', () => insAbrir(q));
        drop.appendChild(c);
      }
      drop.style.display = 'block';
    });
}

function recAgregar(id, nombre, unidad, costo){
  costo = parseFloat(costo)||0;
  if (document.querySelector('input[name="insumo_id[]"][value="'+id+'"]')) {
    document.getElementById('rec-drop').style.display='none';
    document.getElementById('rec-add').value='';
    return;
  }
  const row = document.createElement('div'); row.className='rec-row';
  row.innerHTML = '<span class="rec-nm">'+nombre+'</span>'+
    '<input type="hidden" name="insumo_id[]" value="'+id+'" data-costo="'+costo+'" data-unidad="'+unidad+'">'+
    '<input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="1" oninput="recalc()">'+
    '<span class="rec-u">'+unidad+'</span>'+
    '<button type="button" class="rec-del" onclick="this.closest(\'.rec-row\').remove();recalc()">✕</button>';
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
  const body = new URLSearchParams({action:'crear', nombre:insPend, unidad:document.getElementById('ins-unidad').value, tipo:document.getElementById('ins-tipo').value, costo_unitario:document.getElementById('ins-costo').value||'0'});
  fetch(INS_API, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF}, body})
    .then(r=>r.json()).then(d=>{
      if(d.ok){
        recAgregar(d.insumo.id, d.insumo.nombre, d.insumo.unidad, 0);
        document.getElementById('ins-costo').value='';
        insCerrar();
      } else { alert(d.error||'No se pudo crear'); }
    });
}

function recalc(){
  var total = 0;
  document.querySelectorAll('#rec-rows .rec-row').forEach(function(row){
    var hid = row.querySelector('input[name="insumo_id[]"]');
    var q = parseFloat(row.querySelector('.rec-q').value) || 0;
    var costo = hid ? parseFloat(hid.dataset.costo)||0 : 0;
    total += costo * q;
  });
  document.getElementById('costoTotal').textContent = 'S/ ' + total.toFixed(2);
  document.querySelectorAll('.mg-row').forEach(function(r){
    var precio = parseFloat(r.dataset.precio)||0;
    var el = r.querySelector('.mg-val');
    if (precio > 0){ var fc = Math.round(total*100/precio); el.textContent = 'S/'+(precio-total).toFixed(2)+' · '+fc+'% fc';
      el.style.color = fc<=35?'#16a34a':(fc<=45?'#ca8a04':'#dc2626'); }
    else el.textContent = '—';
  });
}

document.addEventListener('click', e=>{ if(!e.target.closest('.add-wrap')){ const d=document.getElementById('rec-drop'); if(d) d.style.display='none'; } });
recalc();
</script>

<div id="ins-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.5);z-index:50;align-items:center;justify-content:center;padding:18px">
  <div style="width:340px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden">
    <div style="background:#fafafb;padding:13px 16px;border-bottom:1px solid var(--border,#eee);font-weight:800">Crear insumo: «<span id="ins-name"></span>»</div>
    <div style="padding:16px">
      <div class="form-group"><label>Unidad</label>
        <select id="ins-unidad"><option value="unidad">unidad</option><option value="g">gramos (g)</option><option value="ml">ml</option><option value="kg">kg</option><option value="l">l</option><option value="lonja">lonja</option><option value="porcion">porción</option></select></div>
      <div class="form-group"><label>Tipo</label>
        <select id="ins-tipo"><option value="ingrediente">Ingrediente</option><option value="descartable">Descartable / papelería</option></select></div>
      <div class="form-group"><label>Costo por unidad (opcional)</label><input id="ins-costo" inputmode="decimal" placeholder="0.00"></div>
    </div>
    <div style="display:flex;gap:8px;padding:0 16px 16px">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="insCerrar()">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="insCrear()">Crear y agregar</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
