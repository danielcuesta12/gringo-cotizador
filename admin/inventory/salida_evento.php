<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_evento');
if (!inventarioListo()) { flashMessage('error', 'Aplica install/inventario.sql primero.'); redirect('/admin/inventory/stock.php'); }

$ubicaciones = ubicacionesConInventario();
// Cotizaciones de evento. Tolerante: si falta la migración 50, cae a la consulta simple.
try {
    $cotizaciones = Database::fetchAll("SELECT id, quote_number, event_date, evento_nombre FROM quotes WHERE (origin='event' OR status='aceptada') AND COALESCE(evento_atendido,0)=0 ORDER BY id DESC LIMIT 100");
    $gestionables = Database::fetchAll("SELECT id, quote_number, event_date, evento_nombre, COALESCE(evento_atendido,0) evento_atendido FROM quotes WHERE origin='event' OR status='aceptada' ORDER BY COALESCE(evento_atendido,0) ASC, id DESC LIMIT 200");
} catch (\Throwable $e) {
    $cotizaciones = Database::fetchAll("SELECT id, quote_number, event_date FROM quotes WHERE origin='event' OR status='aceptada' ORDER BY id DESC LIMIT 100");
    $gestionables = [];
}
$eventosAbiertos = [];
try { $eventosAbiertos = Database::fetchAll("SELECT id, nombre, fecha_inicio FROM eventos WHERE estado='abierto' ORDER BY fecha_inicio DESC"); } catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Gestionar cotizaciones de evento: nombre + marcar atendida (form aparte).
    if (($_POST['accion'] ?? '') === 'gestionar_eventos') {
        $nombres = is_array($_POST['nombre'] ?? null)   ? $_POST['nombre']   : [];
        $atend   = is_array($_POST['atendido'] ?? null) ? $_POST['atendido'] : [];
        try {
            foreach (Database::fetchAll("SELECT id FROM quotes WHERE origin='event' OR status='aceptada'") as $q) {
                $qid = (int)$q['id'];
                if (!array_key_exists($qid, $nombres) && !array_key_exists($qid, $atend)) continue; // no estaba en el form
                $nom = clean($nombres[$qid] ?? '');
                $at  = isset($atend[$qid]) ? 1 : 0;
                Database::execute("UPDATE quotes SET evento_nombre=?, evento_atendido=? WHERE id=?", [$nom ?: null, $at, $qid]);
            }
            flashMessage('success', 'Cotizaciones de evento actualizadas.');
        } catch (\Throwable $e) {
            flashMessage('error', 'Falta aplicar install/50_quotes_evento.sql en phpMyAdmin.');
        }
        redirect('/admin/inventory/salida_evento.php');
    }

    $ubiId = cleanInt($_POST['ubicacion_id'] ?? 0);
    $ref   = clean($_POST['ref'] ?? '');
    $ins   = $_POST['insumo_id'] ?? [];
    $cant  = $_POST['cantidad'] ?? [];
    if (!$ubiId) { flashMessage('error', 'Elige una ubicación.'); redirect('/admin/inventory/salida_evento.php'); }

    $agg = [];
    foreach ($ins as $idx => $iid) {
        $iid = (int)$iid; $c = (float)($cant[$idx] ?? 0);
        if ($iid <= 0 || $c <= 0) continue;
        $agg[$iid] = ($agg[$iid] ?? 0) + $c;
    }
    $n = 0;
    foreach ($agg as $iid => $c) {
        invMovimiento($ubiId, $iid, 'evento', -$c, ['ref' => $ref ?: null, 'motivo' => 'Salida evento' . ($ref ? ': ' . $ref : '')]);
        $n++;
    }
    // Asignar a evento (opcional): el requerimiento = inventario inicial del evento.
    $evModo = $_POST['evento_modo'] ?? 'no';
    $eventoId = 0;
    try {
        if ($evModo === 'nuevo') {
            $evNombre = clean($_POST['evento_nombre'] ?? '');
            $fIni = clean($_POST['evento_fecha_inicio'] ?? '') ?: date('Y-m-d');
            $fFin = clean($_POST['evento_fecha_fin'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFin) || $fFin < $fIni) $fFin = null;
            $qid  = cleanInt($_POST['evento_quote_id'] ?? 0) ?: null;
            $truckId = cleanInt($_POST['evento_truck_id'] ?? 0) ?: null;
            if ($evNombre === '') $evNombre = 'Evento ' . $fIni;
            $eventoId = Database::insert(
                "INSERT INTO eventos (nombre,quote_id,ubicacion_id,truck_ubicacion_id,fecha_inicio,fecha_fin,estado) VALUES (?,?,?,?,?,?, 'abierto')",
                [$evNombre, $qid, $ubiId, $truckId, $fIni, $fFin]
            );
        } elseif ($evModo === 'existente') {
            $eventoId = cleanInt($_POST['evento_id'] ?? 0);
            $ok = $eventoId ? Database::fetch("SELECT id FROM eventos WHERE id=? AND estado='abierto'", [$eventoId]) : null;
            if (!$ok) $eventoId = 0;
        }
        if ($eventoId > 0) {
            $costos = [];
            foreach (Database::fetchAll("SELECT id, costo_unitario FROM insumos") as $ix) { $costos[(int)$ix['id']] = (float)$ix['costo_unitario']; }
            foreach ($agg as $iid => $c) {
                Database::execute(
                    "INSERT INTO evento_insumos (evento_id,insumo_id,cantidad_inicial,costo_unitario) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE cantidad_inicial = cantidad_inicial + VALUES(cantidad_inicial)",
                    [$eventoId, (int)$iid, $c, $costos[(int)$iid] ?? 0]
                );
            }
        }
    } catch (\Throwable $e) { /* tablas de evento aún no migradas → la salida igual descontó */ }

    $msgEv = $eventoId > 0 ? ' Inventario inicial guardado en el evento.' : '';
    flashMessage('success', "Salida de evento registrada: $n insumo(s) descontados del stock.$msgEv");
    if ($eventoId > 0) { redirect('/admin/inventory/evento_detalle.php?id=' . $eventoId); }
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

