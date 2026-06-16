<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_compras');
if (!comprasListo()) { flashMessage('error', 'Aplica install/inventario_c.sql primero.'); redirect('/admin/inventory/compras.php'); }

$ubicaciones = ubicacionesConInventario();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $provId = cleanInt($_POST['proveedor_id'] ?? 0) ?: null;
    $ubiId  = cleanInt($_POST['ubicacion_id'] ?? 0);
    $fecha  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
    $nota   = clean($_POST['nota'] ?? '');
    $ins    = $_POST['insumo_id'] ?? [];
    $cant   = $_POST['cantidad'] ?? [];
    $cost   = $_POST['costo_unitario'] ?? [];

    $lineas = [];
    foreach ($ins as $i => $iid) {
        $iid = (int)$iid; $c = (float)($cant[$i] ?? 0); $cu = (float)($cost[$i] ?? 0);
        if ($iid <= 0 || $c <= 0) continue;
        $lineas[] = ['insumo_id'=>$iid, 'cantidad'=>$c, 'costo'=>$cu, 'subtotal'=>round($c*$cu, 2)];
    }
    if (!$ubiId)        $errors[] = 'Elige la ubicación que recibe la compra.';
    if (empty($lineas)) $errors[] = 'Agrega al menos un insumo con cantidad.';

    if (empty($errors)) {
        $total = array_sum(array_column($lineas, 'subtotal'));
        $compraId = Database::insert(
            "INSERT INTO compras (proveedor_id,ubicacion_id,fecha,total,nota,user_id) VALUES (?,?,?,?,?,?)",
            [$provId, $ubiId, $fecha, $total, $nota ?: null, currentUser()['id'] ?? null]
        );
        foreach ($lineas as $l) {
            Database::insert("INSERT INTO compra_items (compra_id,insumo_id,cantidad,costo_unitario,subtotal) VALUES (?,?,?,?,?)",
                [$compraId, $l['insumo_id'], $l['cantidad'], $l['costo'], $l['subtotal']]);
            invEntradaCompra($ubiId, $l['insumo_id'], $l['cantidad'], $l['costo'], ['ref' => 'Compra #'.$compraId, 'motivo' => 'Compra #'.$compraId]);
        }
        flashMessage('success', 'Compra registrada: stock actualizado y costos recalculados.');
        redirect('/admin/inventory/compras.php');
    }
}

