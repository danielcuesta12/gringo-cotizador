<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('reservas');

$id = cleanInt(isset($_GET['id']) ? $_GET['id'] : 0);
$reserva = Database::fetch("SELECT * FROM reservas WHERE id = ?", array($id));
if (!$reserva) {
    flashMessage('error', 'Reserva no encontrada.');
    redirect('/admin/reservas/index.php');
}

// ─── POST: confirmar o rechazar ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $nuevo_estado = clean(isset($_POST['nuevo_estado']) ? $_POST['nuevo_estado'] : '');
    $asunto       = clean(isset($_POST['asunto'])       ? $_POST['asunto']       : '');
    $mensaje      = clean(isset($_POST['mensaje'])      ? $_POST['mensaje']      : '');

    $estadosValidos = array('confirmada', 'rechazada');
    if (!in_array($nuevo_estado, $estadosValidos, true)) {
        flashMessage('error', 'Estado no valido.');
        redirect('/admin/reservas/detail.php?id=' . $id);
    }

    // Actualizar estado
    Database::execute(
        "UPDATE reservas SET estado = ? WHERE id = ?",
        array($nuevo_estado, $id)
    );

    $verbo = $nuevo_estado === 'confirmada' ? 'confirmada' : 'rechazada';

    // Enviar email solo si el cliente dejo uno y hay asunto
    if ($reserva['email'] && $asunto) {
        $company   = getSetting('company_name', 'El Gringo Burger Joint');
        $fromEmail = 'reservas@elgringo.pe';
        $fromName  = '=?UTF-8?B?' . base64_encode($company) . '?=';

        $bodyHtml = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f0f0f0;font-family:-apple-system,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f0;padding:20px 0">
<tr><td align="center">
<table width="100%" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden">

  <!-- Header -->
  <tr>
    <td style="background:#C8102E;padding:24px 28px">
      <p style="margin:0;font-size:20px;font-weight:800;color:#fff">' . htmlspecialchars($company) . '</p>
      <p style="margin:4px 0 0;font-size:12px;color:rgba(255,255,255,.7)">Reservas</p>
    </td>
  </tr>

  <!-- Cuerpo -->
  <tr>
    <td style="padding:28px">
      <p style="margin:0 0 20px;font-size:15px;color:#1a1a1a;line-height:1.6">' . nl2br(htmlspecialchars($mensaje)) . '</p>
      <p style="margin:20px 0 0;font-size:12px;color:#aaa;text-align:center">
        Para responder a este correo, escribenos a <a href="mailto:' . $fromEmail . '" style="color:#C8102E">' . $fromEmail . '</a>.
      </p>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="background:#1a1a1a;padding:14px 28px">
      <p style="margin:0;font-size:11px;color:#555">' . htmlspecialchars($company) . ' &middot; Lima, Peru</p>
    </td>
  </tr>

</table>
</td></tr>
</table>
</body>
</html>';

        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $headers .= 'From: ' . $fromName . ' <' . $fromEmail . '>' . "\r\n";
        $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
        $headers .= 'X-Mailer: ElGringoCotizador/1.0' . "\r\n";

        $enviado = @mail(
            $reserva['email'],
            '=?UTF-8?B?' . base64_encode($asunto) . '?=',
            $bodyHtml,
            $headers
        );

        if ($enviado) {
            flashMessage('success', 'Reserva ' . $verbo . ' y correo enviado a ' . $reserva['email'] . '.');
        } else {
            flashMessage('error', 'Reserva ' . $verbo . ', pero no se pudo enviar el correo. Verifica la configuracion de correo saliente.');
        }
    } else {
        flashMessage('success', 'Reserva ' . $verbo . '. El cliente no dejo correo, solo se actualizo el estado.');
    }

    redirect('/admin/reservas/index.php');
}

