<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('pos_caja');

// ─── Filtros ──────────────────────────────────────────────────────────────────
$desde   = clean($_GET['desde'] ?? '');
$hasta   = clean($_GET['hasta'] ?? '');
$ubiF    = cleanInt($_GET['ubi'] ?? 0);
$cajeroF = cleanInt($_GET['cajero'] ?? 0);

// "Hoy" shortcut
$esHoy = (isset($_GET['hoy']) && $_GET['hoy'] === '1');
if ($esHoy) {
    $desde = date('Y-m-d');
    $hasta = date('Y-m-d');
}

// ─── Datos ────────────────────────────────────────────────────────────────────
$tableReady  = true;
$turnos      = [];
$ubicaciones = [];
$cajeros     = [];
$sumVentas   = 0.0;
$sumDif      = 0.0;

try {
    $ubicaciones = Database::fetchAll(
        "SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, nombre"
    );
    $cajeros = Database::fetchAll(
        "SELECT id, name FROM users ORDER BY name"
    );

    $where  = '1=1';
    $params = [];

    if ($desde !== '') { $where .= " AND DATE(t.abierto_en) >= ?"; $params[] = $desde; }
    if ($hasta !== '') { $where .= " AND DATE(t.abierto_en) <= ?"; $params[] = $hasta; }
    if ($ubiF > 0)     { $where .= " AND t.ubicacion_id = ?";      $params[] = $ubiF; }
    if ($cajeroF > 0)  { $where .= " AND t.usuario_id = ?";        $params[] = $cajeroF; }

    $turnos = Database::fetchAll(
        "SELECT t.*,
                u.name   AS cajero_nombre,
                ub.nombre AS ubi_nombre
         FROM pos_turnos t
         LEFT JOIN users       u  ON u.id  = t.usuario_id
         LEFT JOIN ubicaciones ub ON ub.id = t.ubicacion_id
         WHERE {$where}
         ORDER BY t.abierto_en DESC",
        $params
    );

    foreach ($turnos as $t) {
        $sumVentas += (float)($t['total_ventas'] ?? 0);
        if ($t['estado'] === 'cerrado') {
            $sumDif += (float)($t['diferencia'] ?? 0);
        }
    }
} catch (Exception $e) {
    $tableReady = false;
}

// ─── Helpers locales ─────────────────────────────────────────────────────────
function difColor(float $dif): string {
    $r = round($dif, 2);
    if ($r === 0.0)  return '#16a34a';  // verde — exacto
    if ($r > 0)      return '#d97706';  // ámbar — sobra
    return '#C8102E';                   // rojo  — falta
}

function difLabel(float $dif): string {
    $r = round($dif, 2);
    if ($r === 0.0)  return 'Exacto';
    if ($r > 0)      return '+' . formatMoney($r);
    return formatMoney($r);
}

// ─── Layout ───────────────────────────────────────────────────────────────────
$pageTitle  = 'Historial de caja';
$activePage = 'pos-caja';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 style="display:flex;align-items:center;gap:8px">
      <span style="display:inline-flex;color:var(--text-secondary)">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2M8 7V5a2 2 0 0 0-4 0v2M2 11h20"/>
        </svg>
      </span>
      Historial de caja
    </h1>
    <p>Turnos de caja abiertos y cerrados</p>
  </div>
</div>

<?php if (!$tableReady): ?>
<!-- ─── Migración pendiente ─────────────────────────────────────────────────── -->
<div class="card">
  <div class="empty-state">
    <div class="empty-state-icon" style="color:var(--text-muted)">
      <svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
    </div>
    <h3>Falta aplicar la migración de arqueo</h3>
    <p>Aplica <code>install/pos_arqueo.sql</code> en phpMyAdmin para activar esta sección.</p>
  </div>
</div>
<?php else: ?>

