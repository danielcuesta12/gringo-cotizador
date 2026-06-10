<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();

$id = cleanInt($_GET['id'] ?? 0);

$ESTADOS = [
    'pendiente'      => ['Pendiente',       'badge-warning'],
    'en_preparacion' => ['En preparación',  'badge-info'],
    'listo'          => ['Listo',           'badge-success'],
    'entregado'      => ['Entregado',       'badge-secondary'],
    'cancelado'      => ['Cancelado',       'badge-danger'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (isset($_POST['delete'])) {
        Database::execute("DELETE FROM pedidos WHERE id = ?", [$id]);
        flashMessage('success', 'Pedido eliminado.');
        redirect('/admin/pedidos/index.php');
    }
    $nuevo = clean($_POST['estado'] ?? '');
    if (isset($ESTADOS[$nuevo])) {
        if ($nuevo === 'en_preparacion') {
            Database::execute("UPDATE pedidos SET estado=?, aceptado_at=COALESCE(aceptado_at,NOW()) WHERE id=?", [$nuevo, $id]);
        } elseif (in_array($nuevo, ['listo','entregado','cancelado'], true)) {
            Database::execute("UPDATE pedidos SET estado=?, completado_at=NOW() WHERE id=?", [$nuevo, $id]);
        } else {
            Database::execute("UPDATE pedidos SET estado=? WHERE id=?", [$nuevo, $id]);
        }
        flashMessage('success', 'Estado actualizado a «'.$ESTADOS[$nuevo][0].'».');
    }
    redirect('/admin/pedidos/detail.php?id='.$id);
}

$p = $id ? Database::fetch(
    "SELECT p.*, u.nombre AS ubi_nombre FROM pedidos p LEFT JOIN ubicaciones u ON u.id=p.ubicacion_id WHERE p.id=?",
    [$id]
) : null;
if (!$p) { flashMessage('error', 'Pedido no encontrado.'); redirect('/admin/pedidos/index.php'); }

$items = json_decode($p['items_json'] ?? '[]', true) ?: [];
[$el, $ec] = $ESTADOS[$p['estado']] ?? [$p['estado'], 'badge-secondary'];
$tipo = ($p['origen'] ?? 'carta')==='pos' ? 'Salón' : ($p['tipo_entrega']==='delivery'?'Delivery':'Recojo');

// Teléfono normalizado para WhatsApp / llamada
$telDigits = preg_replace('/\D+/', '', $p['telefono'] ?? '');
if ($telDigits && strlen($telDigits) === 9 && $telDigits[0] === '9') $telDigits = '51'.$telDigits;

$pageTitle  = 'Pedido #'.str_pad($p['id'],3,'0',STR_PAD_LEFT);
$activePage = 'pedidos';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/pedidos/index.php">Pedidos</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current">#<?= str_pad($p['id'],3,'0',STR_PAD_LEFT) ?></span>
</div>

<div class="page-header">
  <div class="page-header-left">
    <h1 style="display:flex;align-items:center;gap:10px">Pedido #<?= str_pad($p['id'],3,'0',STR_PAD_LEFT) ?> <span class="badge <?= $ec ?>"><?= $el ?></span></h1>
    <p><?= formatDatetime($p['created_at']) ?> · <?= clean($p['ubi_nombre'] ?: 'Sin ubicación') ?></p>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">

  <!-- Detalle del pedido -->
  <div class="card">
    <div class="card-header"><span class="card-title">Detalle del pedido</span></div>
    <div class="card-body" style="padding:0">
      <table class="data-table">
        <tbody>
        <?php $calc = 0; foreach ($items as $it):
          $qty = (int)($it['qty'] ?? 1); $precio = (float)($it['precio'] ?? 0);
          $sub = isset($it['subtotal']) ? (float)$it['subtotal'] : $precio*$qty; $calc += $sub;
          $mods = $it['modificadores'] ?? [];
        ?>
          <tr>
            <td style="width:42px;font-weight:700;color:var(--brand-dark)"><?= $qty ?>x</td>
            <td>
              <div style="font-weight:600"><?= clean($it['nombre'] ?? '') ?></div>
              <?php if (!empty($mods)): ?>
                <div style="font-size:12px;color:var(--text-muted);margin-top:2px"><?= clean(implode(', ', array_map(fn($m) => $m['nombre'] ?? '', $mods))) ?></div>
              <?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:600;white-space:nowrap"><?= formatMoney($sub) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr style="border-top:2px solid var(--border)">
            <td colspan="2" style="text-align:right;font-weight:700;font-size:15px">Total</td>
            <td style="text-align:right;font-weight:800;font-size:16px;color:var(--brand-dark);white-space:nowrap"><?= formatMoney($p['total'] ?: $calc) ?></td>
          </tr>
        </tbody>
      </table>
      <?php if (!empty($p['comentarios'])): ?>
        <div style="padding:14px 16px;border-top:1px solid var(--border)">
          <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px">Comentarios</div>
          <div style="font-size:14px"><?= nl2br(clean($p['comentarios'])) ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Lateral -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Cliente -->
    <div class="card">
      <div class="card-header"><span class="card-title">Cliente</span></div>
      <div class="card-body">
        <div style="font-size:16px;font-weight:700;margin-bottom:2px"><?= clean($p['nombre'] ?: 'Cliente') ?></div>
        <div style="font-size:13px;color:var(--text-secondary);margin-bottom:14px"><?= $tipo ?><?= $p['telefono'] ? ' · '.clean($p['telefono']) : '' ?></div>
        <?php if (!empty($p['direccion'])): ?>
          <div style="font-size:13px;margin-bottom:10px"><strong>Dirección:</strong> <?= clean($p['direccion']) ?></div>
        <?php endif; ?>
        <?php if (!empty($p['horario'])): ?>
          <div style="font-size:13px;margin-bottom:14px"><strong>Horario:</strong> <?= clean($p['horario']) ?></div>
        <?php endif; ?>
        <?php if ($telDigits): ?>
        <div style="display:flex;gap:8px">
          <a href="https://wa.me/<?= $telDigits ?>" target="_blank" class="btn btn-sm" style="flex:1;background:#25D366;color:#fff;gap:6px;justify-content:center">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.82 11.82 0 0 1 8.413 3.488 11.82 11.82 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 0 0 1.523 5.281l-.999 3.648 3.965-1.728z"/></svg>
            WhatsApp
          </a>
          <a href="tel:+<?= $telDigits ?>" class="btn btn-ghost btn-sm" style="justify-content:center">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Estado / acciones -->
    <div class="card">
      <div class="card-header"><span class="card-title">Cambiar estado</span></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
        <?php
        $flujo = [
            'en_preparacion' => ['Aceptar / En preparación', 'btn-primary'],
            'listo'          => ['Marcar listo',             'btn-success'],
            'entregado'      => ['Marcar entregado',         'btn-ghost'],
            'cancelado'      => ['Cancelar pedido',          'btn-danger'],
        ];
        foreach ($flujo as $val => $info):
          if ($p['estado'] === $val) continue;
        ?>
        <form method="post"><?= csrfField() ?><input type="hidden" name="estado" value="<?= $val ?>">
          <button type="submit" class="btn <?= $info[1] ?> btn-block btn-sm"><?= $info[0] ?></button>
        </form>
        <?php endforeach; ?>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">
          Pago: <strong><?= $p['metodo_pago']==='izipay'?'Izipay (online)':'WhatsApp' ?></strong>
        </div>
      </div>
    </div>

    <form method="post">
      <?= csrfField() ?>
      <button type="submit" name="delete" value="1" class="btn btn-danger btn-block btn-sm" data-confirm="¿Eliminar este pedido? No se puede deshacer.">
        Eliminar pedido
      </button>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