// ─── Preparar datos para el template JS y la vista ───────────────────────────
$company  = getSetting('company_name', 'El Gringo Burger Joint');
$phone    = preg_replace('/\D/', '', $reserva['telefono'] ?: '');
$waMsg    = rawurlencode('Hola ' . $reserva['nombre'] . ', te contactamos de ' . $company . ' sobre tu reserva para el ' . ($reserva['fecha'] ? formatDate($reserva['fecha']) : 'proximo evento') . '.');
$waLink   = $phone ? 'https://wa.me/' . $phone . '?text=' . $waMsg : '';
$callLink = $phone ? 'tel:+' . $phone : '';

// Templates predeterminados — interpolados en PHP para pasarlos a JS
$fechaFmt    = $reserva['fecha'] ? formatDate($reserva['fecha']) : '(por coordinar)';
$horaFmt     = $reserva['hora']  ?: '(por confirmar)';
$personasStr = $reserva['num_personas'] > 0 ? $reserva['num_personas'] : '?';
$nombreFmt   = $reserva['nombre'];

$tplConfirmarAsunto  = 'Tu reserva en El Gringo esta confirmada';
$tplConfirmarMensaje = 'Hola ' . $nombreFmt . ', ' . mb_convert_encoding('&#129395;', 'UTF-8', 'HTML-ENTITIES') . ' ' . mb_convert_encoding('&#127828;', 'UTF-8', 'HTML-ENTITIES') . ' ' . mb_convert_encoding('&#9989;', 'UTF-8', 'HTML-ENTITIES') . "\n\n"
    . 'Tu reserva esta confirmada. Te esperamos el ' . $fechaFmt . ' a las ' . $horaFmt . ' para ' . $personasStr . ' persona(s).' . "\n\n"
    . 'Si necesitas cambiar algo o tienes alguna consulta, escribenos con gusto.' . "\n\n"
    . 'Gracias por elegir El Gringo ' . mb_convert_encoding('&#127884;', 'UTF-8', 'HTML-ENTITIES') . "\n"
    . '— El equipo de ' . $company;

$tplRechazarAsunto  = 'Sobre tu reserva en El Gringo';
$tplRechazarMensaje = 'Hola ' . $nombreFmt . ',' . "\n\n"
    . 'Lamentablemente no podemos confirmar tu reserva para el ' . $fechaFmt . ' a las ' . $horaFmt . '.' . "\n\n"
    . 'Si quieres, escibenos para buscar otra fecha u horario que se ajuste mejor. Disculpa las molestias.' . "\n\n"
    . '— ' . $company;

$bc = array('pendiente' => 'badge-warning', 'confirmada' => 'badge-success', 'rechazada' => 'badge-danger');
$bl = array('pendiente' => 'Pendiente',     'confirmada' => 'Confirmada',    'rechazada' => 'Rechazada');

$pageTitle  = 'Reserva — ' . $reserva['nombre'];
$activePage = 'reservas';

