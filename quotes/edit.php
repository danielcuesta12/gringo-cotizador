<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();

$id    = cleanInt(isset($_GET['id']) ? $_GET['id'] : 0);
$quote = Database::fetch(
    "SELECT q.*, c.name as client_name, c.type as client_type, c.ruc_dni,
            c.email as client_email, c.phone as client_phone
     FROM quotes q JOIN clients c ON c.id=q.client_id WHERE q.id=?",
    array($id)
);
if (!$quote) { flashMessage('error','Cotizacion no encontrada.'); redirect('/quotes/list.php'); }

if (!isAdmin() && $quote['user_id'] != $_SESSION['user_id']) {
    flashMessage('error','No tienes permiso para editar esta cotizacion.');
    redirect('/quotes/list.php');
}

$items = Database::fetchAll("SELECT * FROM quote_items WHERE quote_id=? ORDER BY sort_order", array($id));
$log   = Database::fetchAll(
    "SELECT l.*, u.name as user_name FROM quote_status_log l
     JOIN users u ON u.id=l.user_id WHERE l.quote_id=? ORDER BY l.created_at DESC",
    array($id)
);

// Cambiar estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_status'])) {
    verifyCsrf();
    $newStatus = clean(isset($_POST['new_status']) ? $_POST['new_status'] : '');
    $note      = clean(isset($_POST['status_note']) ? $_POST['status_note'] : '');
    $valid     = array('borrador','enviada','aceptada','rechazada');
    if (in_array($newStatus, $valid)) {
        $now = date('Y-m-d H:i:s');
        $upd = "UPDATE quotes SET status=?";
        $p   = array($newStatus);
        if ($newStatus==='enviada')   { $upd.=', sent_at=?';     $p[]=$now; }
        if ($newStatus==='aceptada')  { $upd.=', accepted_at=?'; $p[]=$now; }
        if ($newStatus==='rechazada') { $upd.=', rejected_at=?'; $p[]=$now; }
        if ($note) { $upd.=', status_note=?'; $p[]=$note; }
        $upd.=' WHERE id=?'; $p[]=$id;
        Database::execute($upd, $p);
        Database::insert(
            "INSERT INTO quote_status_log (quote_id,user_id,from_status,to_status,note) VALUES (?,?,?,?,?)",
            array($id, $_SESSION['user_id'], $quote['status'], $newStatus, $note)
        );
        flashMessage('success', 'Estado actualizado a «' . quoteStatusLabel($newStatus) . '».');
        redirect('/quotes/edit.php?id=' . $id);
    }
}

$pubLink = APP_URL . '/quotes/view.php?token=' . $quote['public_token'];
$pdfLink = APP_URL . '/quotes/pdf.php?id=' . $id;

// WhatsApp al CLIENTE — mensaje adaptado al estado con emojis
$clientPhone = preg_replace('/\D/', '', isset($quote['client_phone']) ? $quote['client_phone'] : '');
$_clientName = $quote['client_name'];
$_quoteNum   = $quote['quote_number'];
$_eventDate  = $quote['event_date'] ? formatDate($quote['event_date']) : '';
$_daysSent   = $quote['sent_at'] ? (int)((time() - strtotime($quote['sent_at'])) / 86400) : null;

// Emojis como codepoints Unicode (compatibles con todos los sistemas)
$_e_burger  = json_decode('"\uD83C\uDF54"'); // 🍔
$_e_down    = json_decode('"\uD83D\uDC47"'); // 👇
$_e_wave    = json_decode('"\uD83D\uDC4B"'); // 👋
$_e_smile   = json_decode('"\uD83D\uDE0A"'); // 😊
$_e_party   = json_decode('"\uD83C\uDF89"'); // 🎉
$_e_cal     = json_decode('"\uD83D\uDDD3"'); // 🗓
$_e_pray    = json_decode('"\uD83D\uDE4F"'); // 🙏
$_e_spark   = json_decode('"\u2728"');        // ✨