<?php if (!empty($gestionables)): ?>
<details class="card" style="margin-bottom:18px">
  <summary style="cursor:pointer;padding:14px 18px;font-weight:700">⚙️ Gestionar cotizaciones de evento <small style="font-weight:400;color:var(--text-muted)">— ponles nombre y marca las atendidas para limpiar el selector</small></summary>
  <div class="card-body" style="border-top:1px solid var(--border)">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="gestionar_eventos">
      <div class="table-wrap" style="border:none">
        <table class="data-table">
          <thead><tr><th>Cotización</th><th>Nombre del evento</th><th style="width:100px;text-align:center">Atendida</th></tr></thead>
          <tbody>
            <?php foreach ($gestionables as $g): ?>
            <tr<?= !empty($g['evento_atendido']) ? ' style="opacity:.55"' : '' ?>>
              <td><strong><?= clean($g['quote_number']) ?></strong><?= $g['event_date'] ? ' <small style="color:var(--text-muted)">· ' . clean($g['event_date']) . '</small>' : '' ?></td>
              <td><input type="text" name="nombre[<?= (int)$g['id'] ?>]" value="<?= clean($g['evento_nombre'] ?? '') ?>" placeholder="Ej: Boda Pérez" style="width:100%"></td>
              <td style="text-align:center"><input type="checkbox" name="atendido[<?= (int)$g['id'] ?>]" value="1" <?= !empty($g['evento_atendido']) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--brand)"></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:12px">Guardar cambios</button>
    </form>
  </div>
