<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('gastos');

$admin = isAdmin();
$uid   = (int) (currentUser()['id'] ?? 0);

$ready = (bool) Database::fetch(
    "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'gastos'"
);

// ── Acciones (admin) ───────────────────────────────────────────────
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $accion = $_POST['accion'] ?? '';
    $gid    = cleanInt($_POST['id'] ?? 0);
    if ($accion === 'marcar_pagado' && $admin && $gid) {
        Database::execute("UPDATE gastos SET estado='pagado', pagado_at=NOW(), pagado_por=? WHERE id=? AND tipo='prestamo'", [$uid, $gid]);
        flashMessage('success', 'Préstamo marcado como pagado.');
    } elseif ($accion === 'eliminar' && $admin && $gid) {
        $g = Database::fetch("SELECT foto FROM gastos WHERE id=?", [$gid]);
        if ($g && !empty($g['foto']) && is_file(UPLOAD_PATH . $g['foto'])) @unlink(UPLOAD_PATH . $g['foto']);
        Database::execute("DELETE FROM gastos WHERE id=?", [$gid]);
        flashMessage('success', 'Gasto eliminado.');
    }
    redirect('/admin/gastos/index.php' . (!empty($_POST['qs']) ? ('?' . $_POST['qs']) : ''));
}

// ── Limpieza de fotos > 2 meses (al entrar al módulo) ──────────────
if ($ready) {
    try {
        $viejas = Database::fetchAll("SELECT id, foto FROM gastos WHERE foto IS NOT NULL AND foto <> '' AND created_at < (NOW() - INTERVAL 2 MONTH)");
        foreach ($viejas as $v) {
            if (is_file(UPLOAD_PATH . $v['foto'])) @unlink(UPLOAD_PATH . $v['foto']);
            Database::execute("UPDATE gastos SET foto=NULL WHERE id=?", [$v['id']]);
        }
    } catch (\Throwable $e) { /* tolerante */ }
}

// ── Filtros ────────────────────────────────────────────────────────
$fTipo   = in_array($_GET['tipo'] ?? '', ['empresa', 'prestamo'], true) ? $_GET['tipo'] : '';
$fEstado = in_array($_GET['estado'] ?? '', ['pendiente', 'pagado'], true) ? $_GET['estado'] : '';
$fTag    = trim((string)($_GET['tag'] ?? ''));
$q       = trim((string)($_GET['q'] ?? ''));

$where = []; $params = [];
if (!$admin) { $where[] = "g.usuario_id = ?"; $params[] = $uid; $where[] = "g.tipo = 'prestamo'"; }
if ($fTipo)   { $where[] = "g.tipo = ?";   $params[] = $fTipo; }
if ($fEstado) { $where[] = "g.estado = ?"; $params[] = $fEstado; }
if ($fTag)    { $where[] = "FIND_IN_SET(?, g.tags)"; $params[] = $fTag; }
if ($q)       { $where[] = "g.concepto LIKE ?"; $params[] = '%' . $q . '%'; }
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$gastos = $ready ? Database::fetchAll(
    "SELECT g.*, c.nombre AS categoria, ub.nombre AS ubicacion, u.name AS usuario
     FROM gastos g
     LEFT JOIN gasto_categorias c ON c.id = g.categoria_id
     LEFT JOIN ubicaciones ub ON ub.id = g.ubicacion_id
     LEFT JOIN users u ON u.id = g.usuario_id
     $wsql
     ORDER BY g.fecha DESC, g.id DESC
     LIMIT 200", $params) : [];

// ── Totales ────────────────────────────────────────────────────────
if ($admin) {
    $totPend = (float) (Database::fetch("SELECT COALESCE(SUM(monto),0) t FROM gastos WHERE tipo='prestamo' AND estado='pendiente'")['t'] ?? 0);
    $totMes  = (float) (Database::fetch("SELECT COALESCE(SUM(monto),0) t FROM gastos WHERE tipo='empresa' AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE())")['t'] ?? 0);
} else {
    $totPend = (float) (Database::fetch("SELECT COALESCE(SUM(monto),0) t FROM gastos WHERE usuario_id=? AND tipo='prestamo' AND estado='pendiente'", [$uid])['t'] ?? 0);
}

// Tags para el filtro
$tagSet = [];
if ($ready) {
    $tw = $admin ? '' : 'WHERE usuario_id = ' . $uid . " AND tipo='prestamo'";
    foreach (Database::fetchAll("SELECT tags FROM gastos $tw") as $r) {
        foreach (explode(',', (string)$r['tags']) as $t) { $t = trim($t); if ($t !== '') $tagSet[$t] = true; }
    }
}
$tagList = array_slice(array_keys($tagSet), 0, 30);

$qs = http_build_query(array_filter(['tipo' => $fTipo, 'estado' => $fEstado, 'tag' => $fTag, 'q' => $q]));
function chipUrl(array $over): string {
    global $fTipo, $fEstado, $fTag, $q;
    $base = ['tipo' => $fTipo, 'estado' => $fEstado, 'tag' => $fTag, 'q' => $q];
    $merged = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null);
    return APP_URL . '/admin/gastos/index.php' . ($merged ? ('?' . http_build_query($merged)) : '');
}

