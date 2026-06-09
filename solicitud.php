<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<link rel="icon" type="image/png" href="/img/favicon.png">
<title>Cotiza tu evento — El Gringo</title>
<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$co = array(
    'name'   => getSetting('company_name',      'El Gringo Burger Joint'),
    'logo'   => getSetting('company_logo',        ''),   // logo oscuro (Logo A) para fondo claro
    'wa'     => getSetting('whatsapp_number',     ''),
);
$logoUrl  = !empty($co['logo']) ? UPLOAD_URL . $co['logo'] : '';
$logoPath = !empty($co['logo']) ? UPLOAD_PATH . $co['logo'] : '';

$countryCodes = array(
    '51'  => 'PE +51', '56'  => 'CL +56', '57'  => 'CO +57', '54'  => 'AR +54',
    '55'  => 'BR +55', '1'   => 'US +1',  '34'  => 'ES +34', '52'  => 'MX +52',
    '593' => 'EC +593','591' => 'BO +591','595' => 'PY +595','598' => 'UY +598',
);

$errors  = array();
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type     = in_array(isset($_POST['type'])?$_POST['type']:'', array('empresa','persona')) ? $_POST['type'] : 'empresa';
    $name     = clean(isset($_POST['name'])         ? $_POST['name']         : '');
    $rucDni   = clean(isset($_POST['ruc_dni'])      ? $_POST['ruc_dni']      : '');
    $contact  = clean(isset($_POST['contact_name']) ? $_POST['contact_name'] : '');
    $email    = clean(isset($_POST['email'])        ? $_POST['email']        : '');
    $code     = preg_replace('/\D/', '', isset($_POST['country_code'])  ? $_POST['country_code']  : '51');
    $phoneNum = preg_replace('/\D/', '', isset($_POST['phone_number'])  ? $_POST['phone_number']  : '');
    $phone    = ($code && $phoneNum) ? $code . $phoneNum : '';
    $evDate   = clean(isset($_POST['event_date'])     ? $_POST['event_date']     : '');
    $evTime   = clean(isset($_POST['event_time'])     ? $_POST['event_time']     : '');
    $evDur    = clean(isset($_POST['event_duration']) ? $_POST['event_duration'] : '');
    $evLoc    = clean(isset($_POST['event_location']) ? $_POST['event_location'] : '');
    $numP     = cleanInt(isset($_POST['num_people'])  ? $_POST['num_people']     : 0);
    $comments = clean(isset($_POST['comments'])       ? $_POST['comments']       : '');

    if (!$name)  $errors[] = 'El nombre es obligatorio.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El email no es valido.';
    if (!$evDate) $errors[] = 'La fecha del evento es obligatoria.';
    if (!$numP)   $errors[] = 'Indica el numero de personas.';

    if (isset($_POST['website']) && !empty($_POST['website'])) {
        $success = true; // honeypot
    } elseif (empty($errors)) {
        $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        Database::insert(
            "INSERT INTO quote_requests (type,name,ruc_dni,contact_name,email,phone,event_date,event_time,event_duration,event_location,num_people,comments,ip_address)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
            array($type,$name,$rucDni,$contact,$email,$phone,$evDate,$evTime,$evDur,$evLoc,$numP,$comments,$ip)
        );
        $success = true;
    }
}
// Valor por defecto del tipo (para re-render con error)
$pType = in_array(isset($_POST['type'])?$_POST['type']:'', array('empresa','persona')) ? $_POST['type'] : 'empresa';
function pv($k){ return clean(isset($_POST[$k]) ? $_POST[$k] : ''); }
$embed = isset($_GET['embed']);   // incrustado en la landing (acordeón)
?>
<meta name="theme-color" content="#FCDA13">
<style>
  :root{ --brand:#FCDA13; --brand-dark:#e6c400; --ink:#1a1a1a; --bg:#f4f4f0; --card:#fff;
         --line:#e7e4dc; --muted:#8a877e; --green:#16a34a; }
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
  html{-webkit-text-size-adjust:100%}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);
       color:var(--ink);min-height:100vh;display:flex;justify-content:center;-webkit-font-smoothing:antialiased}
  .wrap{width:100%;max-width:480px;display:flex;flex-direction:column;min-height:100vh}
  .top{padding:24px 22px 0;text-align:center}
  .top img{max-height:46px;max-width:160px;object-fit:contain;display:block;margin:0 auto 6px}
  .top .name-fb{font-size:22px;font-weight:900;letter-spacing:-.5px}
  .top h1{font-size:19px;font-weight:800;margin-top:8px}
  .top p{font-size:13px;color:var(--muted);margin-top:3px}
  .prog{display:flex;gap:6px;padding:18px 22px 8px}
  .prog .seg{flex:1;height:5px;border-radius:999px;background:var(--line)}
  .prog .seg.done{background:var(--brand)}
  .step-lbl{font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);padding:0 22px 8px}
  .body{flex:1;padding:6px 22px 14px}
  .step{display:none;animation:slide .35s cubic-bezier(.2,.8,.25,1)}
  .step.active{display:block}
  @keyframes slide{from{opacity:0;transform:translateX(14px)}to{opacity:1;transform:none}}
  .fg{margin-bottom:14px}
  label{display:block;font-size:12.5px;font-weight:700;color:#444;margin-bottom:6px}
  label .opt{font-weight:400;color:var(--muted)}
  input,select,textarea{width:100%;padding:13px 14px;background:#fff;border:1.5px solid var(--line);
       border-radius:11px;font-size:16px;color:var(--ink);outline:none;font-family:inherit;transition:border-color .15s,box-shadow .15s;-webkit-appearance:none}
  input:focus,select:focus,textarea:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(252,218,19,.25)}
  input.err,select.err{border-color:#dc2626;box-shadow:0 0 0 3px rgba(220,38,38,.15)}
  textarea{min-height:96px;resize:vertical}
  .row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .phone{display:flex;gap:8px}.phone select{width:104px;flex-shrink:0}
  .seg-ctl{display:flex;background:#efece4;border-radius:12px;padding:4px;margin-bottom:18px}
  .seg-ctl button{flex:1;border:none;background:none;padding:11px;font-size:14px;font-weight:700;color:var(--muted);border-radius:9px;cursor:pointer;transition:.15s}
  .seg-ctl button.on{background:#fff;color:var(--ink);box-shadow:0 1px 4px rgba(0,0,0,.08)}
  .stepper{display:flex;align-items:center;border:1.5px solid var(--line);border-radius:11px;overflow:hidden;width:fit-content}
  .stepper button{width:48px;height:50px;border:none;background:#fff;font-size:22px;cursor:pointer;color:var(--ink)}
  .stepper button:active{background:#f0f0f0}
  .stepper input{width:78px;text-align:center;border:none;border-left:1.5px solid var(--line);border-right:1.5px solid var(--line);border-radius:0;font-weight:800;font-size:17px}
  .stepper input:focus{box-shadow:none}
  .note{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px;font-size:13px;color:var(--muted);line-height:1.5}
  .nav{position:sticky;bottom:0;background:linear-gradient(transparent,var(--bg) 24%);padding:14px 22px 22px;display:flex;gap:10px}
  .btn{flex:1;padding:15px;border-radius:12px;border:none;font-size:15px;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;min-height:52px}
  .btn-next{background:var(--brand);color:var(--ink)}.btn-next:active{background:var(--brand-dark)}
  .btn-back{flex:0 0 auto;width:54px;background:#fff;border:1.5px solid var(--line);color:#555}
  .btn svg{width:18px;height:18px}
  .alert{margin:0 22px 14px;padding:13px 15px;border-radius:11px;font-size:13.5px;background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
  #done{text-align:center;padding:64px 26px;flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center}
  .done-ic{width:78px;height:78px;border-radius:50%;background:var(--green);display:flex;align-items:center;justify-content:center;margin-bottom:18px;animation:pop .4s cubic-bezier(.2,.8,.25,1)}
  @keyframes pop{from{transform:scale(.6);opacity:0}to{transform:scale(1);opacity:1}}
  .done-ic svg{width:38px;height:38px;color:#fff}
  #done h2{font-size:22px;font-weight:800;margin-bottom:8px}
  #done p{font-size:14px;color:var(--muted);line-height:1.55;margin-bottom:24px}
  .wa{display:inline-flex;align-items:center;gap:8px;background:#25D366;color:#fff;text-decoration:none;padding:14px 22px;border-radius:12px;font-weight:700}
  .wa svg{width:18px;height:18px}
  @media(min-width:520px){ body{padding:24px 16px}.wrap{min-height:auto;background:var(--card);border-radius:18px;box-shadow:0 6px 30px rgba(0,0,0,.08);overflow:hidden;min-height:600px} }
  /* modo embebido (dentro de la landing) */
  body.embed{background:transparent;min-height:0}
  body.embed .wrap{min-height:0!important;box-shadow:none;border-radius:16px;display:block}
  body.embed .body{padding-top:14px;flex:none}
  body.embed .nav{position:static;background:none;padding:8px 22px 16px}
  @media(min-width:520px){ body.embed{padding:0}body.embed .wrap{box-shadow:none} }
</style>
</head>
<body class="<?php echo $embed ? 'embed' : ''; ?>">
<div class="wrap">

  <?php if (!$embed): ?>
  <div class="top">
    <?php if ($logoUrl && file_exists($logoPath)): ?>
      <img src="<?php echo clean($logoUrl); ?>" alt="<?php echo clean($co['name']); ?>">
    <?php else: ?>
      <div class="name-fb"><?php echo clean($co['name']); ?></div>
    <?php endif; ?>
    <h1>Cotiza tu evento</h1>
    <p>Cuéntanos y te armamos una propuesta a tu medida</p>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <div id="done">
    <div class="done-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>
    <h2>¡Solicitud enviada!</h2>
    <p>Gracias por escribirnos. Nos pondremos en contacto muy pronto para coordinar los detalles y enviarte tu cotización.</p>
    <?php if ($co['wa']): ?>
    <a class="wa" href="https://wa.me/<?php echo clean(preg_replace('/\D/','',$co['wa'])); ?>?text=<?php echo rawurlencode('Hola! Acabo de enviar una solicitud de cotización de evento 🍔'); ?>" target="_blank"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"/></svg> Escríbenos por WhatsApp</a>
    <?php endif; ?>
  </div>
  <?php else: ?>

  <?php if ($errors): ?><div class="alert">✗ <?php echo htmlspecialchars(implode(' ', $errors)); ?></div><?php endif; ?>

  <form method="post" id="reqForm">
    <div class="hp" style="position:absolute;left:-9999px"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
    <input type="hidden" name="type" id="typeInput" value="<?php echo $pType; ?>">

    <div class="prog"><span class="seg done"></span><span class="seg"></span><span class="seg"></span></div>
    <div class="step-lbl" id="stepLbl">Paso 1 de 3 · Tus datos</div>

    <div class="body">
      <!-- PASO 1 -->
      <div class="step active" data-step="1">
        <div class="seg-ctl">
          <button type="button" id="opt-empresa" class="<?php echo $pType==='empresa'?'on':''; ?>" onclick="setType('empresa')">Empresa</button>
          <button type="button" id="opt-persona" class="<?php echo $pType==='persona'?'on':''; ?>" onclick="setType('persona')">Persona</button>
        </div>
        <div class="fg">
          <label id="nameLabel"><?php echo $pType==='persona'?'Nombre completo':'Razón social'; ?></label>
          <input type="text" name="name" id="nameInput" value="<?php echo pv('name'); ?>"
                 placeholder="<?php echo $pType==='persona'?'Nombre y apellidos':'Nombre de tu empresa'; ?>" data-req>
        </div>
        <div class="row2">
          <div class="fg"><label id="docLabel"><?php echo $pType==='persona'?'DNI':'RUC'; ?></label>
            <input type="text" name="ruc_dni" id="docInput" value="<?php echo pv('ruc_dni'); ?>"
                   placeholder="<?php echo $pType==='persona'?'8 dígitos':'11 dígitos'; ?>" inputmode="numeric"></div>
          <div class="fg" id="contactGroup" style="<?php echo $pType==='persona'?'display:none':''; ?>">
            <label>Contacto</label>
            <input type="text" name="contact_name" value="<?php echo pv('contact_name'); ?>" placeholder="Persona de contacto"></div>
        </div>
        <div class="fg"><label>Teléfono / WhatsApp</label>
          <div class="phone">
            <select name="country_code">
              <?php foreach ($countryCodes as $code => $label): ?>
              <option value="<?php echo $code; ?>" <?php echo (isset($_POST['country_code'])?$_POST['country_code']:'51')===(string)$code?'selected':''; ?>><?php echo $label; ?></option>
              <?php endforeach; ?>
            </select>
            <input type="tel" name="phone_number" value="<?php echo pv('phone_number'); ?>" placeholder="987 654 321" inputmode="numeric">
          </div>
        </div>
        <div class="fg"><label>Email <span class="opt">(opcional)</span></label>
          <input type="email" name="email" value="<?php echo pv('email'); ?>" placeholder="tu@correo.com" inputmode="email"></div>
      </div>

      <!-- PASO 2 -->
      <div class="step" data-step="2">
        <div class="row2">
          <div class="fg"><label>Fecha</label><input type="date" name="event_date" id="dateInput" value="<?php echo pv('event_date'); ?>" min="<?php echo date('Y-m-d'); ?>" data-req></div>
          <div class="fg"><label>Hora <span class="opt">(opcional)</span></label><input type="time" name="event_time" value="<?php echo pv('event_time'); ?>"></div>
        </div>
        <div class="fg"><label>Duración estimada <span class="opt">(opcional)</span></label>
          <input type="text" name="event_duration" value="<?php echo pv('event_duration'); ?>" placeholder="Ej: 3 horas"></div>
        <div class="fg"><label>Lugar del evento <span class="opt">(opcional)</span></label>
          <input type="text" name="event_location" value="<?php echo pv('event_location'); ?>" placeholder="Local, dirección, distrito…"></div>
        <div class="fg"><label>N° de personas</label>
          <div class="stepper">
            <button type="button" onclick="people(-10)">−</button>
            <input type="text" name="num_people" id="pplInput" value="<?php echo cleanInt(pv('num_people'))?:''; ?>" placeholder="0" inputmode="numeric" data-req>
            <button type="button" onclick="people(10)">+</button>
          </div>
        </div>
      </div>

      <!-- PASO 3 -->
      <div class="step" data-step="3">
        <div class="fg"><label>Comentarios <span class="opt">(opcional)</span></label>
          <textarea name="comments" placeholder="Ej: queremos smash burgers y salchipapas, hay parrilla disponible, presupuesto aprox…"><?php echo pv('comments'); ?></textarea></div>
        <div class="note">Al enviar, recibimos tu solicitud y te contactamos por WhatsApp o teléfono para coordinar y mandarte la cotización. 🙌</div>
      </div>
    </div>

    <div class="nav">
      <button type="button" class="btn btn-back" id="backBtn" style="display:none" onclick="go(-1)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg></button>
      <button type="button" class="btn btn-next" id="nextBtn" onclick="go(1)">Siguiente
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg></button>
      <button type="submit" class="btn btn-next" id="submitBtn" style="display:none">Enviar solicitud</button>
    </div>
  </form>
  <?php endif; ?>

</div>

<script>
var cur = 1, total = 3;
function postH(){ if(window.parent!==window){ try{ var h=Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)+4; parent.postMessage({eg_quote_height: h}, '*'); }catch(e){} } }
window.addEventListener('message', function(e){ if(e.data && e.data.eg_request_height) postH(); });
function render(){
  document.querySelectorAll('.step').forEach(function(s){ s.classList.toggle('active', +s.dataset.step===cur); });
  document.querySelectorAll('.prog .seg').forEach(function(s,i){ s.classList.toggle('done', i<cur); });
  var labels=['Tus datos','Tu evento','¿Algo más?'];
  document.getElementById('stepLbl').textContent='Paso '+cur+' de '+total+' · '+labels[cur-1];
  document.getElementById('backBtn').style.display = cur>1?'flex':'none';
  document.getElementById('nextBtn').style.display   = cur<total?'flex':'none';
  document.getElementById('submitBtn').style.display = cur===total?'flex':'none';
  postH();
}
function validateStep(){
  var step = document.querySelector('.step[data-step="'+cur+'"]');
  var ok = true, first=null;
  step.querySelectorAll('[data-req]').forEach(function(f){
    var bad = !f.value.trim() || (f.id==='pplInput' && !(parseInt(f.value)>0));
    f.classList.toggle('err', bad);
    if (bad && !first){ first=f; ok=false; }
  });
  if (first) first.focus();
  return ok;
}
function go(d){
  if (d>0 && !validateStep()) return;
  cur = Math.min(total, Math.max(1, cur+d));
  render();
  if (window.scrollY) window.scrollTo({top:0,behavior:'smooth'});
}
function setType(t){
  document.getElementById('typeInput').value=t;
  document.getElementById('opt-empresa').classList.toggle('on', t==='empresa');
  document.getElementById('opt-persona').classList.toggle('on', t==='persona');
  document.getElementById('nameLabel').textContent  = t==='empresa'?'Razón social':'Nombre completo';
  document.getElementById('nameInput').placeholder  = t==='empresa'?'Nombre de tu empresa':'Nombre y apellidos';
  document.getElementById('docLabel').textContent   = t==='empresa'?'RUC':'DNI';
  document.getElementById('docInput').placeholder   = t==='empresa'?'11 dígitos':'8 dígitos';
  document.getElementById('contactGroup').style.display = t==='empresa'?'':'none';
  postH();
}
function people(d){ var el=document.getElementById('pplInput'); el.value=Math.max(0,(parseInt(el.value)||0)+d); el.classList.remove('err'); }
var f=document.getElementById('reqForm');
if (f){
  f.addEventListener('submit', function(e){
    if (!validateStep()){ e.preventDefault(); return; }
    var b=document.getElementById('submitBtn'); b.disabled=true; b.textContent='Enviando…';
  });
  render();
  <?php if ($errors): ?>document.getElementById('nameInput').classList.add('err');<?php endif; ?>
}
window.addEventListener('load', function(){ postH(); setTimeout(postH, 350); });
window.addEventListener('resize', postH);
</script>
</body>
</html>
