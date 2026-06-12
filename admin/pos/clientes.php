<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('pos_clientes');

$doc = clean($_GET['doc'] ?? '');
$q   = clean($_GET['q']   ?? '');

// ──────────────────────────────────────────────────────────────
// DETAIL MODE
// ──────────────────────────────────────────────────────────────
if ($doc !== '') {

    $tableReady  = true;
    $cliente     = null;
    $historial   = [];

    try {
        $cliente = Database::fetch(
            "SELECT cliente_documento AS doc,
                    MAX(cliente_tipo) AS tipo,
                    COALESCE(MAX(NULLIF(cliente_razon_social,'')), MAX(NULLIF(cliente_nombre,''))) AS nombre,
                    COUNT(*) AS compras,
                    COALESCE(SUM(total),0) AS total_gastado,
                    MAX(created_at) AS ultima
             FROM pedidos
             WHERE origen='pos'
               AND cliente_documento = ?
               AND estado <> 'cancelado'
             GROUP BY cliente_documento",
            [$doc]
        );

        $historial = Database::fetchAll(
            "SELECT p.id, p.total, p.metodo_pago, p.comprobante_tipo, p.created_at,
                    COALESCE(u.nombre,'—') AS ubi
             FROM pedidos p
             LEFT JOIN ubicaciones u ON u.id = p.ubicacion_id
             WHERE p.origen='pos'
               AND p.cliente_documento = ?
               AND p.estado <> 'cancelado'
             ORDER BY p.id DESC",
            [$doc]
        );
    } catch (Exception $e) {
        $tableReady = false;
    }

    $pageTitle  = 'Clientes POS';
    $activePage = 'pos-clientes';
    include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 style="display:flex;align-items:center;gap:8px">
      <span style="display:inline-flex;color:var(--text-secondary)">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </span>
      Clientes POS
    </h1>
    <p>Historial de compras del cliente</p>
  </div>
  <a href="<?= APP_URL ?>/admin/pos/clientes.php<?= $q ? '?q='.urlencode($q) : '' ?>" style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;background:var(--bg-page);color:var(--text-secondary);border:1.5px solid var(--border)">
    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Volver a clientes
  </a>
</div>

<?php if (!$tableReady): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)">
        <svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <h3>Error al cargar datos</h3>
      <p>Aún no hay ventas POS o falta aplicar la migración del POS.</p>
    </div>
  </div>
<?php elseif (!$cliente): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)">
        <svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <h3>Cliente no encontrado</h3>
      <p>No se encontraron compras para el documento <strong><?= htmlspecialchars($doc) ?></strong>.</p>
    </div>
  </div>
<?php else:
    $tipoLabel = ['nombre' => 'Nombre', 'dni' => 'DNI', 'ruc' => 'RUC'];
    $badgeClass = $cliente['tipo'] === 'ruc' ? 'badge-info' : 'badge-secondary';
    $compBadge  = ['ticket' => ['Ticket', 'badge-secondary'], 'boleta' => ['Boleta', 'badge-warning'], 'factura' => ['Factura', 'badge-info']];
?>

<!-- Cabecera cliente -->
<div class="card" style="margin-bottom:16px;padding:20px 24px">
  <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap">
    <div style="width:50px;height:50px;border-radius:50%;background:var(--red-light);display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="var(--red)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
    </div>
    <div style="flex:1;min-width:0">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <h2 style="margin:0;font-size:18px;font-weight:700;color:var(--text-primary)"><?= htmlspecialchars($cliente['nombre'] ?: '—') ?></h2>
        <span class="badge <?= $badgeClass ?>"><?= $tipoLabel[$cliente['tipo']] ?? strtoupper($cliente['tipo']) ?></span>
      </div>
      <div style="font-size:14px;color:var(--text-secondary);margin-top:4px;font-weight:600"><?= htmlspecialchars($cliente['doc']) ?></div>
    </div>
    <div style="display:flex;gap:24px;flex-wrap:wrap">
      <div style="text-align:right">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);font-weight:600">Compras</div>
        <div style="font-size:22px;font-weight:800;color:var(--text-primary)"><?= (int)$cliente['compras'] ?></div>
      </div>
      <div style="text-align:right">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);font-weight:600">Total gastado</div>
        <div style="font-size:22px;font-weight:800;color:var(--red)"><?= formatMoney($cliente['total_gastado']) ?></div>
      </div>
      <div style="text-align:right">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);font-weight:600">Ultima compra</div>
        <div style="font-size:14px;font-weight:600;color:var(--text-primary);margin-top:4px"><?= formatDate($cliente['ultima']) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Historial -->
