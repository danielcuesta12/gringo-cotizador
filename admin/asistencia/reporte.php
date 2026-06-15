<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/asistencia.php';

requirePermission('asistencia');

$desde = clean($_GET['desde'] ?? date('Y-m-01'));
$hasta = clean($_GET['hasta'] ?? date('Y-m-d'));

$rows = Database::fetchAll(
    "SELECT m.empleado_id, e.nombre, m.tipo, m.marcada_at
       FROM asistencia_marcas m JOIN empleados e ON e.id = m.empleado_id
      WHERE DATE(m.marcada_at) BETWEEN ? AND ?
      ORDER BY m.empleado_id, m.marcada_at",
    [$desde, $hasta]
);

$acc = asistenciaResumen($rows);

uasort($acc, fn($a, $b) => strcmp($a['nombre'], $b['nombre']));

$pageTitle  = 'Reporte de horas';
$activePage = 'asistencia';
include __DIR__ . '/../../admin/layout-top.php';
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.5rem;">
    <h1 style="margin:0;font-size:1.4rem;">Reporte de horas</h1>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
        <a href="<?= APP_URL ?>/admin/asistencia/index.php" class="btn btn-secondary">← Revisión</a>
        <a href="<?= APP_URL ?>/admin/asistencia/empleados.php" class="btn btn-secondary">Empleados</a>
    </div>
</div>

<form method="get" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.5rem;">
    <div>
        <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Desde</label>
        <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"
               style="padding:.45rem .7rem;border:1px solid #ccc;border-radius:6px;">
    </div>
    <div>
        <label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:.3rem;">Hasta</label>
        <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"
               style="padding:.45rem .7rem;border:1px solid #ccc;border-radius:6px;">
    </div>
    <button type="submit" class="btn btn-primary">Ver</button>
</form>

<?php if (empty($acc)): ?>
    <p style="color:#666;">Sin marcas en el rango.</p>
<?php else: ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.92rem;">
            <thead>
                <tr style="background:var(--bg-secondary,#f5f5f5);text-align:left;">
                    <th style="padding:.65rem 1rem;border-bottom:2px solid #ddd;">Empleado</th>
                    <th style="padding:.65rem 1rem;border-bottom:2px solid #ddd;">Horas trabajadas</th>
                    <th style="padding:.65rem 1rem;border-bottom:2px solid #ddd;">Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($acc as $eid => $data):
                    $h = floor($data['segundos'] / 3600);
                    $m = floor(($data['segundos'] % 3600) / 60);
                ?>
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:.65rem 1rem;"><?= htmlspecialchars($data['nombre']) ?></td>
                    <td style="padding:.65rem 1rem;font-variant-numeric:tabular-nums;"><?= $h ?>h <?= $m ?>m</td>
                    <td style="padding:.65rem 1rem;">
                        <?php if ($data['incompletas'] > 0): ?>
                            <span style="background:#FEF3C7;color:#92400E;font-size:.8rem;font-weight:600;padding:.25rem .6rem;border-radius:999px;white-space:nowrap;">
                                <?= $data['incompletas'] ?> jornada(s) incompleta(s) — revisar
                            </span>
                        <?php else: ?>
                            <span style="color:#16a34a;font-size:.85rem;">✓ OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../../admin/layout-bottom.php'; ?>
