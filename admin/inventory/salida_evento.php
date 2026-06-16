<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_evento');
if (!inventarioListo()) { flashMessage('error', 'Aplica install/inventario.sql primero.'); redirect('/admin/inventory/stock.php'); }

$ubicaciones = Database::fetchAll("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, nombre");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $ubiId = cleanInt($_POST['ubicacion_id'] ?? 0);
    $ref   = clean($_POST['ref'] ?? '');
    $ins   = $_POST['insumo_id'] ?? [];
    $cant  = $_POST['cantidad'] ?? [];
    if (!$ubiId) { flashMessage('error', 'Elige una ubicación.'); redirect('/admin/inventory/salida_evento.php'); }

    $n = 0;
    foreach ($ins as $idx => $iid) {
        $iid = (int)$iid; $c = (float)($cant[$idx] ?? 0);
        if ($iid <= 0 || $c <= 0) continue;
        invMovimiento($ubiId, $iid, 'evento', -$c, ['ref' => $ref ?: null, 'motivo' => 'Salida evento' . ($ref ? ': ' . $ref : '')]);
        $n++;
    }
    flashMessage('success', "Salida de evento registrada: $n insumo(s) descontados del stock.");
    redirect('/admin/inventory/movimientos.php?tipo=evento');
}

// Datos para el explosionado en el navegador
$products = Database::fetchAll("SELECT id,name FROM products WHERE active=1 ORDER BY name");
$insumos  = Database::fetchAll("SELECT id,nombre,unidad,costo_unitario,tipo FROM insumos WHERE activo=1 ORDER BY nombre");
$recetas  = Database::fetchAll("SELECT product_id,insumo_id,cantidad FROM recetas");

$recByProd = [];
foreach ($recetas as $r) { $recByProd[(int)$r['product_id']][] = ['insumo_id'=>(int)$r['insumo_id'],'cantidad'=>(float)$r['cantidad']]; }
$insMap = [];
foreach ($insumos as $i) { $insMap[(int)$i['id']] = ['nombre'=>$i['nombre'],'unidad'=>$i['unidad'],'costo'=>(float)$i['costo_unitario'],'tipo'=>$i['tipo']]; }

$recMod = [];
try { foreach (Database::fetchAll("SELECT modificador_id,insumo_id,cantidad FROM receta_modificadores") as $r) { $recMod[(int)$r['modificador_id']][] = ['insumo_id'=>(int)$r['insumo_id'],'cantidad'=>(float)$r['cantidad']]; } } catch (\Throwable $e) { $recMod = []; }
$modsByProd = [];
try {
    $rows = Database::fetchAll("SELECT pmg.product_id, m.id, m.nombre FROM product_modifier_groups pmg JOIN modificadores m ON m.grupo_id = pmg.grupo_id WHERE EXISTS (SELECT 1 FROM receta_modificadores rm WHERE rm.modificador_id = m.id) ORDER BY m.nombre");
    foreach ($rows as $r) { $modsByProd[(int)$r['product_id']][] = ['id'=>(int)$r['id'],'nombre'=>$r['nombre']]; }
} catch (\Throwable $e) { $modsByProd = []; }

$pageTitle  = 'Salida a evento';
$activePage = 'inv-evento';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left">
  <h1>Salida a evento</h1>
  <p>Arma el evento por productos, explota a ingredientes y ajusta (ej. quita el tocino) antes de descontar del stock</p>
</div></div>

