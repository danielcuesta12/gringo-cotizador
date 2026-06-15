<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('asistencia');

$uid = (int) ($_SESSION['user_id'] ?? 0);

// ── POST: corrección manual ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $accion     = $_POST['accion'] ?? '';
    $fechaRedir = clean($_POST['fecha'] ?? date('Y-m-d'));

    if ($accion === 'manual') {
        $empId    = cleanInt($_POST['empleado_id'] ?? 0);
        $tipo     = in_array($_POST['tipo'] ?? '', ['entrada', 'salida']) ? $_POST['tipo'] : 'entrada';
        $fechaHora = str_replace('T', ' ', clean($_POST['marcada_at'] ?? ''));
        $nota     = clean($_POST['nota'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $fechaHora)) {
            flashMessage('error', 'Fecha/hora inválida.');
            redirect('/admin/asistencia/index.php?fecha=' . urlencode($fechaRedir));
        }
        if ($empId && $fechaHora) {
            $emp = Database::fetch("SELECT ubicacion_id FROM empleados WHERE id=?", [$empId]);
            Database::insert(
                "INSERT INTO asistencia_marcas (empleado_id,ubicacion_id,tipo,dentro_geocerca,fuente,origen,nota,registrada_por,marcada_at)
                 VALUES (?,?,?,1,'tablet','manual',?,?,?)",
                [$empId, $emp['ubicacion_id'] ?? null, $tipo, ($nota ?: 'Ajuste manual'), $uid, $fechaHora]
            );
            flashMessage('success', 'Marca manual agregada.');
        }
        redirect('/admin/asistencia/index.php?fecha=' . urlencode($fechaRedir));
    }

    if ($accion === 'editar') {
        $mid       = cleanInt($_POST['marca_id'] ?? 0);
        $fechaHora = str_replace('T', ' ', clean($_POST['marcada_at'] ?? ''));
        $tipo      = in_array($_POST['tipo'] ?? '', ['entrada', 'salida']) ? $_POST['tipo'] : 'entrada';
        $nota      = clean($_POST['nota'] ?? 'Ajuste manual');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $fechaHora)) {
            flashMessage('error', 'Fecha/hora inválida.');
            redirect('/admin/asistencia/index.php?fecha=' . urlencode($fechaRedir));
        }
        if ($mid && $fechaHora) {
            Database::execute(
                "UPDATE asistencia_marcas SET tipo=?, marcada_at=?, origen='manual', nota=?, registrada_por=? WHERE id=?",
                [$tipo, $fechaHora, ($nota ?: 'Ajuste manual'), $uid, $mid]
            );
            flashMessage('success', 'Marca corregida.');
        }
        redirect('/admin/asistencia/index.php?fecha=' . urlencode($fechaRedir));
    }
}

// ── Autoborrado fotos > 2 meses ────────────────────────────────────────────
try {
    $viejas = Database::fetchAll(
        "SELECT id, foto FROM asistencia_marcas WHERE foto IS NOT NULL AND foto <> '' AND marcada_at < (NOW() - INTERVAL 2 MONTH)"
    );
    foreach ($viejas as $v) {
        if (is_file(UPLOAD_PATH . $v['foto'])) @unlink(UPLOAD_PATH . $v['foto']);
        Database::execute("UPDATE asistencia_marcas SET foto=NULL WHERE id=?", [$v['id']]);
    }
} catch (Throwable $e) {}

// ── Consulta marcas del día ────────────────────────────────────────────────
$fecha   = clean($_GET['fecha'] ?? date('Y-m-d'));
$marcas  = Database::fetchAll(
    "SELECT m.*, e.nombre AS emp_nombre, e.cargo, u.nombre AS ubi_nombre
       FROM asistencia_marcas m
       JOIN empleados e ON e.id = m.empleado_id
      LEFT JOIN ubicaciones u ON u.id = m.ubicacion_id
      WHERE DATE(m.marcada_at) = ?
      ORDER BY e.nombre, m.marcada_at",
    [$fecha]
);
$empleadosAll = Database::fetchAll(
    "SELECT id, nombre FROM empleados WHERE activo=1 ORDER BY nombre"
);

