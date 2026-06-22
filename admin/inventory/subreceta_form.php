<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_recetas');
if (!subrecetasListo()) { flashMessage('error', 'Aplica install/60_costeo_recetas.sql primero.'); redirect('/admin/inventory/recetas.php'); }

$id = cleanInt($_GET['id'] ?? 0);
$sub = $id ? Database::fetch("SELECT * FROM subrecetas WHERE id=?", [$id]) : null;
if ($id && !$sub) { flashMessage('error', 'Subreceta no encontrada.'); redirect('/admin/inventory/subrecetas.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $nombre = clean($_POST['nombre'] ?? '');
    $unidad = clean($_POST['unidad'] ?? 'unidad') ?: 'unidad';
    $rend   = max(0.001, cleanFloat($_POST['rendimiento'] ?? 1));
    $llevaStock = (subrecetaStockListo() && !empty($_POST['lleva_stock'])) ? 1 : 0;
    if ($nombre === '') { flashMessage('error', 'Falta el nombre.'); redirect('/admin/inventory/subreceta_form.php' . ($id ? '?id='.$id : '')); }
    if ($id) {
        if (subrecetaStockListo()) {
            Database::execute("UPDATE subrecetas SET nombre=?, unidad=?, rendimiento=?, lleva_stock=? WHERE id=?", [$nombre, $unidad, $rend, $llevaStock, $id]);
        } else {
            Database::execute("UPDATE subrecetas SET nombre=?, unidad=?, rendimiento=? WHERE id=?", [$nombre, $unidad, $rend, $id]);
        }
    } else {
        if (subrecetaStockListo()) {
            $id = Database::insert("INSERT INTO subrecetas (nombre,unidad,rendimiento,lleva_stock,activo) VALUES (?,?,?,?,1)", [$nombre, $unidad, $rend, $llevaStock]);
        } else {
            $id = Database::insert("INSERT INTO subrecetas (nombre,unidad,rendimiento,activo) VALUES (?,?,?,1)", [$nombre, $unidad, $rend]);
        }
    }
    $ins = $_POST['insumo_id'] ?? [];
    $cant = $_POST['cantidad'] ?? [];
    Database::execute("DELETE FROM subreceta_items WHERE subreceta_id=?", [$id]);
    $seen = [];
    foreach ($ins as $idx => $iid) {
        $iid = (int)$iid; $c = (float)($cant[$idx] ?? 0);
        if ($iid <= 0 || $c <= 0 || isset($seen[$iid])) continue;
        $seen[$iid] = true;
        Database::insert("INSERT INTO subreceta_items (subreceta_id,insumo_id,cantidad) VALUES (?,?,?)", [$id, $iid, $c]);
    }
    flashMessage('success', 'Subreceta guardada.');
    redirect('/admin/inventory/subrecetas.php');
}

$items = $id ? Database::fetchAll(
    "SELECT si.insumo_id, si.cantidad, i.nombre, i.unidad, i.costo_unitario
       FROM subreceta_items si JOIN insumos i ON i.id=si.insumo_id
      WHERE si.subreceta_id=? ORDER BY i.nombre", [$id]) : [];
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = $id ? 'Subreceta · ' . $sub['nombre'] : 'Nueva subreceta';
$activePage = 'inv-subrecetas';
$extraHead  = '<style>
.rec-head,.rec-row{display:grid;grid-template-columns:1fr 92px 82px 82px 26px;gap:10px;align-items:center}
.rec-head{margin-bottom:4px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:700}
.rec-row{margin-bottom:8px}
.rh-r{text-align:right}
.rec-nm{font-weight:700;color:var(--black,#1E1E1E);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rec-um{color:var(--text-muted);font-weight:600;font-size:13px}
.rec-q{width:100%;text-align:right}
.rec-u{font-size:12px;color:var(--text-muted)}
.rec-precio{text-align:right;color:var(--text-muted);font-size:13px;font-variant-numeric:tabular-nums}
.rec-costo{text-align:right;font-weight:700;color:var(--black,#1E1E1E);font-variant-numeric:tabular-nums}
.rec-del{background:none;border:none;color:#dc2626;cursor:pointer;padding:4px;font-size:16px;justify-self:end}
.rec-opt{padding:10px 13px;cursor:pointer;display:flex;justify-content:space-between;align-items:center}
.rec-opt:hover{background:#fffbe9}
.rec-create{color:#1f9d55;font-weight:800;border-top:1px dashed #eee}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/inventory/subrecetas.php">Subrecetas</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= $id ? clean($sub['nombre']) : 'Nueva' ?></span>
</div>

<div class="page-header"><div class="page-header-left"><h1><?= $id ? 'Subreceta · '.clean($sub['nombre']) : 'Nueva subreceta' ?></h1>
  <p>Una preparación base (salsa, masa, aderezo) que luego usás en varias recetas</p></div></div>

<form method="post">
  <?= csrfField() ?>
  <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">
    <div class="card"><div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 130px 90px;gap:12px;margin-bottom:16px">
        <div class="form-group" style="margin:0"><label>Nombre</label>
          <input type="text" name="nombre" value="<?= $id ? clean($sub['nombre']) : '' ?>" required></div>
        <div class="form-group" style="margin:0"><label>Rendimiento</label>
          <input type="text" inputmode="decimal" name="rendimiento" id="sr-rend" value="<?= $id ? nf($sub['rendimiento']) : '1' ?>" oninput="recalc()"></div>
        <div class="form-group" style="margin:0"><label>Unidad</label>
          <select name="unidad">
            <?php foreach (['unidad','g','kg','ml','l','porcion','lonja'] as $u): ?>
              <option value="<?= $u ?>"<?= ($id && $sub['unidad']===$u) ? ' selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>

      <?php if (subrecetaStockListo()): ?>
      <label style="display:flex;align-items:center;gap:9px;margin:4px 0 16px;cursor:pointer;font-size:14px">
        <input type="checkbox" name="lleva_stock" value="1" <?= ($id && !empty($sub['lleva_stock'])) ? 'checked' : '' ?> style="width:18px;height:18px">
        <span><strong>Se produce y lleva stock.</strong> Se prepara por lote (consume insumos) y se descuenta de su propio stock al vender. Si lo dejás apagado, la subreceta explota a insumos como hasta ahora.</span>
      </label>
      <?php endif; ?>

      <label style="font-size:13px;font-weight:700;color:var(--text-muted)">Insumos de la preparación</label>
      <div class="rec-head" style="margin-top:8px">
        <span>Insumo</span><span class="rh-r">Cantidad</span><span class="rh-r">Precio</span><span class="rh-r">Costo</span><span></span>
      </div>
      <div id="rec-rows">
        <?php foreach ($items as $r): ?>
        <div class="rec-row">
          <span class="rec-nm"><?= clean($r['nombre']) ?> <span class="rec-um">(<?= clean($r['unidad']) ?>)</span></span>
          <input type="hidden" name="insumo_id[]" value="<?= (int)$r['insumo_id'] ?>" data-costo="<?= (float)$r['costo_unitario'] ?>">
          <input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="<?= nf($r['cantidad']) ?>" oninput="recalc()">
          <span class="rec-precio">S/ <?= number_format((float)$r['costo_unitario'], 2) ?></span>
          <span class="rec-costo">S/ 0.00</span>
          <button type="button" class="rec-del" onclick="this.closest('.rec-row').remove();recalc()">✕</button>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="add-wrap" style="position:relative;margin-top:8px">
        <input type="text" id="rec-add" autocomplete="off" placeholder="Agregar insumo (busca o crea)…"
               oninput="recBuscar(this.value)" onfocus="recBuscar(this.value)"
               style="width:100%;padding:11px 13px;border:1.5px dashed #c9c9d2;border-radius:10px">
        <div id="rec-drop" style="display:none;position:absolute;left:0;right:0;top:48px;background:#fff;border:1px solid var(--border,#eee);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:30;overflow:hidden"></div>
      </div>

      <div style="display:flex;gap:12px;margin-top:18px">
        <button type="submit" class="btn btn-primary">Guardar subreceta</button>
        <a href="<?= APP_URL ?>/admin/inventory/subrecetas.php" class="btn btn-ghost">Cancelar</a>
      </div>
    </div></div>

    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="card"><div class="card-body" style="text-align:center">
        <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Costo total</div>
        <div id="costoTotal" style="font-size:26px;font-weight:800;margin-top:4px">S/ 0.00</div>
        <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-top:12px">Costo por unidad</div>
        <div id="costoUM" style="font-size:26px;font-weight:800;color:var(--black,#1E1E1E);margin-top:4px">S/ 0.00</div>
      </div></div>
    </div>
  </div>
</form>

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
    document.getElementById('rec-drop').style.display='none'; document.getElementById('rec-add').value=''; return;
  }
  const row = document.createElement('div'); row.className='rec-row';
  row.innerHTML = '<span class="rec-nm">'+nombre+' <span class="rec-um">('+(unidad||'')+')</span></span>'+
    '<input type="hidden" name="insumo_id[]" value="'+id+'" data-costo="'+costo+'">'+
    '<input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="1" oninput="recalc()">'+
    '<span class="rec-precio">S/ '+costo.toFixed(2)+'</span>'+
    '<span class="rec-costo">S/ 0.00</span>'+
    '<button type="button" class="rec-del" onclick="this.closest(\'.rec-row\').remove();recalc()">✕</button>';
  document.getElementById('rec-rows').appendChild(row);
  document.getElementById('rec-add').value='';
  document.getElementById('rec-drop').style.display='none';
  recalc();
}

function insAbrir(nome){
  insPend = nome;
  document.getElementById('ins-name').textContent = nome;
  document.getElementById('rec-drop').style.display='none';
  document.getElementById('ins-ov').style.display='flex';
}
function insCerrar(){ document.getElementById('ins-ov').style.display='none'; }
function insCrear(){
  const body = new URLSearchParams({action:'crear', nombre:insPend, unidad:document.getElementById('ins-unidad').value, tipo:'ingrediente', costo_unitario:document.getElementById('ins-costo').value||'0'});
  fetch(INS_API, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF}, body})
    .then(r=>r.json()).then(d=>{
      if(d.ok){ recAgregar(d.insumo.id, d.insumo.nombre, d.insumo.unidad, parseFloat(d.insumo.costo_unitario)||0); document.getElementById('ins-costo').value=''; insCerrar(); }
      else { alert(d.error||'No se pudo crear'); }
    });
}

function recalc(){
  let total = 0;
  document.querySelectorAll('#rec-rows .rec-row').forEach(function(row){
    const hid = row.querySelector('input[name="insumo_id[]"]');
    const q = parseFloat(row.querySelector('.rec-q').value) || 0;
    const costo = hid ? parseFloat(hid.dataset.costo)||0 : 0;
    const rc = costo * q;
    total += rc;
    const cc = row.querySelector('.rec-costo'); if (cc) cc.textContent = 'S/ ' + rc.toFixed(2);
  });
  const rend = parseFloat(document.getElementById('sr-rend').value) || 0;
  document.getElementById('costoTotal').textContent = 'S/ ' + total.toFixed(2);
  document.getElementById('costoUM').textContent = 'S/ ' + (rend > 0 ? (total/rend) : 0).toFixed(2);
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
      <div class="form-group"><label>Costo por unidad (opcional)</label><input id="ins-costo" inputmode="decimal" placeholder="0.00"></div>
    </div>
    <div style="display:flex;gap:8px;padding:0 16px 16px">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="insCerrar()">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="insCrear()">Crear y agregar</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