<div class="card">
  <?php if (empty($historial)): ?>
    <div class="empty-state">
      <h3>Sin compras registradas</h3>
      <p>No se encontraron pedidos para este cliente.</p>
    </div>
  <?php else: ?>

  <!-- DESKTOP -->
  <div class="cli-desktop">
    <table class="data-table">
      <thead>
        <tr>
          <th>#Pedido</th>
          <th>Fecha</th>
          <th>Ubicacion</th>
          <th>Metodo de pago</th>
          <th>Comprobante</th>
          <th style="text-align:right">Total</th>
          <th style="width:80px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($historial as $p):
          [$cbLabel, $cbClass] = $compBadge[$p['comprobante_tipo']] ?? ['—', 'badge-secondary'];
        ?>
        <tr>
          <td><strong style="color:var(--text-primary)">#<?= str_pad($p['id'], 3, '0', STR_PAD_LEFT) ?></strong></td>
          <td style="font-size:13px;color:var(--text-secondary)"><?= formatDatetime($p['created_at']) ?></td>
          <td style="font-size:13px"><?= htmlspecialchars($p['ubi']) ?></td>
          <td><span class="badge badge-secondary"><?= htmlspecialchars(ucfirst($p['metodo_pago'] ?: '—')) ?></span></td>
          <td><?php if ($p['comprobante_tipo']): ?><span class="badge <?= $cbClass ?>"><?= $cbLabel ?></span><?php else: ?>—<?php endif; ?></td>
          <td style="text-align:right;font-weight:700"><?= formatMoney($p['total']) ?></td>
          <td>
            <a href="<?= APP_URL ?>/pos/ticket.php?id=<?= (int)$p['id'] ?>" target="_blank" class="btn btn-ghost btn-sm" style="display:inline-flex;align-items:center;gap:4px">
              <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
              Ticket
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- MOBILE -->
  <div class="cli-mobile">
    <?php foreach ($historial as $p):
      [$cbLabel, $cbClass] = $compBadge[$p['comprobante_tipo']] ?? ['—', 'badge-secondary'];
    ?>
    <div style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border)">
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:700;color:var(--text-primary)">#<?= str_pad($p['id'], 3, '0', STR_PAD_LEFT) ?> &middot; <?= htmlspecialchars($p['ubi']) ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= formatDatetime($p['created_at']) ?></div>
        <div style="margin-top:5px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          <span class="badge badge-secondary" style="font-size:10px"><?= htmlspecialchars(ucfirst($p['metodo_pago'] ?: '—')) ?></span>
          <?php if ($p['comprobante_tipo']): ?>
          <span class="badge <?= $cbClass ?>" style="font-size:10px"><?= $cbLabel ?></span>
          <?php endif; ?>
          <span style="font-size:13px;font-weight:700;color:var(--red)"><?= formatMoney($p['total']) ?></span>
        </div>
      </div>
      <a href="<?= APP_URL ?>/pos/ticket.php?id=<?= (int)$p['id'] ?>" target="_blank" style="flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:var(--bg-page);border:1.5px solid var(--border);color:var(--text-secondary)">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div>

<?php endif; ?>

<style>
.cli-desktop{display:block}.cli-mobile{display:none}
@media(max-width:768px){.cli-desktop{display:none}.cli-mobile{display:block}}
</style>