<!-- ─── Filtros ─────────────────────────────────────────────────────────────── -->
<form method="get" style="margin-bottom:16px">
  <div class="card" style="padding:14px 18px">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">

      <div>
        <label class="form-label">Desde</label><br>
        <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"
               style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px">
      </div>

      <div>
        <label class="form-label">Hasta</label><br>
        <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"
               style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px">
      </div>

      <?php if (count($ubicaciones) > 1): ?>
      <div>
        <label class="form-label">Ubicación</label><br>
        <select name="ubi" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px">
          <option value="0">Todas</option>
          <?php foreach ($ubicaciones as $ub): ?>
            <option value="<?= (int)$ub['id'] ?>" <?= $ubiF === (int)$ub['id'] ? 'selected' : '' ?>><?= clean($ub['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if (count($cajeros) > 1): ?>
      <div>
        <label class="form-label">Cajero</label><br>
        <select name="cajero" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px">
          <option value="0">Todos</option>
          <?php foreach ($cajeros as $cj): ?>
            <option value="<?= (int)$cj['id'] ?>" <?= $cajeroF === (int)$cj['id'] ? 'selected' : '' ?>><?= clean($cj['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div style="display:flex;gap:8px;margin-top:auto">
        <button type="submit" class="btn btn-primary" style="padding:8px 16px">Filtrar</button>
        <a href="?hoy=1" class="btn btn-ghost" style="padding:8px 14px">Hoy</a>
        <a href="?" class="btn btn-ghost" style="padding:8px 14px">Limpiar</a>
      </div>
    </div>
  </div>
</form>

<!-- ─── Totales resumen ───────────────────────────────────────────────────────── -->
<?php if (!empty($turnos)): ?>
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
  <div class="card" style="flex:1;min-width:160px;padding:14px 18px;display:flex;flex-direction:column;gap:2px">
    <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Turnos mostrados</div>
    <div style="font-size:22px;font-weight:700;color:var(--text-primary)"><?= count($turnos) ?></div>
  </div>
  <div class="card" style="flex:1;min-width:160px;padding:14px 18px;display:flex;flex-direction:column;gap:2px">
    <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Ventas totales</div>
    <div style="font-size:22px;font-weight:700;color:var(--text-primary)"><?= formatMoney($sumVentas) ?></div>
  </div>
  <div class="card" style="flex:1;min-width:160px;padding:14px 18px;display:flex;flex-direction:column;gap:2px">
    <div style="font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">Diferencia neta</div>
    <div style="font-size:22px;font-weight:700;color:<?= difColor($sumDif) ?>"><?= difLabel($sumDif) ?></div>
  </div>
</div>
<?php endif; ?>

<!-- ─── Tabla de turnos ──────────────────────────────────────────────────────── -->
<div class="card">
  <?php if (empty($turnos)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)">
        <svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-4 0v2M8 7V5a2 2 0 0 0-4 0v2M2 11h20"/>
        </svg>
      </div>
      <h3>Sin turnos</h3>
      <p>No hay turnos de caja para los filtros seleccionados.</p>
    </div>
  <?php else: ?>

  <!-- DESKTOP -->
  <div class="caja-desktop">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:36px"></th>
          <th>Apertura</th>
          <th>Cierre</th>
          <th>Cajero</th>
          <th>Ubicación</th>
          <th>Estado</th>
          <th style="text-align:right">Inicial</th>
          <th style="text-align:right">Ventas</th>
          <th style="text-align:right">Gastos</th>
          <th style="text-align:right">Esperada</th>
          <th style="text-align:right">Real</th>
          <th style="text-align:right">Diferencia</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($turnos as $idx => $t):
          $abierto     = $t['estado'] === 'abierto';
          $gastos      = json_decode($t['gastos_json'] ?? '[]', true) ?: [];
          $difVal      = $abierto ? null : (float)($t['diferencia'] ?? 0);
          $rowId       = 'caja-row-' . $t['id'];
          $detailId    = 'caja-detail-' . $t['id'];
        ?>
        <!-- Main row -->
        <tr id="<?= $rowId ?>" class="caja-main-row" style="cursor:pointer" onclick="toggleCajaDetail('<?= $detailId ?>', '<?= $rowId ?>')">
          <td>
            <span id="arrow-<?= $t['id'] ?>" style="display:inline-block;font-size:13px;color:var(--text-muted);transition:transform .2s">&#9658;</span>
          </td>
          <td style="font-size:13px"><?= formatDatetime($t['abierto_en']) ?></td>
          <td style="font-size:13px;color:<?= $abierto ? 'var(--text-muted)' : 'var(--text-primary)' ?>">
            <?= $abierto ? '<em>— abierto</em>' : formatDatetime($t['cerrado_en']) ?>
          </td>
          <td style="font-weight:600"><?= clean($t['cajero_nombre'] ?? '—') ?></td>
          <td style="font-size:13px"><?= clean($t['ubi_nombre'] ?? '—') ?></td>
          <td>
            <?php if ($abierto): ?>
              <span class="badge badge-info">Abierto</span>
            <?php else: ?>
              <span class="badge badge-secondary">Cerrado</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;font-size:13px"><?= formatMoney($t['monto_inicial'] ?? 0) ?></td>
          <td style="text-align:right;font-weight:700">
            <?= formatMoney($t['total_ventas'] ?? 0) ?>
            <div style="font-size:10px;color:var(--text-muted);line-height:1.4;white-space:nowrap">
              <?php
              $ef = (float)($t['total_efectivo'] ?? 0);
              $ta = (float)($t['total_tarjeta'] ?? 0);
              $qr = (float)($t['total_qr']      ?? 0);
              $parts = [];
              if ($ef) $parts[] = 'Ef ' . formatMoney($ef);
              if ($ta) $parts[] = 'Tj ' . formatMoney($ta);
              if ($qr) $parts[] = 'QR ' . formatMoney($qr);
              echo implode(' · ', $parts);
              ?>
            </div>
          </td>
          <td style="text-align:right;font-size:13px;color:var(--text-secondary)"><?= formatMoney($t['gastos_total'] ?? 0) ?></td>
          <td style="text-align:right;font-size:13px"><?= $abierto ? '<span style="color:var(--text-muted)">—</span>' : formatMoney($t['caja_esperada'] ?? 0) ?></td>
          <td style="text-align:right;font-size:13px"><?= $abierto ? '<span style="color:var(--text-muted)">—</span>' : formatMoney($t['caja_real'] ?? 0) ?></td>
          <td style="text-align:right;font-weight:700;color:<?= $abierto ? 'var(--text-muted)' : difColor($difVal ?? 0) ?>">
            <?= $abierto ? '—' : difLabel($difVal ?? 0) ?>
          </td>
        </tr>
        <!-- Detail row -->
        <tr id="<?= $detailId ?>" style="display:none">
          <td colspan="12" style="padding:0">
            <div style="background:#f8f8f9;border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:16px 20px">
              <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">

                <!-- Gastos -->
                <div style="flex:1;min-width:220px">
                  <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Gastos del turno</div>
                  <?php if (empty($gastos)): ?>
                    <div style="font-size:13px;color:var(--text-muted)">Sin gastos registrados.</div>
                  <?php else: ?>
                    <table style="width:100%;border-collapse:collapse;font-size:13px">
                      <thead>
                        <tr>
                          <th style="text-align:left;padding:4px 8px 4px 0;color:var(--text-secondary);font-weight:600;border-bottom:1px solid var(--border)">Concepto</th>
                          <th style="text-align:right;padding:4px 0 4px 8px;color:var(--text-secondary);font-weight:600;border-bottom:1px solid var(--border)">Monto</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($gastos as $g): ?>
                        <tr>
                          <td style="padding:4px 8px 4px 0;color:var(--text-primary)"><?= clean($g['concepto'] ?? '') ?></td>
                          <td style="padding:4px 0 4px 8px;text-align:right;font-weight:600"><?= formatMoney((float)($g['monto'] ?? 0)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="border-top:1px solid var(--border)">
                          <td style="padding:6px 8px 0 0;font-weight:700">Total gastos</td>
                          <td style="padding:6px 0 0 8px;text-align:right;font-weight:700"><?= formatMoney($t['gastos_total'] ?? 0) ?></td>
                        </tr>
                      </tbody>
                    </table>
                  <?php endif; ?>
                </div>

                <!-- Desglose de cobros -->
                <div style="flex:1;min-width:220px">
                  <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Cobros por método</div>
                  <table style="width:100%;border-collapse:collapse;font-size:13px">
                    <tbody>
                      <?php
                      $metodos = [
                          'Efectivo' => (float)($t['total_efectivo'] ?? 0),
                          'Tarjeta'  => (float)($t['total_tarjeta']  ?? 0),
                          'QR/Yape'  => (float)($t['total_qr']       ?? 0),
                          'Otros'    => (float)($t['total_otros']     ?? 0),
                      ];
                      foreach ($metodos as $ml => $mv): ?>
                      <tr>
                        <td style="padding:3px 8px 3px 0;color:var(--text-secondary)"><?= $ml ?></td>
                        <td style="padding:3px 0 3px 8px;text-align:right;font-weight:600"><?= formatMoney($mv) ?></td>
                      </tr>
                      <?php endforeach; ?>
                      <tr style="border-top:1px solid var(--border)">
                        <td style="padding:6px 8px 0 0;font-weight:700">Total ventas</td>
                        <td style="padding:6px 0 0 8px;text-align:right;font-weight:700"><?= formatMoney($t['total_ventas'] ?? 0) ?></td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <!-- Arqueo y acciones -->
                <div style="flex:0 0 auto;min-width:180px">
                  <?php if (!$abierto): ?>
                  <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Arqueo</div>
                  <table style="font-size:13px;border-collapse:collapse">
                    <tr>
                      <td style="padding:3px 12px 3px 0;color:var(--text-secondary)">Inicial</td>
                      <td style="font-weight:600;text-align:right"><?= formatMoney($t['monto_inicial'] ?? 0) ?></td>
                    </tr>
                    <tr>
                      <td style="padding:3px 12px 3px 0;color:var(--text-secondary)">Efectivo cobrado</td>
                      <td style="font-weight:600;text-align:right"><?= formatMoney($t['ingreso_efectivo'] ?? 0) ?></td>
                    </tr>
                    <tr>
                      <td style="padding:3px 12px 3px 0;color:var(--text-secondary)">Gastos</td>
                      <td style="font-weight:600;text-align:right;color:var(--text-secondary)">- <?= formatMoney($t['gastos_total'] ?? 0) ?></td>
                    </tr>
                    <tr style="border-top:1px solid var(--border)">
                      <td style="padding:5px 12px 3px 0;font-weight:700">Esperada</td>
                      <td style="font-weight:700;text-align:right"><?= formatMoney($t['caja_esperada'] ?? 0) ?></td>
                    </tr>
                    <tr>
                      <td style="padding:3px 12px 3px 0;font-weight:700">Real</td>
                      <td style="font-weight:700;text-align:right"><?= formatMoney($t['caja_real'] ?? 0) ?></td>
                    </tr>
                    <tr>
                      <td style="padding:3px 12px 3px 0;font-weight:700">Diferencia</td>
                      <td style="font-weight:700;text-align:right;color:<?= difColor($difVal ?? 0) ?>"><?= difLabel($difVal ?? 0) ?></td>
                    </tr>
                  </table>
                  <?php endif; ?>

                  <div style="margin-top:14px">
                    <a href="<?= APP_URL ?>/admin/pedidos/index.php?turno=<?= (int)$t['id'] ?>"
                       style="font-size:13px;color:var(--blue);text-decoration:none;font-weight:600"
                       target="_blank">
                      Ver pedidos del turno &#8250;
                    </a>
                  </div>
                </div>

              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- MOBILE -->
  <div class="caja-mobile">
    <?php foreach ($turnos as $t):
      $abierto  = $t['estado'] === 'abierto';
      $difVal   = $abierto ? null : (float)($t['diferencia'] ?? 0);
      $gastos   = json_decode($t['gastos_json'] ?? '[]', true) ?: [];
      $detailId = 'mob-detail-' . $t['id'];
    ?>
    <div style="border-bottom:1px solid var(--border)">
      <!-- Summary row -->
      <div onclick="toggleMob('<?= $detailId ?>', this)"
           style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;cursor:pointer;user-select:none">
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:3px">
            <span style="font-size:14px;font-weight:700;color:var(--text-primary)"><?= clean($t['cajero_nombre'] ?? '—') ?></span>
            <?php if ($abierto): ?>
              <span class="badge badge-info">Abierto</span>
            <?php else: ?>
              <span class="badge badge-secondary">Cerrado</span>
            <?php endif; ?>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">
            <?= formatDatetime($t['abierto_en']) ?>
            <?= !$abierto ? ' → ' . formatDatetime($t['cerrado_en']) : '' ?>
          </div>
          <div style="display:flex;gap:12px;flex-wrap:wrap;font-size:13px">
            <span><span style="color:var(--text-muted)">Ventas: </span><strong><?= formatMoney($t['total_ventas'] ?? 0) ?></strong></span>
            <?php if (!$abierto): ?>
            <span style="color:<?= difColor($difVal ?? 0) ?>;font-weight:700"><?= difLabel($difVal ?? 0) ?></span>
            <?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= clean($t['ubi_nombre'] ?? '') ?></div>
        </div>
        <div class="mob-arrow" style="font-size:16px;color:var(--text-muted);padding-top:2px;transition:transform .2s">&#9658;</div>
      </div>
      <!-- Detail panel (mobile) -->
      <div id="<?= $detailId ?>" style="display:none;background:#f8f8f9;padding:12px 16px 16px;border-top:1px solid var(--border)">
        <!-- Gastos -->
        <?php if (!empty($gastos)): ?>
        <div style="margin-bottom:12px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px">Gastos</div>
          <?php foreach ($gastos as $g): ?>
          <div style="display:flex;justify-content:space-between;font-size:13px;padding:2px 0">
            <span style="color:var(--text-secondary)"><?= clean($g['concepto'] ?? '') ?></span>
            <strong><?= formatMoney((float)($g['monto'] ?? 0)) ?></strong>
          </div>
          <?php endforeach; ?>
          <div style="display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-top:1px solid var(--border);font-weight:700;margin-top:4px">
            <span>Total gastos</span><span><?= formatMoney($t['gastos_total'] ?? 0) ?></span>
          </div>
        </div>
        <?php endif; ?>
        <!-- Métodos cobro -->
        <div style="margin-bottom:12px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px">Cobros</div>
          <?php foreach (['Efectivo'=>'total_efectivo','Tarjeta'=>'total_tarjeta','QR/Yape'=>'total_qr','Otros'=>'total_otros'] as $ml => $mk): ?>
          <?php if ((float)($t[$mk] ?? 0) > 0): ?>
          <div style="display:flex;justify-content:space-between;font-size:13px;padding:2px 0">
            <span style="color:var(--text-secondary)"><?= $ml ?></span>
            <strong><?= formatMoney((float)($t[$mk] ?? 0)) ?></strong>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php if (!$abierto): ?>
        <!-- Arqueo -->
        <div style="margin-bottom:12px">
          <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px">Arqueo</div>
          <div style="display:flex;justify-content:space-between;font-size:13px;padding:2px 0"><span style="color:var(--text-secondary)">Esperada</span><strong><?= formatMoney($t['caja_esperada'] ?? 0) ?></strong></div>
          <div style="display:flex;justify-content:space-between;font-size:13px;padding:2px 0"><span style="color:var(--text-secondary)">Real</span><strong><?= formatMoney($t['caja_real'] ?? 0) ?></strong></div>
          <div style="display:flex;justify-content:space-between;font-size:13px;padding:2px 0"><span style="color:var(--text-secondary)">Diferencia</span><strong style="color:<?= difColor($difVal ?? 0) ?>"><?= difLabel($difVal ?? 0) ?></strong></div>
        </div>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/admin/pedidos/index.php?turno=<?= (int)$t['id'] ?>"
           style="font-size:13px;color:var(--blue);text-decoration:none;font-weight:600"
           target="_blank">
          Ver pedidos del turno &#8250;
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div>

<?php endif; ?>

<style>
.caja-desktop { display: block; }
.caja-mobile  { display: none; }
@media (max-width: 768px) {
  .caja-desktop { display: none; }
  .caja-mobile  { display: block; }
}
.caja-main-row:hover { background: #fafafa; }
</style>

<script>
function toggleCajaDetail(detailId, rowId) {
    var detail = document.getElementById(detailId);
    var arrow  = document.getElementById(rowId.replace('caja-row-', 'arrow-'));
    var open   = detail.style.display !== 'none' && detail.style.display !== '';
    if (open) {
        detail.style.display = 'none';
        if (arrow) arrow.style.transform = '';
    } else {
        detail.style.display = '';
        if (arrow) arrow.style.transform = 'rotate(90deg)';
    }
}
function toggleMob(detailId, trigger) {
    var detail = document.getElementById(detailId);
    var arrow  = trigger.querySelector('.mob-arrow');
    var open   = detail.style.display !== 'none' && detail.style.display !== '';
    if (open) {
        detail.style.display = 'none';
        if (arrow) arrow.style.transform = '';
    } else {
        detail.style.display = '';
        if (arrow) arrow.style.transform = 'rotate(90deg)';
    }
}
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
