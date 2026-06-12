<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('locations');

$id  = cleanInt($_GET['id'] ?? 0);
$loc = $id ? Database::fetch("SELECT * FROM ubicaciones WHERE id = ?", [$id]) : null;
if (!$loc) { flashMessage('error', 'Ubicación no encontrada.'); redirect('/admin/locations/index.php'); }

// Guardar la carta de esta ubicación (sincroniza location_products)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $offer = $_POST['offer']     ?? [];   // [product_id => '1']
    $price = $_POST['price']     ?? [];   // [product_id => '12.50']
    $avail = $_POST['available'] ?? [];   // [product_id => '1']

    $allProducts = Database::fetchAll("SELECT id FROM products");
    foreach ($allProducts as $p) {
        $pid = (int)$p['id'];
        if (!empty($offer[$pid])) {
            $pr = max(0, cleanFloat($price[$pid] ?? 0));
            $av = !empty($avail[$pid]) ? 1 : 0;
            // upsert
            $exists = Database::fetch("SELECT id FROM location_products WHERE location_id=? AND product_id=?", [$id, $pid]);
            if ($exists) {
                Database::execute("UPDATE location_products SET price=?, available=? WHERE location_id=? AND product_id=?", [$pr, $av, $id, $pid]);
            } else {
                Database::insert("INSERT INTO location_products (location_id, product_id, price, available) VALUES (?,?,?,?)", [$id, $pid, $pr, $av]);
            }
        } else {
            Database::execute("DELETE FROM location_products WHERE location_id=? AND product_id=?", [$id, $pid]);
        }
    }
    flashMessage('success', 'Carta de «' . $loc['nombre'] . '» actualizada.');
    redirect('/admin/locations/items.php?id=' . $id);
}

// Catálogo (productos activos) + lo que ya ofrece esta ubicación
$products = Database::fetchAll(
    "SELECT p.id, p.name, p.image, c.name AS cat_name, c.sort_order AS cat_order
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.active = 1
     ORDER BY c.sort_order, c.name, p.sort_order, p.name"
);
$lpRows = Database::fetchAll("SELECT * FROM location_products WHERE location_id = ?", [$id]);
$lp = [];
foreach ($lpRows as $r) { $lp[(int)$r['product_id']] = $r; }

$pageTitle  = 'Carta — ' . $loc['nombre'];
$activePage = 'locations';
$extraHead  = '<style>
.it-row{display:flex;align-items:center;gap:12px;padding:11px 14px;border-bottom:1px solid var(--border)}
.it-row:last-child{border-bottom:none}
.it-off{opacity:.5}
.it-name{flex:1;min-width:0;font-weight:600;font-size:14px}
.it-cat{font-size:11px;color:var(--text-muted);font-weight:400}
.it-price{width:120px;flex-shrink:0;position:relative}
.it-price input{padding-left:30px;text-align:right}
.it-price .cur{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px}
.cat-hdr{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:12px 14px 6px;background:#fafafa;border-bottom:1px solid var(--border)}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/locations/index.php">Ubicaciones</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= clean($loc['nombre']) ?> · Carta</span>
</div>

<div class="page-header">
  <div class="page-header-left">
    <h1>Carta de <?= clean($loc['nombre']) ?></h1>
    <p>Marca los productos que se ofrecen aquí y define su precio y disponibilidad. Los datos del producto (nombre, foto) se editan en Catálogo → Productos.</p>
  </div>
</div>

<?php if (empty($products)): ?>
  <div class="card"><div class="empty-state">
    <h3>Sin productos en el catálogo</h3>
    <p>Primero crea productos en Catálogo → Productos.</p>
    <a href="<?= APP_URL ?>/admin/products/index.php" class="btn btn-primary">Ir a Productos</a>
  </div></div>
<?php else: ?>
<form method="post">
  <?= csrfField() ?>
  <div class="card">
    <?php $lastCat = null; foreach ($products as $p):
      $pid = (int)$p['id'];
      $row = $lp[$pid] ?? null;
      $on  = $row !== null;
      $cat = $p['cat_name'] ?: 'Sin categoría';
      if ($cat !== $lastCat): $lastCat = $cat; ?>
      <div class="cat-hdr"><?= clean($cat) ?></div>
    <?php endif; ?>
      <div class="it-row<?= $on ? '' : ' it-off' ?>" data-pid="<?= $pid ?>">
        <input type="checkbox" name="offer[<?= $pid ?>]" value="1" <?= $on?'checked':'' ?>
               onchange="this.closest('.it-row').classList.toggle('it-off', !this.checked)"
               style="width:18px;height:18px;accent-color:var(--brand);flex-shrink:0" title="Ofrecer en esta ubicación">
        <div class="it-name"><?= clean($p['name']) ?></div>
        <div class="it-price">
          <span class="cur">S/</span>
          <input type="text" inputmode="decimal" name="price[<?= $pid ?>]"
                 value="<?= $row ? number_format((float)$row['price'], 2, '.', '') : '' ?>" placeholder="0.00">
        </div>
        <label class="toggle-wrap" style="cursor:pointer;flex-shrink:0" title="Disponible / agotado">
          <input type="checkbox" name="available[<?= $pid ?>]" value="1" <?= (!$row || $row['available']) ? 'checked' : '' ?>
                 style="width:18px;height:18px;accent-color:var(--green)">
          <span class="toggle-label" style="font-size:12px">Disp.</span>
        </label>
      </div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:12px;margin-top:16px;position:sticky;bottom:16px">
    <button type="submit" class="btn btn-primary btn-lg" style="gap:6px;box-shadow:var(--shadow-md)">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
      Guardar carta
    </button>
    <a href="<?= APP_URL ?>/admin/locations/index.php" class="btn btn-ghost">Volver</a>
  </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