<?php
    include __DIR__ . '/../layout-bottom.php';
    exit;
}

// ──────────────────────────────────────────────────────────────
// LIST MODE
// ──────────────────────────────────────────────────────────────

$tableReady = true;
$clientes   = [];
$total      = 0;
$page       = max(1, cleanInt($_GET['page'] ?? 1));
$perPage    = 25;

try {
    $where  = "origen='pos' AND cliente_documento IS NOT NULL AND cliente_documento <> '' AND estado <> 'cancelado'";
    $params = [];

    if ($q !== '') {
        $where   .= " AND (cliente_documento LIKE ? OR cliente_nombre LIKE ? OR cliente_razon_social LIKE ?)";
        $like     = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // Count of distinct docs
    $total = (int)(Database::fetch(
        "SELECT COUNT(DISTINCT cliente_documento) AS n FROM pedidos WHERE $where",
        $params
    )['n'] ?? 0);

    $offset   = ($page - 1) * $perPage;

    $clientes = Database::fetchAll(
        "SELECT cliente_documento AS doc,
                MAX(cliente_tipo) AS tipo,
                COALESCE(MAX(NULLIF(cliente_razon_social,'')), MAX(NULLIF(cliente_nombre,''))) AS nombre,
                COUNT(*) AS compras,
                COALESCE(SUM(total),0) AS total_gastado,
                MAX(created_at) AS ultima
         FROM pedidos
         WHERE $where
         GROUP BY cliente_documento
         ORDER BY ultima DESC
         LIMIT ? OFFSET ?",
        array_merge($params, [$perPage, $offset])
    );
} catch (Exception $e) {
    $tableReady = false;
}

$pag = paginate($total, $perPage, $page);

$pageTitle  = 'Clientes POS';
$activePage = 'pos-clientes';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 style="display:flex;align-items:center;gap:8px">
      <span style="display:inline-flex;color:var(--text-secondary)">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </span>
      Clientes POS
    </h1>
    <p>Clientes identificados con DNI o RUC en ventas del terminal</p>
  </div>
</div>

<!-- Buscador -->
<form method="get" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
  <div style="position:relative;flex:1;min-width:220px;max-width:400px">
    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;display:flex">
      <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    </span>
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
           placeholder="Buscar por documento, nombre o razon social..."
           style="width:100%;padding:9px 11px 9px 32px;border:1.5px solid var(--border);border-radius:8px;font-size:14px;background:#fff;box-sizing:border-box">
  </div>
  <button type="submit" class="btn btn-primary">Buscar</button>
  <?php if ($q !== ''): ?>
  <a href="<?= APP_URL ?>/admin/pos/clientes.php" style="padding:9px 14px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;background:var(--bg-page);color:var(--text-secondary);border:1.5px solid var(--border);display:inline-flex;align-items:center">Limpiar</a>
  <?php endif; ?>
</form>

<?php if (!$tableReady): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)">
        <svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <h3>Error al cargar datos</h3>
      <p>Aún no hay ventas POS o falta aplicar la migración del POS.</p>
    </div>
  </div>
<?php else: ?>

