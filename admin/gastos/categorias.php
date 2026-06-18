<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/gastos.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $a = $_POST['accion'] ?? '';
    if ($a === 'cat_add')      { gastoCrearCategoria(clean($_POST['nombre'] ?? '')); }
    elseif ($a === 'cat_ren')  { $cid=cleanInt($_POST['id']??0); $nm=clean($_POST['nombre']??''); if($cid&&$nm) Database::execute("UPDATE gasto_categorias SET nombre=? WHERE id=?", [$nm,$cid]); }
    elseif ($a === 'cat_del')  { $cid=cleanInt($_POST['id']??0); if($cid){ Database::execute("UPDATE gasto_items SET categoria_id=NULL WHERE categoria_id=?", [$cid]); Database::execute("DELETE FROM gasto_subcategorias WHERE categoria_id=?", [$cid]); Database::execute("DELETE FROM gasto_categorias WHERE id=?", [$cid]); } }
    elseif ($a === 'sub_add')  { gastoCrearSubcategoria(cleanInt($_POST['categoria_id']??0), clean($_POST['nombre']??'')); }
    elseif ($a === 'sub_ren')  { $sid=cleanInt($_POST['id']??0); $nm=clean($_POST['nombre']??''); if($sid&&$nm) Database::execute("UPDATE gasto_subcategorias SET nombre=? WHERE id=?", [$nm,$sid]); }
    elseif ($a === 'sub_del')  { $sid=cleanInt($_POST['id']??0); if($sid){ Database::execute("UPDATE gasto_items SET subcategoria_id=NULL WHERE subcategoria_id=?", [$sid]); Database::execute("DELETE FROM gasto_subcategorias WHERE id=?", [$sid]); } }
    flashMessage('success', 'Listo.');
    redirect('/admin/gastos/categorias.php');
}

$cats = Database::fetchAll("SELECT id, nombre FROM gasto_categorias ORDER BY nombre");
$subsByCat = [];
foreach (Database::fetchAll("SELECT id, categoria_id, nombre FROM gasto_subcategorias ORDER BY nombre") as $s) {
    $subsByCat[(int)$s['categoria_id']][] = $s;
}

$pageTitle = 'Categorías de gastos';
$activePage = 'gastos_cat';
$extraHead = '<style>
.catcard{background:#fff;border:1px solid var(--border,#eee);border-radius:14px;padding:14px;margin-bottom:11px}
.catcard h3{font-size:16px;margin:0 0 8px;display:flex;justify-content:space-between;align-items:center}
.sub-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-top:1px dashed var(--border,#eee);font-size:14px}
.inline-f{display:inline-flex;gap:6px}
.inline-f input{padding:7px 10px;border:1.5px solid var(--border,#ddd);border-radius:8px;font-size:13px}
.lk{border:none;background:none;cursor:pointer;font-weight:800;font-size:12px}
.lk.del{color:#e23744}
</style>';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header"><div class="page-header-left"><h1>Categorías de gastos</h1></div></div>

<div class="card" style="margin-bottom:16px"><div class="card-body">
  <form method="post" class="inline-f"><?= csrfField() ?>
    <input type="hidden" name="accion" value="cat_add">
    <input type="text" name="nombre" placeholder="Nueva categoría…" required>
    <button class="btn btn-primary" type="submit">Agregar categoría</button>
  </form>
</div></div>

<?php foreach ($cats as $c): $cid=(int)$c['id']; ?>
<div class="catcard">
  <h3>
    <span><?= clean($c['nombre']) ?></span>
    <form method="post" onsubmit="return confirm('¿Eliminar la categoría y sus subcategorías? Los gastos quedan sin categoría.')"><?= csrfField() ?>
      <input type="hidden" name="accion" value="cat_del"><input type="hidden" name="id" value="<?= $cid ?>">
      <button class="lk del" type="submit">Eliminar</button>
    </form>
  </h3>
  <?php foreach (($subsByCat[$cid] ?? []) as $s): ?>
  <div class="sub-row">
    <span><?= clean($s['nombre']) ?></span>
    <form method="post" onsubmit="return confirm('¿Eliminar subcategoría?')"><?= csrfField() ?>
      <input type="hidden" name="accion" value="sub_del"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button class="lk del" type="submit">✕</button>
    </form>
  </div>
  <?php endforeach; ?>
  <div class="sub-row">
    <form method="post" class="inline-f"><?= csrfField() ?>
      <input type="hidden" name="accion" value="sub_add"><input type="hidden" name="categoria_id" value="<?= $cid ?>">
      <input type="text" name="nombre" placeholder="Nueva subcategoría…" required>
      <button class="lk" type="submit">+ Subcategoría</button>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
