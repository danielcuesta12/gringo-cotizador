<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
requirePermission('inv_evento');
$id = cleanInt($_GET['id'] ?? 0);
$ev = $id ? Database::fetch(
  "SELECT e.*, u.nombre AS ubi_nombre, q.quote_number, t.nombre AS truck_nombre FROM eventos e
     LEFT JOIN ubicaciones u ON u.id=e.ubicacion_id LEFT JOIN quotes q ON q.id=e.quote_id
     LEFT JOIN ubicaciones t ON t.id=e.truck_ubicacion_id WHERE e.id=?",
  [$id]
) : null;
if (!$ev) { flashMessage('error','Evento no encontrado.'); redirect('/admin/inventory/eventos.php'); }
$insumos = Database::fetchAll(
  "SELECT ei.*, i.nombre, i.unidad, i.tipo FROM evento_insumos ei JOIN insumos i ON i.id=ei.insumo_id WHERE ei.evento_id=? ORDER BY i.tipo, i.nombre",
  [$id]
);
$costoTotal = 0; foreach ($insumos as $r) { $costoTotal += (float)$r['cantidad_inicial'] * (float)$r['costo_unitario']; }

require_once __DIR__ . '/../../includes/inventario.php';
require_once __DIR__ . '/../../includes/gastos.php';