<div class="card">
  <?php if (empty($clientes)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)">
        <svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      </div>
      <h3><?= $q ? 'Sin resultados para &ldquo;' . htmlspecialchars($q) . '&rdquo;.' : 'Todavia no se han registrado clientes en el POS.' ?></h3>
      <p><?= $q ? 'Prueba con otro termino de busqueda.' : 'Los clientes aparecen aqui cuando una venta POS captura un DNI o RUC.' ?></p>
    </div>
  <?php else: ?>

  <!-- Contador -->
  <div style="padding:12px 16px 0;font-size:13px;color:var(--text-muted)">
    <?= $total ?> cliente<?= $total !== 1 ? 's' : '' ?><?= $q ? ' encontrado'.($total !== 1 ? 's' : '') : '' ?>
  </div>

  <!-- DESKTOP -->
  <div class="cli-desktop">
    <table class="data-table">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Documento</th>
          <th style="text-align:center">N.&deg; compras</th>
          <th style="text-align:right">Total gastado</th>
          <th>Ultima compra</th>
          <th style="width:70px"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clientes as $c):
          $tipoLabel  = ['nombre' => 'Nombre', 'dni' => 'DNI', 'ruc' => 'RUC'];
          $badgeClass = $c['tipo'] === 'ruc' ? 'badge-info' : 'badge-secondary';
        ?>
        <tr>
          <td>
            <a href="?doc=<?= urlencode($c['doc']) ?><?= $q ? '&q='.urlencode($q) : '' ?>" style="font-weight:700;color:var(--text-primary);text-decoration:none">
              <?= htmlspecialchars($c['nombre'] ?: '—') ?>
            </a>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <span class="badge <?= $badgeClass ?>" style="font-size:10px"><?= $tipoLabel[$c['tipo']] ?? strtoupper($c['tipo']) ?></span>
              <span style="font-size:13px;font-family:monospace;color:var(--text-secondary)"><?= htmlspecialchars($c['doc']) ?></span>
            </div>
          </td>
          <td style="text-align:center;font-weight:700"><?= (int)$c['compras'] ?></td>
          <td style="text-align:right;font-weight:700;color:var(--red)"><?= formatMoney($c['total_gastado']) ?></td>
          <td style="font-size:13px;color:var(--text-muted)"><?= formatDate($c['ultima']) ?></td>
          <td>
            <a href="?doc=<?= urlencode($c['doc']) ?><?= $q ? '&q='.urlencode($q) : '' ?>" class="btn btn-ghost btn-sm">Ver</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- MOBILE -->
  <div class="cli-mobile">
    <?php foreach ($clientes as $c):
      $tipoLabel  = ['nombre' => 'Nombre', 'dni' => 'DNI', 'ruc' => 'RUC'];
      $badgeClass = $c['tipo'] === 'ruc' ? 'badge-info' : 'badge-secondary';
    ?>
    <a href="?doc=<?= urlencode($c['doc']) ?><?= $q ? '&q='.urlencode($q) : '' ?>" style="display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);text-decoration:none">
      <div style="width:40px;height:40px;border-radius:50%;background:var(--red-light);display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="var(--red)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:700;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($c['nombre'] ?: '—') ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px;display:flex;align-items:center;gap:5px">
          <span class="badge <?= $badgeClass ?>" style="font-size:10px"><?= $tipoLabel[$c['tipo']] ?? strtoupper($c['tipo']) ?></span>
          <span style="font-family:monospace"><?= htmlspecialchars($c['doc']) ?></span>
        </div>
        <div style="margin-top:4px;font-size:12px;color:var(--text-secondary)"><?= (int)$c['compras'] ?> compra<?= $c['compras'] != 1 ? 's' : '' ?> &middot; <strong style="color:var(--red)"><?= formatMoney($c['total_gastado']) ?></strong></div>
      </div>
      <div style="font-size:18px;color:var(--text-muted)">&#8250;</div>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($pag['total_pages'] > 1): ?>
  <div class="pagination">
    <?php $qs = $q ? 'q='.urlencode($q).'&' : ''; ?>
    <?php if ($pag['has_prev']): ?><a href="?<?= $qs ?>page=<?= $page - 1 ?>" class="page-btn">&#8249;</a><?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($pag['total_pages'], $page + 2); $i++): ?>
    <a href="?<?= $qs ?>page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($pag['has_next']): ?><a href="?<?= $qs ?>page=<?= $page + 1 ?>" class="page-btn">&#8250;</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</div>

<?php endif; ?>

<style>
.cli-desktop{display:block}.cli-mobile{display:none}
@media(max-width:768px){.cli-desktop{display:none}.cli-mobile{display:block}}
</style>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
