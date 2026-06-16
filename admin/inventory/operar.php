<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_stock');

$ready       = inventarioListo();
$ubicaciones = $ready ? ubicacionesConInventario() : [];
$ubiF        = cleanInt($_GET['ubi'] ?? $_POST['ubicacion_id'] ?? 0) ?: ($ubicaciones[0]['id'] ?? 0);
$modo        = $_GET['modo'] ?? $_POST['modo'] ?? 'ingresos';
if (!in_array($modo, ['ingresos','salidas','conteo'], true)) $modo = 'ingresos';

// Ubicación actual + si es almacén
$ubiActual = null;
foreach ($ubicaciones as $u) { if ((int)$u['id'] === (int)$ubiF) { $ubiActual = $u; break; } }
$esAlmacen = $ubiActual ? ((int)($ubiActual['es_almacen'] ?? 0) === 1) : false;
$idsValidos = array_map(fn($u) => (int)$u['id'], $ubicaciones);

function _back($ubi, $modo) { redirect('/admin/inventory/operar.php?ubi=' . (int)$ubi . '&modo=' . $modo); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready && $ubiF) {
    verifyCsrf();
    $cant = is_array($_POST['cant'] ?? null) ? $_POST['cant'] : [];

    // Mapa de stock actual de esta ubicación (para validar y para conteo)
    $stockMap = [];
    foreach (Database::fetchAll("SELECT insumo_id, stock FROM insumo_stock WHERE ubicacion_id=?", [$ubiF]) as $s) {
        $stockMap[(int)$s['insumo_id']] = (float)$s['stock'];
    }

    if ($modo === 'ingresos') {
        $n = 0;
        foreach ($cant as $iid => $v) {
            $iid = (int)$iid; $v = cleanFloat($v);
            if ($iid > 0 && $v > 0) { invMovimiento($ubiF, $iid, 'ingreso', $v, ['motivo' => 'Ingreso · recepción']); $n++; }
        }
        flashMessage($n ? 'success' : 'error', $n ? "Ingresos registrados: $n insumo(s)." : 'No ingresaste cantidades.');
        _back($ubiF, 'ingresos');
    }

    if ($modo === 'salidas') {
        if ($esAlmacen) {
            $destino = cleanInt($_POST['destino'] ?? 0);
            if ($destino <= 0 || $destino === (int)$ubiF || !in_array($destino, $idsValidos, true)) {
                flashMessage('error', 'Elige un destino válido para el despacho.');
                _back($ubiF, 'salidas');
            }
            $items = []; $faltan = 0;
            foreach ($cant as $iid => $v) {
                $iid = (int)$iid; $v = cleanFloat($v);
                if ($iid <= 0 || $v <= 0) continue;
                if ($v > ($stockMap[$iid] ?? 0) + 0.0001) { $faltan++; continue; }
                $items[$iid] = $v;
            }
            if ($faltan) {
                flashMessage('error', "No hay stock suficiente en $faltan insumo(s). No se despachó nada — corrige las cantidades.");
                _back($ubiF, 'salidas');
            }
            if (!$items) { flashMessage('error', 'No ingresaste cantidades.'); _back($ubiF, 'salidas'); }
            $ref = invTransferir($ubiF, $destino, $items, 'Despacho');
            flashMessage($ref ? 'success' : 'error', $ref ? 'Despacho realizado: ' . count($items) . ' insumo(s) transferido(s).' : 'No se pudo completar el despacho.');
            _back($ubiF, 'salidas');
        } else {
            $n = 0; $faltan = 0;
            foreach ($cant as $iid => $v) {
                $iid = (int)$iid; $v = cleanFloat($v);
                if ($iid <= 0 || $v <= 0) continue;
                if ($v > ($stockMap[$iid] ?? 0) + 0.0001) { $faltan++; continue; }
                invMovimiento($ubiF, $iid, 'merma', -$v, ['motivo' => 'Salida / merma']); $n++;
            }
            if ($faltan) flashMessage('error', "$faltan insumo(s) sin stock suficiente no se registraron.");
            if ($n)      flashMessage('success', "Salidas registradas: $n insumo(s).");
            if (!$n && !$faltan) flashMessage('error', 'No ingresaste cantidades.');
            _back($ubiF, 'salidas');
        }
    }

    if ($modo === 'conteo') {
        $n = 0;
        foreach ($cant as $iid => $v) {
            $iid = (int)$iid;
            if ($iid <= 0 || trim((string)$v) === '') continue;
            $real  = cleanFloat($v);
            $delta = round($real - ($stockMap[$iid] ?? 0), 3);
            if (abs($delta) >= 0.001) { invMovimiento($ubiF, $iid, 'ajuste', $delta, ['motivo' => 'Conteo de inventario']); $n++; }
        }
        flashMessage('success', "Conteo guardado: $n ajuste(s).");
        _back($ubiF, 'conteo');
    }
}