if (function_exists('gastosListo') && gastosListo()) {
    gastoMigrarEventoLegacy((int)$id, (int)(currentUser()['id'] ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'eliminar') {
        if (!isAdmin()) { flashMessage('error', 'Solo un administrador puede eliminar eventos.'); redirect('/admin/inventory/evento_detalle.php?id=' . $id); }
        eventoEliminar($id);
        flashMessage('success', 'Evento eliminado.');
        redirect('/admin/inventory/eventos.php');
    }

    if ($accion === 'agregar_dia' && $ev['estado'] === 'abierto') {
        $max = (int)(Database::fetch("SELECT COALESCE(MAX(dia_num),0) m FROM evento_dias WHERE evento_id=?", [$id])['m'] ?? 0);
        $fecha = date('Y-m-d', strtotime($ev['fecha_inicio'] . ' +' . $max . ' day'));
        Database::insert("INSERT INTO evento_dias (evento_id,fecha,dia_num) VALUES (?,?,?)", [$id, $fecha, $max + 1]);
        flashMessage('success', 'Día agregado.');
        redirect('/admin/inventory/evento_detalle.php?id=' . $id);
    }

    if ($accion === 'guardar_dia' && $ev['estado'] === 'abierto') {
        $diaId = cleanInt($_POST['dia_id'] ?? 0);
        $dia = $diaId ? Database::fetch("SELECT id FROM evento_dias WHERE id=? AND evento_id=?", [$diaId, $id]) : null;
        if ($dia) {
            $corr = $_POST['corregido'] ?? [];
            $cont = $_POST['conteo'] ?? [];
            Database::execute("DELETE FROM evento_dia_conteo WHERE dia_id=?", [$diaId]);
            $iids = array_unique(array_merge(array_keys($corr), array_keys($cont)));
            foreach ($iids as $iid) {
                $iid = (int)$iid;
                if ($iid <= 0) continue;
                $cv = ($corr[$iid] ?? '') !== '' ? (float)$corr[$iid] : null;
                $kv = ($cont[$iid] ?? '') !== '' ? (float)$cont[$iid] : null;
                if ($cv === null && $kv === null) continue;
                Database::execute("INSERT INTO evento_dia_conteo (dia_id,insumo_id,corregido,conteo) VALUES (?,?,?,?)", [$diaId, $iid, $cv, $kv]);
            }
            flashMessage('success', 'Día guardado.');
        }
        redirect('/admin/inventory/evento_detalle.php?id=' . $id . '&dia=' . $diaId);
    }

    if ($accion === 'cerrar' && $ev['estado'] === 'abierto') {
        $saldo = eventoSaldoFinal($id);
        $ubiEv = (int)($ev['ubicacion_id'] ?? 0);
        if ($ubiEv > 0) {
            foreach ($saldo as $iid => $s) {
                if ($s > 0.0001) {
                    invMovimiento($ubiEv, (int)$iid, 'evento', (float)$s, ['motivo' => 'Cierre evento #' . $id . ': sobrante devuelto']);
                }
            }
        }
        Database::execute("UPDATE eventos SET estado='cerrado' WHERE id=?", [$id]);
        flashMessage('success', 'Evento cerrado. El sobrante volvió al stock del local.');
        redirect('/admin/inventory/evento_detalle.php?id=' . $id);
    }

    if ($accion === 'guardar_ingresos') {
        $usaPos = isset($_POST['usa_pos']) ? 1 : 0;
        // Si usa POS, el ingreso sale del POS → no guardamos monto manual (evita que reaparezca si luego se desmarca).
        $venta  = $usaPos ? null : (($_POST['venta_manual'] ?? '') !== '' ? max(0, cleanFloat($_POST['venta_manual'])) : null);
        Database::execute("UPDATE eventos SET venta_manual=? WHERE id=?", [$venta, $id]);
        try { Database::execute("UPDATE eventos SET usa_pos=? WHERE id=?", [$usaPos, $id]); } catch (\Throwable $e) { /* falta migración 54 */ }
        flashMessage('success', 'Ingresos del evento guardados.');
        redirect('/admin/inventory/evento_detalle.php?id=' . $id);
    }

    if ($accion === 'gasto_add') {
        $monto = cleanFloat($_POST['monto'] ?? 0);
        $desc  = clean($_POST['descripcion'] ?? '');
        $catId = cleanInt($_POST['categoria_id'] ?? 0) ?: null;
        $subId = cleanInt($_POST['subcategoria_id'] ?? 0) ?: null;
        if ($monto > 0) {
            gastoGuardar(
                ['tipo' => 'empresa', 'concepto' => ($desc ?: 'Gasto de evento'),
                 'ubicacion_id' => null, 'fecha' => date('Y-m-d'), 'estado' => 'pagado',
                 'usuario_id' => (int)(currentUser()['id'] ?? 0), 'origen' => 'evento', 'evento_id' => (int)$id],
                [['concepto' => $desc, 'monto' => $monto, 'categoria_id' => $catId, 'subcategoria_id' => $subId]]
            );
            flashMessage('success', 'Gasto agregado.');
        }
        redirect('/admin/inventory/evento_detalle.php?id=' . (int)$id);
    }
    if ($accion === 'gasto_del') {
        $gid = cleanInt($_POST['gasto_id'] ?? 0);
        if ($gid) {
            $own = Database::fetch("SELECT id FROM gastos WHERE id=? AND origen='evento' AND evento_id=?", [$gid, (int)$id]);
            if ($own) gastoEliminar($gid);
        }
        redirect('/admin/inventory/evento_detalle.php?id=' . (int)$id);
    }
}

// Cargar días y calcular control diario
$dias = Database::fetchAll("SELECT * FROM evento_dias WHERE evento_id=? ORDER BY dia_num", [$id]);
if (!$dias && $insumos) {
    Database::insert("INSERT INTO evento_dias (evento_id,fecha,dia_num) VALUES (?,?,1)", [$id, $ev['fecha_inicio']]);
    $dias = Database::fetchAll("SELECT * FROM evento_dias WHERE evento_id=? ORDER BY dia_num", [$id]);
}
$conteos = [];
foreach (Database::fetchAll("SELECT dc.* FROM evento_dia_conteo dc JOIN evento_dias d ON d.id=dc.dia_id WHERE d.evento_id=?", [$id]) as $c) {
    $conteos[(int)$c['dia_id']][(int)$c['insumo_id']] = ['corregido' => $c['corregido'], 'conteo' => $c['conteo']];
}
$saldoPrev = [];
foreach ($insumos as $r) { $saldoPrev[(int)$r['insumo_id']] = (float)$r['cantidad_inicial']; }
$diaData = [];
foreach ($dias as $d) {
    $teo = eventoConsumoTeorico($id, $d['fecha']);
    $rows = [];
    $saldoNext = [];
    foreach ($insumos as $r) {
        $iid    = (int)$r['insumo_id'];
        $ini    = $saldoPrev[$iid] ?? 0;
        $t      = round($teo[$iid] ?? 0, 3);
        $cfg    = $conteos[(int)$d['id']][$iid] ?? null;
        $corr   = ($cfg && $cfg['corregido'] !== null) ? (float)$cfg['corregido'] : null;
        $cont   = ($cfg && $cfg['conteo']    !== null) ? (float)$cfg['conteo']    : null;
        $consumo   = $corr !== null ? $corr : $t;
        $saldoEsp  = round($ini - $consumo, 3);
        $dif       = $cont !== null ? round($cont - $saldoEsp, 3) : null;
        $rows[$iid] = [
            'inicial'   => $ini,
            'teorico'   => $t,
            'corregido' => $corr,
            'saldo'     => $saldoEsp,
            'conteo'    => $cont,
            'dif'       => $dif,
        ];
        $saldoNext[$iid] = $cont !== null ? $cont : $saldoEsp;
    }
    $diaData[(int)$d['id']] = $rows;
    $saldoPrev = $saldoNext;
}
$diaSel = cleanInt($_GET['dia'] ?? 0) ?: (int)($dias[count($dias) - 1]['id'] ?? 0);

$otrosGastos = Database::fetchAll(
    "SELECT g.id, g.monto, gi.concepto AS descripcion, c.nombre AS cat_nombre, s.nombre AS sub_nombre
     FROM gastos g
     LEFT JOIN gasto_items gi ON gi.gasto_id = g.id
     LEFT JOIN gasto_categorias c ON c.id = gi.categoria_id
     LEFT JOIN gasto_subcategorias s ON s.id = gi.subcategoria_id
     WHERE g.origen='evento' AND g.evento_id=? ORDER BY g.id DESC", [(int)$id]);
$totalOtros = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) t FROM gastos WHERE origen='evento' AND evento_id=?", [(int)$id])['t'] ?? 0);

// Liquidación
$saldoFin = eventoSaldoFinal($id);
$costoMercaderia = 0.0; $costoPapeleria = 0.0;
foreach ($insumos as $r) {
    $iid = (int)$r['insumo_id'];
    $consumo = max(0, (float)$r['cantidad_inicial'] - ($saldoFin[$iid] ?? 0));
    $costo = $consumo * (float)$r['costo_unitario'];
    if (($r['tipo'] ?? 'ingrediente') === 'descartable') $costoPapeleria += $costo; else $costoMercaderia += $costo;
}
$ventaPOS = 0.0;
$truckId = (int)($ev['truck_ubicacion_id'] ?: $ev['ubicacion_id']);
if ($truckId > 0) {
    $fIni = $ev['fecha_inicio']; $fFin = $ev['fecha_fin'] ?: $ev['fecha_inicio'];
    $ventaPOS = (float)(Database::fetch("SELECT COALESCE(SUM(total),0) t FROM pedidos WHERE ubicacion_id=? AND DATE(created_at) BETWEEN ? AND ? AND estado<>'cancelado'", [$truckId, $fIni, $fFin])['t'] ?? 0);
}
$ventaCot = 0.0;
if (!empty($ev['quote_id'])) { $ventaCot = (float)(Database::fetch("SELECT total FROM quotes WHERE id=?", [$ev['quote_id']])['total'] ?? 0); }
// ¿Usa POS? Con POS → el ingreso es la venta del POS; sin POS → prevalece el monto manual.
$usaPos = array_key_exists('usa_pos', $ev) ? ((int)$ev['usa_pos'] === 1) : true;
if ($usaPos) {
    $ingresos = $ventaPOS;
} else {
    $ingresos = $ev['venta_manual'] !== null ? (float)$ev['venta_manual'] : 0.0;
}
$utilidad = $ingresos - $costoMercaderia - $costoPapeleria - $totalOtros;
$rendimiento = $ingresos > 0 ? ($utilidad / $ingresos) * 100 : 0;

$pageTitle = 'Evento · ' . $ev['nombre'];
$activePage = 'inv-eventos';
include __DIR__ . '/../layout-top.php';

// Helper de formato inline
function fmtCant($v) { return rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.'); }
?>
<div class="breadcrumb"><a href="<?= APP_URL ?>/admin/inventory/eventos.php">Eventos</a><span class="breadcrumb-sep">›</span><span class="breadcrumb-current"><?= clean($ev['nombre']) ?></span></div>
<div class="page-header"><div class="page-header-left"><h1><?= clean($ev['nombre']) ?></h1>
  <p><?= clean($ev['fecha_inicio']) ?><?= $ev['fecha_fin'] ? ' → ' . clean($ev['fecha_fin']) : '' ?>
     <?= $ev['ubi_nombre'] ? ' · ' . clean($ev['ubi_nombre']) : '' ?>
     <?= $ev['quote_number'] ? ' · ' . clean($ev['quote_number']) : '' ?>
     <?= !empty($ev['truck_nombre']) ? ' · 🚚 ' . clean($ev['truck_nombre']) : '' ?>
     · <span class="badge <?= $ev['estado']==='abierto'?'badge-success':'badge-secondary' ?>"><?= $ev['estado']==='abierto'?'Abierto':'Cerrado' ?></span></p></div></div>

<div class="card"><div class="card-header"><span class="card-title">Inventario inicial (apertura)</span></div>
<div class="card-body" style="padding:0">
  <?php if (!$insumos): ?>
    <div class="empty-state" style="padding:30px;text-align:center"><p>Este evento no tiene inventario inicial cargado.</p></div>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse;font-size:14px">
    <thead><tr><th style="text-align:left;padding:9px 12px">Insumo</th><th style="padding:9px 12px">Tipo</th><th style="text-align:right;padding:9px 12px">Cantidad</th><th style="text-align:right;padding:9px 12px">Costo</th></tr></thead>
    <tbody>
    <?php foreach ($insumos as $r): $sub = (float)$r['cantidad_inicial'] * (float)$r['costo_unitario']; ?>
      <tr style="border-top:1px solid var(--border)">
        <td style="padding:9px 12px;font-weight:600"><?= clean($r['nombre']) ?></td>
        <td style="padding:9px 12px;text-align:center"><span class="badge <?= ($r['tipo']??'ingrediente')==='descartable'?'badge-secondary':'badge-info' ?>"><?= ($r['tipo']??'ingrediente')==='descartable'?'Descartable':'Ingrediente' ?></span></td>
        <td style="padding:9px 12px;text-align:right"><?= rtrim(rtrim(number_format((float)$r['cantidad_inicial'],3,'.',''),'0'),'.') ?> <?= clean($r['unidad']) ?></td>
        <td style="padding:9px 12px;text-align:right"><?= formatMoney($sub) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr style="border-top:2px solid var(--border);font-weight:800"><td colspan="3" style="padding:10px 12px;text-align:right">Costo de mercadería inicial</td><td style="padding:10px 12px;text-align:right"><?= formatMoney($costoTotal) ?></td></tr></tfoot>
  </table>
  <?php endif; ?>
</div></div>

<?php if ($insumos): ?>
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span class="card-title" style="margin-right:auto">Control diario</span>
    <?php foreach ($dias as $d): ?>
      <a href="<?= APP_URL ?>/admin/inventory/evento_detalle.php?id=<?= $id ?>&dia=<?= (int)$d['id'] ?>"
         class="btn btn-sm <?= (int)$d['id'] === $diaSel ? 'btn-primary' : 'btn-secondary' ?>"
         style="min-width:64px">Día <?= (int)$d['dia_num'] ?></a>
    <?php endforeach; ?>
    <?php if ($ev['estado'] === 'abierto'): ?>
    <form method="post" style="display:inline">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="agregar_dia">
      <button type="submit" class="btn btn-sm btn-secondary">+ Día</button>
    </form>
    <?php endif; ?>
  </div>

  <?php if ($diaSel && isset($diaData[$diaSel])): ?>
  <?php
    $diaInfo = null;
    foreach ($dias as $d) { if ((int)$d['id'] === $diaSel) { $diaInfo = $d; break; } }
  ?>
  <div class="card-body" style="padding:0">
    <?php if ($diaInfo): ?>
    <p style="padding:8px 14px;margin:0;font-size:13px;color:var(--text-muted)">
      Día <?= (int)$diaInfo['dia_num'] ?> — <?= clean($diaInfo['fecha']) ?>
    </p>
    <?php endif; ?>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="guardar_dia">
      <input type="hidden" name="dia_id" value="<?= $diaSel ?>">
      <div style="overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="background:var(--bg-alt,#f7f7f7)">
            <th style="text-align:left;padding:8px 12px">Insumo</th>
            <th style="text-align:right;padding:8px 10px">Inicial</th>
            <th style="text-align:right;padding:8px 10px">Teórico</th>
            <th style="text-align:right;padding:8px 10px">Corregido</th>
            <th style="text-align:right;padding:8px 10px">Saldo esp.</th>
            <th style="text-align:right;padding:8px 10px">Conteo</th>
            <th style="text-align:right;padding:8px 10px">Diferencia</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($insumos as $r):
          $iid  = (int)$r['insumo_id'];
          $row  = $diaData[$diaSel][$iid] ?? ['inicial'=>0,'teorico'=>0,'corregido'=>null,'saldo'=>0,'conteo'=>null,'dif'=>null];
          $dis  = $ev['estado'] === 'cerrado' ? ' disabled' : '';
          $difColor = '';
          if ($row['dif'] !== null) {
              $difColor = $row['dif'] == 0 ? 'color:#1a7a1a;font-weight:700' : 'color:#c8102e;font-weight:700';
          }
        ?>
          <tr style="border-top:1px solid var(--border)">
            <td style="padding:8px 12px;font-weight:600"><?= clean($r['nombre']) ?> <span style="font-weight:400;color:var(--text-muted)"><?= clean($r['unidad']) ?></span></td>
            <td style="padding:8px 10px;text-align:right"><?= fmtCant($row['inicial']) ?></td>
            <td style="padding:8px 10px;text-align:right"><?= fmtCant($row['teorico']) ?></td>
            <td style="padding:8px 10px;text-align:right">
              <input type="number" step="0.001" min="0"
                     name="corregido[<?= $iid ?>]"
                     value="<?= $row['corregido'] !== null ? fmtCant($row['corregido']) : '' ?>"
                     placeholder="<?= fmtCant($row['teorico']) ?>"
                     style="width:90px;text-align:right"<?= $dis ?>>
            </td>
            <td style="padding:8px 10px;text-align:right"><?= fmtCant($row['saldo']) ?></td>
            <td style="padding:8px 10px;text-align:right">
              <input type="number" step="0.001" min="0"
                     name="conteo[<?= $iid ?>]"
                     value="<?= $row['conteo'] !== null ? fmtCant($row['conteo']) : '' ?>"
                     placeholder="—"
                     style="width:90px;text-align:right"<?= $dis ?>>
            </td>
            <td style="padding:8px 10px;text-align:right;<?= $difColor ?>">
              <?php if ($row['dif'] === null): ?>—<?php else: ?><?= ($row['dif'] > 0 ? '+' : '') . fmtCant($row['dif']) ?><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if ($ev['estado'] === 'abierto'): ?>
      <div style="padding:12px 14px;text-align:right">
        <button type="submit" class="btn btn-primary">Guardar día</button>
      </div>
      <?php endif; ?>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($insumos): ?>
<div class="card">
  <div class="card-header"><span class="card-title">Otros gastos del evento</span></div>
  <div class="card-body" style="padding:0">
    <?php if ($otrosGastos): ?>
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead><tr style="background:var(--bg-alt,#f7f7f7)">
        <th style="text-align:left;padding:8px 12px">Categoría</th>
        <th style="text-align:left;padding:8px 12px">Descripción</th>
        <th style="text-align:right;padding:8px 12px">Monto</th>
        <th style="padding:8px 12px"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($otrosGastos as $g): ?>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:8px 12px">
            <?= $g['cat_nombre'] ? clean($g['cat_nombre']) : '<span style="color:var(--text-muted)">—</span>' ?>
            <?php if ($g['sub_nombre']): ?><span style="color:var(--text-muted)"> · <?= clean($g['sub_nombre']) ?></span><?php endif; ?>
          </td>
          <td style="padding:8px 12px"><?= $g['descripcion'] ? clean($g['descripcion']) : '<span style="color:var(--text-muted)">—</span>' ?></td>
          <td style="padding:8px 12px;text-align:right;font-weight:600"><?= formatMoney((float)$g['monto']) ?></td>
          <td style="padding:8px 12px;text-align:right">
            <form method="post" style="display:inline">
              <?= csrfField() ?>
              <input type="hidden" name="accion" value="gasto_del">
              <input type="hidden" name="gasto_id" value="<?= (int)$g['id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger"
                      data-confirm="¿Eliminar este gasto?">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr style="border-top:2px solid var(--border);font-weight:800">
        <td colspan="2" style="padding:10px 12px;text-align:right">Total otros gastos</td>
        <td style="padding:10px 12px;text-align:right"><?= formatMoney($totalOtros) ?></td>
        <td></td>
      </tr></tfoot>
    </table>
    <?php else: ?>
    <p style="padding:14px;margin:0;color:var(--text-muted);font-size:14px">Sin gastos registrados.</p>
    <?php endif; ?>
  </div>
  <div class="card-body" style="border-top:1px solid var(--border)">
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start" data-egc-scope="1">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="gasto_add">
      <input type="text" name="descripcion" placeholder="Descripción" style="min-width:150px">
      <input type="text" name="monto" inputmode="decimal" placeholder="Monto S/" style="width:110px">
      <div class="egc egc-cat" data-egc data-search="buscar_categorias" data-create="crear_categoria" data-csrf="<?= clean(csrfToken()) ?>" data-dep="" data-dep-create-key="" style="min-width:160px">
        <input type="text" class="egc-input" placeholder="Categoría…" autocomplete="off">
        <input type="hidden" class="egc-id" name="categoria_id" value="">
        <div class="egc-menu"></div>
      </div>
      <div class="egc" data-egc data-search="buscar_subcategorias" data-create="crear_subcategoria" data-csrf="<?= clean(csrfToken()) ?>" data-dep=".egc-cat .egc-id" data-dep-create-key="categoria_id" style="min-width:160px">
        <input type="text" class="egc-input" placeholder="Subcategoría…" autocomplete="off">
        <input type="hidden" class="egc-id" name="subcategoria_id" value="">
        <div class="egc-menu"></div>
      </div>
      <button type="submit" class="btn btn-primary">Agregar gasto</button>
    </form>
  </div>
</div>
<?php endif; // otros gastos $insumos ?>

<?php /* Liquidación: siempre disponible (incluso sin inventario inicial o evento cerrado) para poder registrar/corregir la venta */ ?>
<div class="card" style="border-top:3px solid var(--yellow,#FFDF00)">
  <div class="card-header"><span class="card-title">Liquidación</span></div>
  <div class="card-body">
    <form method="post" style="margin-bottom:20px">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="guardar_ingresos">
      <label class="toggle-wrap" style="display:flex;align-items:center;gap:9px;cursor:pointer;margin-bottom:12px">
        <input type="checkbox" name="usa_pos" id="ev-usa-pos" value="1" <?= $usaPos ? 'checked' : '' ?> onchange="evTogglePos()" style="width:18px;height:18px;accent-color:var(--brand)">
        <span>
          <span style="font-weight:700">Este evento vende por POS</span>
          <span style="display:block;font-size:12px;color:var(--text-muted);margin-top:2px">Si está marcado, el ingreso se toma de las ventas del POS. Si no, escribes el ingreso a mano.</span>
        </span>
      </label>
      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
        <div id="ev-ingreso-manual" style="display:<?= $usaPos ? 'none' : 'flex' ?>;flex-direction:column;gap:4px;flex:1;min-width:180px">
          <label style="font-size:12px;font-weight:600">Ingreso manual del evento (S/)</label>
          <input type="text" inputmode="decimal" name="venta_manual"
                 value="<?= ($ev['venta_manual'] ?? null) !== null ? number_format((float)$ev['venta_manual'], 2, '.', '') : '' ?>"
                 placeholder="0.00" style="max-width:200px">
        </div>
        <div id="ev-ingreso-pos" style="display:<?= $usaPos ? 'flex' : 'none' ?>;flex-direction:column;gap:4px;flex:1;min-width:180px">
          <label style="font-size:12px;font-weight:600">Ingreso (del POS)</label>
          <div style="font-size:20px;font-weight:800"><?= formatMoney($ventaPOS) ?></div>
          <span style="font-size:12px;color:var(--text-muted)">Se toma automáticamente de las ventas del POS del local/truck<?php if ($ventaCot > 0): ?> · Cotización: <?= formatMoney($ventaCot) ?><?php endif; ?></span>
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
    <script>function evTogglePos(){var on=document.getElementById('ev-usa-pos').checked;document.getElementById('ev-ingreso-pos').style.display=on?'flex':'none';document.getElementById('ev-ingreso-manual').style.display=on?'none':'flex';}</script>

    <table style="width:100%;border-collapse:collapse;font-size:14px;max-width:460px">
      <tbody>
        <tr>
          <td style="padding:8px 0;color:var(--text-muted)">+ Ingresos</td>
          <td style="padding:8px 0;text-align:right;font-weight:600;color:#1a7a1a"><?= formatMoney($ingresos) ?></td>
        </tr>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:8px 0;color:var(--text-muted)">− Mercadería</td>
          <td style="padding:8px 0;text-align:right"><?= formatMoney($costoMercaderia) ?></td>
        </tr>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:8px 0;color:var(--text-muted)">− Papelería / descartables</td>
          <td style="padding:8px 0;text-align:right"><?= formatMoney($costoPapeleria) ?></td>
        </tr>
        <tr style="border-top:1px solid var(--border)">
          <td style="padding:8px 0;color:var(--text-muted)">− Otros gastos</td>
          <td style="padding:8px 0;text-align:right"><?= formatMoney($totalOtros) ?></td>
        </tr>
        <tr style="border-top:2px solid var(--border)">
          <td style="padding:10px 0;font-weight:800">= Utilidad</td>
          <td style="padding:10px 0;text-align:right;font-weight:800;<?= $utilidad >= 0 ? 'color:#1a7a1a' : 'color:#c8102e' ?>"><?= formatMoney($utilidad) ?></td>
        </tr>
      </tbody>
    </table>

    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:20px">
      <div style="flex:1;min-width:120px;background:var(--bg-alt,#f7f7f7);border-radius:8px;padding:14px;text-align:center">
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Facturado</div>
        <div style="font-size:22px;font-weight:800;margin-top:4px"><?= formatMoney($ingresos) ?></div>
      </div>
      <div style="flex:1;min-width:120px;background:var(--bg-alt,#f7f7f7);border-radius:8px;padding:14px;text-align:center">
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Utilidad</div>
        <div style="font-size:22px;font-weight:800;margin-top:4px;<?= $utilidad >= 0 ? 'color:#1a7a1a' : 'color:#c8102e' ?>"><?= formatMoney($utilidad) ?></div>
      </div>
      <div style="flex:1;min-width:120px;background:var(--bg-alt,#f7f7f7);border-radius:8px;padding:14px;text-align:center">
        <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Rendimiento</div>
        <div style="font-size:22px;font-weight:800;margin-top:4px;<?= $rendimiento >= 0 ? 'color:#1a7a1a' : 'color:#c8102e' ?>"><?= number_format($rendimiento, 1) ?>%</div>
      </div>
    </div>
  </div>
</div>
<?php /* fin Liquidación */ ?>

<?php if ($ev['estado'] === 'abierto'): ?>
<div class="card" style="border-top:3px solid var(--red,#c8102e)">
  <div class="card-body" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="flex:1;min-width:220px">
      <strong>Cerrar evento</strong>
      <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted)">Marca el evento como cerrado y devuelve el sobrante de stock al local. Esta acción no se puede deshacer.</p>
    </div>
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="cerrar">
      <button type="submit" class="btn btn-danger"
              data-confirm="¿Cerrar el evento? El sobrante se devuelve al stock del local y ya no se podrá editar.">
        Cerrar evento
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php endif; // $insumos ?>

<?php if (isAdmin()): ?>
<div class="card" style="border-top:3px solid var(--red,#c8102e)">
  <div class="card-body" style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
    <div style="flex:1;min-width:220px">
      <strong>Eliminar evento</strong>
      <p style="margin:4px 0 0;font-size:13px;color:var(--text-muted)">Borra el evento y TODOS sus datos (inventario inicial, control diario, gastos, liquidación). Permanente. No revierte los movimientos de stock ya registrados.</p>
    </div>
    <form method="post" onsubmit="return confirm('¿Eliminar este evento y TODOS sus datos (inventario, control diario, gastos, liquidación)? No se puede deshacer.') && confirm('Confirma de nuevo: este borrado es permanente.');">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="eliminar">
      <button type="submit" class="btn btn-danger">Eliminar evento</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('[data-confirm]').forEach(btn => {
  btn.addEventListener('click', function(e) {
    if (!confirm(this.dataset.confirm)) e.preventDefault();
  });
});
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