</details>
<?php endif; ?>

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
        <!-- Buscador de insumos sueltos -->
        <div id="se-add" style="position:relative;margin-top:10px">
          <input type="text" id="se-q" placeholder="+ Agregar insumo suelto…" autocomplete="off" style="width:100%;box-sizing:border-box" oninput="seBuscar(this.value)">
          <div id="se-drop" style="display:none;position:absolute;z-index:200;left:0;right:0;background:var(--card-bg,#fff);border:1px solid var(--border);border-top:none;border-radius:0 0 6px 6px;max-height:220px;overflow-y:auto"></div>
        </div>
        <!-- Mini-modal crear insumo -->
        <div id="ins-ov" style="display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);align-items:center;justify-content:center">
          <div style="background:var(--card-bg,#fff);border-radius:10px;padding:20px;width:min(340px,94vw);box-shadow:0 8px 32px rgba(0,0,0,.2)">
            <p style="margin:0 0 14px;font-weight:700;font-size:15px">Nuevo insumo</p>
            <div class="form-group"><label>Nombre</label><input type="text" id="ins-name"></div>
            <div class="form-row form-row-2" style="gap:10px">
              <div class="form-group" style="margin:0"><label>Unidad</label><input type="text" id="ins-unidad" placeholder="kg, un, l…"></div>
              <div class="form-group" style="margin:0"><label>Tipo</label>
                <select id="ins-tipo"><option value="descartable">Descartable</option><option value="insumo">Insumo</option><option value="ingrediente">Ingrediente</option></select>
              </div>
            </div>
            <div class="form-group"><label>Costo unitario (S/)</label><input type="text" inputmode="decimal" id="ins-costo" value="0"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px">
              <button type="button" class="btn btn-ghost" onclick="document.getElementById('ins-ov').style.display='none'">Cancelar</button>
              <button type="button" class="btn btn-primary" onclick="insCrear()">Crear y agregar</button>
            </div>
          </div>
        </div>
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border)">
          <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px">Asignar a evento <small style="font-weight:400;color:var(--text-muted)">(opcional — para liquidar después)</small></label>
          <select name="evento_modo" id="evModo" onchange="evModoChange()" style="margin-bottom:8px">
            <option value="no">No registrar como evento</option>
            <option value="nuevo">Crear un evento nuevo</option>
            <?php if (!empty($eventosAbiertos)): ?><option value="existente">Agregar a un evento abierto</option><?php endif; ?>
          </select>
          <div id="evNuevo" style="display:none">
            <input type="text" name="evento_nombre" placeholder="Nombre del evento (ej. Feria de Barranco)" style="margin-bottom:6px">
            <div class="form-row form-row-2" style="margin-bottom:6px">
              <input type="date" name="evento_fecha_inicio" value="<?= date('Y-m-d') ?>">
              <input type="date" name="evento_fecha_fin">
            </div>
            <select name="evento_quote_id">
              <option value="">— Sin vincular a cotización —</option>
              <?php foreach ($cotizaciones as $c): $lbl = !empty($c['evento_nombre'] ?? '') ? $c['evento_nombre'] : ($c['quote_number'] . ($c['event_date'] ? ' · ' . $c['event_date'] : '')); ?><option value="<?= (int)$c['id'] ?>"><?= clean($lbl) ?></option><?php endforeach; ?>
            </select>
            <select name="evento_truck_id" style="margin-top:6px">
              <option value="">— Truck / ubicación donde vende (opcional) —</option>
              <?php foreach ($ubicaciones as $u): ?><option value="<?= (int)$u['id'] ?>"><?= clean($u['nombre']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php if (!empty($eventosAbiertos)): ?>
          <div id="evExist" style="display:none">
            <select name="evento_id">
              <?php foreach ($eventosAbiertos as $e): ?><option value="<?= (int)$e['id'] ?>"><?= clean($e['nombre']) ?> · <?= clean($e['fecha_inicio']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>
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
  var RECETAS = <?= json_encode($recByProd, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var RECETAS_MOD = <?= json_encode($recMod, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var MODS_BY_PROD = <?= json_encode($modsByProd, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var INSUMOS = <?= json_encode($insMap, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var PRODNAMES = {}; <?php foreach ($products as $p): ?>PRODNAMES[<?= (int)$p['id'] ?>] = <?= json_encode($p['name'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;<?php endforeach; ?>
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
        if (x.excl.indexOf(r.insumo_id) >= 0) return;
        agg[r.insumo_id] = (agg[r.insumo_id] || 0) + r.cantidad * x.qty;
      });
      (x.mods || []).forEach(function(mid){
        (RECETAS_MOD[mid] || []).forEach(function(r){
          agg[r.insumo_id] = (agg[r.insumo_id] || 0) + r.cantidad * x.qty;
        });
      });
    });
    var ids = Object.keys(agg);
    var list = document.getElementById('ingList');
    var sueltas = Array.prototype.slice.call(list.querySelectorAll('.ev-row[data-suelto]'));
    if (!ids.length && !sueltas.length){ list.innerHTML = '<p style="color:var(--text-muted);font-size:14px;margin:0">Esos productos no tienen receta definida.</p>'; document.getElementById('ingFoot').style.display='none'; return; }
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
    // Re-anexar las filas sueltas que NO estén ya en la explosión (evita duplicar insumo_id[])
    sueltas.forEach(function(r){ if (agg[r.dataset.iid] === undefined) list.appendChild(r); });
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

  // ── Buscador de insumos sueltos ──────────────────────────────────────────
  var INS_API  = '<?= APP_URL ?>/api/insumos.php';
  var CSRF_TOK = '<?= csrfToken() ?>';
  var _seTimer = null;

  function seBuscar(q){
    clearTimeout(_seTimer);
    var drop = document.getElementById('se-drop');
    if (!q || q.trim().length < 2){ drop.style.display = 'none'; drop.innerHTML = ''; return; }
    _seTimer = setTimeout(function(){
      fetch(INS_API + '?action=buscar&q=' + encodeURIComponent(q.trim()))
        .then(function(r){ return r.json(); })
        .then(function(data){
          drop.innerHTML = '';
          var items = Array.isArray(data) ? data : (data.insumos || []);
          var qLower = q.trim().toLowerCase();
          var exactMatch = items.some(function(i){ return (i.nombre||'').toLowerCase() === qLower; });
          items.forEach(function(i){
            var el = document.createElement('div');
            el.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--border)';
            el.textContent = i.nombre + (i.unidad ? ' (' + i.unidad + ')' : '');
            el.addEventListener('mouseover', function(){ el.style.background='var(--hover-bg,#f5f5f5)'; });
            el.addEventListener('mouseout',  function(){ el.style.background=''; });
            el.addEventListener('click', function(){
              seAgregar(parseInt(i.id), i.nombre, i.unidad || '', parseFloat(i.costo_unitario) || 0);
              document.getElementById('se-q').value = '';
              drop.style.display = 'none';
            });
            drop.appendChild(el);
          });
          if (!exactMatch){
            var crEl = document.createElement('div');
            crEl.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:14px;color:var(--primary,#007bff)';
            crEl.textContent = '+ Crear «' + q.trim() + '»';
            crEl.addEventListener('mouseover', function(){ crEl.style.background='var(--hover-bg,#f5f5f5)'; });
            crEl.addEventListener('mouseout',  function(){ crEl.style.background=''; });
            crEl.addEventListener('click', function(){
              insAbrir(q.trim());
              drop.style.display = 'none';
            });
            drop.appendChild(crEl);
          }
          drop.style.display = drop.childNodes.length ? 'block' : 'none';
        })
        .catch(function(){ drop.style.display = 'none'; });
    }, 280);
  }

  function seAgregar(iid, nombre, unidad, costo){
    // No duplicar
    var exist = document.querySelector('#ingList .ev-row[data-iid="'+iid+'"]');
    if (exist){ exist.querySelector('.ev-q').focus(); return; }
    // Crear fila con mismo formato que calcular()
    var row = document.createElement('div');
    row.className = 'ev-row';
    row.dataset.iid    = iid;
    row.dataset.costo  = costo;
    row.dataset.suelto = '1';
    row.style.cssText = 'display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)';
    var nameSpan = document.createElement('span');
    nameSpan.style.cssText = 'flex:1;font-size:14px';
    nameSpan.textContent = nombre;
    var hidIid = document.createElement('input');
    hidIid.type = 'hidden'; hidIid.name = 'insumo_id[]'; hidIid.value = iid;
    var qInput = document.createElement('input');
    qInput.type = 'text'; qInput.inputMode = 'decimal';
    qInput.name = 'cantidad[]'; qInput.value = '1'; qInput.className = 'ev-q';
    qInput.style.cssText = 'width:90px;text-align:right';
    qInput.addEventListener('input', sumCost);
    var uSpan = document.createElement('span');
    uSpan.style.cssText = 'width:36px;font-size:12px;color:var(--text-muted)';
    uSpan.textContent = unidad;
    var rmBtn = document.createElement('button');
    rmBtn.type = 'button';
    rmBtn.style.cssText = 'background:none;border:none;color:#dc2626;cursor:pointer';
    rmBtn.textContent = '✕';
    rmBtn.addEventListener('click', function(){ row.remove(); sumCost(); });
    row.appendChild(nameSpan);
    row.appendChild(hidIid);
    row.appendChild(qInput);
    row.appendChild(uSpan);
    row.appendChild(rmBtn);
    // Quitar el placeholder si aún existe
    var placeholder = document.querySelector('#ingList > p');
    if (placeholder) placeholder.remove();
    document.getElementById('ingList').appendChild(row);
    document.getElementById('ingFoot').style.display = 'block';
    document.getElementById('fUbi').value = document.getElementById('ubiSel').value;
    document.getElementById('fRef').value = document.getElementById('refInput').value;
    sumCost();
  }

  function insAbrir(nombre){
    document.getElementById('ins-name').value = nombre || '';
    document.getElementById('ins-unidad').value = '';
    document.getElementById('ins-tipo').value = 'descartable';
    document.getElementById('ins-costo').value = '0';
    document.getElementById('ins-ov').style.display = 'flex';
    document.getElementById('ins-name').focus();
  }

  function insCrear(){
    var nombre = document.getElementById('ins-name').value.trim();
    if (!nombre){ document.getElementById('ins-name').focus(); return; }
    var body = new URLSearchParams({
      action:        'crear',
      nombre:        nombre,
      unidad:        document.getElementById('ins-unidad').value.trim(),
      tipo:          document.getElementById('ins-tipo').value,
      costo_unitario: document.getElementById('ins-costo').value || '0'
    });
    fetch(INS_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF_TOK },
      body: body.toString()
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (d.insumo && d.insumo.id){
        seAgregar(parseInt(d.insumo.id), d.insumo.nombre, d.insumo.unidad || '', 0);
        document.getElementById('ins-ov').style.display = 'none';
        document.getElementById('se-q').value = '';
      } else {
        alert(d.error || 'Error al crear insumo');
      }
    })
    .catch(function(){ alert('Error de red al crear insumo'); });
  }

  function evModoChange(){
    var m = document.getElementById('evModo').value;
    document.getElementById('evNuevo').style.display = m==='nuevo' ? 'block' : 'none';
    var ex = document.getElementById('evExist'); if (ex) ex.style.display = m==='existente' ? 'block' : 'none';
  }

  // Sincronizar ubicación y referencia al enviar (fuente autoritativa, por si el usuario cambió después de calcular)
  document.getElementById('evForm').addEventListener('submit', function(){
    document.getElementById('fUbi').value = document.getElementById('ubiSel').value;
    document.getElementById('fRef').value = document.getElementById('refInput').value;
  });

  // Cerrar dropdown al hacer click fuera
  document.addEventListener('click', function(e){
    var add = document.getElementById('se-add');
    if (add && !add.contains(e.target)){
      var drop = document.getElementById('se-drop');
      if (drop) drop.style.display = 'none';
    }
  });

  renderProds();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
