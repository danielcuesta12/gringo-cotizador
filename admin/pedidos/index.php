<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

// Cambiar estado o borrar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (isset($_POST['delete_id'])) {
        Database::execute("DELETE FROM pedidos WHERE id = ?", [cleanInt($_POST['delete_id'])]);
        flashMessage('success', 'Pedido eliminado.');
    }
    redirect('/admin/pedidos/index.php');
}

// Mapa de estados
$ESTADOS = [
    'pendiente'      => ['Esperando pago',  'badge-warning'],
    'en_preparacion' => ['En preparación',  'badge-info'],
    'listo'          => ['Listo',           'badge-success'],
    'entregado'      => ['Entregado',       'badge-secondary'],
    'cancelado'      => ['Cancelado',       'badge-danger'],
];

$filter  = clean($_GET['estado'] ?? 'activos');
$ubiF    = cleanInt($_GET['ubi'] ?? 0);
$page    = max(1, cleanInt($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$tableReady = true;
$pedidos = []; $total = 0; $cmap = []; $ubicaciones = [];
try {
    $ubicaciones = Database::fetchAll("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, nombre");

    $where = '1=1'; $params = [];
    if ($filter === 'activos')      { $where .= " AND p.estado IN ('pendiente','en_preparacion')"; }
    elseif (isset($ESTADOS[$filter])) { $where .= " AND p.estado = ?"; $params[] = $filter; }
    if ($ubiF > 0) { $where .= " AND p.ubicacion_id = ?"; $params[] = $ubiF; }

    $total = (int)Database::fetch("SELECT COUNT(*) n FROM pedidos p WHERE $where", $params)['n'];
    $pedidos = Database::fetchAll(
        "SELECT p.*, u.nombre AS ubi_nombre
         FROM pedidos p LEFT JOIN ubicaciones u ON u.id = p.ubicacion_id
         WHERE $where ORDER BY p.created_at DESC LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );
    $counts = Database::fetchAll("SELECT estado, COUNT(*) n FROM pedidos GROUP BY estado");
    $cmap   = array_column($counts, 'n', 'estado');
} catch (Exception $e) {
    $tableReady = false;
}
$pag = paginate($total, $perPage, $page);

$pageTitle  = 'Pedidos';
$activePage = 'pedidos';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 style="display:flex;align-items:center;gap:8px">
      <span style="display:inline-flex;color:var(--text-secondary)"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg></span>
      Pedidos
    </h1>
    <p>Pedidos recibidos desde la carta de venta</p>
  </div>
  <a href="<?= APP_URL ?>/admin/kds/index.php" class="btn btn-primary" target="_blank" style="display:inline-flex;align-items:center;gap:6px">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
    Abrir KDS (cocina)
  </a>
</div>

<?php if (!$tableReady): ?>
  <div class="card"><div class="empty-state">
    <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg></div>
    <h3>Falta crear la tabla de pedidos</h3>
    <p>Aplica <code>install/pedidos.sql</code> (y <code>install/pedidos_kds.sql</code> si ya la tenías) en phpMyAdmin.</p>
  </div></div>
<?php else: ?>

<!-- Filtros -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
  <?php
  $tabs = ['activos'=>'Activos','listo'=>'Listos','entregado'=>'Entregados','cancelado'=>'Cancelados','all'=>'Todos'];
  foreach ($tabs as $val => $label):
    if ($val==='activos') $cnt = ($cmap['pendiente']??0)+($cmap['en_preparacion']??0);
    elseif ($val==='all') $cnt = array_sum($cmap);
    else $cnt = $cmap[$val] ?? 0;
    $active = $filter === $val;
    $q = 'estado='.$val.($ubiF?'&ubi='.$ubiF:'');
  ?>
  <a href="?<?= $q ?>" style="padding:6px 14px;border-radius:20px;font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid;white-space:nowrap;<?= $active?'background:var(--brand);color:#1a1a1a;border-color:var(--brand)':'background:#fff;color:var(--text-secondary);border-color:var(--border)' ?>">
    <?= $label ?> <span style="font-size:11px;opacity:.8">(<?= $cnt ?>)</span>
  </a>
  <?php endforeach; ?>

  <?php if (count($ubicaciones) > 1): ?>
  <select onchange="location.href='?estado=<?= clean($filter) ?>'+(this.value?'&ubi='+this.value:'')" style="margin-left:auto;padding:6px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:13px;background:#fff">
    <option value="">Todas las ubicaciones</option>
    <?php foreach ($ubicaciones as $u): ?>
      <option value="<?= (int)$u['id'] ?>" <?= $ubiF==$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (empty($pedidos)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg></div>
      <h3>Sin pedidos</h3>
      <p>Cuando un cliente haga un pedido desde la carta, aparecerá aquí</p>
    </div>
  <?php else: ?>

  <!-- DESKTOP -->
  <div class="ped-desktop">
    <table class="data-table">
      <thead><tr><th>#</th><th>Cliente</th><th>Detalle</th><th>Ubicación</th><th>Total</th><th>Pago</th><th>Recibido</th><th>Estado</th><th style="width:90px"></th></tr></thead>
      <tbody>
        <?php foreach ($pedidos as $p):
          $items = json_decode($p['items_json'] ?? '[]', true) ?: [];
          $resumen = implode(', ', array_map(fn($i) => (($i['qty'] ?? 1).'x '.($i['nombre'] ?? '')), $items));
          [$el, $ec] = $ESTADOS[$p['estado']] ?? [$p['estado'], 'badge-secondary'];
          $tipo = ($p['origen'] ?? 'carta')==='pos' ? 'Salón' : ($p['tipo_entrega']==='delivery'?'Delivery':'Recojo');
        ?>
        <tr>
          <td><a href="<?= APP_URL ?>/admin/pedidos/detail.php?id=<?= $p['id'] ?>" style="font-weight:700;color:var(--ink);text-decoration:none">#<?= str_pad($p['id'],3,'0',STR_PAD_LEFT) ?></a></td>
          <td>
            <a href="<?= APP_URL ?>/admin/pedidos/detail.php?id=<?= $p['id'] ?>" style="font-weight:600;color:var(--ink);text-decoration:none"><?= clean($p['nombre'] ?: 'Cliente') ?></a>
            <div style="font-size:11px;color:var(--text-muted)"><?= $tipo ?><?= $p['telefono'] ? ' · '.clean($p['telefono']) : '' ?></div>
          </td>
          <td style="max-width:280px"><div style="font-size:12px;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= clean($resumen) ?></div></td>
          <td style="font-size:13px"><?= clean($p['ubi_nombre'] ?: '—') ?></td>
          <td style="font-weight:700"><?= formatMoney($p['total']) ?></td>
          <td><span class="badge <?= $p['metodo_pago']==='izipay'?'badge-info':'badge-secondary' ?>"><?= $p['metodo_pago']==='izipay'?'Izipay':'WhatsApp' ?></span></td>
          <td style="font-size:12px;color:var(--text-muted)"><?= formatDatetime($p['created_at']) ?></td>
          <td><span class="badge <?= $ec ?>"><?= $el ?></span></td>
          <td>
            <div class="td-actions">
              <a href="<?= APP_URL ?>/admin/pedidos/detail.php?id=<?= $p['id'] ?>" class="btn btn-ghost btn-sm">Ver</a>
              <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="delete_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" title="Eliminar" data-confirm="¿Eliminar el pedido #<?= str_pad($p['id'],3,'0',STR_PAD_LEFT) ?>?">
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
  <div class="ped-mobile">
    <?php foreach ($pedidos as $p):
      $items = json_decode($p['items_json'] ?? '[]', true) ?: [];
      $resumen = implode(', ', array_map(fn($i) => (($i['qty'] ?? 1).'x '.($i['nombre'] ?? '')), $items));
      [$el, $ec] = $ESTADOS[$p['estado']] ?? [$p['estado'], 'badge-secondary'];
    ?>
    <a href="<?= APP_URL ?>/admin/pedidos/detail.php?id=<?= $p['id'] ?>" style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);text-decoration:none">
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:700;color:#1a1a1a">#<?= str_pad($p['id'],3,'0',STR_PAD_LEFT) ?> · <?= clean($p['nombre'] ?: 'Cliente') ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= clean($resumen) ?></div>
        <div style="margin-top:5px"><span class="badge <?= $ec ?>" style="font-size:10px"><?= $el ?></span> <span style="font-size:12px;font-weight:700;color:var(--ink);margin-left:4px"><?= formatMoney($p['total']) ?></span></div>
      </div>
      <div style="font-size:18px;color:var(--text-muted)">&#8250;</div>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($pag['total_pages'] > 1): ?>
  <div class="pagination">
    <?php $qs = 'estado='.$filter.($ubiF?'&ubi='.$ubiF:''); ?>
    <?php if ($pag['has_prev']): ?><a href="?<?= $qs ?>&page=<?= $page-1 ?>" class="page-btn">&#8249;</a><?php endif; ?>
    <?php for ($i=max(1,$page-2);$i<=min($pag['total_pages'],$page+2);$i++): ?>
    <a href="?<?= $qs ?>&page=<?= $i ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($pag['has_next']): ?><a href="?<?= $qs ?>&page=<?= $page+1 ?>" class="page-btn">&#8250;</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<style>.ped-desktop{display:block}.ped-mobile{display:none}@media(max-width:768px){.ped-desktop{display:none}.ped-mobile{display:block}}</style>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
