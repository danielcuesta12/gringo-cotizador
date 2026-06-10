<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }
if (!inventarioListo()) { flashMessage('error', 'Aplica install/inventario.sql primero.'); redirect('/admin/inventory/stock.php'); }

$insumoId = cleanInt($_GET['insumo'] ?? 0);
$ubiId    = cleanInt($_GET['ubi'] ?? 0);
$insumo   = $insumoId ? Database::fetch("SELECT * FROM insumos WHERE id=?", [$insumoId]) : null;
$ubi      = $ubiId ? Database::fetch("SELECT * FROM ubicaciones WHERE id=?", [$ubiId]) : null;
if (!$insumo || !$ubi) { flashMessage('error', 'Insumo o ubicación no válidos.'); redirect('/admin/inventory/stock.php'); }

$st = Database::fetch("SELECT * FROM insumo_stock WHERE insumo_id=? AND ubicacion_id=?", [$insumoId, $ubiId]);
$stockActual = (float)($st['stock'] ?? 0);
$stockMin    = (float)($st['stock_min'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $tipo     = in_array($_POST['tipo'] ?? '', ['ingreso','merma','ajuste'], true) ? $_POST['tipo'] : 'ingreso';
    $cantidad = cleanFloat($_POST['cantidad'] ?? 0);
    $motivo   = clean($_POST['motivo'] ?? '');
    $nuevoMin = $_POST['stock_min'] !== '' ? max(0, cleanFloat($_POST['stock_min'])) : null;

    // stock mínimo (siempre que venga)
    if ($nuevoMin !== null) invSetStockMin($ubiId, $insumoId, $nuevoMin);

    if ($cantidad > 0) {
        if ($tipo === 'ingreso') {
            invMovimiento($ubiId, $insumoId, 'ingreso', $cantidad, ['motivo'=>$motivo ?: 'Ingreso manual', 'costo_unitario'=>$insumo['costo_unitario']]);
            flashMessage('success', 'Ingreso registrado: +'.$cantidad.' '.$insumo['unidad']);
        } elseif ($tipo === 'merma') {
            invMovimiento($ubiId, $insumoId, 'merma', -$cantidad, ['motivo'=>$motivo ?: 'Merma']);
            flashMessage('success', 'Merma registrada: -'.$cantidad.' '.$insumo['unidad']);
        } elseif ($tipo === 'ajuste') {
            // ajuste por conteo físico: cantidad = stock real contado
            $delta = $cantidad - $stockActual;
            if ($delta != 0) invMovimiento($ubiId, $insumoId, 'ajuste', $delta, ['motivo'=>$motivo ?: 'Ajuste por conteo']);
            flashMessage('success', 'Stock ajustado a '.$cantidad.' '.$insumo['unidad']);
        }
    } elseif ($nuevoMin !== null) {
        flashMessage('success', 'Stock mínimo actualizado.');
    }
    redirect('/admin/inventory/stock.php?ubi='.$ubiId);
}

function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }
$pageTitle  = 'Ajuste de stock';
$activePage = 'inv-stock';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/inventory/stock.php?ubi=<?= $ubiId ?>">Stock</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= clean($insumo['nombre']) ?></span>
</div>

<div class="page-header"><div class="page-header-left">
  <h1><?= clean($insumo['nombre']) ?></h1>
  <p><?= clean($ubi['nombre']) ?> · Stock actual: <strong><?= nf($stockActual) ?> <?= clean($insumo['unidad']) ?></strong></p>
</div></div>

<div class="card" style="max-width:520px"><div class="card-body">
  <form method="post">
    <?= csrfField() ?>
    <div class="form-group">
      <label>Tipo de movimiento</label>
      <select name="tipo" id="tipo" onchange="document.getElementById('cantHint').textContent = this.value==='ajuste' ? 'Ingresa el stock REAL contado (se ajusta a ese valor)' : (this.value==='merma' ? 'Cantidad que se descuenta del stock' : 'Cantidad que se suma al stock');">
        <option value="ingreso">Ingreso manual (suma stock)</option>
        <option value="merma">Merma / pérdida (resta stock)</option>
        <option value="ajuste">Ajuste por conteo físico (fija el valor)</option>
      </select>
    </div>
    <div class="form-group">
      <label>Cantidad (<?= clean($insumo['unidad']) ?>)</label>
      <input type="text" inputmode="decimal" name="cantidad" value="" placeholder="0">
      <div class="form-hint" id="cantHint">Cantidad que se suma al stock</div>
    </div>
    <div class="form-group">
      <label>Motivo <small style="font-weight:400;color:var(--text-muted)">(opcional)</small></label>
      <input type="text" name="motivo" placeholder="Ej: Compra rápida, producto vencido, conteo de cierre">
    </div>
    <div class="form-group">
      <label>Stock mínimo (alerta)</label>
      <input type="text" inputmode="decimal" name="stock_min" value="<?= $stockMin ? nf($stockMin) : '' ?>" placeholder="0">
      <div class="form-hint">Cuando el stock baje de este valor, se marca «bajo mínimo».</div>
    </div>
    <div style="display:flex;gap:12px;margin-top:8px">
      <button type="submit" class="btn btn-primary">Guardar</button>
      <a href="<?= APP_URL ?>/admin/inventory/stock.php?ubi=<?= $ubiId ?>" class="btn btn-ghost">Cancelar</a>
    </div>
  </form>
</div></div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
