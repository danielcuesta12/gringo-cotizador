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

$pageTitle = 'Tablero de mesas';
$activePage = 'mesas';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1>Tablero de mesas</h1></div>
  <div class="page-header-right"><a href="<?= APP_URL ?>/admin/mesas/index.php?ubicacion_id=<?= $ubiSel ?>" class="btn btn-secondary">Editar plano</a></div>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="card-body"><p>Aplica <code>install/56_mesas.sql</code> en phpMyAdmin y recarga.</p></div></div>
<?php elseif (!$pisos): ?>
  <div class="card"><div class="card-body"><p>No hay pisos/mesas para este local todavía. <a href="<?= APP_URL ?>/admin/mesas/index.php?ubicacion_id=<?= $ubiSel ?>">Arma el plano</a>.</p></div></div>
<?php else: ?>
  <div id="tb-tabs" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px"></div>
  <div id="tb-board" class="card" style="padding:10px"></div>
<?php endif; ?>

<?php if ($ready && $pisos): ?>
<script src="<?= APP_URL ?>/assets/js/plano-render.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/plano-render.js') ?: time() ?>"></script>
<script>
var PISOS = <?= json_encode($pisos, JSON_UNESCAPED_UNICODE) ?>;
var UPLOAD = '<?= UPLOAD_URL ?>';
var cur = 0;
var tabs = document.getElementById('tb-tabs');
var board = document.getElementById('tb-board');
function draw() {
  PlanoRender.draw(board, PISOS[cur], { uploadUrl: UPLOAD, onMesaTap: function (id, m) { /* Sub-build B: abrir cuenta */ } });
}
PISOS.forEach(function (p, i) {
  var t = document.createElement('span');
  t.style.cssText = 'padding:7px 14px;border-radius:9px;font-weight:700;font-size:13px;cursor:pointer;background:' + (i === 0 ? '#FFDF00' : '#eee') + ';color:#1E1E1E';
  t.textContent = p.nombre;
  t.addEventListener('click', function () { cur = i; tabs.querySelectorAll('span').forEach(function (s, j) { s.style.background = j === i ? '#FFDF00' : '#eee'; }); draw(); });
  tabs.appendChild(t);
});
window.addEventListener('resize', draw);
draw();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