// ── Agrupar marcas por empleado y calcular estado ─────────────────────────
/*
   Por cada empleado, se toman todas sus marcas del día en orden cronológico.
   Se empareja iterativamente: la primera entrada sin par abre una jornada;
   la primera salida posterior la cierra. Esto permite N pares (turno partido).
   Para el resumen de la fila se usa solo la primera entrada y la última salida.
   Estado:
     - Si alguna marca tiene dentro_geocerca=0  → rojo "Fuera de geocerca"
     - Si hay entrada sin salida o salida sin entrada → ámbar "Incompleta"
     - Par(es) completo(s) y todo dentro              → verde "Completo"
   La prioridad de la bandera roja supera a la ámbar.
*/
$porEmpleado = [];
foreach ($marcas as $m) {
    $eid = (int) $m['empleado_id'];
    if (!isset($porEmpleado[$eid])) {
        $porEmpleado[$eid] = [
            'nombre'     => $m['emp_nombre'],
            'cargo'      => $m['cargo'],
            'ubi_nombre' => $m['ubi_nombre'],
            'marcas'     => [],
        ];
    }
    $porEmpleado[$eid]['marcas'][] = $m;
}

$filas = [];
foreach ($porEmpleado as $eid => $emp) {
    $ms        = $emp['marcas'];   // ya ordenadas por marcada_at ASC
    $entradas  = [];               // marcas tipo 'entrada' pendientes de par
    $salidas   = [];               // marcas tipo 'salida' sueltas (sin entrada previa)
    $pares     = [];               // [entrada_row, salida_row]
    $fueraGeo  = false;
    $algManual = false;

    foreach ($ms as $m) {
        if ((int) $m['dentro_geocerca'] === 0) $fueraGeo = true;
        if ($m['origen'] === 'manual')          $algManual = true;

        if ($m['tipo'] === 'entrada') {
            $entradas[] = $m;
        } else {
            // busca la entrada más antigua sin par
            if ($entradas) {
                $ent = array_shift($entradas);
                $pares[] = ['entrada' => $ent, 'salida' => $m];
            } else {
                $salidas[] = $m; // salida suelta
            }
        }
    }

    // entradas sin cerrar quedan en $entradas; salidas sueltas en $salidas
    $huerfanas = array_merge($entradas, $salidas);

    // primera entrada y última salida del día para mostrar en la tabla
    $primeraEntrada = null;
    $ultimaSalida   = null;
    foreach ($ms as $m) {
        if ($m['tipo'] === 'entrada' && $primeraEntrada === null) $primeraEntrada = $m;
        if ($m['tipo'] === 'salida')  $ultimaSalida = $m;
    }

    // calcular horas trabajadas (suma de todos los pares)
    $totalSeg = 0;
    foreach ($pares as $par) {
        $t1 = strtotime($par['entrada']['marcada_at']);
        $t2 = strtotime($par['salida']['marcada_at']);
        if ($t2 > $t1) $totalSeg += ($t2 - $t1);
    }

    // estado
    if ($fueraGeo) {
        $estado = 'geo';       // rojo — Fuera de geocerca (máxima prioridad)
    } elseif ($huerfanas) {
        $estado = 'incompleta'; // ámbar
    } else {
        $estado = 'completo';  // verde
    }

    // horas formateadas
    $horasStr = '';
    if ($totalSeg > 0) {
        $h = intdiv($totalSeg, 3600);
        $m2 = intdiv($totalSeg % 3600, 60);
        $horasStr = $h . 'h ' . str_pad($m2, 2, '0', STR_PAD_LEFT) . 'm';
    }

    $filas[] = [
        'eid'            => $eid,
        'nombre'         => $emp['nombre'],
        'cargo'          => $emp['cargo'],
        'ubi_nombre'     => $emp['ubi_nombre'],
        'primera_entrada'=> $primeraEntrada,
        'ultima_salida'  => $ultimaSalida,
        'horas'          => $horasStr,
        'estado'         => $estado,
        'manual'         => $algManual,
        'marcas'         => $ms,        // todas las marcas del día (para modal editar)
        'sin_salida'     => !empty($entradas), // hay entrada abierta sin cerrar
    ];
}