// Datos para render
$insumos = ($ready && $ubiF) ? Database::fetchAll(
    "SELECT i.id, i.nombre, i.unidad, i.tipo, COALESCE(s.stock,0) stock
     FROM insumos i
     LEFT JOIN insumo_stock s ON s.insumo_id = i.id AND s.ubicacion_id = ?
     WHERE i.activo = 1 ORDER BY i.nombre",
    [$ubiF]
) : [];
$destinos = array_values(array_filter($ubicaciones, fn($u) => (int)$u['id'] !== (int)$ubiF));

function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$MODOS = [
    'ingresos' => ['Ingresos', 'Cantidad recibida', 'Guardar ingresos', '#1f9d55'],
    'salidas'  => ['Salidas',  'Cantidad salida',   ($esAlmacen ? 'Despachar' : 'Guardar salidas'), '#d64545'],
    'conteo'   => ['Conteo',   'Stock real (conteo)', 'Guardar conteo', '#d9920a'],
];

$pageTitle  = 'Operar inventario';
$activePage = 'inv-operar';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left">
  <h1>Operar inventario</h1>
  <p>Ingresos, salidas (despacho) y conteo, por ubicación</p>
</div></div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <h3>Falta crear el módulo de inventario</h3>
    <p>Aplica <code>install/inventario.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php elseif (empty($ubicaciones)): ?>
  <div class="card"><div class="empty-state"><h3>Sin ubicaciones</h3><p>Crea una ubicación primero.</p></div></div>
<?php else: ?>

