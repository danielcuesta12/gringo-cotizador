<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }
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
.rec-row select{flex:1}
.rec-row .rec-q{width:120px}
.rec-row .rec-u{width:48px;font-size:12px;color:var(--text-muted)}
.rec-row .rec-del{background:none;border:none;color:#dc2626;cursor:pointer;padding:6px;flex-shrink:0}
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

<?php if (empty($insumos)): ?>
  <div class="card"><div class="empty-state"><h3>No hay insumos</h3><p>Crea insumos primero en la sección «Insumos».</p>
    <a href="<?= APP_URL ?>/admin/inventory/insumo_form.php" class="btn btn-primary">+ Nuevo insumo</a></div></div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">
  <div class="card"><div class="card-body">
    <form method="post">
      <?= csrfField() ?>
      <div id="recList">
        <?php if (empty($receta)) $receta = [['insumo_id'=>'','cantidad'=>'']]; ?>
        <?php foreach ($receta as $r): ?>
        <div class="rec-row">
          <select name="insumo_id[]" class="rec-i" onchange="recalc()">
            <option value="">— insumo —</option>
            <?php foreach ($insumos as $i): ?>
              <option value="<?= $i['id'] ?>" data-costo="<?= $i['costo_unitario'] ?>" data-unidad="<?= clean($i['unidad']) ?>" <?= $r['insumo_id']==$i['id']?'selected':'' ?>><?= clean($i['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="<?= $r['cantidad']!=='' ? nf($r['cantidad']) : '' ?>" placeholder="cant." oninput="recalc()">
          <span class="rec-u"></span>
          <button type="button" class="rec-del" onclick="this.closest('.rec-row').remove();recalc()" title="Quitar"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" onclick="addRow()" style="margin-top:4px;gap:6px">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Agregar insumo
      </button>
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
function addRow(){
  var row = document.querySelector('#recList .rec-row');
  var clone = row.cloneNode(true);
  clone.querySelector('select').value = '';
  clone.querySelector('.rec-q').value = '';
  clone.querySelector('.rec-u').textContent = '';
  document.getElementById('recList').appendChild(clone);
  recalc();
}
function recalc(){
  var total = 0;
  document.querySelectorAll('#recList .rec-row').forEach(function(row){
    var sel = row.querySelector('select'); var q = parseFloat(row.querySelector('.rec-q').value) || 0;
    var opt = sel.options[sel.selectedIndex];
    var costo = opt ? parseFloat(opt.dataset.costo)||0 : 0;
    var uni = opt ? (opt.dataset.unidad||'') : '';
    row.querySelector('.rec-u').textContent = uni;
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
recalc();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