// ── Layout ────────────────────────────────────────────────────────────────
$pageTitle  = 'Control de asistencia';
$activePage = 'asistencia';
$extraHead  = '<style>
/* ── Asistencia index ─────────────────────────────── */
.asis-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:18px}
.asis-toolbar .at-date{border:1px solid var(--border,#ddd);border-radius:10px;padding:8px 12px;font-size:13px;font-weight:700;background:var(--card,#fff);color:var(--text-primary,#1E1E1E);cursor:pointer}
.asis-toolbar .at-nav{border:1px solid var(--border,#ddd);border-radius:10px;padding:8px 10px;font-size:13px;background:var(--card,#fff);color:var(--text-primary,#1E1E1E);cursor:pointer;font-weight:800;text-decoration:none;line-height:1}
.asis-toolbar .at-today{border:1.5px solid #1E1E1E;border-radius:10px;padding:8px 12px;font-size:12px;font-weight:800;background:#1E1E1E;color:#FFDF00;cursor:pointer;text-decoration:none}
.asis-table{width:100%;border-collapse:collapse;font-size:13px}
.asis-table th{text-align:left;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#888);padding:10px 12px;border-bottom:2px solid var(--border,#eee);background:var(--bg-page,#fafafb)}
.asis-table td{padding:10px 12px;border-bottom:1px solid var(--border,#eee);vertical-align:middle}
.asis-table tr:last-child td{border-bottom:none}
.asis-emp-cell{display:flex;align-items:center;gap:10px}
.asis-thumb{width:38px;height:38px;border-radius:9px;background:linear-gradient(135deg,#ffe9a8,#ffd34d);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;overflow:hidden}
.asis-thumb img{width:38px;height:38px;object-fit:cover;border-radius:9px;display:block}
.asis-emp-cell b{font-size:13px;color:var(--text-primary,#1E1E1E)}
.asis-emp-cell small{display:block;font-size:11px;color:var(--text-muted,#888)}
.asis-hora{font-size:13px;font-weight:700}
.asis-hora .tag-man{font-size:10px;font-weight:800;padding:2px 7px;border-radius:12px;background:rgba(30,30,75,.1);color:#1B1F4B;margin-left:4px;vertical-align:middle}
.asis-foto{width:32px;height:32px;border-radius:7px;object-fit:cover;display:block;border:1px solid var(--border,#ddd)}
.asis-foto-ph{width:32px;height:32px;border-radius:7px;background:var(--bg-page,#f1f1f4);display:flex;align-items:center;justify-content:center;font-size:15px;color:var(--text-muted,#bbb)}
.asis-tag{font-size:10.5px;font-weight:800;padding:3px 9px;border-radius:20px;display:inline-block}
.asis-tag.ok{background:rgba(22,163,74,.12);color:#16a34a}
.asis-tag.geo{background:rgba(220,38,38,.12);color:#dc2626}
.asis-tag.inc{background:rgba(217,119,6,.14);color:#b45309}
.asis-btn{font-size:11.5px;font-weight:800;padding:6px 11px;border-radius:8px;border:none;background:#FFDF00;color:#1E1E1E;cursor:pointer}
.asis-legend{display:flex;gap:14px;flex-wrap:wrap;font-size:11.5px;color:var(--text-muted,#888);padding:12px 0 2px;border-top:1px solid var(--border,#eee);margin-top:6px}
/* Modal overlay */
.asis-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;align-items:center;justify-content:center}
.asis-modal-bg.on{display:flex}
.asis-modal{background:var(--card,#fff);border-radius:18px;padding:22px 20px;width:100%;max-width:400px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
.asis-modal h3{font-size:15px;font-weight:900;margin-bottom:14px}
.asis-modal .mrow{margin-bottom:12px}
.asis-modal label{display:block;font-size:11.5px;font-weight:800;color:var(--text-muted,#888);margin-bottom:4px}
.asis-modal input,.asis-modal select,.asis-modal textarea{width:100%;border:1px solid var(--border,#ddd);border-radius:9px;padding:9px 11px;font-size:13px;background:var(--card,#fff);color:var(--text-primary,#1E1E1E)}
.asis-modal .mbtns{display:flex;gap:8px;margin-top:16px}
.asis-modal .mbtn-ok{flex:1;background:#1E1E1E;color:#FFDF00;border:none;border-radius:10px;padding:11px;font-size:13px;font-weight:800;cursor:pointer}
.asis-modal .mbtn-cancel{background:var(--bg-page,#f1f1f4);color:var(--text-primary,#1E1E1E);border:none;border-radius:10px;padding:11px 14px;font-size:13px;font-weight:800;cursor:pointer}
/* Add manual form card */
.asis-add-card{background:var(--card,#fff);border:1.5px solid var(--border,#ddd);border-radius:14px;padding:16px;margin-bottom:22px}
.asis-add-card h3{font-size:14px;font-weight:800;margin-bottom:14px}
.asis-add-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:540px){.asis-add-grid{grid-template-columns:1fr}}
.asis-add-card label{display:block;font-size:11.5px;font-weight:800;color:var(--text-muted,#888);margin-bottom:4px}
.asis-add-card input,.asis-add-card select,.asis-add-card textarea{width:100%;border:1px solid var(--border,#ddd);border-radius:9px;padding:9px 11px;font-size:13px;background:var(--card,#fff);color:var(--text-primary,#1E1E1E)}
.asis-add-card .full{grid-column:1/-1}
.asis-empty{text-align:center;color:var(--text-muted,#888);padding:40px 0}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Control de asistencia</h1>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/asistencia/empleados.php" class="btn btn-outline">Gestionar empleados</a>
    <a href="<?= APP_URL ?>/admin/asistencia/reporte.php" class="btn btn-outline">Reporte de horas</a>
  </div>
</div>

<!-- ── Selector de fecha ──────────────────────────────────────────────────── -->
<?php
$fechaPrev = date('Y-m-d', strtotime($fecha . ' -1 day'));
$fechaNext = date('Y-m-d', strtotime($fecha . ' +1 day'));
$hoy       = date('Y-m-d');
$esHoy     = ($fecha === $hoy);

// Formato legible de la fecha mostrada
$ts = strtotime($fecha);
$diasEs = ['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
$mesesEs = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$fechaLabel = $diasEs[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' de ' . $mesesEs[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
?>
<div class="asis-toolbar">
  <a class="at-nav" href="<?= APP_URL ?>/admin/asistencia/index.php?fecha=<?= urlencode($fechaPrev) ?>" title="Día anterior">&#8592;</a>
  <form method="get" action="<?= APP_URL ?>/admin/asistencia/index.php" style="display:inline">
    <input type="date" name="fecha" class="at-date" value="<?= clean($fecha) ?>" onchange="this.form.submit()">
  </form>
  <a class="at-nav" href="<?= APP_URL ?>/admin/asistencia/index.php?fecha=<?= urlencode($fechaNext) ?>" title="Día siguiente">&#8594;</a>
  <?php if (!$esHoy): ?>
  <a class="at-today" href="<?= APP_URL ?>/admin/asistencia/index.php">Hoy</a>
  <?php endif; ?>
  <span style="font-size:13px;color:var(--text-muted,#888);font-weight:600"><?= ucfirst($fechaLabel) ?></span>
</div>

<!-- ── Tabla de marcajes ───────────────────────────────────────────────────── -->
<?php if (!$filas): ?>
  <div class="asis-empty">No hay marcajes registrados para este día.</div>
<?php else: ?>
<div class="card" style="overflow:auto">
  <table class="asis-table">
    <thead>
      <tr>
        <th>Empleado</th>
        <th>Entrada</th>
        <th>Salida</th>
        <th>Horas</th>
        <th>Estado</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $f):
        $ent  = $f['primera_entrada'];
        $sal  = $f['ultima_salida'];
        $entHora = $ent  ? date('H:i', strtotime($ent['marcada_at']))  : '—';
        $salHora = $sal  ? date('H:i', strtotime($sal['marcada_at']))  : '—';
        $entManual = $ent && $ent['origen'] === 'manual';
        $salManual = $sal && $sal['origen'] === 'manual';

        // foto de la primera entrada (thumbnail)
        $fotoUrl = (!empty($ent['foto'])) ? UPLOAD_URL . $ent['foto'] : '';
    ?>
      <tr>
        <!-- Empleado -->
        <td>
          <div class="asis-emp-cell">
            <div class="asis-thumb">
              <?php if ($fotoUrl): ?>
                <a href="<?= clean($fotoUrl) ?>" target="_blank"><img src="<?= clean($fotoUrl) ?>" alt="foto" class="asis-foto" style="width:38px;height:38px;border-radius:9px"></a>
              <?php else: ?>
                &#128247;
              <?php endif; ?>
            </div>
            <div>
              <b><?= clean($f['nombre']) ?></b>
              <small><?= clean($f['cargo'] ?? '') ?><?= $f['ubi_nombre'] ? ' · ' . clean($f['ubi_nombre']) : '' ?></small>
            </div>
          </div>
        </td>

        <!-- Entrada -->
        <td class="asis-hora">
          <?php if ($ent): ?>
            <?= $entHora ?>
            <?php if ($entManual): ?><span class="tag-man">manual</span><?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>

        <!-- Salida -->
        <td class="asis-hora">
          <?php if ($sal): ?>
            <?= $salHora ?>
            <?php if ($salManual): ?><span class="tag-man">manual</span><?php endif; ?>
          <?php else: ?>—<?php endif; ?>
        </td>

        <!-- Horas -->
        <td style="font-weight:700"><?= $f['horas'] ?: '—' ?></td>

        <!-- Estado -->
        <td>
          <?php if ($f['estado'] === 'geo'): ?>
            <span class="asis-tag geo">&#9873; Fuera de geocerca</span>
          <?php elseif ($f['estado'] === 'incompleta'): ?>
            <span class="asis-tag inc">&#9888; Incompleta</span>
          <?php else: ?>
            <span class="asis-tag ok">&#10003; Completo</span>
          <?php endif; ?>
          <?php if ($f['manual']): ?>
            &nbsp;<span class="asis-tag" style="background:rgba(30,30,75,.08);color:#1B1F4B">manual</span>
          <?php endif; ?>
        </td>

        <!-- Acciones -->
        <td style="white-space:nowrap">
          <?php if ($f['estado'] === 'incompleta' && $f['sin_salida']): ?>
            <!-- Cerrar marca rápida: precargar empleado + salida + fecha actual -->
            <button class="asis-btn"
              onclick="abrirManual(<?= (int)$f['eid'] ?>, 'salida', '<?= clean($fecha) ?>')">
              Cerrar marca
            </button>
          <?php else: ?>
            <?php
            // Para editar, tomamos la primera marca disponible del empleado
            $primeraM = $f['marcas'][0] ?? null;
            if ($primeraM):
                $dtVal = date('Y-m-d\TH:i', strtotime($primeraM['marcada_at']));
            ?>
            <button class="asis-btn"
              onclick="abrirEditar(<?= (int)$primeraM['id'] ?>, '<?= $primeraM['tipo'] ?>', '<?= $dtVal ?>', <?= (int)$f['eid'] ?>)">
              Ajustar
            </button>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Leyenda -->
<div class="asis-legend">
  <span><span class="asis-tag ok">&#10003; Completo</span> par entrada/salida ok</span>
  <span><span class="asis-tag inc">&#9888; Incompleta</span> falta una marca (no suma horas)</span>
  <span><span class="asis-tag geo">&#9873; Fuera de geocerca</span> marcó lejos del local</span>
  <span><span class="asis-tag" style="background:rgba(30,30,75,.08);color:#1B1F4B">manual</span> ajustada por el admin</span>
</div>

<?php endif; ?>

<!-- ── Agregar marca manual ────────────────────────────────────────────────── -->
<div style="margin-top:28px">
<div class="asis-add-card">
  <h3>+ Agregar marca manual</h3>
  <form method="post" action="<?= APP_URL ?>/admin/asistencia/index.php?fecha=<?= urlencode($fecha) ?>">
    <?= csrfField() ?>
    <input type="hidden" name="accion" value="manual">
    <input type="hidden" name="fecha" value="<?= clean($fecha) ?>">
    <div class="asis-add-grid">
      <div>
        <label>Empleado</label>
        <select name="empleado_id" required>
          <option value="">— elegir —</option>
          <?php foreach ($empleadosAll as $e): ?>
            <option value="<?= (int)$e['id'] ?>"><?= clean($e['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Tipo</label>
        <select name="tipo">
          <option value="entrada">Entrada</option>
          <option value="salida">Salida</option>
        </select>
      </div>
      <div>
        <label>Fecha y hora</label>
        <input type="datetime-local" name="marcada_at"
          value="<?= clean($fecha) ?>T<?= date('H:i') ?>" required>
      </div>
      <div>
        <label>Nota (opcional)</label>
        <input type="text" name="nota" placeholder="Ajuste manual" maxlength="200">
      </div>
    </div>
    <div style="margin-top:12px">
      <button type="submit" class="btn btn-primary">Agregar marca</button>
    </div>
  </form>
</div>
</div>

<!-- ── Modal: Editar marca existente ──────────────────────────────────────── -->
<div class="asis-modal-bg" id="modalEditarBg">
  <div class="asis-modal">
    <h3>Corregir marca</h3>
    <form method="post" action="<?= APP_URL ?>/admin/asistencia/index.php?fecha=<?= urlencode($fecha) ?>">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="fecha" value="<?= clean($fecha) ?>">
      <input type="hidden" name="marca_id" id="editMarcaId" value="">
      <div class="mrow">
        <label>Tipo</label>
        <select name="tipo" id="editTipo">
          <option value="entrada">Entrada</option>
          <option value="salida">Salida</option>
        </select>
      </div>
      <div class="mrow">
        <label>Fecha y hora</label>
        <input type="datetime-local" name="marcada_at" id="editFechaHora" required>
      </div>
      <div class="mrow">
        <label>Nota</label>
        <input type="text" name="nota" id="editNota" placeholder="Ajuste manual" maxlength="200">
      </div>
      <div class="mbtns">
        <button type="button" class="mbtn-cancel" onclick="cerrarEditar()">Cancelar</button>
        <button type="submit" class="mbtn-ok">Guardar cambio</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Modal: Cerrar marca rápida (sin salida) ───────────────────────────── -->
<div class="asis-modal-bg" id="modalManualBg">
  <div class="asis-modal">
    <h3 id="modalManualTitulo">Cerrar marca</h3>
    <form method="post" action="<?= APP_URL ?>/admin/asistencia/index.php?fecha=<?= urlencode($fecha) ?>">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="manual">
      <input type="hidden" name="fecha" value="<?= clean($fecha) ?>">
      <input type="hidden" name="empleado_id" id="manualEmpId" value="">
      <input type="hidden" name="tipo" id="manualTipo" value="salida">
      <div class="mrow">
        <label>Fecha y hora de salida</label>
        <input type="datetime-local" name="marcada_at" id="manualFechaHora" required>
      </div>
      <div class="mrow">
        <label>Nota</label>
        <input type="text" name="nota" placeholder="Ajuste manual" maxlength="200">
      </div>
      <div class="mbtns">
        <button type="button" class="mbtn-cancel" onclick="cerrarManual()">Cancelar</button>
        <button type="submit" class="mbtn-ok">Guardar</button>
      </div>
    </form>
  </div>
</div>

<script>
function abrirEditar(marcaId, tipo, fechaHora, empId) {
    document.getElementById('editMarcaId').value   = marcaId;
    document.getElementById('editTipo').value      = tipo;
    document.getElementById('editFechaHora').value = fechaHora;
    document.getElementById('editNota').value      = '';
    document.getElementById('modalEditarBg').classList.add('on');
}
function cerrarEditar() {
    document.getElementById('modalEditarBg').classList.remove('on');
}

function abrirManual(empId, tipo, fecha) {
    document.getElementById('manualEmpId').value = empId;
    document.getElementById('manualTipo').value  = tipo;
    // sugerir fecha + hora actual
    var now = new Date();
    var pad = n => String(n).padStart(2,'0');
    var dt  = fecha + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes());
    document.getElementById('manualFechaHora').value = dt;
    document.getElementById('modalManualTitulo').textContent = tipo === 'salida' ? 'Cerrar marca (salida)' : 'Agregar marca manual';
    document.getElementById('modalManualBg').classList.add('on');
}
function cerrarManual() {
    document.getElementById('modalManualBg').classList.remove('on');
}

// cerrar modales al hacer click fuera
document.getElementById('modalEditarBg').addEventListener('click', function(e){
    if (e.target === this) cerrarEditar();
});
document.getElementById('modalManualBg').addEventListener('click', function(e){
    if (e.target === this) cerrarManual();
});
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