<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
  <select onchange="location.href='?ubi='+this.value+'&modo=<?= $modo ?>'" style="padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-weight:700;background:#fff">
    <?php foreach ($ubicaciones as $u): ?>
      <option value="<?= (int)$u['id'] ?>" <?= $ubiF==$u['id']?'selected':'' ?>><?= !empty($u['es_almacen']) ? '🏬 ' : '🍔 ' ?><?= clean($u['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($esAlmacen): ?>
    <span class="badge" style="background:var(--brand);color:#1e1e1e">Almacén central · no vende</span>
  <?php else: ?>
    <span class="badge badge-secondary" style="background:#FFBBC8;color:#1e1e1e">Restaurante</span>
  <?php endif; ?>
</div>

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach ($MODOS as $key => $m): $on = $modo===$key; ?>
    <a href="?ubi=<?= (int)$ubiF ?>&modo=<?= $key ?>"
       style="flex:1;min-width:130px;text-align:center;padding:12px;border-radius:12px;font-weight:800;font-size:14px;text-decoration:none;border:2px solid <?= $on?$m[3]:'var(--border)' ?>;color:<?= $on?$m[3]:'var(--text-secondary)' ?>;background:<?= $on?($m[3].'14'):'#fff' ?>">
      <?= $m[0] ?>
    </a>
  <?php endforeach; ?>
</div>

<form method="post" id="opForm">
  <?= csrfField() ?>
  <input type="hidden" name="ubicacion_id" value="<?= (int)$ubiF ?>">
  <input type="hidden" name="modo" value="<?= $modo ?>">

  <?php if ($modo === 'salidas' && $esAlmacen): ?>
    <div class="card" style="margin-bottom:14px;border-left:3px solid #d9920a"><div class="card-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <strong>Despachar a:</strong>
      <select name="destino" required style="padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-weight:700;background:#fff">
        <option value="">— elige restaurante —</option>
        <?php foreach ($destinos as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= clean($d['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <span style="font-size:13px;color:var(--text-muted)">El stock baja aquí y <strong>sube</strong> en el destino (transferencia enlazada).</span>
    </div></div>
  <?php endif; ?>

  <div class="card">
    <?php if (empty($insumos)): ?>
      <div class="empty-state"><h3>Sin insumos activos</h3><p>Crea insumos en la sección «Insumos».</p></div>
    <?php else: ?>
    <div class="table-wrap" style="border:none;border-radius:0">
      <table class="data-table">
        <thead><tr>
          <th>Insumo</th><th>Tipo</th><th>Stock actual</th><th><?= $MODOS[$modo][1] ?></th>
          <?php if ($modo==='conteo'): ?><th>Diferencia</th><?php endif; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($insumos as $it): $desc = ($it['tipo'] ?? '') === 'descartable'; ?>
          <tr>
            <td><strong><?= clean($it['nombre']) ?></strong></td>
            <td><span class="badge <?= $desc?'badge-info':'badge-secondary' ?>" style="font-size:10px"><?= $desc?'descartable':'ingrediente' ?></span></td>
            <td class="op-stock" data-stock="<?= (float)$it['stock'] ?>"><?= nf($it['stock']) ?> <span style="color:var(--text-muted);font-size:12px"><?= clean($it['unidad']) ?></span></td>
            <td><input type="text" inputmode="decimal" name="cant[<?= (int)$it['id'] ?>]" class="op-input" data-id="<?= (int)$it['id'] ?>" placeholder="0" style="width:96px;padding:8px;border:1.5px solid var(--border);border-radius:8px;text-align:right"></td>
            <?php if ($modo==='conteo'): ?><td class="op-diff" data-id="<?= (int)$it['id'] ?>" style="font-weight:800;color:var(--text-muted)">—</td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:12px;padding:14px 16px;border-top:1px solid var(--border);align-items:center">
      <span id="opResumen" style="font-size:13px;color:var(--text-secondary)"></span>
      <button type="submit" class="btn btn-primary" style="margin-left:auto;background:<?= $MODOS[$modo][3] ?>;border-color:<?= $MODOS[$modo][3] ?>"><?= $MODOS[$modo][2] ?></button>
    </div>
    <?php endif; ?>
  </div>
</form>

<script>
(function(){
  var modo = <?= json_encode($modo) ?>;
  var inputs = Array.prototype.slice.call(document.querySelectorAll('.op-input'));
  var resumen = document.getElementById('opResumen');
  function fnum(v){ var n = parseFloat(String(v).replace(',','.')); return isNaN(n)?null:n; }
  function refresh(){
    var n = inputs.filter(function(i){ return i.value.trim()!=='' && fnum(i.value)!==null; }).length;
    if(resumen) resumen.innerHTML = '<strong>'+n+'</strong> insumo(s) con '+(modo==='conteo'?'conteo':'cantidad')+'.';
  }
  inputs.forEach(function(inp){
    inp.addEventListener('input', function(){
      if(modo==='conteo'){
        var cell = document.querySelector('.op-diff[data-id="'+inp.dataset.id+'"]');
        var stock = parseFloat(inp.closest('tr').querySelector('.op-stock').dataset.stock)||0;
        var v = fnum(inp.value);
        if(v===null || inp.value.trim()===''){ cell.textContent='—'; cell.style.color='var(--text-muted)'; }
        else { var d = Math.round((v-stock)*1000)/1000; cell.textContent=(d>0?'+':'')+d; cell.style.color = d>0?'#1f9d55':(d<0?'#d64545':'var(--text-muted)'); }
      }
      refresh();
    });
  });
  refresh();
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
