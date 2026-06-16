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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $accion = $_POST['accion'] ?? '';

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
        $insAll = Database::fetchAll("SELECT insumo_id, cantidad_inicial FROM evento_insumos WHERE evento_id=?", [$id]);
        $diasC  = Database::fetchAll("SELECT * FROM evento_dias WHERE evento_id=? ORDER BY dia_num", [$id]);
        $cont2  = [];
        foreach (Database::fetchAll("SELECT dc.* FROM evento_dia_conteo dc JOIN evento_dias d ON d.id=dc.dia_id WHERE d.evento_id=?", [$id]) as $c) {
            $cont2[(int)$c['dia_id']][(int)$c['insumo_id']] = $c;
        }
        $saldo = [];
        foreach ($insAll as $r) { $saldo[(int)$r['insumo_id']] = (float)$r['cantidad_inicial']; }
        foreach ($diasC as $d) {
            $teo = eventoConsumoTeorico($id, $d['fecha']);
            foreach ($insAll as $r) {
                $iid     = (int)$r['insumo_id'];
                $cfg     = $cont2[(int)$d['id']][$iid] ?? null;
                $corr    = ($cfg && $cfg['corregido'] !== null) ? (float)$cfg['corregido'] : null;
                $cnt     = ($cfg && $cfg['conteo']    !== null) ? (float)$cfg['conteo']    : null;
                $consumo = $corr !== null ? $corr : round($teo[$iid] ?? 0, 3);   // mismo redondeo que el display
                $saldoEsp = round(($saldo[$iid] ?? 0) - $consumo, 3);
                $saldo[$iid] = $cnt !== null ? $cnt : $saldoEsp;
            }
        }
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

<script>
document.querySelectorAll('[data-confirm]').forEach(btn => {
  btn.addEventListener('click', function(e) {
    if (!confirm(this.dataset.confirm)) e.preventDefault();
  });
});
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