switch ($quote['status']) {
    case 'borrador':
        $waMsgTxt = "Hola " . $_clientName . "! " . $_e_burger . " " .
            "Te comparto la cotizaci\xC3\xB3n " . $_quoteNum .
            ($_eventDate ? " para tu evento del " . $_eventDate : "") .
            ". Puedes verla completa aqu\xC3\xAD " . $_e_down . "\n" . $pubLink;
        break;
    case 'enviada':
        $_diasStr = $_daysSent !== null
            ? " hace " . $_daysSent . " d\xC3\xADa" . ($_daysSent !== 1 ? "s" : "")
            : "";
        $waMsgTxt = "Hola " . $_clientName . "! " . $_e_wave . " " .
            "Solo quer\xC3\xADa hacer un seguimiento de la cotizaci\xC3\xB3n " . $_quoteNum .
            " que te enviamos" . $_diasStr . ". " .
            "\xC2\xBFTuviste oportunidad de revisarla? Quedo atento a cualquier consulta " . $_e_smile . "\n\n" . $pubLink;
        break;
    case 'aceptada':
        $waMsgTxt = "Hola " . $_clientName . "! " . $_e_party . " " .
            "Con el evento" . ($_eventDate ? " del " . $_eventDate : "") .
            " acerc\xC3\xA1ndose, quer\xC3\xADa coordinar los \xC3\xBAltimos detalles contigo. " .
            "\xC2\xBFTienes disponibilidad para conversar esta semana? " . $_e_cal;
        break;
    case 'rechazada':
    default:
        $waMsgTxt = "Hola " . $_clientName . "! " . $_e_pray . " " .
            "Entendemos que por el momento no pudimos concretar. " .
            "Si en alg\xC3\xBAn momento retoman la b\xC3\xBAsqueda, con gusto les preparamos una nueva propuesta. " .
            "\xC2\xA1\xC3\x89xitos! " . $_e_spark;
        break;
}
$waMsg  = rawurlencode($waMsgTxt);
$waLink = $clientPhone
    ? "https://wa.me/" . $clientPhone . "?text=" . $waMsg
    : "https://wa.me/?text=" . $waMsg;

