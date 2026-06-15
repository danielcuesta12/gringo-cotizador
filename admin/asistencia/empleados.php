<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('asistencia');

// Eliminar empleado (SOLO admin). Borra en duro si no tiene marcas; si tiene
// historial de asistencia, se desactiva para conservar los reportes.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
    verifyCsrf();
    if (!isAdmin()) { flashMessage('error', 'Solo un administrador puede eliminar empleados.'); redirect('/admin/asistencia/empleados'); }
    $eid = cleanInt($_POST['empleado_id'] ?? 0);
    $emp = $eid ? Database::fetch("SELECT * FROM empleados WHERE id = ?", [$eid]) : null;
    if (!$emp) { flashMessage('error', 'Empleado no encontrado.'); redirect('/admin/asistencia/empleados'); }
    $nMarcas = (int) (Database::fetch("SELECT COUNT(*) n FROM asistencia_marcas WHERE empleado_id = ?", [$eid])['n'] ?? 0);
    if ($nMarcas > 0) {
        Database::execute("UPDATE empleados SET activo = 0 WHERE id = ?", [$eid]);
        flashMessage('success', 'El empleado tiene ' . $nMarcas . ' marca(s) registradas; se desactivó para conservar el historial.');
    } else {
        if (!empty($emp['foto_referencia']) && is_file(UPLOAD_PATH . $emp['foto_referencia'])) @unlink(UPLOAD_PATH . $emp['foto_referencia']);
        Database::execute("DELETE FROM empleados WHERE id = ?", [$eid]);
        flashMessage('success', 'Empleado eliminado.');
    }
    redirect('/admin/asistencia/empleados');
}

$empleados = Database::fetchAll(
    "SELECT e.*, u.nombre AS ubi_nombre
     FROM empleados e
     LEFT JOIN ubicaciones u ON u.id = e.ubicacion_id
     ORDER BY e.activo DESC, e.nombre"
);

$pageTitle  = 'Empleados';
$activePage = 'asistencia';
$extraHead  = '<style>
.emp-table{width:100%;border-collapse:collapse}
.emp-table th{text-align:left;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#888);padding:8px 10px;border-bottom:2px solid var(--border,#eee)}
.emp-table td{padding:10px;border-bottom:1px solid var(--border,#eee);vertical-align:middle}
.emp-table tr:last-child td{border-bottom:none}
.emp-thumb{width:40px;height:40px;border-radius:10px;object-fit:cover;background:var(--bg-page,#f1f1f4);display:flex;align-items:center;justify-content:center;color:var(--text-muted,#aaa);font-size:18px;font-weight:800}
.emp-thumb img{width:40px;height:40px;border-radius:10px;object-fit:cover;display:block}
.badge-activo{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800}
.badge-activo.si{background:#e6f5ec;color:#16a34a}
.badge-activo.no{background:#f1f1f4;color:#999}
.emp-empty{text-align:center;color:var(--text-muted,#888);padding:40px 0}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left"><h1>Empleados</h1></div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/asistencia/empleado_form.php" class="btn btn-primary">+ Nuevo empleado</a>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <?php if (!$empleados): ?>
      <div class="emp-empty">No hay empleados registrados.</div>
    <?php else: ?>
    <table class="emp-table">
      <thead>
        <tr>
          <th style="width:52px">Foto</th>
          <th>Nombre</th>
          <th>Cargo</th>
          <th>Local</th>
          <th>PIN</th>
          <th>Estado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($empleados as $e): ?>
        <tr>
          <td>
            <?php if (!empty($e['foto_referencia'])): ?>
              <div class="emp-thumb"><img src="<?= UPLOAD_URL . clean($e['foto_referencia']) ?>" alt="<?= clean($e['nombre']) ?>"></div>
            <?php else: ?>
              <div class="emp-thumb"><?= mb_strtoupper(mb_substr(clean($e['nombre']), 0, 1)) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-weight:700"><?= clean($e['nombre']) ?></td>
          <td style="color:var(--text-muted,#888)"><?= $e['cargo'] ? clean($e['cargo']) : '—' ?></td>
          <td><?= $e['ubi_nombre'] ? clean($e['ubi_nombre']) : '—' ?></td>
          <td><?= !empty($e['pin_hash']) ? 'Sí' : '—' ?></td>
          <td>
            <span class="badge-activo <?= $e['activo'] ? 'si' : 'no' ?>">
              <?= $e['activo'] ? 'Activo' : 'Inactivo' ?>
            </span>
          </td>
          <td style="white-space:nowrap">
            <a href="<?= APP_URL ?>/admin/asistencia/empleado_form.php?id=<?= (int)$e['id'] ?>" class="btn btn-secondary" style="padding:6px 14px;font-size:12px">Editar</a>
            <?php if (isAdmin()): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este empleado? Si tiene marcas registradas, se desactivará para conservar el historial.');">
              <?= csrfField() ?>
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="empleado_id" value="<?= (int)$e['id'] ?>">
              <button type="submit" class="btn" style="padding:6px 14px;font-size:12px;background:#fee2e2;color:#dc2626;border:1px solid #fecaca">Eliminar</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