$pageTitle  = 'Nueva compra';
$activePage = 'inv-compras';
$extraHead  = '<style>
.cp-row{display:flex;gap:8px;margin-bottom:8px;align-items:center}
.cp-ins{flex:1;position:relative;min-width:0}.cp-row .cp-q{width:90px}.cp-row .cp-c{width:100px}.cp-row .cp-s{width:90px;text-align:right;font-weight:600;font-size:13px}
.cp-row .cp-del{background:none;border:none;color:#dc2626;cursor:pointer;padding:6px}
.ac-drop{display:none;position:absolute;left:0;right:0;top:100%;z-index:60;background:var(--card-bg,#fff);border:1px solid var(--border,#e7e7ec);border-radius:8px;box-shadow:0 10px 28px rgba(0,0,0,.14);max-height:240px;overflow-y:auto;margin-top:2px}
.ac-opt{padding:9px 12px;cursor:pointer;font-size:13.5px;display:flex;justify-content:space-between;gap:8px;border-bottom:1px solid var(--border,#f0f0f3)}
.ac-opt:last-child{border-bottom:none}.ac-opt:hover{background:#fafafb}.ac-opt .ac-u{color:var(--text-muted);font-size:12px}
.ac-create{color:#9a6b08;font-weight:700;background:#fffaf0}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/inventory/compras.php">Compras</a>
  <span class="breadcrumb-sep">›</span><span class="breadcrumb-current">Nueva</span>
</div>

<div class="page-header"><div class="page-header-left"><h1>Nueva compra</h1>
  <p>Registra una entrada de insumos. Recalcula el costo promedio ponderado de cada insumo.</p></div></div>

<?php foreach ($errors as $e): ?><div class="alert alert-error">✗ <?= $e ?></div><?php endforeach; ?>

<form method="post"><div class="card" style="max-width:760px"><div class="card-body">
  <?= csrfField() ?>
  <div class="form-row form-row-3">
    <div class="form-group"><label>Proveedor</label>
      <div class="cp-ins">
        <input type="text" id="provQ" placeholder="Buscar o crear proveedor…" autocomplete="off" oninput="provSearch(this.value)" onfocus="provSearch(this.value)" style="width:100%;box-sizing:border-box">
        <input type="hidden" name="proveedor_id" id="provId">
        <div id="provDrop" class="ac-drop"></div>
      </div>
    </div>
    <div class="form-group"><label class="form-required">Ubicación que recibe</label>
      <select name="ubicacion_id" required>
        <?php foreach ($ubicaciones as $u): ?><option value="<?= (int)$u['id'] ?>"><?= clean($u['nombre']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
  </div>

  <div class="form-group"><label>Insumos comprados <small style="font-weight:400;color:var(--text-muted)">— busca o crea cada insumo</small></label>
    <div id="cpList">
      <div class="cp-row">
        <div class="cp-ins">
          <input type="text" class="cp-ins-q" placeholder="Buscar o crear insumo…" autocomplete="off" oninput="insSearch(this)" onfocus="insSearch(this)" style="width:100%;box-sizing:border-box">
          <input type="hidden" name="insumo_id[]" class="cp-ins-id">
          <div class="ac-drop cp-ins-drop"></div>
        </div>
        <input type="text" inputmode="decimal" name="cantidad[]" class="cp-q" placeholder="cant." oninput="calc()">
        <input type="text" inputmode="decimal" name="costo_unitario[]" class="cp-c" placeholder="costo u." oninput="calc()">
        <span class="cp-s">S/0.00</span>
        <button type="button" class="cp-del" onclick="this.closest('.cp-row').remove();calc()"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
      <button type="button" class="btn btn-ghost btn-sm" onclick="addRow()" style="gap:6px"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Agregar fila</button>
    </div>
  </div>

  <div class="form-group"><label>Nota <small style="font-weight:400;color:var(--text-muted)">(opcional)</small></label><input type="text" name="nota" placeholder="Ej: factura 0123"></div>

  <div style="display:flex;justify-content:space-between;align-items:center;border-top:2px solid var(--border);padding-top:14px;margin-top:6px">
    <span style="font-size:15px;font-weight:700">Total compra</span>
    <span id="cpTotal" style="font-size:22px;font-weight:800">S/ 0.00</span>
  </div>

  <div style="display:flex;gap:12px;margin-top:16px">
    <button type="submit" class="btn btn-primary" style="gap:6px"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Registrar compra</button>
    <a href="<?= APP_URL ?>/admin/inventory/compras.php" class="btn btn-ghost">Cancelar</a>
  </div>
</div></div></form>

<script>
const INS_API  = '<?= APP_URL ?>/api/insumos.php';
const PROV_API = '<?= APP_URL ?>/api/proveedores.php';
const CSRF     = '<?= csrfToken() ?>';

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function fnum(v){ var n=parseFloat(v); return isNaN(n)?0:n; }
function nfCost(v){ return parseFloat(v).toFixed(4).replace(/0+$/,'').replace(/\.$/,''); }

/* ---------- Proveedor (búsqueda en vivo) ---------- */
function provSearch(val){
  val=(val||'').trim();
  var drop=document.getElementById('provDrop');
  document.getElementById('provId').value=''; // al editar el texto, se des-selecciona hasta volver a elegir
  if(!val){ drop.style.display='none'; return; }
  fetch(PROV_API+'?action=buscar&q='+encodeURIComponent(val)).then(function(r){return r.json();}).then(function(d){
    drop.innerHTML='';
    (d.items||[]).forEach(function(p){
      var o=document.createElement('div'); o.className='ac-opt';
      var n=document.createElement('span'); n.textContent=p.nombre; o.appendChild(n);
      o.addEventListener('click',function(){ provPick(p.id,p.nombre); });
      drop.appendChild(o);
    });
    var exacto=(d.items||[]).some(function(p){ return p.nombre.toLowerCase()===val.toLowerCase(); });
    if(!exacto){
      var c=document.createElement('div'); c.className='ac-opt ac-create'; c.textContent='+ Crear «'+val+'»';
      c.addEventListener('click',function(){ provCrear(val); });
      drop.appendChild(c);
    }
    drop.style.display='block';
  });
}
function provPick(id,nombre){
  document.getElementById('provId').value=id;
  document.getElementById('provQ').value=nombre;
  document.getElementById('provDrop').style.display='none';
}
function provCrear(nombre){
  document.getElementById('provDrop').style.display='none';
  inlineCreate({ title:'Nuevo proveedor', endpoint:PROV_API, action:'crear', csrf:CSRF,
    fields:[{key:'nombre',label:'Nombre',value:nombre},{key:'telefono',label:'Teléfono (opcional)',placeholder:'999 888 777',inputmode:'tel'}],
    onCreated:function(d){ provPick(d.proveedor.id, d.proveedor.nombre); } });
}

/* ---------- Insumo por fila (búsqueda en vivo) ---------- */
function insSearch(inp){
  var val=inp.value.trim();
  var row=inp.closest('.cp-row');
  var drop=row.querySelector('.cp-ins-drop');
  row.querySelector('.cp-ins-id').value='';
  if(!val){ drop.style.display='none'; return; }
  fetch(INS_API+'?action=buscar&q='+encodeURIComponent(val)).then(function(r){return r.json();}).then(function(d){
    drop.innerHTML='';
    (d.items||[]).forEach(function(i){
      var o=document.createElement('div'); o.className='ac-opt';
      var n=document.createElement('span'); n.textContent=i.nombre;
      var u=document.createElement('span'); u.className='ac-u'; u.textContent=i.unidad||'';
      o.appendChild(n); o.appendChild(u);
      o.addEventListener('click',function(){ insPick(row,i); });
      drop.appendChild(o);
    });
    var exacto=(d.items||[]).some(function(i){ return i.nombre.toLowerCase()===val.toLowerCase(); });
    if(!exacto){
      var c=document.createElement('div'); c.className='ac-opt ac-create'; c.textContent='+ Crear «'+val+'»';
      c.addEventListener('click',function(){ insCrear(row,val); });
      drop.appendChild(c);
    }
    drop.style.display='block';
  });
}
function insPick(row,i){
  row.querySelector('.cp-ins-id').value=i.id;
  row.querySelector('.cp-ins-q').value=i.nombre;
  row.querySelector('.cp-ins-drop').style.display='none';
  var cInput=row.querySelector('.cp-c');
  if(i.costo_unitario && !cInput.value) cInput.value=nfCost(i.costo_unitario);
  calc();
}
function insCrear(row,nombre){
  row.querySelector('.cp-ins-drop').style.display='none';
  inlineCreate({ title:'Nuevo insumo', endpoint:INS_API, action:'crear', csrf:CSRF,
    fields:[
      {key:'nombre', label:'Nombre', value:nombre},
      {key:'unidad', label:'Unidad', type:'select', value:'unidad', options:[
        {value:'unidad',label:'unidad'},{value:'g',label:'gramos (g)'},{value:'kg',label:'kg'},
        {value:'ml',label:'ml'},{value:'l',label:'l'},{value:'lonja',label:'lonja'},{value:'porcion',label:'porción'}]},
      {key:'tipo', label:'Tipo', type:'select', value:'ingrediente', options:[
        {value:'ingrediente',label:'Ingrediente'},{value:'descartable',label:'Descartable / papelería'}]},
      {key:'costo_unitario', label:'Costo por unidad (opcional)', placeholder:'0.00', inputmode:'decimal'}
    ],
    onCreated:function(d){ insPick(row, d.insumo); } });
}

function addRow(){
  var row = document.querySelector('#cpList .cp-row');
  var clone = row.cloneNode(true);
  clone.querySelectorAll('input').forEach(function(i){ i.value=''; });
  clone.querySelector('.cp-ins-drop').style.display='none';
  clone.querySelector('.cp-ins-drop').innerHTML='';
  clone.querySelector('.cp-s').textContent='S/0.00';
  document.getElementById('cpList').appendChild(clone);
}
function calc(){
  var total = 0;
  document.querySelectorAll('#cpList .cp-row').forEach(function(r){
    var q = fnum(r.querySelector('.cp-q').value);
    var c = fnum(r.querySelector('.cp-c').value);
    var s = q*c; total += s;
    r.querySelector('.cp-s').textContent = 'S/'+s.toFixed(2);
  });
  document.getElementById('cpTotal').textContent = 'S/ '+total.toFixed(2);
}

// Cerrar dropdowns al hacer clic fuera
document.addEventListener('click', function(e){
  if(!e.target.closest('.cp-ins')){
    document.querySelectorAll('.ac-drop').forEach(function(d){ d.style.display='none'; });
  }
});
</script>

<?php include __DIR__ . '/../inline-create.php'; ?>
<?php include __DIR__ . '/../layout-bottom.php'; ?>