$extraHead = '
<style>
.det-wrap{display:flex;flex-direction:column;gap:16px}
.contact-box{background:var(--bg-input);border:1.5px solid var(--border);border-radius:12px;padding:16px}
.contact-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:12px}
.contact-person{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.contact-av{width:42px;height:42px;border-radius:50%;background:var(--red-light);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:var(--red);flex-shrink:0}
.contact-name{font-size:15px;font-weight:700;color:var(--text-primary)}
.contact-sub{font-size:12px;color:var(--text-muted);margin-top:2px}
.contact-btns{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.cbtn{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:12px 8px;border-radius:10px;border:1.5px solid var(--border);background:var(--bg-card);cursor:pointer;font-size:12px;font-weight:600;color:var(--text-primary);text-decoration:none;min-height:70px;-webkit-tap-highlight-color:transparent;transition:all .15s}
.cbtn:active{opacity:.7;transform:scale(.97)}
.cbtn-icon{font-size:22px;line-height:1}
.cbtn-wa{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.cbtn-call{border-color:#bfdbfe;background:#eff6ff;color:#1e40af}
.cbtn.disabled{opacity:.35;pointer-events:none}

.det-section{border:1px solid var(--border);border-radius:12px;overflow:hidden}
.det-sec-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:8px 14px;background:var(--bg-input);border-bottom:1px solid var(--border)}
.det-grid{display:grid;grid-template-columns:1fr 1fr}
.det-cell{padding:10px 14px;border-right:1px solid var(--border);border-bottom:1px solid var(--border)}
.det-cell:nth-child(2n){border-right:none}
.det-cell.last-row,.det-cell.last-row~.det-cell{border-bottom:none}
.det-cell.full{grid-column:1/-1;border-right:none}
.det-lbl{font-size:10px;color:var(--text-muted);margin-bottom:3px}
.det-val{font-size:13px;font-weight:600;color:var(--text-primary)}

.respond-section{border:1px solid var(--border);border-radius:12px;overflow:hidden}
.respond-header{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 14px;background:var(--bg-input);border-bottom:1px solid var(--border)}
.respond-body{padding:16px}
.preset-btns{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.preset-btn{padding:12px 8px;border-radius:10px;border:2px solid var(--border);background:var(--bg-card);cursor:pointer;font-size:13px;font-weight:700;color:var(--text-primary);text-align:center;transition:all .15s;-webkit-tap-highlight-color:transparent}
.preset-btn:active{transform:scale(.97);opacity:.8}
.preset-btn-confirm{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.preset-btn-reject{border-color:#fecaca;background:#fef2f2;color:#991b1b}
.preset-btn.active-confirm{background:#16a34a;color:#fff;border-color:#16a34a}
.preset-btn.active-reject{background:#dc2626;color:#fff;border-color:#dc2626}

.respond-field{margin-bottom:12px}
.respond-field label{display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:6px}
.respond-field input,.respond-field textarea{width:100%;box-sizing:border-box}
.respond-field textarea{resize:vertical;min-height:120px}

.action-footer-fixed{position:fixed;bottom:0;left:0;right:0;background:var(--bg-card);border-top:1px solid var(--border);padding:10px 16px;padding-bottom:max(10px,env(safe-area-inset-bottom));display:flex;gap:8px;z-index:100;box-shadow:0 -4px 16px rgba(0,0,0,.08)}
.action-footer-fixed .btn{flex:1;min-height:48px;font-size:13px}
.det-spacer{height:76px}
@media(min-width:768px){
  .action-footer-fixed{position:static;padding:16px 0 0;box-shadow:none;border:none}
  .det-spacer{display:none}
}
</style>';

include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?php echo APP_URL; ?>/admin/reservas/index.php">Reservas</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?php echo htmlspecialchars($reserva['nombre']); ?></span>
</div>

<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px">
  <h1 style="font-size:20px;font-weight:800"><?php echo htmlspecialchars($reserva['nombre']); ?></h1>
  <span class="badge <?php echo isset($bc[$reserva['estado']]) ? $bc[$reserva['estado']] : 'badge-secondary'; ?>">
    <?php echo isset($bl[$reserva['estado']]) ? $bl[$reserva['estado']] : htmlspecialchars($reserva['estado']); ?>
  </span>
  <span style="font-size:12px;color:var(--text-muted)">Recibida <?php echo formatDatetime($reserva['created_at']); ?></span>
  <a href="<?php echo APP_URL; ?>/admin/reservas/index.php" class="btn btn-ghost btn-sm" style="margin-left:auto">
    &#8592; Volver a reservas
  </a>
</div>

<div class="det-wrap">

  <!-- CONTACTAR AL CLIENTE -->
  <div class="contact-box">
    <div class="contact-title">Contactar al cliente</div>
    <div class="contact-person">
      <div class="contact-av"><?php echo strtoupper(mb_substr($reserva['nombre'], 0, 1)); ?></div>
      <div>
        <div class="contact-name"><?php echo htmlspecialchars($reserva['nombre']); ?></div>
        <div class="contact-sub">
          <?php if ($reserva['telefono']): ?>+<?php echo htmlspecialchars($reserva['telefono']); ?><?php endif; ?>
          <?php if ($reserva['email']): ?> &middot; <?php echo htmlspecialchars($reserva['email']); ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="contact-btns">
      <?php if ($waLink): ?>
      <a href="<?php echo $waLink; ?>" target="_blank" class="cbtn cbtn-wa">
        <span class="cbtn-icon">&#128172;</span>WhatsApp
      </a>
      <?php else: ?>
      <div class="cbtn disabled"><span class="cbtn-icon">&#128172;</span>WhatsApp</div>
      <?php endif; ?>

      <?php if ($callLink): ?>
      <a href="<?php echo $callLink; ?>" class="cbtn cbtn-call">
        <span class="cbtn-icon">&#128222;</span>Llamar
      </a>
      <?php else: ?>
      <div class="cbtn disabled"><span class="cbtn-icon">&#128222;</span>Llamar</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- DATOS DE LA RESERVA -->
  <div class="det-section">
    <div class="det-sec-title">Datos de la reserva</div>
    <div class="det-grid">
      <div class="det-cell">
        <div class="det-lbl">Fecha</div>
        <div class="det-val"><?php echo $reserva['fecha'] ? formatDate($reserva['fecha']) : '—'; ?></div>
      </div>
      <div class="det-cell">
        <div class="det-lbl">Hora</div>
        <div class="det-val"><?php echo $reserva['hora'] ? htmlspecialchars($reserva['hora']) : '—'; ?></div>
      </div>
      <div class="det-cell">
        <div class="det-lbl">N° personas</div>
        <div class="det-val"><?php echo $reserva['num_personas'] > 0 ? $reserva['num_personas'] . ' personas' : '—'; ?></div>
      </div>
      <div class="det-cell">
        <div class="det-lbl">Ubicacion</div>
        <div class="det-val"><?php echo $reserva['ubicacion_id'] ? htmlspecialchars($reserva['ubicacion_id']) : '—'; ?></div>
      </div>
      <div class="det-cell">
        <div class="det-lbl">Email de contacto</div>
        <div class="det-val"><?php echo $reserva['email'] ? htmlspecialchars($reserva['email']) : '—'; ?></div>
      </div>
      <div class="det-cell">
        <div class="det-lbl">Telefono</div>
        <div class="det-val"><?php echo $reserva['telefono'] ? htmlspecialchars($reserva['telefono']) : '—'; ?></div>
      </div>
      <?php if ($reserva['comentarios']): ?>
      <div class="det-cell full last-row">
        <div class="det-lbl">Comentarios</div>
        <div class="det-val" style="font-weight:400;line-height:1.5"><?php echo htmlspecialchars($reserva['comentarios']); ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- RESPONDER AL CLIENTE -->
  <?php if ($reserva['estado'] === 'pendiente'): ?>
  <div class="respond-section">
    <div class="respond-header">Responder al cliente</div>
    <div class="respond-body">
      <form method="post" id="respondForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="nuevo_estado" id="nuevo_estado" value="">

        <div class="preset-btns">
          <button type="button" class="preset-btn preset-btn-confirm" id="btnConfirmar"
                  onclick="fillPreset('confirmada')">
            &#10003; Confirmar reserva
          </button>
          <button type="button" class="preset-btn preset-btn-reject" id="btnRechazar"
                  onclick="fillPreset('rechazada')">
            &#10007; Rechazar reserva
          </button>
        </div>

        <?php if (!$reserva['email']): ?>
        <div class="alert alert-info" style="margin-bottom:14px;font-size:13px">
          &#9432; El cliente no dejo email — se actualizara el estado sin enviar correo.
        </div>
        <?php else: ?>
        <div id="mailFields" style="display:none">
          <div class="respond-field">
            <label>Asunto del correo</label>
            <input type="text" name="asunto" id="asunto" placeholder="Asunto..." value="">
          </div>
          <div class="respond-field">
            <label>Mensaje para el cliente <span style="font-weight:400;color:var(--text-muted)">(editable)</span></label>
            <textarea name="mensaje" id="mensaje" placeholder="Escribe el mensaje..."></textarea>
          </div>
          <div class="alert alert-info" style="margin-bottom:14px;font-size:13px">
            &#9993; Se enviara desde <strong>reservas@elgringo.pe</strong> a <strong><?php echo htmlspecialchars($reserva['email']); ?></strong>
          </div>
        </div>
        <?php endif; ?>

        <div id="submitArea" style="display:none">
          <button type="submit" class="btn btn-primary" id="submitBtn" style="min-width:200px">
            Enviar y marcar
          </button>
          <button type="button" class="btn btn-ghost" style="margin-left:8px"
                  onclick="resetPreset()">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="det-section">
    <div class="det-sec-title">Estado actual</div>
    <div style="padding:16px;font-size:14px;color:var(--text-secondary)">
      Esta reserva ya fue
      <strong><?php echo isset($bl[$reserva['estado']]) ? strtolower($bl[$reserva['estado']]) : htmlspecialchars($reserva['estado']); ?></strong>.
      No se puede cambiar el estado desde aqui.
    </div>
  </div>
  <?php endif; ?>

</div>

<div class="det-spacer"></div>

<script>
var hasEmail = <?php echo $reserva['email'] ? 'true' : 'false'; ?>;
var tpls = {
  confirmada: {
    asunto:  <?php echo json_encode($tplConfirmarAsunto, JSON_UNESCAPED_UNICODE); ?>,
    mensaje: <?php echo json_encode($tplConfirmarMensaje, JSON_UNESCAPED_UNICODE); ?>
  },
  rechazada: {
    asunto:  <?php echo json_encode($tplRechazarAsunto, JSON_UNESCAPED_UNICODE); ?>,
    mensaje: <?php echo json_encode($tplRechazarMensaje, JSON_UNESCAPED_UNICODE); ?>
  }
};

function fillPreset(estado) {
  document.getElementById('nuevo_estado').value = estado;

  var btnC = document.getElementById('btnConfirmar');
  var btnR = document.getElementById('btnRechazar');
  var submitBtn = document.getElementById('submitBtn');

  btnC.classList.remove('active-confirm', 'active-reject');
  btnR.classList.remove('active-confirm', 'active-reject');

  if (estado === 'confirmada') {
    btnC.classList.add('active-confirm');
    if (submitBtn) submitBtn.textContent = hasEmail ? 'Confirmar y enviar correo' : 'Confirmar reserva';
  } else {
    btnR.classList.add('active-reject');
    if (submitBtn) submitBtn.textContent = hasEmail ? 'Rechazar y enviar correo' : 'Rechazar reserva';
  }

  if (hasEmail) {
    var mailFields = document.getElementById('mailFields');
    if (mailFields) {
      mailFields.style.display = 'block';
      document.getElementById('asunto').value  = tpls[estado].asunto;
      document.getElementById('mensaje').value = tpls[estado].mensaje;
    }
  }

  var submitArea = document.getElementById('submitArea');
  if (submitArea) submitArea.style.display = 'block';
}

function resetPreset() {
  document.getElementById('nuevo_estado').value = '';

  var btnC = document.getElementById('btnConfirmar');
  var btnR = document.getElementById('btnRechazar');
  btnC.classList.remove('active-confirm', 'active-reject');
  btnR.classList.remove('active-confirm', 'active-reject');

  if (hasEmail) {
    var mailFields = document.getElementById('mailFields');
    if (mailFields) {
      mailFields.style.display = 'none';
      document.getElementById('asunto').value  = '';
      document.getElementById('mensaje').value = '';
    }
  }

  var submitArea = document.getElementById('submitArea');
  if (submitArea) submitArea.style.display = 'none';
}

// Validar que se selecciono un estado antes de hacer submit
document.getElementById('respondForm') && document.getElementById('respondForm').addEventListener('submit', function(e) {
  var estado = document.getElementById('nuevo_estado').value;
  if (!estado) {
    e.preventDefault();
    alert('Selecciona primero Confirmar o Rechazar la reserva.');
    return false;
  }
  var verbo = estado === 'confirmada' ? 'confirmar' : 'rechazar';
  if (!confirm('Se va a ' + verbo + ' la reserva de ' + <?php echo json_encode($reserva['nombre'], JSON_UNESCAPED_UNICODE); ?> + '. ¿Continuar?')) {
    e.preventDefault();
    return false;
  }
});
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
