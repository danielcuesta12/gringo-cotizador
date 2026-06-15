<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$slug  = clean($_GET['u'] ?? '');
$token = clean($_GET['t'] ?? '');
$ubi = Database::fetch("SELECT * FROM ubicaciones WHERE slug=? AND asistencia_token=? AND activa=1", [$slug, $token]);
if (!$ubi || $token === '') { http_response_code(404); exit('Enlace de marcaje inválido.'); }
$empleados = Database::fetchAll("SELECT id, nombre, foto_referencia, (pin_hash IS NOT NULL) AS tiene_pin FROM empleados WHERE ubicacion_id=? AND activo=1 ORDER BY nombre", [$ubi['id']]);
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#1B1F4B">
<title>Marcar asistencia — <?= clean($ubi['nombre']) ?></title>
<style>
  :root {
    --navy: #1B1F4B;
    --yellow: #F5C200;
    --ink: #1e1e1e;
    --green: #1f9d55;
    --red: #d64545;
    --amber: #d9920a;
    --line: #ececec;
    --muted: #8a8a93;
    --bg: #e9e9ec;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
  html { -webkit-text-size-adjust: 100%; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 12px 40px;
    color: var(--ink);
    -webkit-font-smoothing: antialiased;
  }

  .phone {
    width: 100%;
    max-width: 420px;
    background: #fff;
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 18px 50px rgba(0,0,0,.18);
  }

  /* Header */
  .mk-top {
    background: var(--navy);
    color: #fff;
    padding: 18px 20px 16px;
    text-align: center;
  }
  .mk-top .loc {
    font-size: 11px;
    font-weight: 700;
    color: var(--yellow);
    letter-spacing: 1px;
    text-transform: uppercase;
    margin-bottom: 3px;
  }
  .mk-top h1 {
    font-size: 17px;
    font-weight: 900;
    line-height: 1.2;
  }
  .mk-top .clock {
    font-size: 12.5px;
    color: #cfcfe0;
    margin-top: 4px;
  }

  /* Body */
  .mk-body { padding: 18px; }

  /* Roster */
  .mk-hint {
    font-size: 13px;
    color: var(--muted);
    text-align: center;
    margin-bottom: 14px;
  }
  .roster {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
  }
  .emp {
    border: 1.5px solid var(--line);
    border-radius: 14px;
    padding: 12px 6px;
    text-align: center;
    cursor: pointer;
    transition: border-color .12s, background .12s, transform .1s;
    -webkit-user-select: none;
    user-select: none;
  }
  .emp:active { transform: scale(.96); }
  .emp:hover { border-color: var(--yellow); background: #fffbec; }
  .emp .av {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    margin: 0 auto 7px;
    background: linear-gradient(135deg, #ffe9a8, #ffd34d);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    font-weight: 900;
    color: var(--navy);
    overflow: hidden;
    flex-shrink: 0;
  }
  .emp .av img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
  }
  .emp .nm {
    font-size: 11.5px;
    font-weight: 800;
    color: var(--navy);
    line-height: 1.2;
    word-break: break-word;
  }
  .emp .pin-badge {
    font-size: 9px;
    color: var(--muted);
    margin-top: 3px;
  }

  /* Mark panel */
  .mk-panel { display: none; }
  .mk-panel.on { display: block; }

  .back {
    background: none;
    border: none;
    color: var(--muted);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    padding: 0 0 10px 0;
    display: flex;
    align-items: center;
    gap: 4px;
  }
  .back:hover { color: var(--ink); }

  /* Camera / selfie area */
  .selfie-wrap {
    width: 160px;
    height: 160px;
    border-radius: 18px;
    margin: 0 auto 8px;
    background: #111;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
  }
  .selfie-wrap video,
  .selfie-wrap canvas,
  .selfie-wrap img.preview {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 18px;
  }
  .selfie-wrap canvas { display: none; }
  .selfie-placeholder {
    color: #555;
    font-size: 13px;
    text-align: center;
    padding: 12px;
    z-index: 1;
  }
  .selfie-placeholder .face { font-size: 52px; display: block; margin-bottom: 6px; }
  .live-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(0,0,0,.55);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 20px;
    z-index: 2;
    display: none;
  }
  .live-badge.on { display: block; }

  .capture-btn {
    display: block;
    width: 160px;
    margin: 0 auto 10px;
    background: var(--navy);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: 9px 0;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: opacity .15s;
  }
  .capture-btn:active { opacity: .8; }
  .capture-btn.taken { background: var(--green); }

  /* PIN input */
  .pin-wrap {
    display: none;
    margin-bottom: 14px;
  }
  .pin-wrap.on { display: block; }
  .pin-wrap label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: #444;
    margin-bottom: 6px;
  }
  .pin-wrap input {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid var(--line);
    border-radius: 11px;
    font-size: 20px;
    letter-spacing: 6px;
    text-align: center;
    outline: none;
    font-family: inherit;
    color: var(--ink);
    background: #fff;
    transition: border-color .15s;
  }
  .pin-wrap input:focus { border-color: var(--yellow); box-shadow: 0 0 0 3px rgba(245,194,0,.2); }

  .who {
    text-align: center;
    font-size: 16px;
    font-weight: 900;
    color: var(--navy);
    margin: 6px 0 2px;
  }

  /* GPS status */
  .geo {
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 14px;
  }
  .geo.ok { color: var(--green); }
  .geo.bad { color: var(--red); }
  .geo.wait { color: var(--muted); }

  /* Action buttons */
  .btns { display: flex; gap: 10px; }
  .mbtn {
    flex: 1;
    border: none;
    border-radius: 14px;
    padding: 16px 0;
    font-size: 15px;
    font-weight: 900;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: .5px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 3px;
    transition: opacity .15s, transform .1s;
  }
  .mbtn:active { transform: scale(.97); opacity: .85; }
  .mbtn .sub {
    font-size: 10px;
    font-weight: 700;
    text-transform: none;
    letter-spacing: 0;
    opacity: .8;
  }
  .b-in { background: var(--green); color: #fff; }
  .b-out { background: #fff; color: var(--ink); border: 1.5px solid var(--line); }

  .sugg {
    font-size: 11px;
    color: var(--muted);
    text-align: center;
    margin-top: 10px;
    line-height: 1.4;
  }
  .consent {
    font-size: 10.5px;
    color: #aaa;
    text-align: center;
    margin-top: 14px;
    line-height: 1.4;
  }

  /* Feedback overlay */
  .feedback {
    display: none;
    text-align: center;
    padding: 30px 16px 20px;
  }
  .feedback.on { display: block; }
  .feedback .ic {
    font-size: 52px;
    display: block;
    margin-bottom: 10px;
    animation: pop .35s cubic-bezier(.2,.8,.25,1);
  }
  .feedback h2 {
    font-size: 18px;
    font-weight: 900;
    color: var(--navy);
    margin-bottom: 6px;
  }
  .feedback p {
    font-size: 13.5px;
    color: var(--muted);
    line-height: 1.5;
  }
  .feedback .ok-msg { color: var(--green); }
  .feedback .err-msg { color: var(--red); }

  .btn-volver {
    display: block;
    width: 100%;
    margin-top: 18px;
    background: var(--navy);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 14px;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    text-align: center;
    transition: opacity .15s;
  }
  .btn-volver:active { opacity: .8; }

  /* Loading spinner on buttons */
  .mbtn.loading { opacity: .65; pointer-events: none; }

  @keyframes pop {
    from { transform: scale(.6); opacity: 0; }
    to   { transform: scale(1);  opacity: 1; }
  }

  /* Note below card */
  .page-note {
    max-width: 420px;
    width: 100%;
    margin-top: 14px;
    font-size: 11.5px;
    color: #777;
    text-align: center;
    line-height: 1.5;
  }
</style>
</head>
<body>

<div class="phone">

  <!-- Header -->
  <div class="mk-top">
    <div class="loc">📍 <?= clean($ubi['nombre']) ?></div>
    <h1>Marca tu asistencia</h1>
    <div class="clock" id="reloj">—</div>
  </div>

  <div class="mk-body">

    <!-- Feedback (éxito / error) -->
    <div class="feedback" id="feedback">
      <span class="ic" id="fb-ic">✓</span>
      <h2 id="fb-title"></h2>
      <p id="fb-msg"></p>
      <button class="btn-volver" onclick="volverRoster()">Volver al padrón</button>
    </div>

    <!-- Paso 1: elegir empleado -->
    <div id="step-roster">
      <?php if (empty($empleados)): ?>
        <p style="text-align:center;color:var(--muted);font-size:13px;padding:20px 0;">No hay empleados asignados a este local.</p>
      <?php else: ?>
        <div class="mk-hint">Toca tu nombre para marcar</div>
        <div class="roster">
          <?php foreach ($empleados as $emp): ?>
          <?php
            $inicial = mb_strtoupper(mb_substr(trim($emp['nombre']), 0, 1, 'UTF-8'), 'UTF-8');
            $foto    = !empty($emp['foto_referencia']) ? UPLOAD_URL . $emp['foto_referencia'] : '';
          ?>
          <div class="emp" onclick="elegirEmpleado(<?= (int)$emp['id'] ?>, <?= htmlspecialchars(json_encode(clean($emp['nombre'])), ENT_QUOTES) ?>, <?= $emp['tiene_pin'] ? 'true' : 'false' ?>)">
            <div class="av">
              <?php if ($foto): ?>
                <img src="<?= clean($foto) ?>" alt="<?= clean($emp['nombre']) ?>" onerror="this.parentNode.innerHTML='<?= htmlspecialchars($inicial, ENT_QUOTES) ?>'">
              <?php else: ?>
                <?= $inicial ?>
              <?php endif; ?>
            </div>
            <div class="nm"><?= clean($emp['nombre']) ?></div>
            <?php if ($emp['tiene_pin']): ?><div class="pin-badge">🔒 PIN</div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Paso 2: selfie + entrada/salida -->
    <div class="mk-panel" id="step-mark">
      <button class="back" onclick="backRoster()">‹ Cambiar de persona</button>

      <!-- Selfie / cámara -->
      <div class="selfie-wrap" id="selfie-wrap">
        <div class="selfie-placeholder" id="cam-placeholder">
          <span class="face">🤳</span>
          <span>Iniciando cámara…</span>
        </div>
        <video id="cam-video" autoplay playsinline muted></video>
        <canvas id="cam-canvas"></canvas>
        <span class="live-badge" id="live-badge">● EN VIVO</span>
      </div>

      <button class="capture-btn" id="btn-capturar" onclick="capturar()">📸 Tomar selfie</button>

      <div class="who" id="who-name">—</div>

      <!-- GPS status -->
      <div class="geo wait" id="geo-status">⏳ Obteniendo ubicación…</div>

      <!-- PIN (solo si el empleado tiene) -->
      <div class="pin-wrap" id="pin-wrap">
        <label for="pin-input">🔒 Ingresa tu PIN</label>
        <input type="password" id="pin-input" inputmode="numeric" maxlength="8" autocomplete="off" placeholder="• • • •">
      </div>

      <!-- Botones Entrada / Salida -->
      <div class="btns">
        <button class="mbtn b-in" id="btn-entrada" onclick="marcar('entrada')">
          Entrada
          <span class="sub" id="sub-entrada">↳ sugerido</span>
        </button>
        <button class="mbtn b-out" id="btn-salida" onclick="marcar('salida')">
          Salida
          <span class="sub" id="sub-salida"></span>
        </button>
      </div>

      <div class="sugg" id="sugg-text">Elige <b>Entrada</b> o <b>Salida</b>. Tú confirmas.</div>

      <div class="consent">Al marcar aceptas que se tome una foto para control de asistencia. Se borra a los 2 meses.</div>
    </div>

  </div><!-- /.mk-body -->
</div><!-- /.phone -->

<div class="page-note">
  En <b>modo celular</b> (food truck / delivery) se abre en tu teléfono. Si marcas fuera del local, el GPS sale en rojo pero igual puedes marcar.
</div>

<!-- Honeypot oculto (antispam) -->
<input name="website" style="display:none" tabindex="-1" autocomplete="off" value="">

<script>
/* ========== CONFIG ========== */
var API_URL     = '<?= APP_URL ?>/api/asistencia.php';
var UBI_ID      = <?= (int)$ubi['id'] ?>;
var UBI_TOKEN   = '<?= clean($ubi['asistencia_token']) ?>';
var UBI_FUENTE  = '<?= clean($ubi['modo_marcaje'] ?? 'tablet') ?>';

/* ========== ESTADO ========== */
var empActual   = null;  // { id, nombre, tienePIN }
var fotoB64     = null;  // string base64 o null
var gpsLat      = null;
var gpsLng      = null;
var gpsOk       = false;
var camStream   = null;
var fotoTomada  = false;

/* ========== RELOJ ========== */
(function tickReloj() {
  var d = new Date();
  var dias = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
  var meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
  var h = d.getHours(), m = d.getMinutes();
  var ampm = h >= 12 ? 'p. m.' : 'a. m.';
  h = h % 12 || 12;
  var mm = m < 10 ? '0' + m : m;
  var txt = dias[d.getDay()] + ' ' + d.getDate() + ' ' + meses[d.getMonth()] + ' · ' + h + ':' + mm + ' ' + ampm;
  var el = document.getElementById('reloj');
  if (el) el.textContent = txt;
  setTimeout(tickReloj, 15000);
})();

/* ========== ELEGIR EMPLEADO ========== */
function elegirEmpleado(id, nombre, tienePIN) {
  empActual  = { id: id, nombre: nombre, tienePIN: tienePIN };
  fotoB64    = null;
  fotoTomada = false;
  gpsLat     = null;
  gpsLng     = null;
  gpsOk      = false;

  document.getElementById('step-roster').style.display = 'none';
  document.getElementById('feedback').classList.remove('on');
  document.getElementById('step-mark').classList.add('on');

  document.getElementById('who-name').textContent = nombre;

  // PIN
  var pinWrap  = document.getElementById('pin-wrap');
  var pinInput = document.getElementById('pin-input');
  if (tienePIN) {
    pinWrap.classList.add('on');
    pinInput.value = '';
  } else {
    pinWrap.classList.remove('on');
  }

  // Resetear foto
  var video   = document.getElementById('cam-video');
  var canvas  = document.getElementById('cam-canvas');
  var ph      = document.getElementById('cam-placeholder');
  var badge   = document.getElementById('live-badge');
  var btnCap  = document.getElementById('btn-capturar');
  canvas.style.display = 'none';
  video.style.display  = 'block';
  ph.style.display     = 'flex';
  badge.classList.remove('on');
  btnCap.classList.remove('taken');
  btnCap.textContent = '📸 Tomar selfie';

  iniciarCamara();
  iniciarGPS();
}

/* ========== CÁMARA ========== */
function iniciarCamara() {
  if (camStream) {
    camStream.getTracks().forEach(function(t){ t.stop(); });
    camStream = null;
  }
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    document.getElementById('cam-placeholder').innerHTML = '<span class="face">📷</span><span>Cámara no disponible en este dispositivo</span>';
    return;
  }
  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
    .then(function(stream) {
      camStream = stream;
      var video = document.getElementById('cam-video');
      var ph    = document.getElementById('live-badge');
      video.srcObject = stream;
      video.onloadedmetadata = function() {
        video.play();
        document.getElementById('cam-placeholder').style.display = 'none';
        ph.classList.add('on');
      };
    })
    .catch(function(err) {
      console.warn('Cámara:', err);
      document.getElementById('cam-placeholder').innerHTML = '<span class="face">📷</span><span style="font-size:11px">Sin acceso a cámara. Igual puedes marcar.</span>';
    });
}

function capturar() {
  var video  = document.getElementById('cam-video');
  var canvas = document.getElementById('cam-canvas');
  var btnCap = document.getElementById('btn-capturar');

  if (!camStream || !video.videoWidth) {
    // Sin cámara: marcar sin foto
    fotoB64    = null;
    fotoTomada = true;
    btnCap.classList.add('taken');
    btnCap.textContent = '✓ Sin foto (cámara no disponible)';
    return;
  }

  canvas.width  = video.videoWidth;
  canvas.height = video.videoHeight;
  var ctx = canvas.getContext('2d');
  // Espejo horizontal (selfie)
  ctx.translate(canvas.width, 0);
  ctx.scale(-1, 1);
  ctx.drawImage(video, 0, 0);

  fotoB64    = canvas.toDataURL('image/jpeg', 0.7);
  fotoTomada = true;

  // Mostrar preview en vez del video
  video.style.display  = 'none';
  canvas.style.display = 'block';
  document.getElementById('live-badge').classList.remove('on');
  btnCap.classList.add('taken');
  btnCap.textContent = '✓ Foto tomada · Toca para repetir';

  // Detener stream para liberar la cámara
  if (camStream) {
    camStream.getTracks().forEach(function(t){ t.stop(); });
    camStream = null;
  }
}

/* ========== GPS ========== */
function iniciarGPS() {
  var el = document.getElementById('geo-status');
  el.className = 'geo wait';
  el.textContent = '⏳ Obteniendo ubicación…';

  if (!navigator.geolocation) {
    el.className = 'geo bad';
    el.textContent = '✗ GPS no disponible en este dispositivo';
    return;
  }

  navigator.geolocation.getCurrentPosition(
    function(pos) {
      gpsLat = pos.coords.latitude;
      gpsLng = pos.coords.longitude;
      gpsOk  = true;
      el.className = 'geo ok';
      el.textContent = '✓ Ubicación obtenida · GPS ok';
    },
    function(err) {
      gpsLat = null;
      gpsLng = null;
      gpsOk  = false;
      el.className = 'geo bad';
      el.textContent = '⚑ Sin GPS — igual puedes marcar';
    },
    { timeout: 10000, maximumAge: 60000, enableHighAccuracy: true }
  );
}

/* ========== VOLVER AL ROSTER ========== */
function backRoster() {
  detenerCamara();
  empActual   = null;
  fotoB64     = null;
  fotoTomada  = false;

  document.getElementById('step-mark').classList.remove('on');
  document.getElementById('feedback').classList.remove('on');
  document.getElementById('step-roster').style.display = 'block';
}

function volverRoster() {
  backRoster();
}

function detenerCamara() {
  if (camStream) {
    camStream.getTracks().forEach(function(t){ t.stop(); });
    camStream = null;
  }
}

/* ========== MARCAR ========== */
function marcar(tipo) {
  if (!empActual) return;

  // Pedir foto si no se ha tomado
  if (!fotoTomada) {
    capturar();
    // Si aún sin stream (cámara off), marcar como tomada igualmente
    if (!fotoTomada) {
      fotoTomada = true;
      fotoB64 = null;
    }
  }

  var pin = '';
  if (empActual.tienePIN) {
    pin = (document.getElementById('pin-input').value || '').replace(/\D/g, '');
    if (!pin) {
      var pinEl = document.getElementById('pin-input');
      pinEl.style.borderColor = 'var(--red)';
      pinEl.focus();
      setTimeout(function(){ pinEl.style.borderColor = ''; }, 2000);
      return;
    }
  }

  // Deshabilitar botones
  var btnIn  = document.getElementById('btn-entrada');
  var btnOut = document.getElementById('btn-salida');
  btnIn.classList.add('loading');
  btnOut.classList.add('loading');

  var payload = {
    ubicacion_id: UBI_ID,
    token:        UBI_TOKEN,
    empleado_id:  empActual.id,
    pin:          pin,
    tipo:         tipo,
    foto:         fotoB64 || '',
    lat:          gpsLat,
    lng:          gpsLng,
    fuente:       UBI_FUENTE,
    website:      ''
  };

  fetch(API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    btnIn.classList.remove('loading');
    btnOut.classList.remove('loading');

    if (data.ok) {
      var now = new Date();
      var h   = now.getHours(), m = now.getMinutes();
      var hh  = h < 10 ? '0'+h : h;
      var mm  = m < 10 ? '0'+m : m;
      var tipoLabel = tipo === 'entrada' ? 'Entrada' : 'Salida';
      mostrarFeedback(true, '✓ ' + tipoLabel + ' registrada', empActual.nombre + ' · ' + hh + ':' + mm);
      detenerCamara();
    } else {
      mostrarFeedback(false, 'Error al marcar', data.error || 'Inténtalo de nuevo.');
    }
  })
  .catch(function(err) {
    btnIn.classList.remove('loading');
    btnOut.classList.remove('loading');
    mostrarFeedback(false, 'Sin conexión', 'Verifica tu red e inténtalo nuevamente.');
  });
}

/* ========== FEEDBACK ========== */
function mostrarFeedback(ok, titulo, msg) {
  document.getElementById('step-mark').classList.remove('on');
  var fb = document.getElementById('feedback');
  fb.classList.add('on');
  document.getElementById('fb-ic').textContent    = ok ? '✅' : '❌';
  document.getElementById('fb-title').textContent = titulo;
  document.getElementById('fb-title').className   = ok ? 'ok-msg' : 'err-msg';
  document.getElementById('fb-msg').textContent   = msg;
}
</script>
</body>
</html>
