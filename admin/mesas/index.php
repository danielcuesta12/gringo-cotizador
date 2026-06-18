<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('mesas');

$ready = (bool) Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='mesa_pisos'");

$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, sort_order, nombre");
$ubiSel = cleanInt($_GET['ubicacion_id'] ?? 0) ?: (int)($ubis[0]['id'] ?? 0);

$pisos = [];
if ($ready && $ubiSel) {
    foreach (Database::fetchAll("SELECT id FROM mesa_pisos WHERE ubicacion_id = ? AND activo = 1 ORDER BY orden, id", [$ubiSel]) as $row) {
        $pid = (int)$row['id'];
        $p = Database::fetch("SELECT id, nombre, orden, fondo_img, ancho, alto FROM mesa_pisos WHERE id = ?", [$pid]);
        $p['mesas'] = Database::fetchAll("SELECT id, numero, capacidad, forma, pos_x, pos_y, ancho, alto FROM mesas WHERE piso_id = ? AND activa = 1 ORDER BY id", [$pid]);
        $p['elementos'] = Database::fetchAll("SELECT id, tipo, texto, pos_x, pos_y, ancho, alto FROM mesa_elementos WHERE piso_id = ? ORDER BY id", [$pid]);
        $pisos[] = $p;
    }
}

$pageTitle = 'Mesas / Plano';
$activePage = 'mesas';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1>Mesas / Plano</h1></div>
  <div class="page-header-right">
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <label style="font-size:13px;color:var(--text-muted)">Local</label>
      <select name="ubicacion_id" onchange="this.form.submit()">
        <?php foreach ($ubis as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id']===$ubiSel?'selected':'' ?>><?= clean($u['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <a href="<?= APP_URL ?>/admin/mesas/tablero.php?ubicacion_id=<?= $ubiSel ?>" class="btn btn-secondary">Ver tablero</a>
    </form>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="card-body"><p>El módulo de mesas necesita su migración. Aplica <code>install/56_mesas.sql</code> en phpMyAdmin y recarga.</p></div></div>
<?php elseif (!$ubiSel): ?>
  <div class="card"><div class="card-body"><p>Crea primero una ubicación activa.</p></div></div>
<?php else: ?>
  <div id="plano-editor"></div>
<?php endif; ?>

<?php if ($ready && $ubiSel): ?>
<script>
window.EG_MESAS_API  = '<?= APP_URL ?>/api/mesas.php';
window.EG_CSRF       = <?= json_encode(csrfToken()) ?>;
window.EG_UPLOAD_URL = '<?= UPLOAD_URL ?>';
</script>
<script src="<?= APP_URL ?>/assets/js/plano-editor.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/plano-editor.js') ?: time() ?>"></script>
<script>
PlanoEditor.init({
  mount: document.getElementById('plano-editor'),
  ubicacionId: <?= $ubiSel ?>,
  pisos: <?= json_encode($pisos, JSON_UNESCAPED_UNICODE) ?>
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