$pageTitle  = 'Registro de gastos';
$activePage = 'gastos';
$extraHead  = '<style>
.g-totales{display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.g-tot{flex:1;min-width:150px;background:var(--card,#fff);border:1px solid var(--border,#eee);border-radius:13px;padding:14px}
.g-tot .l{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted,#888)}
.g-tot .v{font-size:22px;font-weight:900;margin-top:3px}
.g-tot.pend .v{color:#e23744}
.g-filters{display:flex;gap:7px;overflow-x:auto;padding-bottom:6px;margin-bottom:14px}
.g-chip{flex:0 0 auto;border:1.5px solid var(--border,#ddd);background:var(--card,#fff);border-radius:999px;padding:7px 13px;font-size:12px;font-weight:700;color:var(--text-muted,#777);white-space:nowrap;text-decoration:none}
.g-chip.on{background:#1E1E1E;color:#fff;border-color:#1E1E1E}
.g-chip.tag.on{background:#1E1E1E;color:#FFDF00}
.g-card{background:var(--card,#fff);border:1px solid var(--border,#eee);border-radius:14px;padding:14px;margin-bottom:11px}
.g-top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.g-concepto{font-size:15px;font-weight:800}
.g-monto{font-size:17px;font-weight:900;white-space:nowrap}
.g-meta{font-size:12px;color:var(--text-muted,#888);margin-top:5px;display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.g-tag{font-size:10px;font-weight:800;padding:2px 8px;border-radius:6px}
.g-tag.empresa{background:#eef2ff;color:#4f46e5}
.g-tag.prestamo{background:#FFBBC8;color:#1E1E1E}
.g-tag.cat{background:var(--bg-page,#f1f1f4);color:#555}
.g-tag.hash{background:#1E1E1E;color:#FFDF00}
.g-estado{font-size:11px;font-weight:800;padding:3px 9px;border-radius:999px}
.g-estado.pendiente{background:#fde8ea;color:#e23744}
.g-estado.pagado{background:#e6f5ec;color:#16a34a}
.g-actions{margin-top:11px;display:flex;gap:8px;flex-wrap:wrap}
.g-actions a,.g-actions button{border-radius:9px;padding:9px 12px;font-size:12px;font-weight:800;cursor:pointer;border:1px solid var(--border,#ddd);background:var(--card,#fff);color:var(--text-primary,#1E1E1E);text-decoration:none;display:inline-flex;align-items:center}
.g-actions .pay{background:#16a34a;color:#fff;border-color:#16a34a}
.g-actions .del{color:#e23744}
.g-empty{text-align:center;color:var(--text-muted,#888);padding:40px 0}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left"><h1>Registro de gastos</h1></div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/gastos/form.php" class="btn btn-primary">+ Nuevo gasto</a>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="card-body">
    <p>El módulo de gastos necesita su migración. Aplica <code>install/gastos.sql</code> en phpMyAdmin y recarga.</p>
  </div></div>
<?php else: ?>

<div class="g-totales">
  <div class="g-tot pend">
    <div class="l"><?= $admin ? 'Préstamos pendientes' : 'Mis préstamos pendientes' ?></div>
    <div class="v"><?= formatMoney($totPend) ?></div>
  </div>
  <?php if ($admin): ?>
  <div class="g-tot">
    <div class="l">Gastos empresa (mes)</div>
    <div class="v"><?= formatMoney($totMes) ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="g-filters">
  <a class="g-chip <?= $fTipo===''&&$fEstado===''?'on':'' ?>" href="<?= chipUrl(['tipo'=>'','estado'=>'']) ?>">Todos</a>
  <?php if ($admin): ?>
  <a class="g-chip <?= $fTipo==='empresa'?'on':'' ?>" href="<?= chipUrl(['tipo'=>$fTipo==='empresa'?'':'empresa']) ?>">Empresa</a>
  <?php endif; ?>
  <a class="g-chip <?= $fTipo==='prestamo'?'on':'' ?>" href="<?= chipUrl(['tipo'=>$fTipo==='prestamo'?'':'prestamo']) ?>">Préstamos</a>
  <a class="g-chip <?= $fEstado==='pendiente'?'on':'' ?>" href="<?= chipUrl(['estado'=>$fEstado==='pendiente'?'':'pendiente']) ?>">Pendientes</a>
  <a class="g-chip <?= $fEstado==='pagado'?'on':'' ?>" href="<?= chipUrl(['estado'=>$fEstado==='pagado'?'':'pagado']) ?>">Pagados</a>
  <?php foreach ($tagList as $t): ?>
  <a class="g-chip tag <?= $fTag===$t?'on':'' ?>" href="<?= chipUrl(['tag'=>$fTag===$t?'':$t]) ?>">#<?= clean($t) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$gastos): ?>
  <div class="g-empty">No hay gastos<?= ($fTipo||$fEstado||$fTag||$q) ? ' con esos filtros' : ' registrados' ?>.</div>
<?php else: foreach ($gastos as $g):
  $tags = array_filter(array_map('trim', explode(',', (string)$g['tags'])));
?>
  <div class="g-card">
    <div class="g-top">
      <div class="g-concepto"><?= clean($g['concepto']) ?></div>
      <div class="g-monto"><?= formatMoney((float)$g['monto']) ?></div>
    </div>
    <div class="g-meta">
      <span class="g-tag <?= $g['tipo'] ?>"><?= $g['tipo']==='empresa'?'Empresa':'Préstamo' ?></span>
      <?php if ($g['categoria']): ?><span class="g-tag cat"><?= clean($g['categoria']) ?></span><?php endif; ?>
      <?php if ($g['tipo']==='prestamo'): ?><span class="g-estado <?= $g['estado'] ?>"><?= $g['estado']==='pagado'?'Pagado':'Pendiente' ?></span><?php endif; ?>
    </div>
    <div class="g-meta">
      <?= $g['ubicacion'] ? clean($g['ubicacion']) . ' · ' : '' ?><?= formatDate($g['fecha']) ?> · <?= clean($g['usuario'] ?? '—') ?>
      <?php if (!empty($g['nota'])): ?> · <?= clean($g['nota']) ?><?php endif; ?>
    </div>
    <?php if ($tags): ?>
    <div class="g-meta"><?php foreach ($tags as $t): ?><span class="g-tag hash">#<?= clean($t) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <div class="g-actions">
      <?php if (!empty($g['foto'])): ?><a href="<?= UPLOAD_URL . clean($g['foto']) ?>" target="_blank">Ver foto</a><?php endif; ?>
      <a href="<?= APP_URL ?>/admin/gastos/form.php?id=<?= (int)$g['id'] ?>">Editar</a>
      <?php if ($admin && $g['tipo']==='prestamo' && $g['estado']==='pendiente'): ?>
      <form method="post" style="display:inline">
        <?= csrfField() ?><input type="hidden" name="accion" value="marcar_pagado"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="qs" value="<?= clean($qs) ?>">
        <button type="submit" class="pay">Marcar pagado</button>
      </form>
      <?php endif; ?>
      <?php if ($admin): ?>
      <form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar este gasto?')">
        <?= csrfField() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="qs" value="<?= clean($qs) ?>">
        <button type="submit" class="del">Eliminar</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