$pageTitle  = 'Cotizacion ' . $quote['quote_number'];
$activePage = 'quotes';
$extraHead  = '
<style>
/* ---- Edit mobile layout ---- */
.edit-grid {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
/* Barra de acciones fija abajo en mobile */
.edit-action-bar {
  position: fixed;
  bottom: 0; left: 0; right: 0;
  background: #fff;
  border-top: 1px solid var(--border);
  padding: 10px 14px;
  padding-bottom: max(10px, env(safe-area-inset-bottom));
  display: flex;
  gap: 8px;
  z-index: 100;
  box-shadow: 0 -4px 16px rgba(0,0,0,.08);
}
.edit-action-bar .btn {
  flex: 1;
  min-height: 48px;
  font-size: 12px;
  padding: 10px 6px;
  -webkit-tap-highlight-color: transparent;
}
.edit-spacer { height: 76px; }

/* Total destacado en mobile */
.total-hero {
  background: var(--brand);
  color: var(--ink);
  border-radius: 12px;
  padding: 16px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
.total-hero-label { font-size: 12px; font-weight: 700; opacity: .8; }
.total-hero-amount { font-size: 26px; font-weight: 800; }

/* Items compactos en mobile */
.item-compact {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8px;
}
.item-compact:last-child { border-bottom: none; }
.item-compact-left { flex: 1; min-width: 0; }
.item-compact-name { font-size: 14px; font-weight: 600; line-height: 1.3; }
.item-compact-meta { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.item-compact-right { text-align: right; flex-shrink: 0; }
.item-compact-sub   { font-size: 15px; font-weight: 700; }
.item-compact-mode  {
  display: inline-block;
  background: #f0f0f0;
  border-radius: 4px;
  padding: 1px 6px;
  font-size: 10px;
  color: #666;
}

/* Estado badges grandes para touch */
/* Botón de seguimiento */
.seg-btn {
  display: flex; align-items: center; gap: 12px; width: 100%;
  padding: 14px 16px; border-radius: 12px; border: 1.5px solid var(--border);
  background: #fff; cursor: pointer; text-align: left; text-decoration: none;
  transition: opacity .15s; -webkit-tap-highlight-color: transparent;
  margin-bottom: 12px;
}
.seg-btn:active { opacity: .75; }
.seg-btn-icon { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.seg-btn-text { flex: 1; min-width: 0; }
.seg-btn-title { font-size: 14px; font-weight: 700; margin-bottom: 2px; }
.seg-btn-sub   { font-size: 12px; color: var(--text-muted); }
.seg-btn-arrow { font-size: 18px; color: var(--text-muted); flex-shrink: 0; }
.seg-borrador  .seg-btn-icon  { background: #f0f0f0; color: #666; }
.seg-borrador  .seg-btn-title { color: var(--text-primary); }
.seg-enviada   { border-color: #93c5fd; background: #eff6ff; }
.seg-enviada   .seg-btn-icon  { background: #dbeafe; color: #1d4ed8; }
.seg-enviada   .seg-btn-title { color: #1d4ed8; }
.seg-aceptada  { border-color: #86efac; background: #f0fdf4; }
.seg-aceptada  .seg-btn-icon  { background: #dcfce7; color: #15803d; }
.seg-aceptada  .seg-btn-title { color: #15803d; }
.seg-rechazada { border-color: #fca5a5; background: #fff5f5; }
.seg-rechazada .seg-btn-icon  { background: #fee2e2; color: #dc2626; }
.seg-rechazada .seg-btn-title { color: #dc2626; }
.seg-panel { display: none; border: 1.5px solid var(--border); border-radius: 12px; margin-bottom: 12px; overflow: hidden; }
.seg-panel.open { display: block; }
.seg-contact-btns { display: grid; grid-template-columns: 1fr 1fr 1fr; }
.seg-cbtn { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px; padding: 12px 8px; background: none; border: none; border-right: 1px solid var(--border); border-top: 1px solid var(--border); cursor: pointer; font-size: 11px; font-weight: 600; color: var(--text-primary); text-decoration: none; min-height: 64px; -webkit-tap-highlight-color: transparent; }
.seg-cbtn:last-child { border-right: none; }
.seg-cbtn:active { background: var(--bg-input); }
.seg-cbtn-icon { font-size: 22px; }
.seg-cbtn-wa   { color: #16a34a; }
.seg-cbtn-call { color: #2563eb; }
.seg-cbtn.disabled { opacity: .35; pointer-events: none; }

.status-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 12px 14px;
  border: 1.5px solid var(--border);
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 8px;
  min-height: 48px;
  text-align: left;
  -webkit-tap-highlight-color: transparent;
  transition: border-color .15s, background .15s;
}
.status-btn:hover  { border-color: var(--red); background: var(--red-light); }
.status-btn.active { border-color: var(--red); background: var(--red-light); color: var(--red); }
.status-dot-lg {
  width: 10px; height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

@media (min-width: 768px) {
  .edit-grid {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 20px;
    align-items: start;
  }
  .edit-action-bar { display: none; }
  .edit-spacer { display: none; }
  .edit-desktop-actions { display: flex !important; }
}
.card-title{display:inline-flex;align-items:center;gap:8px}
.card-title .sec-ico{display:inline-flex;color:var(--text-secondary)}
.card-title .sec-ico svg{width:16px;height:16px}
.seg-btn-icon svg,.seg-cbtn-icon svg{width:18px;height:18px}
.btn svg{vertical-align:-3px}
</style>';

include __DIR__ . '/../admin/layout-top.php';
?>

<!-- BREADCRUMB -->
<div class="breadcrumb">
  <a href="<?php echo APP_URL; ?>/quotes/list.php">Cotizaciones</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?php echo clean($quote['quote_number']); ?></span>
</div>

<!-- PAGE HEADER mobile-friendly -->
<div style="margin-bottom:16px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px">
    <h1 style="font-size:20px;font-weight:800"><?php echo clean($quote['quote_number']); ?></h1>
    <?php echo quoteStatusBadge($quote['status']); ?>
  </div>
  <p style="font-size:13px;color:var(--text-muted)">
    <?php echo clean($quote['client_name']); ?>
    <?php if ($quote['event_type']): ?> · <?php echo clean($quote['event_type']); ?><?php endif; ?>
    <?php if ($quote['event_date']): ?> · <?php echo formatDate($quote['event_date']); ?><?php endif; ?>
  </p>
</div>

<div class="edit-grid">

  <!-- ===== COLUMNA PRINCIPAL ===== -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- TOTAL HERO (mobile first) -->
    <div class="total-hero">
      <div>
        <div class="total-hero-label">TOTAL COTIZACION</div>
        <div class="total-hero-amount"><?php echo formatMoney((float)$quote['total']); ?></div>
        <?php if ($quote['num_people'] > 0 && $quote['price_per_person'] > 0): ?>
        <div style="font-size:12px;opacity:.75;margin-top:2px">
          <?php echo formatMoney((float)$quote['price_per_person']); ?> por persona
        </div>
        <?php endif; ?>
      </div>
      <div style="text-align:right">
        <div style="font-size:11px;opacity:.7;margin-bottom:4px">Desglose</div>
        <div style="font-size:12px;opacity:.85">Sub: <?php echo formatMoney((float)$quote['subtotal']); ?></div>
        <?php if ($quote['igv_type'] !== 'none'): ?>
        <div style="font-size:12px;opacity:.85">IGV <?php echo $quote['igv_type']; ?>%: <?php echo formatMoney((float)$quote['igv_amount']); ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- PRODUCTOS -->
    <div class="card">
      <div class="card-header">
        <span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg></span>Productos cotizados</span>
        <span style="font-size:13px;color:var(--text-muted)"><?php echo count($items); ?> items</span>
      </div>
      <?php foreach ($items as $it):
        $modeLabel = 'Libre';
        if ($it['price_mode'] === 'per_person') $modeLabel = '&times; persona';
        if ($it['price_mode'] === 'per_event')  $modeLabel = '&times; evento';
      ?>
      <div class="item-compact">
        <div class="item-compact-left">
          <div class="item-compact-name"><?php echo clean($it['name']); ?></div>
          <div class="item-compact-meta">
            <span class="item-compact-mode"><?php echo $modeLabel; ?></span>
            <?php echo formatMoney((float)$it['unit_price']); ?>
            &times; <?php echo number_format((float)$it['quantity'], 1); ?>
            <?php if ($it['discount_pct'] > 0): ?>
              &nbsp;&minus;<?php echo number_format((float)$it['discount_pct'], 1); ?>%
            <?php endif; ?>
          </div>
        </div>
        <div class="item-compact-right">
          <div class="item-compact-sub"><?php echo formatMoney((float)$it['subtotal']); ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- LINK PUBLICO -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></span>Link público</span></div>
      <div class="card-body">
        <div style="font-size:12px;background:#f8f8f8;border-radius:8px;padding:10px;word-break:break-all;color:#555;margin-bottom:10px;line-height:1.5">
          <?php echo APP_URL; ?>/quotes/view.php?token=<?php echo clean($quote['public_token']); ?>
        </div>
        <div style="display:flex;gap:8px">
          <button type="button" id="copyLinkBtn"
                  onclick="copyPublicLink()"
                  class="btn btn-secondary btn-sm" style="flex:1">
            Copiar link
          </button>
          <a href="<?php echo clean($pubLink); ?>" target="_blank"
             class="btn btn-ghost btn-sm" style="flex:1">
            Abrir
          </a>
        </div>
        <div class="form-hint" style="margin-top:8px">El cliente puede ver la cotizacion sin necesidad de login</div>
      </div>
    </div>

    <!-- HISTORIAL -->
    <?php if (!empty($log)): ?>
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l4 2"/></svg></span>Historial</span></div>
      <div class="card-body" style="padding:0">
        <?php foreach ($log as $l):
          $_svg = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="#8a7600" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
          $icons = array(
            'enviada'   => $_svg . '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4Z"/></svg>',
            'aceptada'  => $_svg . '<path d="M20 6 9 17l-5-5"/></svg>',
            'rechazada' => $_svg . '<path d="M18 6 6 18M6 6l12 12"/></svg>',
          );
          $icon  = isset($icons[$l['to_status']]) ? $icons[$l['to_status']] : ($_svg . '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>');
        ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border)">
          <div style="width:34px;height:34px;border-radius:50%;background:var(--red-light);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0">
            <?php echo $icon; ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600">
              <?php echo $l['from_status'] ? quoteStatusLabel($l['from_status']) . ' &rarr; ' . quoteStatusLabel($l['to_status']) : quoteStatusLabel($l['to_status']); ?>
            </div>
            <?php if ($l['note']): ?>
            <div style="font-size:12px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo clean($l['note']); ?></div>
            <?php endif; ?>
          </div>
          <div style="text-align:right;flex-shrink:0">
            <div style="font-size:11px;color:var(--text-muted)"><?php echo clean($l['user_name']); ?></div>
            <div style="font-size:11px;color:var(--text-muted)"><?php echo formatDate($l['created_at']); ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- ===== COLUMNA LATERAL (desktop) ===== -->
  <div style="display:flex;flex-direction:column;gap:16px">

    <!-- SEGUIMIENTO -->
    <?php
    $daysSinceSent = $quote['sent_at']
        ? (int)((time() - strtotime($quote['sent_at'])) / 86400)
        : null;
    $daysToEvent = $quote['event_date']
        ? (int)((strtotime($quote['event_date']) - time()) / 86400)
        : null;

    $segClass = 'seg-' . $quote['status'];
    switch ($quote['status']) {
        case 'borrador':
            $segIcon  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4Z"/></svg>';
            $segTitle = 'Enviar cotizaci&oacute;n al cliente';
            $segSub   = 'A&uacute;n no se ha enviado &middot; Lista para enviar';
            break;
        case 'enviada':
            $segIcon  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';
            $segTitle = 'Hacer seguimiento';
            $segSub   = ($daysSinceSent !== null ? 'Enviada hace ' . $daysSinceSent . ' d&iacute;a' . ($daysSinceSent !== 1 ? 's' : '') . ' &middot; ' : '') . 'Recuerda al cliente por WhatsApp o llamada';
            break;
        case 'aceptada':
            $segIcon  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/></svg>';
            $segTitle = 'Coordinar el evento';
            $segSub   = ($daysToEvent !== null && $daysToEvent >= 0
                ? 'Evento en ' . $daysToEvent . ' d&iacute;a' . ($daysToEvent !== 1 ? 's' : '')
                : 'Evento confirmado') . ' &middot; Confirma detalles con el cliente';
            break;
        case 'rechazada':
        default:
            $segIcon  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>';
            $segTitle = 'Recuperar cliente';
            $segSub   = 'Contacta para entender el motivo y ofrecer alternativas';
            break;
    }

    $callLink = preg_replace('/\D/', '', $quote['client_phone'] ?? '')
        ? 'tel:+' . preg_replace('/\D/', '', $quote['client_phone'])
        : '';
    ?>
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/></svg></span>Seguimiento</span></div>
      <div class="card-body">
        <button type="button" class="seg-btn <?php echo $segClass; ?>" onclick="toggleSegPanel()">
          <div class="seg-btn-icon"><?php echo $segIcon; ?></div>
          <div class="seg-btn-text">
            <div class="seg-btn-title"><?php echo $segTitle; ?></div>
            <div class="seg-btn-sub"><?php echo $segSub; ?></div>
          </div>
          <span class="seg-btn-arrow" id="segArrow">&#8250;</span>
        </button>
        <div class="seg-panel" id="segPanel">
          <div style="padding:12px 14px;font-size:13px;font-weight:600;color:var(--text-primary)">
            <?php echo clean($quote['client_name']); ?>
            <?php if ($quote['client_phone']): ?>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;font-weight:400">
              +<?php echo clean(preg_replace('/\D/', '', $quote['client_phone'])); ?>
              <?php if ($quote['client_email']): ?>&nbsp;&middot;&nbsp;<?php echo clean($quote['client_email']); ?><?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <div class="seg-contact-btns">
            <?php if ($waLink): ?>
            <a href="<?php echo $waLink; ?>" target="_blank" class="seg-cbtn seg-cbtn-wa">
              <span class="seg-cbtn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/></svg></span>WhatsApp
            </a>
            <?php else: ?>
            <div class="seg-cbtn disabled"><span class="seg-cbtn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/></svg></span>WhatsApp</div>
            <?php endif; ?>

            <?php if ($callLink): ?>
            <a href="<?php echo $callLink; ?>" class="seg-cbtn seg-cbtn-call">
              <span class="seg-cbtn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/></svg></span>Llamar
            </a>
            <?php else: ?>
            <div class="seg-cbtn disabled"><span class="seg-cbtn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92Z"/></svg></span>Llamar</div>
            <?php endif; ?>

            <?php if ($quote['client_email']): ?>
            <a href="<?php echo APP_URL; ?>/quotes/send-email.php?id=<?php echo $id; ?>" class="seg-cbtn">
              <span class="seg-cbtn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></svg></span>Email
            </a>
            <?php else: ?>
            <div class="seg-cbtn disabled"><span class="seg-cbtn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></svg></span>Email</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- CAMBIAR ESTADO -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/></svg></span>Cambiar estado</span></div>
      <div class="card-body">
        <form method="post" id="statusForm">
          <?php echo csrfField(); ?>
          <input type="hidden" name="change_status" value="1">
          <input type="hidden" name="new_status" id="newStatusInput" value="<?php echo $quote['status']; ?>">

          <?php
          $statusConfig = array(
            'borrador'  => array('#aaa',    'Borrador'),
            'enviada'   => array('#2563eb', 'Enviada'),
            'aceptada'  => array('#16a34a', 'Aceptada'),
            'rechazada' => array('#dc2626', 'Rechazada'),
          );
          foreach ($statusConfig as $st => [$color, $label]):
          ?>
          <button type="button"
                  class="status-btn <?php echo $quote['status'] === $st ? 'active' : ''; ?>"
                  onclick="selectStatus('<?php echo $st; ?>', this)">
            <span class="status-dot-lg" style="background:<?php echo $color; ?>"></span>
            <?php echo $label; ?>
            <?php if ($quote['status'] === $st): ?>
              <span style="margin-left:auto;font-size:11px;opacity:.6">Actual</span>
            <?php endif; ?>
          </button>
          <?php endforeach; ?>

          <div class="form-group" style="margin-top:8px">
            <input type="text" name="status_note"
                   placeholder="Nota opcional (ej: Confirmado por WhatsApp)"
                   style="font-size:14px">
          </div>
          <button type="submit" class="btn btn-primary btn-block">
            Guardar estado
          </button>
        </form>
      </div>
    </div>

    <!-- ACCIONES DESKTOP -->
    <div class="edit-desktop-actions" style="display:none;flex-direction:column;gap:8px">
      <a href="<?php echo $waLink; ?>" target="_blank" class="btn btn-success btn-block" style="gap:8px">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/></svg>
        WhatsApp al cliente
      </a>
      <?php if ($quote['client_email']): ?>
      <a href="<?php echo APP_URL; ?>/quotes/send-email.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-block" style="gap:8px">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></svg>
        Enviar por email
      </a>
      <?php endif; ?>
      <a href="<?php echo $pdfLink; ?>" target="_blank" class="btn btn-secondary btn-block" style="gap:8px">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
        Ver cotización
      </a>
    </div>

  </div>

</div>

<!-- SPACER para barra fija mobile -->
<div class="edit-spacer"></div>

<!-- BARRA DE ACCIONES FIJA MOBILE -->
<div class="edit-action-bar">
  <a href="<?php echo APP_URL; ?>/quotes/list.php" class="btn btn-back">
    ← Lista
  </a>
  <a href="<?php echo $pdfLink; ?>" target="_blank" class="btn btn-pdf" style="background:#1a1a1a;color:#fff;gap:5px">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg> Ver
  </a>
  <a href="<?php echo $waLink; ?>" target="_blank" class="btn btn-wa" style="background:#25D366;color:#fff;gap:5px">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/></svg> WA
  </a>
  <?php if ($quote['client_email']): ?>
  <a href="<?php echo APP_URL; ?>/quotes/send-email.php?id=<?php echo $id; ?>" class="btn btn-email" style="background:#2563eb;color:#fff;gap:5px">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></svg> Email
  </a>
  <?php endif; ?>
  <button onclick="copyPublicLink()" class="btn btn-link" id="copyMobileBtn" style="background:#f0f0f0;color:#333;gap:5px">
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg> Link
  </button>
</div>

<script>
var pubLink = '<?php echo APP_URL; ?>/quotes/view.php?token=<?php echo clean($quote['public_token']); ?>';

function toggleSegPanel() {
  var panel = document.getElementById('segPanel');
  var arrow = document.getElementById('segArrow');
  var open  = panel.classList.contains('open');
  panel.classList.toggle('open', !open);
  if (arrow) arrow.style.transform = open ? '' : 'rotate(90deg)';
}

function copyPublicLink() {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(pubLink).then(function() {
      var btns = [document.getElementById('copyLinkBtn'), document.getElementById('copyMobileBtn')];
      btns.forEach(function(btn) {
        if (!btn) return;
        var orig = btn.innerHTML;
        btn.innerHTML = '&#10003; Copiado';
        btn.style.background = '#16a34a';
        btn.style.color = '#fff';
        setTimeout(function() {
          btn.innerHTML = orig;
          btn.style.background = '';
          btn.style.color = '';
        }, 2000);
      });
    });
  } else {
    prompt('Copia este link:', pubLink);
  }
}

function selectStatus(status, btn) {
  document.getElementById('newStatusInput').value = status;
  document.querySelectorAll('.status-btn').forEach(function(b) { b.classList.remove('active'); });
  btn.classList.add('active');
}
</script>

<?php include __DIR__ . '/../admin/layout-bottom.php'; ?>
