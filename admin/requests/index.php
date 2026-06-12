<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('requests');

// Borrar solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    $delId = cleanInt($_POST['delete_id']);
    Database::execute("DELETE FROM quote_requests WHERE id = ?", array($delId));
    flashMessage('success', 'Solicitud eliminada.');
    redirect('/admin/requests/index.php');
}

$filter  = clean(isset($_GET['status']) ? $_GET['status'] : 'pendiente');
$page    = max(1, cleanInt(isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = '1=1';
$params = array();
if ($filter !== 'all') { $where = 'status = ?'; $params[] = $filter; }

$total    = (int)Database::fetch("SELECT COUNT(*) as n FROM quote_requests WHERE $where", $params)['n'];
$requests = Database::fetchAll(
    "SELECT * FROM quote_requests WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
    array_merge($params, array($perPage, $offset))
);
$pag = paginate($total, $perPage, $page);

$counts = Database::fetchAll("SELECT status, COUNT(*) as n FROM quote_requests GROUP BY status");
$cmap   = array_column($counts, 'n', 'status');

$pageTitle  = 'Solicitudes de cotizacion';
$activePage = 'requests';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 style="display:flex;align-items:center;gap:8px">
      <span style="display:inline-flex;color:var(--text-secondary)"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/></svg></span>
      Solicitudes de cotizacion
    </h1>
    <p>Solicitudes enviadas desde el formulario publico</p>
  </div>
  <a href="<?php echo APP_URL; ?>/solicitud" class="btn btn-ghost btn-sm" target="_blank" style="display:inline-flex;align-items:center;gap:6px">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
    Ver formulario publico
  </a>
</div>

<!-- Filtros -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <?php
  $filters = array('pendiente'=>'Pendientes','aceptada'=>'Aceptadas','rechazada'=>'Rechazadas','all'=>'Todas');
  foreach ($filters as $val => $label):
    $cnt    = $val === 'all' ? array_sum($cmap) : (isset($cmap[$val]) ? $cmap[$val] : 0);
    $active = $filter === $val;
  ?>
  <a href="?status=<?php echo $val; ?>"
     style="padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid;white-space:nowrap;
            <?php echo $active ? 'background:var(--brand);color:#1a1a1a;border-color:var(--brand)' : 'background:#fff;color:var(--text-secondary);border-color:var(--border)'; ?>">
    <?php echo $label; ?> <span style="font-size:11px;opacity:.8">(<?php echo $cnt; ?>)</span>
  </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (empty($requests)): ?>
  <div class="empty-state">
    <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11Z"/></svg></div>
    <h3>Sin solicitudes</h3>
    <p>Cuando un cliente complete el formulario publico, aparecera aqui</p>
  </div>
  <?php else: ?>

  <!-- DESKTOP -->
  <div class="requests-desktop">
    <table class="data-table">
      <thead><tr>
        <th>Cliente</th><th>Tipo</th><th>Evento</th><th>Personas</th><th>Recibida</th><th>Estado</th><th style="width:120px"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
        <tr>
          <td>
            <a href="<?php echo APP_URL; ?>/admin/requests/detail.php?id=<?php echo $r['id']; ?>"
               style="font-weight:600;color:var(--ink);text-decoration:none"><?php echo clean($r['name']); ?></a>
            <?php if ($r['email']): ?><div style="font-size:11px;color:var(--text-muted)"><?php echo clean($r['email']); ?></div><?php endif; ?>
          </td>
          <td>
            <?php if ($r['type']==='empresa'): ?>
              <span style="display:inline-flex;align-items:center;gap:6px"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/></svg> Empresa</span>
            <?php else: ?>
              <span style="display:inline-flex;align-items:center;gap:6px"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Persona</span>
            <?php endif; ?>
          </td>
          <td>
            <?php echo $r['event_date'] ? formatDate($r['event_date']) : '—'; ?>
            <?php if ($r['event_location']): ?><div style="font-size:11px;color:var(--text-muted)"><?php echo clean(mb_substr($r['event_location'],0,30)); ?></div><?php endif; ?>
          </td>
          <td><?php echo $r['num_people'] > 0 ? $r['num_people'] . ' pers.' : '—'; ?></td>
          <td style="font-size:12px;color:var(--text-muted)"><?php echo formatDatetime($r['created_at']); ?></td>
          <td>
            <?php
            $bc = array('pendiente'=>'badge-warning','aceptada'=>'badge-success','rechazada'=>'badge-danger');
            $bl = array('pendiente'=>'Pendiente','aceptada'=>'Aceptada','rechazada'=>'Rechazada');
            ?>
            <span class="badge <?php echo isset($bc[$r['status']])?$bc[$r['status']]:'badge-secondary'; ?>">
              <?php echo isset($bl[$r['status']])?$bl[$r['status']]:$r['status']; ?>
            </span>
          </td>
          <td>
            <div class="td-actions">
              <a href="<?php echo APP_URL; ?>/admin/requests/detail.php?id=<?php echo $r['id']; ?>"
                 class="btn btn-ghost btn-sm">Ver detalle</a>
              <form method="post" style="display:inline">
                <?php echo csrfField(); ?>
                <input type="hidden" name="delete_id" value="<?php echo $r['id']; ?>">
                <button type="submit" class="btn btn-danger btn-sm" title="Eliminar"
                        data-confirm="¿Eliminar la solicitud de «<?php echo clean($r['name']); ?>»? Esta acción no se puede deshacer.">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- MOBILE -->
  <div class="requests-mobile">
    <?php foreach ($requests as $r):
      $colors = array('pendiente'=>'#ca8a04','aceptada'=>'#16a34a','rechazada'=>'#dc2626');
      $dc = isset($colors[$r['status']]) ? $colors[$r['status']] : '#aaa';
    ?>
    <a href="<?php echo APP_URL; ?>/admin/requests/detail.php?id=<?php echo $r['id']; ?>"
       style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);text-decoration:none;-webkit-tap-highlight-color:transparent">
      <div style="width:40px;height:40px;border-radius:50%;background:var(--red-light);display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:var(--red);flex-shrink:0">
        <?php echo strtoupper(substr($r['name'],0,1)); ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:600;color:#1a1a1a"><?php echo clean($r['name']); ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">
          <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:<?php echo $dc; ?>;margin-right:4px;vertical-align:middle"></span>
          <?php echo isset($bl[$r['status']])?$bl[$r['status']]:''; ?>
          &nbsp;&middot;&nbsp;<?php echo $r['num_people']>0?$r['num_people'].' pers.':''; ?>
          <?php if ($r['event_date']): ?>&nbsp;&middot;&nbsp;<?php echo formatDate($r['event_date']); ?><?php endif; ?>
        </div>
      </div>
      <div style="font-size:18px;color:var(--text-muted)">&#8250;</div>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($pag['total_pages'] > 1): ?>
  <div class="pagination">
    <?php if ($pag['has_prev']): ?><a href="?status=<?php echo $filter; ?>&page=<?php echo $page-1; ?>" class="page-btn">&#8249;</a><?php endif; ?>
    <?php for ($i=max(1,$page-2);$i<=min($pag['total_pages'],$page+2);$i++): ?>
    <a href="?status=<?php echo $filter; ?>&page=<?php echo $i; ?>" class="page-btn <?php echo $i===$page?'active':''; ?>"><?php echo $i; ?></a>
    <?php endfor; ?>
    <?php if ($pag['has_next']): ?><a href="?status=<?php echo $filter; ?>&page=<?php echo $page+1; ?>" class="page-btn">&#8250;</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<style>
.requests-desktop{display:block}.requests-mobile{display:none}
@media(max-width:768px){.requests-desktop{display:none}.requests-mobile{display:block}}
</style>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
