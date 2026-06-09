<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

// Eliminar (solo admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && isAdmin()) {
    verifyCsrf();
    $id    = cleanInt($_POST['delete_id']);
    $count = Database::fetch("SELECT COUNT(*) as n FROM quotes WHERE client_id = ?", [$id]);
    if ((int)$count['n'] > 0) {
        flashMessage('error', 'No puedes eliminar un cliente que tiene cotizaciones.');
    } else {
        Database::execute("DELETE FROM clients WHERE id = ?", [$id]);
        flashMessage('success', 'Cliente eliminado.');
    }
    redirect('/admin/clients/index.php');
}

$search  = clean($_GET['q']    ?? '');
$typeF   = clean($_GET['type'] ?? '');
$page    = max(1, cleanInt($_GET['page'] ?? 1));
$perPage = 25;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(name LIKE ? OR ruc_dni LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $s = '%'.$search.'%';
    array_push($params, $s, $s, $s, $s);
}
if ($typeF === 'empresa')  { $where[] = 'type = "empresa"';  }
if ($typeF === 'persona')  { $where[] = 'type = "persona"';  }

$whereStr = implode(' AND ', $where);
$total    = (int)Database::fetch("SELECT COUNT(*) as n FROM clients WHERE $whereStr", $params)['n'];
$pag      = paginate($total, $perPage, $page);

$clients = Database::fetchAll(
    "SELECT c.*,
       (SELECT COUNT(*) FROM quotes q WHERE q.client_id = c.id) as quote_count
     FROM clients c
     WHERE $whereStr
     ORDER BY c.name
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $pag['offset']])
);

$pageTitle  = 'Clientes';
$activePage = 'clients';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Clientes</h1>
    <p><?= $total ?> clientes registrados</p>
  </div>
  <a href="<?= APP_URL ?>/admin/clients/form.php" class="btn btn-primary" style="gap:6px">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
    Nuevo cliente
  </a>
</div>

<div class="card">
  <div class="toolbar">
    <form method="get" style="display:contents">
      <div class="search-bar">
        <span class="search-icon"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>
        <input type="text" name="q" value="<?= clean($search) ?>"
               placeholder="Nombre, RUC, email…" autocomplete="off">
      </div>
      <select name="type" style="width:auto;padding:9px 14px">
        <option value="">Todos los tipos</option>
        <option value="empresa" <?= $typeF==='empresa' ? 'selected':'' ?>>Empresas</option>
        <option value="persona" <?= $typeF==='persona' ? 'selected':'' ?>>Personas</option>
      </select>
      <?php if ($search || $typeF): ?>
        <a href="?" class="btn btn-ghost btn-sm">✕ Limpiar</a>
      <?php endif; ?>
    </form>
  </div>

  <div id="liveResults">
  <?php if (empty($clients)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
      <h3>Sin clientes</h3>
      <p>Registra tus clientes para asignarlos a las cotizaciones</p>
      <a href="<?= APP_URL ?>/admin/clients/form.php" class="btn btn-primary" style="gap:6px">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        Nuevo cliente
      </a>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead>
        <tr>
          <th>Nombre / Razón social</th>
          <th>Tipo</th>
          <th>RUC / DNI</th>
          <th>Contacto</th>
          <th>Teléfono</th>
          <th>Cotizaciones</th>
          <th style="width:130px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
        <tr>
          <td>
            <strong><?= clean($c['name']) ?></strong>
            <?php if ($c['email']): ?>
              <div style="font-size:12px;color:var(--text-muted)"><?= clean($c['email']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($c['type'] === 'empresa'): ?>
              <span class="badge badge-info" style="display:inline-flex;align-items:center;gap:5px">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/></svg>
                Empresa
              </span>
            <?php else: ?>
              <span class="badge badge-secondary" style="display:inline-flex;align-items:center;gap:5px">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Persona
              </span>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:13px"><?= clean($c['ruc_dni'] ?? '—') ?></td>
          <td><?= clean($c['contact_name'] ?? '—') ?></td>
          <td>
            <?php if ($c['phone']): ?>
              <a href="https://wa.me/51<?= preg_replace('/\D/','',$c['phone']) ?>"
                 target="_blank" style="color:var(--green);text-decoration:none;display:inline-flex;align-items:center;gap:5px">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/></svg>
                <?= clean($c['phone']) ?>
              </a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <span class="badge badge-<?= $c['quote_count'] > 0 ? 'success' : 'secondary' ?>">
              <?= $c['quote_count'] ?> cot.
            </span>
          </td>
          <td>
            <div class="td-actions">
              <a href="form.php?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:5px">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                Editar
              </a>
              <?php if (isAdmin()): ?>
              <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" style="display:inline-flex;align-items:center"
                  data-confirm="¿Eliminar al cliente «<?= clean($c['name']) ?>»?">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pag['total_pages'] > 1): ?>
  <div class="pagination">
    <?php if ($pag['has_prev']): ?>
      <a href="?q=<?= urlencode($search) ?>&type=<?= $typeF ?>&page=<?= $page-1 ?>" class="page-btn">‹</a>
    <?php endif; ?>
    <?php for ($i=max(1,$page-2); $i<=min($pag['total_pages'],$page+2); $i++): ?>
      <a href="?q=<?= urlencode($search) ?>&type=<?= $typeF ?>&page=<?= $i ?>"
         class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($pag['has_next']): ?>
      <a href="?q=<?= urlencode($search) ?>&type=<?= $typeF ?>&page=<?= $page+1 ?>" class="page-btn">›</a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  </div><!-- /liveResults -->
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
