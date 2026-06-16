<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_compras');
if (!comprasListo()) { flashMessage('error', 'Aplica install/inventario_c.sql primero.'); redirect('/admin/inventory/compras.php'); }

$proveedores = Database::fetchAll("SELECT id,nombre FROM proveedores WHERE activo=1 ORDER BY nombre");
$ubicaciones = ubicacionesConInventario();
$insumos     = Database::fetchAll("SELECT id,nombre,unidad,costo_unitario FROM insumos WHERE activo=1 ORDER BY nombre");
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
.cp-row select{flex:1}.cp-row .cp-q{width:90px}.cp-row .cp-c{width:100px}.cp-row .cp-s{width:90px;text-align:right;font-weight:600;font-size:13px}
.cp-row .cp-del{background:none;border:none;color:#dc2626;cursor:pointer;padding:6px}
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

<?php if (empty($insumos)): ?>
  <div class="card"><div class="empty-state"><h3>No hay insumos</h3><p>Crea insumos primero.</p></div></div>
<?php else: ?>
<form method="post"><div class="card" style="max-width:760px"><div class="card-body">
  <?= csrfField() ?>
  <div class="form-row form-row-3">
    <div class="form-group"><label>Proveedor</label>
      <div style="display:flex;gap:6px">
        <select name="proveedor_id" id="provSel" style="flex:1"><option value="">— sin proveedor —</option>
          <?php foreach ($proveedores as $p): ?><option value="<?= (int)$p['id'] ?>"><?= clean($p['nombre']) ?></option><?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-ghost" onclick="nuevoProveedor()" title="Nuevo proveedor" style="padding:0 12px;font-size:18px;line-height:1">+</button>
      </div>
    </div>
    <div class="form-group"><label class="form-required">Ubicación que recibe</label>
      <select name="ubicacion_id" required>
        <?php foreach ($ubicaciones as $u): ?><option value="<?= (int)$u['id'] ?>"><?= clean($u['nombre']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Fecha</label><input type="date" name="fecha" value="<?= date('Y-m-d') ?>"></div>
  </div>

  <div class="form-group"><label>Insumos comprados</label>
    <div id="cpList">
      <div class="cp-row">
        <select name="insumo_id[]" onchange="prefill(this)">
          <option value="">— insumo —</option>
          <?php foreach ($insumos as $i): ?><option value="<?= $i['id'] ?>" data-costo="<?= $i['costo_unitario'] ?>" data-unidad="<?= clean($i['unidad']) ?>"><?= clean($i['nombre']) ?></option><?php endforeach; ?>
        </select>
        <input type="text" inputmode="decimal" name="cantidad[]" class="cp-q" placeholder="cant." oninput="calc()">
        <input type="text" inputmode="decimal" name="costo_unitario[]" class="cp-c" placeholder="costo u." oninput="calc()">
        <span class="cp-s">S/0.00</span>
        <button type="button" class="cp-del" onclick="this.closest('.cp-row').remove();calc()"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px">
      <button type="button" class="btn btn-ghost btn-sm" onclick="addRow()" style="gap:6px"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Agregar fila</button>
      <button type="button" class="btn btn-ghost btn-sm" onclick="nuevoInsumo()" style="gap:6px"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Nuevo insumo</button>
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

function nuevoProveedor(){
  inlineCreate({
    title:'Nuevo proveedor', endpoint:PROV_API, action:'crear', csrf:CSRF,
    fields:[
      {key:'nombre',   label:'Nombre',              placeholder:'Ej: Distribuidora XYZ'},
      {key:'telefono', label:'Teléfono (opcional)', placeholder:'999 888 777', inputmode:'tel'}
    ],
    onCreated:function(d){
      var s=document.getElementById('provSel');
      var o=document.createElement('option'); o.value=d.proveedor.id; o.textContent=d.proveedor.nombre; o.selected=true;
      s.appendChild(o);
    }
  });
}

function nuevoInsumo(){
  inlineCreate({
    title:'Nuevo insumo', endpoint:INS_API, action:'crear', csrf:CSRF,
    fields:[
      {key:'nombre', label:'Nombre', placeholder:'Ej: Pan brioche'},
      {key:'unidad', label:'Unidad', type:'select', value:'unidad', options:[
        {value:'unidad',label:'unidad'},{value:'g',label:'gramos (g)'},{value:'kg',label:'kg'},
        {value:'ml',label:'ml'},{value:'l',label:'l'},{value:'lonja',label:'lonja'},{value:'porcion',label:'porción'}]},
      {key:'tipo', label:'Tipo', type:'select', value:'ingrediente', options:[
        {value:'ingrediente',label:'Ingrediente'},{value:'descartable',label:'Descartable / papelería'}]},
      {key:'costo_unitario', label:'Costo por unidad (opcional)', placeholder:'0.00', inputmode:'decimal'}
    ],
    onCreated:function(d){
      var ins=d.insumo;
      document.querySelectorAll('#cpList .cp-row select').forEach(function(sel){
        if (sel.querySelector('option[value="'+ins.id+'"]')) return;
        var o=document.createElement('option'); o.value=ins.id; o.textContent=ins.nombre;
        o.setAttribute('data-costo', ins.costo_unitario||0); o.setAttribute('data-unidad', ins.unidad||'');
        sel.appendChild(o);
      });
      var rows=document.querySelectorAll('#cpList .cp-row'), target=null;
      rows.forEach(function(r){ if(!target && !r.querySelector('select').value) target=r; });
      if(!target){ addRow(); rows=document.querySelectorAll('#cpList .cp-row'); target=rows[rows.length-1]; }
      var ts=target.querySelector('select'); ts.value=ins.id; prefill(ts);
    }
  });
}

function prefill(sel){
  var opt = sel.options[sel.selectedIndex];
  var row = sel.closest('.cp-row');
  var cInput = row.querySelector('.cp-c');
  if (opt && opt.dataset.costo && !cInput.value) cInput.value = parseFloat(opt.dataset.costo).toFixed(4).replace(/0+$/,'').replace(/\.$/,'');
  calc();
}
function addRow(){
  var row = document.querySelector('#cpList .cp-row');
  var clone = row.cloneNode(true);
  clone.querySelectorAll('input').forEach(function(i){i.value='';});
  clone.querySelector('select').value='';
  clone.querySelector('.cp-s').textContent='S/0.00';
  document.getElementById('cpList').appendChild(clone);
}
function calc(){
  var total = 0;
  document.querySelectorAll('#cpList .cp-row').forEach(function(r){
    var q = parseFloat(r.querySelector('.cp-q').value)||0;
    var c = parseFloat(r.querySelector('.cp-c').value)||0;
    var s = q*c; total += s;
    r.querySelector('.cp-s').textContent = 'S/'+s.toFixed(2);
  });
  document.getElementById('cpTotal').textContent = 'S/ '+total.toFixed(2);
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../inline-create.php'; ?>
<?php include __DIR__ . '/../layout-bottom.php'; ?>
