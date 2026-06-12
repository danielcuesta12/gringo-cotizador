<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('reservas');

// Borrar reserva (solo admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    if (isAdmin()) {
        Database::execute("DELETE FROM reservas WHERE id = ?", array(cleanInt($_POST['delete_id'])));
        flashMessage('success', 'Reserva eliminada.');
    } else {
        flashMessage('error', 'No tienes permiso para eliminar reservas.');
    }
    redirect('/admin/reservas/index.php');
}

$filter  = clean(isset($_GET['estado']) ? $_GET['estado'] : 'pendiente');
$page    = max(1, cleanInt(isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$tableReady = true;
$reservas = array(); $total = 0; $cmap = array();

try {
    $where  = '1=1';
    $params = array();
    if ($filter !== 'all') { $where = 'estado = ?'; $params[] = $filter; }

    // Para pendientes ordenar por fecha de evento asc (más urgentes primero)
    $orderBy = ($filter === 'pendiente') ? 'fecha ASC, hora ASC' : 'created_at DESC';

    $total   = (int)Database::fetch("SELECT COUNT(*) as n FROM reservas WHERE $where", $params)['n'];
    $reservas = Database::fetchAll(
        "SELECT * FROM reservas WHERE $where ORDER BY $orderBy LIMIT ? OFFSET ?",
        array_merge($params, array($perPage, $offset))
    );
    $counts = Database::fetchAll("SELECT estado, COUNT(*) as n FROM reservas GROUP BY estado");
    $cmap   = array_column($counts, 'n', 'estado');
} catch (Exception $e) {
    $tableReady = false;
}

$pag = paginate($total, $perPage, $page);

$pageTitle  = 'Reservas';
$activePage = 'reservas';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 style="display:flex;align-items:center;gap:8px">
      <span style="display:inline-flex;color:var(--text-secondary)"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
      Reservas
    </h1>
    <p>Solicitudes de reserva recibidas desde el formulario publico</p>
  </div>
</div>

<?php if (!$tableReady): ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
    <h3>Falta crear la tabla de reservas</h3>
    <p>Aplica <code>install/reservas.sql</code> en phpMyAdmin para activar este modulo.</p>
  </div>
</div>
<?php else: ?>

<!-- Filtros de estado -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <?php
  $tabs = array(
      'pendiente'  => 'Pendientes',
      'confirmada' => 'Confirmadas',
      'rechazada'  => 'Rechazadas',
      'all'        => 'Todas',
  );
  foreach ($tabs as $val => $label):
    $cnt    = $val === 'all' ? array_sum($cmap) : (isset($cmap[$val]) ? $cmap[$val] : 0);
    $active = $filter === $val;
  ?>
  <a href="?estado=<?php echo $val; ?>"
     style="padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid;white-space:nowrap;
            <?php echo $active ? 'background:var(--brand);color:#1a1a1a;border-color:var(--brand)' : 'background:#fff;color:var(--text-secondary);border-color:var(--border)'; ?>">
    <?php echo $label; ?> <span style="font-size:11px;opacity:.8">(<?php echo $cnt; ?>)</span>
  </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (empty($reservas)): ?>
  <div class="empty-state">
    <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
    <h3>Sin reservas</h3>
    <p>No hay reservas <?php echo $filter !== 'all' ? 'con estado <strong>' . htmlspecialchars($tabs[$filter] ?? $filter) . '</strong>' : ''; ?> por el momento.</p>
  </div>
  <?php else: ?>

  <!-- DESKTOP -->
  <div class="reservas-desktop">
    <table class="data-table">
      <thead><tr>
        <th>Cliente</th>
        <th>Fecha y hora</th>
        <th>Personas</th>
        <th>Telefono</th>
        <th>Estado</th>
        <th>Recibida</th>
        <th style="width:100px"></th>
      </tr></thead>
      <tbody>
        <?php
        $bc = array('pendiente'=>'badge-warning','confirmada'=>'badge-success','rechazada'=>'badge-danger');
        $bl = array('pendiente'=>'Pendiente','confirmada'=>'Confirmada','rechazada'=>'Rechazada');
        foreach ($reservas as $r):
        ?>
        <tr>
          <td>
            <a href="<?php echo APP_URL; ?>/admin/reservas/detail.php?id=<?php echo $r['id']; ?>"
               style="font-weight:600;color:var(--ink);text-decoration:none"><?php echo htmlspecialchars($r['nombre']); ?></a>
            <?php if ($r['email']): ?><div style="font-size:11px;color:var(--text-muted)"><?php echo htmlspecialchars($r['email']); ?></div><?php endif; ?>
          </td>
          <td>
            <?php echo $r['fecha'] ? formatDate($r['fecha']) : '—'; ?>
            <?php if ($r['hora']): ?><div style="font-size:11px;color:var(--text-muted)"><?php echo htmlspecialchars($r['hora']); ?></div><?php endif; ?>
          </td>
          <td><?php echo $r['num_personas'] > 0 ? $r['num_personas'] . ' pers.' : '—'; ?></td>
          <td>
            <?php if ($r['telefono']): ?>
              <a href="tel:+<?php echo preg_replace('/\D/','',$r['telefono']); ?>"
                 style="color:var(--text-primary);text-decoration:none;font-size:13px">
                <?php echo htmlspecialchars($r['telefono']); ?>
              </a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <span class="badge <?php echo isset($bc[$r['estado']]) ? $bc[$r['estado']] : 'badge-secondary'; ?>">
              <?php echo isset($bl[$r['estado']]) ? $bl[$r['estado']] : htmlspecialchars($r['estado']); ?>
            </span>
          </td>
          <td style="font-size:12px;color:var(--text-muted)"><?php echo formatDatetime($r['created_at']); ?></td>
          <td>
            <div class="td-actions">
              <a href="<?php echo APP_URL; ?>/admin/reservas/detail.php?id=<?php echo $r['id']; ?>"
                 class="btn btn-ghost btn-sm">Ver detalle</a>
              <?php if (isAdmin()): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar la reserva de «<?php echo htmlspecialchars(addslashes($r['nombre'])); ?>»? Esta acción no se puede deshacer.');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="delete_id" value="<?php echo $r['id']; ?>">
                <button type="submit" class="btn btn-danger btn-sm" title="Eliminar reserva">Eliminar</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- MOBILE (cards) -->
  <div class="reservas-mobile">
    <?php
    $colors = array('pendiente'=>'#ca8a04','confirmada'=>'#16a34a','rechazada'=>'#dc2626');
    foreach ($reservas as $r):
      $dc = isset($colors[$r['estado']]) ? $colors[$r['estado']] : '#aaa';
    ?>
    <a href="<?php echo APP_URL; ?>/admin/reservas/detail.php?id=<?php echo $r['id']; ?>"
       style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);text-decoration:none;-webkit-tap-highlight-color:transparent">
      <div style="width:40px;height:40px;border-radius:50%;background:var(--red-light);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:var(--red);flex-shrink:0">
        <?php echo strtoupper(substr($r['nombre'], 0, 1)); ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:600;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo htmlspecialchars($r['nombre']); ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
          <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?php echo $dc; ?>;margin-right:4px;vertical-align:middle"></span>
          <?php echo isset($bl[$r['estado']]) ? $bl[$r['estado']] : htmlspecialchars($r['estado']); ?>
          <?php if ($r['fecha']): ?>&nbsp;&middot;&nbsp;<?php echo formatDate($r['fecha']); ?><?php endif; ?>
          <?php if ($r['num_personas'] > 0): ?>&nbsp;&middot;&nbsp;<?php echo $r['num_personas']; ?> pers.<?php endif; ?>
        </div>
      </div>
      <div style="font-size:18px;color:var(--text-muted)">&#8250;</div>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($pag['total_pages'] > 1): ?>
  <div class="pagination">
    <?php if ($pag['has_prev']): ?><a href="?estado=<?php echo $filter; ?>&page=<?php echo $page - 1; ?>" class="page-btn">&#8249;</a><?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($pag['total_pages'], $page + 2); $i++): ?>
    <a href="?estado=<?php echo $filter; ?>&page=<?php echo $i; ?>" class="page-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if ($pag['has_next']): ?><a href="?estado=<?php echo $filter; ?>&page=<?php echo $page + 1; ?>" class="page-btn">&#8250;</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php endif; // tableReady ?>

<style>
.reservas-desktop { display: block; }
.reservas-mobile  { display: none; }
@media (max-width: 768px) {
  .reservas-desktop { display: none; }
  .reservas-mobile  { display: block; }
}
</style>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