<?php if (empty($products) || empty($insumos)): ?>
  <div class="card"><div class="empty-state"><h3>Faltan datos</h3><p>Necesitas productos con receta e insumos cargados.</p></div></div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

  <!-- Paso 1: productos del evento -->
  <div class="card">
    <div class="card-header"><span class="card-title">1 · Productos del evento</span></div>
    <div class="card-body">
      <div class="form-row form-row-2">
        <div class="form-group" style="margin:0">
          <label>Ubicación (almacén)</label>
          <select id="ubiSel">
            <?php foreach ($ubicaciones as $u): ?><option value="<?= (int)$u['id'] ?>"><?= clean($u['nombre']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label>Referencia <small style="font-weight:400;color:var(--text-muted)">(opcional)</small></label>
          <input type="text" id="refInput" placeholder="Ej: Boda Pérez 12/06">
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:flex-end;margin-top:14px">
        <div class="form-group" style="margin:0;flex:1">
          <label>Producto</label>
          <select id="prodSel">
            <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= clean($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;width:90px"><label>Cant.</label><input type="text" inputmode="numeric" id="prodQty" value="1"></div>
        <button type="button" class="btn btn-ghost" onclick="addProd()" style="height:42px">Agregar</button>
      </div>
      <div id="prodList" style="margin-top:14px"></div>
      <button type="button" class="btn btn-primary btn-block" onclick="calcular()" style="margin-top:14px">Calcular ingredientes →</button>
    </div>
  </div>

  <!-- Paso 2: ingredientes editables -->
  <div class="card">
    <div class="card-header"><span class="card-title">2 · Ingredientes a descontar</span></div>
    <div class="card-body">
      <form method="post" id="evForm">
        <?= csrfField() ?>
        <input type="hidden" name="ubicacion_id" id="fUbi">
        <input type="hidden" name="ref" id="fRef">
        <div id="ingList"><p style="color:var(--text-muted);font-size:14px;margin:0">Agrega productos y pulsa «Calcular ingredientes».</p></div>
        <div id="ingFoot" style="display:none;margin-top:14px;padding-top:12px;border-top:2px solid var(--border)">
          <div style="display:flex;justify-content:space-between;font-size:15px;font-weight:700;margin-bottom:12px">
            <span>Costo total del evento</span><span id="costoTotal">S/ 0.00</span>
          </div>
          <button type="submit" class="btn btn-primary btn-block" style="gap:6px" data-confirm="¿Confirmar la salida? Se descontará del stock de la ubicación seleccionada.">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Confirmar salida y descontar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  var RECETAS = <?= json_encode($recByProd) ?>;
  var RECETAS_MOD = <?= json_encode($recMod) ?>;
  var MODS_BY_PROD = <?= json_encode($modsByProd) ?>;
  var INSUMOS = <?= json_encode($insMap) ?>;
  var PRODNAMES = {}; <?php foreach ($products as $p): ?>PRODNAMES[<?= (int)$p['id'] ?>] = <?= json_encode($p['name']) ?>;<?php endforeach; ?>
  var evProds = [];   // [{pid, qty, mods:[], excl:[]}]

  function addProd(){
    var pid = parseInt(document.getElementById('prodSel').value);
    var qty = parseFloat(document.getElementById('prodQty').value) || 0;
    if (!pid || qty <= 0) return;
    evProds.push({ pid: pid, qty: qty, mods: [], excl: [] });
    renderProds();
  }
  function rmProd(idx){ evProds.splice(idx,1); renderProds(); }
  function toggleMod(idx, mid, on){ var a=evProds[idx].mods; if(on){ if(a.indexOf(mid)<0)a.push(mid);} else { evProds[idx].mods=a.filter(function(m){return m!==mid;}); } }
  function toggleExcl(idx, iid, on){ var a=evProds[idx].excl; if(on){ if(a.indexOf(iid)<0)a.push(iid);} else { evProds[idx].excl=a.filter(function(x){return x!==iid;}); } }
  function renderProds(){
    var el = document.getElementById('prodList');
    if (!evProds.length){ el.innerHTML = '<p style="color:var(--text-muted);font-size:13px;margin:0">Sin productos aún.</p>'; return; }
    el.innerHTML = evProds.map(function(x, idx){
      var sinReceta = !RECETAS[x.pid] ? ' <span style="color:#dc2626;font-size:11px">(sin receta)</span>' : '';
      var html = '<div style="padding:8px 0;border-bottom:1px solid var(--border)">';
      html += '<div style="display:flex;justify-content:space-between;align-items:center;font-size:14px"><span><strong>'+x.qty+'×</strong> '+(PRODNAMES[x.pid]||('#'+x.pid))+sinReceta+'</span><button type="button" onclick="rmProd('+idx+')" style="background:none;border:none;color:#dc2626;cursor:pointer">✕</button></div>';
      var mods = MODS_BY_PROD[x.pid] || [];
      if (mods.length){ html += '<div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:10px">' + mods.map(function(m){ var chk = x.mods.indexOf(m.id)>=0?'checked':''; return '<label style="font-size:12px;display:flex;gap:4px;align-items:center"><input type="checkbox" '+chk+' onchange="toggleMod('+idx+','+m.id+',this.checked)"> +'+m.nombre+'</label>'; }).join('') + '</div>'; }
      var rec = RECETAS[x.pid] || [];
      if (rec.length){ html += '<details style="margin-top:5px"><summary style="font-size:12px;color:var(--text-muted);cursor:pointer">Quitar ingredientes</summary><div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:5px">' + rec.map(function(r){ var info=INSUMOS[r.insumo_id]||{nombre:'#'+r.insumo_id}; var chk=x.excl.indexOf(r.insumo_id)>=0?'checked':''; return '<label style="font-size:12px;display:flex;gap:4px;align-items:center"><input type="checkbox" '+chk+' onchange="toggleExcl('+idx+','+r.insumo_id+',this.checked)"> sin '+info.nombre+'</label>'; }).join('') + '</div></details>'; }
      html += '</div>'; return html;
    }).join('');
  }

  function calcular(){
    var agg = {};   // insumo_id -> cantidad
    evProds.forEach(function(x){
      (RECETAS[x.pid] || []).forEach(function(r){
        agg[r.insumo_id] = (agg[r.insumo_id] || 0) + r.cantidad * x.qty;
      });
    });
    var ids = Object.keys(agg);
    var list = document.getElementById('ingList');
    if (!ids.length){ list.innerHTML = '<p style="color:var(--text-muted);font-size:14px;margin:0">Esos productos no tienen receta definida.</p>'; document.getElementById('ingFoot').style.display='none'; return; }
    list.innerHTML = ids.map(function(iid){
      var info = INSUMOS[iid] || {nombre:'#'+iid, unidad:'', costo:0};
      var qty = Math.round(agg[iid]*1000)/1000;
      return '<div class="ev-row" data-iid="'+iid+'" data-costo="'+info.costo+'" style="display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)">'
        + '<span style="flex:1;font-size:14px">'+info.nombre+'</span>'
        + '<input type="hidden" name="insumo_id[]" value="'+iid+'">'
        + '<input type="text" inputmode="decimal" name="cantidad[]" value="'+qty+'" class="ev-q" style="width:90px;text-align:right" oninput="sumCost()">'
        + '<span style="width:36px;font-size:12px;color:var(--text-muted)">'+info.unidad+'</span>'
        + '<button type="button" onclick="this.closest(\'.ev-row\').remove();sumCost()" style="background:none;border:none;color:#dc2626;cursor:pointer">✕</button></div>';
    }).join('');
    document.getElementById('ingFoot').style.display = 'block';
    document.getElementById('fUbi').value = document.getElementById('ubiSel').value;
    document.getElementById('fRef').value = document.getElementById('refInput').value;
    sumCost();
  }
  function sumCost(){
    var total = 0;
    document.querySelectorAll('#ingList .ev-row').forEach(function(r){
      total += (parseFloat(r.dataset.costo)||0) * (parseFloat(r.querySelector('.ev-q').value)||0);
    });
    document.getElementById('costoTotal').textContent = 'S/ ' + total.toFixed(2);
  }
  renderProds();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
