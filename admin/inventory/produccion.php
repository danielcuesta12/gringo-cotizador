<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_stock');
$ready = inventarioListo() && subrecetaStockListo();

$ubicaciones = $ready ? ubicacionesConInventario() : [];
$ubiF = cleanInt($_GET['ubi'] ?? ($_POST['ubicacion_id'] ?? 0)) ?: ($ubicaciones[0]['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    verifyCsrf();
    $ubi   = cleanInt($_POST['ubicacion_id'] ?? 0);
    $subId = cleanInt($_POST['subreceta_id'] ?? 0);
    $lotes = cleanFloat($_POST['lotes'] ?? 0);
    $res = subProducir($ubi, $subId, $lotes);
    if ($res['ok']) {
        $prod = rtrim(rtrim(number_format($res['producido'], 3, '.', ''), '0'), '.');
        flashMessage('success', "Producción registrada: +$prod de stock.");
    } else {
        flashMessage('error', $res['error'] ?: 'No se pudo registrar la producción.');
    }
    redirect('/admin/inventory/produccion.php?ubi=' . $ubi);
}

$subs = $ready ? Database::fetchAll(
    "SELECT id, nombre, unidad, rendimiento FROM subrecetas WHERE activo=1 AND lleva_stock=1 ORDER BY nombre"
) : [];
// Items por subreceta (para el preview de consumo)
$itemsBySub = [];
if ($subs) {
    foreach (Database::fetchAll(
        "SELECT si.subreceta_id, si.cantidad, i.nombre, i.unidad
           FROM subreceta_items si JOIN insumos i ON i.id=si.insumo_id
          WHERE si.subreceta_id IN (" . implode(',', array_map(fn($s) => (int)$s['id'], $subs)) . ")") as $r) {
        $itemsBySub[(int)$r['subreceta_id']][] = ['nombre'=>$r['nombre'],'unidad'=>$r['unidad'],'cantidad'=>(float)$r['cantidad']];
    }
}
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = 'Producción';
$activePage = 'inv-produccion';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left">
  <h1>Producción de subrecetas</h1>
  <p>Preparar un lote consume insumos y suma stock de la subreceta en este local</p>
</div></div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <h3>Falta aplicar la migración</h3>
    <p>Aplica <code>install/61_subrecetas_stock.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php elseif (empty($subs)): ?>
  <div class="card"><div class="empty-state">
    <h3>No hay subrecetas con stock</h3>
    <p>Marca «Se produce y lleva stock» en una subreceta para poder producirla.</p>
  </div></div>
<?php else: ?>

<form method="post" class="card" style="max-width:560px"><div class="card-body">
  <?= csrfField() ?>
  <div class="form-group"><label>Local de producción</label>
    <select name="ubicacion_id">
      <?php foreach ($ubicaciones as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $ubiF==$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option>
      <?php endforeach; ?>
    </select></div>

  <div class="form-group"><label>Subreceta</label>
    <select name="subreceta_id" id="pr-sub" onchange="prPreview()">
      <?php foreach ($subs as $s): ?>
        <option value="<?= (int)$s['id'] ?>" data-rend="<?= (float)$s['rendimiento'] ?>" data-unidad="<?= clean($s['unidad']) ?>"><?= clean($s['nombre']) ?> (rinde <?= nf($s['rendimiento']) ?> <?= clean($s['unidad']) ?>/lote)</option>
      <?php endforeach; ?>
    </select></div>

  <div class="form-group"><label>Lotes a preparar</label>
    <input type="text" inputmode="decimal" name="lotes" id="pr-lotes" value="1" oninput="prPreview()" style="max-width:140px"></div>

  <div id="pr-preview" style="background:#fafafb;border:1px solid var(--border,#eee);border-radius:10px;padding:14px;margin:6px 0 16px;font-size:13px"></div>

  <button type="submit" class="btn btn-primary">Registrar producción</button>
</div></form>

<script>
const PR_ITEMS = <?= json_encode($itemsBySub, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
function prPreview(){
  const sel = document.getElementById('pr-sub');
  const opt = sel.options[sel.selectedIndex];
  const rend = parseFloat(opt.dataset.rend)||0;
  const unidad = opt.dataset.unidad||'';
  const lotes = parseFloat(document.getElementById('pr-lotes').value)||0;
  const items = PR_ITEMS[sel.value] || [];
  let html = '<strong>Producirá:</strong> ' + (rend*lotes).toFixed(3).replace(/\.?0+$/,'') + ' ' + unidad + ' de stock<br><strong>Consume:</strong>';
  if (!items.length) { html += ' (sin insumos)'; }
  else {
    html += '<ul style="margin:6px 0 0;padding-left:18px">';
    items.forEach(function(it){ html += '<li>' + it.nombre + ': ' + (it.cantidad*lotes).toFixed(3).replace(/\.?0+$/,'') + ' ' + it.unidad + '</li>'; });
    html += '</ul>';
  }
  document.getElementById('pr-preview').innerHTML = html;
}
prPreview();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
