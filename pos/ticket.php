<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
requirePermission('pos_terminal');

$id = cleanInt($_GET['id'] ?? 0);
$p  = $id ? Database::fetch("SELECT * FROM pedidos WHERE id = ? AND origen = 'pos'", [$id]) : null;
if (!$p) { http_response_code(404); echo 'Ticket no encontrado.'; exit; }
$ubi   = Database::fetch("SELECT nombre FROM ubicaciones WHERE id = ?", [(int)$p['ubicacion_id']]);
$items = json_decode($p['items_json'] ?? '[]', true) ?: [];
$emp   = getSetting('company_name', 'El Gringo Burger Joint');
$compLabel = ['ticket'=>'TICKET','boleta'=>'BOLETA','factura'=>'FACTURA'];
?>
<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8">
<title>Ticket #<?= (int)$p['id'] ?></title>
<style>
  @page { size: 58mm auto; margin: 0; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { width:58mm; font-family:'Courier New',monospace; color:#000; background:#fff; padding:3mm; font-size:9pt; line-height:1.35; }
  .c { text-align:center; }
  .b { font-weight:bold; }
  .big { font-size:12pt; font-weight:bold; }
  .hr { border-top:1px dashed #000; margin:2mm 0; }
  .row { display:flex; justify-content:space-between; gap:4px; }
  .it-name { font-weight:bold; }
  .it-mod { font-size:8pt; padding-left:3mm; }
  .tot { font-size:12pt; font-weight:bold; }
  .printbar { text-align:center; margin-top:4mm; }
  .printbar button { padding:8px 16px; font-size:11pt; }
  @media print { .printbar { display:none !important; } }
</style></head>
<body>
  <div class="c big"><?= clean($emp) ?></div>
  <div class="c"><?= clean($ubi['nombre'] ?? '') ?></div>
  <div class="c b"><?= $compLabel[$p['comprobante_tipo']] ?? 'TICKET' ?> · #<?= str_pad((string)$p['id'], 4, '0', STR_PAD_LEFT) ?></div>
  <div class="c"><?= formatDatetime($p['created_at']) ?></div>
  <?php if (!empty($p['cliente_documento']) || !empty($p['cliente_nombre'])): ?>
    <div class="hr"></div>
    <div><?= $p['cliente_tipo'] === 'ruc' ? 'RUC' : ($p['cliente_tipo'] === 'dni' ? 'DNI' : 'Cliente') ?>: <?= clean($p['cliente_documento'] ?? '') ?></div>
    <?php if (!empty($p['cliente_razon_social']) || !empty($p['cliente_nombre'])): ?><div><?= clean($p['cliente_razon_social'] ?: $p['cliente_nombre']) ?></div><?php endif; ?>
  <?php endif; ?>
  <div class="hr"></div>
  <?php foreach ($items as $it):
      $qty = (int)($it['qty'] ?? 1);
      $base = (float)($it['precio'] ?? 0);
      $modsSum = 0; foreach ((array)($it['modificadores'] ?? []) as $m) { $modsSum += (float)($m['precio'] ?? 0); }
      $lineUnit = $base + $modsSum;
      $lineTot = $lineUnit * $qty;
      $dt = $it['desc_tipo'] ?? null; $dv = (float)($it['desc_valor'] ?? 0);
      if ($dt === 'porcentaje') $lineTot -= $lineTot * min(100,max(0,$dv))/100;
      elseif ($dt === 'monto')  $lineTot -= min($lineTot,max(0,$dv));
  ?>
    <div class="row"><span class="it-name"><?= $qty ?>x <?= clean($it['nombre'] ?? '') ?></span><span><?= formatMoney($lineTot) ?></span></div>
    <?php foreach ((array)($it['modificadores'] ?? []) as $m): ?>
      <div class="it-mod"><?= clean($m['nombre'] ?? '') ?><?= ((float)($m['precio'] ?? 0) > 0) ? ' (' . formatMoney((float)$m['precio']) . ')' : '' ?></div>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="hr"></div>
  <?php if ((float)($p['descuento_monto'] ?? 0) > 0): ?>
    <div class="row"><span>Descuento</span><span>- <?= formatMoney((float)$p['descuento_monto']) ?></span></div>
  <?php endif; ?>
  <div class="row tot"><span>TOTAL</span><span><?= formatMoney((float)$p['total']) ?></span></div>
  <div class="row"><span>Pago</span><span><?= clean($p['metodo_pago']) ?></span></div>
  <?php if (!empty($p['notas_pos'])): ?><div class="hr"></div><div><?= clean($p['notas_pos']) ?></div><?php endif; ?>
  <div class="hr"></div>
  <div class="c">¡Gracias por tu compra!</div>

  <div class="printbar"><button onclick="window.print()">Imprimir</button></div>
  <script>window.addEventListener('load', function(){ /* abrir diálogo de impresión automáticamente si se desea: window.print(); */ });</script>
</body></html>
