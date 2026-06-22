<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_recetas');

$ready = subrecetasListo();
$subs = [];
if ($ready) {
    $rows = Database::fetchAll("SELECT id, nombre, unidad, rendimiento FROM subrecetas WHERE activo=1 ORDER BY nombre");
    foreach ($rows as $r) {
        $r['n_items'] = (int)(Database::fetch("SELECT COUNT(*) c FROM subreceta_items WHERE subreceta_id=?", [(int)$r['id']])['c'] ?? 0);
        $r['costo']   = subrecetaCostoTotal((int)$r['id']);
        $r['costo_um'] = subrecetaCostoUM((int)$r['id']);
        $subs[] = $r;
    }
}
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = 'Subrecetas';
$activePage = 'inv-subrecetas';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left"><h1>Subrecetas</h1>
    <p>Preparaciones base (salsas, masas, aderezos) que se costean y se usan dentro de las recetas</p></div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/inventory/subreceta_form.php" class="btn btn-primary">+ Nueva subreceta</a>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <h3>Falta aplicar la migración</h3>
    <p>Aplica <code>install/60_costeo_recetas.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php elseif (empty($subs)): ?>
  <div class="card"><div class="empty-state">
    <h3>Sin subrecetas</h3>
    <p>Crea tu primera preparación base con <strong>+ Nueva subreceta</strong>.</p>
  </div></div>
<?php else: ?>
<div class="card"><div class="table-wrap" style="border:none;border-radius:0">
  <table class="data-table">
    <thead><tr><th>Subreceta</th><th>Insumos</th><th>Rendimiento</th><th>Costo total</th><th>Costo / unidad</th><th style="width:110px"></th></tr></thead>
    <tbody>
      <?php foreach ($subs as $s): ?>
      <tr<?= $s['n_items']==0 ? ' style="opacity:.6"' : '' ?>>
        <td><strong><?= clean($s['nombre']) ?></strong></td>
        <td><?= $s['n_items']==0 ? '<span style="color:var(--text-muted)">Sin insumos</span>' : (int)$s['n_items'].' insumo'.($s['n_items']==1?'':'s') ?></td>
        <td><?= nf($s['rendimiento']) ?> <span style="color:var(--text-muted);font-size:12px"><?= clean($s['unidad']) ?></span></td>
        <td><?= formatMoney($s['costo']) ?></td>
        <td><strong><?= formatMoney($s['costo_um']) ?></strong> <span style="color:var(--text-muted);font-size:12px">/ <?= clean($s['unidad']) ?></span></td>
        <td><a href="<?= APP_URL ?>/admin/inventory/subreceta_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-ghost btn-sm">Editar</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
