<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$ready = (bool) Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cuentas'");
// Selección de local: por querystring o el principal
$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, sort_order, nombre");
$ubiSel = cleanInt($_GET['ubicacion_id'] ?? 0) ?: (int)($_SESSION['mozo_ubi'] ?? ($ubis[0]['id'] ?? 0));
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="theme-color" content="#1E1E1E">
<link rel="manifest" href="<?= APP_URL ?>/mozo/manifest.php">
<title>El Gringo · Mozo</title>
<style>
:root{
  /* Colores de marca: de company_settings vía brandHead() (fallback = marca base El Gringo). */
  --ng:var(--black,#1E1E1E); --am:var(--c-brand,#FFDF00); --rosa:var(--pink,#FFBBC8); --crema:#FFEFBC;
  --bg:#f4f1ea; --surface:#fff; --ink:var(--black,#1E1E1E); --muted:#6f6a60; --faint:#9a948a;
  --line:#e7e2d8; --danger:#dc2626; --ok:#16a34a;
  --r-card:16px; --r-btn:12px; --ease:cubic-bezier(.22,1,.36,1);
}
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
html,body{overscroll-behavior:none}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--ink);height:100dvh;overflow:hidden;-webkit-font-smoothing:antialiased}
.view{display:none;flex-direction:column;height:100dvh}
.view.on{display:flex}
.top{background:var(--ng);color:#fff;padding:13px 14px;padding-top:max(13px,env(safe-area-inset-top));display:flex;align-items:center;justify-content:space-between;font-weight:800;font-size:15px}
.top .y{color:var(--am)}
.top button{background:rgba(255,255,255,.16);border:none;color:#fff;border-radius:10px;min-width:40px;height:40px;padding:0 12px;font-weight:800;font-size:18px;cursor:pointer;transition:background .15s}
.top button:active{background:rgba(255,255,255,.3)}
.body{flex:1;overflow:auto;-webkit-overflow-scrolling:touch}
.foot{background:var(--surface);border-top:1px solid var(--line);padding:12px 14px;box-shadow:0 -4px 16px rgba(0,0,0,.05)}
.btn{display:block;width:100%;text-align:center;background:var(--am);color:var(--ng);font-weight:900;border:none;border-radius:var(--r-btn);padding:15px;font-size:15px;cursor:pointer;transition:transform .12s var(--ease),filter .12s}
.btn:active{transform:scale(.985);filter:brightness(.95)}
.btn.dark{background:var(--ng);color:var(--am)}
.btn.red{background:var(--danger);color:#fff}
.key{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:16px 0;font-size:22px;font-weight:800;color:var(--ink);cursor:pointer;transition:background .12s}
.key:active{background:#efeae0}
.qbtn{width:46px;height:46px;border-radius:50%;border:1.5px solid #d8d2c6;background:var(--surface);font-size:24px;font-weight:800;color:var(--ink);display:inline-flex;align-items:center;justify-content:center;line-height:1;padding:0;cursor:pointer;flex:none;transition:transform .1s var(--ease),background .12s}
.qbtn:active{transform:scale(.9);background:#f1ede4}
.qbtn.danger{color:var(--danger);border-color:#f0bcbc}
.plus{width:40px;height:40px;border-radius:50%;background:var(--am);color:var(--ng);font-weight:900;font-size:24px;display:inline-flex;align-items:center;justify-content:center;line-height:1;flex:none;transition:transform .1s var(--ease)}
.plus:active{transform:scale(.9)}
.pindots{display:flex;gap:12px;justify-content:center;margin:16px 0}
.pindots span{width:14px;height:14px;border-radius:50%;border:2px solid #cfc8bb;transition:.15s var(--ease)}
.pindots span.on{background:var(--ng);border-color:var(--ng);transform:scale(1.05)}
.row{display:flex;justify-content:space-between;align-items:center;padding:13px;border-bottom:1px solid var(--line);background:var(--surface);transition:background .12s}
.row:active{background:#faf8f3}
.tag{font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px;background:var(--crema);color:#7a6300}
.modal{position:fixed;inset:0;background:rgba(20,18,16,0);display:flex;align-items:flex-end;z-index:50;opacity:0;visibility:hidden;pointer-events:none;transition:background .22s ease,opacity .22s ease,visibility 0s .22s}
.modal.on{opacity:1;visibility:visible;pointer-events:auto;background:rgba(20,18,16,.5);transition:background .22s ease,opacity .22s ease}
.sheet{background:var(--surface);border-radius:18px 18px 0 0;width:100%;max-height:92dvh;overflow:auto;padding-bottom:max(14px,env(safe-area-inset-bottom));transform:translateY(100%);transition:transform .28s var(--ease)}
.modal.on .sheet{transform:translateY(0)}
.opt{display:flex;align-items:center;gap:11px;padding:11px 14px;font-size:15px;cursor:pointer;transition:background .12s}
.opt:active{background:#faf8f3}
.mark{width:22px;height:22px;border-radius:6px;border:2px solid #cfc8bb;flex-shrink:0;transition:.12s}
.mark.on{background:var(--ng);border-color:var(--ng)}
.mark.rad{border-radius:50%}.mark.rad.on{background:var(--am);border-color:var(--am);box-shadow:inset 0 0 0 4px #fff}
.chip{display:inline-block;font-size:13px;font-weight:800;padding:8px 14px;border-radius:999px;background:#ebe6da;color:#5a5448;white-space:nowrap;cursor:pointer;transition:background .12s,color .12s}
.chip:active{background:#dfd9cc}
.chip.on{background:var(--ng);color:var(--am)}
input[type=text],input[type=tel],input[type=email],input[type=number]{font-family:inherit;color:var(--ink)}
/* --- Cobro sheet --- */
.seg{display:flex;gap:6px;padding:4px 0;flex-wrap:nowrap;overflow:auto}
.seg button{flex:none;min-height:40px;padding:0 14px;border:1.5px solid var(--line);background:var(--surface);color:var(--ink);border-radius:var(--r-btn);font-size:13px;font-weight:800;cursor:pointer;transition:background .12s,color .12s,border-color .12s;white-space:nowrap}
.seg button.on{background:var(--ng);color:var(--am);border-color:var(--ng)}
.seg button:active{filter:brightness(.92)}
.cobro-resumen{background:#f9f6ef;border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:14px;display:flex;flex-direction:column;gap:4px}
.cobro-resumen .cr-row{display:flex;justify-content:space-between;align-items:baseline}
.cobro-resumen .cr-total{font-size:19px;font-weight:900}
.cobro-resumen .cr-falta{color:var(--ok);font-weight:800}
.cobro-resumen .cr-pagado{color:var(--muted);font-size:12px}
.cobro-config{padding:10px 0}
.cobro-config label{font-size:12px;font-weight:800;color:var(--muted);text-transform:uppercase;display:block;margin-bottom:4px}
.cobro-config input{width:100%;padding:10px 12px;border:1.5px solid var(--line);border-radius:10px;font-size:15px;font-weight:700;background:var(--surface)}
.cobro-config input:focus{border-color:var(--am);outline:none;box-shadow:0 0 0 3px rgba(255,223,0,.35)}
.cobro-config .desc-row{display:flex;gap:8px;margin-top:8px}
.cobro-config .desc-tipo{display:flex;gap:6px}
.cobro-config .desc-tipo button{flex:1;min-height:40px;border:1.5px solid var(--line);background:var(--surface);color:var(--ink);border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:background .12s}
.cobro-config .desc-tipo button.on{background:var(--ng);color:var(--am);border-color:var(--ng)}
.parte{border:1.5px solid var(--line);border-radius:14px;margin-bottom:10px;overflow:hidden}
.parte-head{background:#f2ede4;padding:9px 13px;font-size:12px;font-weight:800;color:var(--muted);text-transform:uppercase;display:flex;justify-content:space-between;align-items:center}
.parte-body{padding:10px 13px;display:flex;flex-direction:column;gap:8px}
.pago-row{display:flex;gap:7px;align-items:center}
.pago-row select{flex:1;min-width:0;padding:9px 10px;border:1.5px solid var(--line);border-radius:9px;font-size:13px;font-weight:700;background:var(--surface);color:var(--ink);-webkit-appearance:none;appearance:none}
.pago-row select:focus{border-color:var(--am);outline:none}
.pago-row input{width:88px;flex:none;padding:9px 10px;border:1.5px solid var(--line);border-radius:9px;font-size:14px;font-weight:800;text-align:right;background:var(--surface)}
.pago-row input:focus{border-color:var(--am);outline:none;box-shadow:0 0 0 3px rgba(255,223,0,.35)}
.pago-row .rm{width:34px;height:34px;border:none;background:none;color:var(--muted);font-size:18px;cursor:pointer;padding:0;display:flex;align-items:center;justify-content:center;flex:none}
.pago-row .rm:active{color:var(--danger)}
.btn-addpago{display:flex;align-items:center;gap:5px;background:none;border:1.5px dashed var(--line);border-radius:9px;padding:8px 12px;font-size:13px;font-weight:700;color:var(--muted);cursor:pointer;width:100%;justify-content:center;min-height:40px}
.btn-addpago:active{background:#f0ece3}
.comp-toggle{display:flex;align-items:center;gap:9px;padding:6px 0;cursor:pointer}
.comp-toggle .mark{flex:none}
.comp-toggle span{font-size:13px;font-weight:700;color:var(--muted)}
.comp-fields{display:none;flex-direction:column;gap:7px;padding:6px 0 0}
.comp-fields.on{display:flex}
.comp-fields input,.comp-fields select{width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-size:13px;background:var(--surface);-webkit-appearance:none;appearance:none}
.comp-fields input:focus,.comp-fields select:focus{border-color:var(--am);outline:none;box-shadow:0 0 0 3px rgba(255,223,0,.35)}
.comp-fields label{font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase}
.cobro-foot{border-top:1px solid var(--line);padding:12px 16px;background:var(--surface);position:sticky;bottom:0}
.cobro-saldo{font-size:13px;font-weight:800;text-align:center;margin-bottom:8px;min-height:18px}
.cobro-saldo.ok{color:var(--ok)}.cobro-saldo.err{color:var(--danger)}
.items-grid{display:flex;flex-direction:column;gap:0}
.items-grid .ig-row{display:flex;align-items:center;gap:9px;padding:8px 0;border-bottom:1px solid var(--line);font-size:13px}
.items-grid .ig-row:last-child{border-bottom:none}
.items-grid .ig-assign{min-width:0;flex:1}
.items-grid .ig-monto{font-weight:800;white-space:nowrap;font-size:12px;color:var(--muted)}
.items-grid .ig-sel select{padding:5px 8px;border:1.5px solid var(--line);border-radius:8px;font-size:12px;background:var(--surface);-webkit-appearance:none;appearance:none}
input:focus{outline:none;border-color:var(--am)!important;box-shadow:0 0 0 3px rgba(255,223,0,.4)}
.anul{text-decoration:line-through;color:var(--faint)}
.toast{position:fixed;left:50%;bottom:max(24px,env(safe-area-inset-bottom));transform:translateX(-50%);background:var(--ng);color:#fff;padding:11px 17px;border-radius:12px;font-weight:700;font-size:13px;z-index:80;display:none;box-shadow:0 8px 24px rgba(0,0,0,.28)}
@media (prefers-reduced-motion: reduce){
  .sheet,.modal,.btn,.qbtn,.plus,.chip,.row,.opt,.top button,.pindots span{transition:none}
  .sheet{transform:none}
}
</style>
<?= brandHead() ?>
</head>
<body>
<?php if (!$ready): ?>
<div class="view on"><div class="body" style="display:flex;align-items:center;justify-content:center;padding:24px;text-align:center">
  <p>La app del mozo necesita su migración. Aplica <code>install/57_cuentas.sql</code> en phpMyAdmin.</p>
</div></div>
<?php else: ?>

<!-- PIN -->
<div class="view on" id="v-pin">
  <div class="top"><span>EL GRINGO · <span class="y">Mozo</span></span><span id="pin-ubi"></span></div>
  <div class="body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:18px">
    <div id="pin-step" style="font-weight:800">Elige tu nombre</div>
    <div id="pin-mozos" style="display:flex;flex-direction:column;gap:8px;width:100%;max-width:320px"></div>
    <div id="pin-pad" style="display:none;width:100%;max-width:320px">
      <div class="pindots"><span></span><span></span><span></span><span></span></div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:9px">
        <button class="key" data-k="1">1</button><button class="key" data-k="2">2</button><button class="key" data-k="3">3</button>
        <button class="key" data-k="4">4</button><button class="key" data-k="5">5</button><button class="key" data-k="6">6</button>
        <button class="key" data-k="7">7</button><button class="key" data-k="8">8</button><button class="key" data-k="9">9</button>
        <button class="key" data-k="x" style="background:none">⌫</button><button class="key" data-k="0">0</button><button class="key" data-k="c" style="background:none;color:#888">‹</button>
      </div>
      <div id="pin-err" style="color:#dc2626;font-weight:700;text-align:center;margin-top:10px;min-height:18px"></div>
    </div>
  </div>
</div>

<!-- PLANO -->
<div class="view" id="v-plano">
  <div class="top"><span>Mesas · <span class="y" id="plano-piso">Piso 1</span></span><span id="plano-mozo"></span></div>
  <div id="plano-tabs" style="display:flex;gap:6px;padding:8px 10px;overflow:auto;background:#efece4"></div>
  <div class="body"><div id="plano-board" style="padding:8px"></div></div>
</div>

<!-- CUENTA -->
<div class="view" id="v-cuenta">
  <div class="top"><button onclick="goPlano()">‹</button><span>Mesa <span class="y" id="cta-mesa"></span> · <span id="cta-com"></span> pers</span><span id="cta-total">S/ 0</span></div>
  <div class="body" id="cta-body"></div>
  <div class="foot">
    <button class="btn" onclick="openCatalogo()">+ Agregar a la cuenta</button>
    <div style="display:flex;gap:8px;margin-top:8px">
      <button class="btn" id="btn-precuenta" type="button" style="background:var(--crema);color:var(--ng);flex:1">Precuenta</button>
      <button class="btn dark" id="btn-cobrar" type="button" style="flex:2">Cobrar</button>
    </div>
  </div>
</div>

<!-- CATÁLOGO (modal de pantalla completa) -->
<div class="view" id="v-cat">
  <div class="top"><button onclick="showView('v-cuenta')">‹</button><span>Agregar a Mesa <span class="y" id="cat-mesa"></span></span><span></span></div>
  <div id="cat-tabs" style="display:flex;gap:6px;padding:8px 10px;overflow:auto;background:#efece4"></div>
  <div class="body" id="cat-list"></div>
  <div class="foot" id="cat-foot" style="background:#FFEFBC;border-top-color:#e7d99a;display:none">
    <button class="btn dark" id="cat-borr-btn" onclick="openBorrador()">🛒 Ver borrador</button>
  </div>
</div>

<!-- modal producto -->
<div class="modal" id="m-prod"><div class="sheet" id="m-prod-in"></div></div>
<!-- modal comensales -->
<div class="modal" id="m-com"><div class="sheet" style="padding:18px">
  <div style="font-weight:900;font-size:16px;margin-bottom:4px">Abrir Mesa <span id="com-mesa"></span></div>
  <div style="font-size:12px;color:#888;margin-bottom:12px">¿Cuántos comensales? (opcional)</div>
  <div style="display:flex;align-items:center;gap:14px;justify-content:center;margin-bottom:14px">
    <button class="qbtn" onclick="comStep(-1)">−</button><b id="com-n" style="font-size:22px;min-width:26px;text-align:center">2</b><button class="qbtn" onclick="comStep(1)">+</button>
  </div>
  <button class="btn" onclick="confirmAbrir()">Abrir cuenta</button>
  <button class="btn" style="background:#eee;color:#555;margin-top:8px" onclick="closeModal('m-com')">Cancelar</button>
</div></div>
<!-- modal anular -->
<div class="modal" id="m-anul"><div class="sheet" style="padding:18px">
  <div style="font-weight:900;font-size:15px;margin-bottom:10px" id="anul-tit">Anular</div>
  <div id="anul-motivos" style="display:flex;flex-direction:column;gap:7px;margin-bottom:12px"></div>
  <button class="btn" style="background:#eee;color:#555" onclick="closeModal('m-anul')">Cancelar</button>
</div></div>
<!-- modal borrador (revisar antes de enviar) -->
<div class="modal" id="m-borr"><div class="sheet" id="m-borr-in"></div></div>
<!-- ficha de mesa (info al tocar) -->
<div class="modal" id="m-mesa"><div class="sheet" id="m-mesa-in"></div></div>
<!-- COBRO -->
<div class="modal" id="m-cobro" aria-hidden="true">
  <div class="sheet" role="dialog" aria-modal="true">
    <div style="padding:14px 16px 0;display:flex;justify-content:space-between;align-items:center">
      <b style="font-size:16px;font-weight:900">Cobrar mesa</b>
      <button type="button" style="background:none;border:none;font-size:22px;color:var(--muted);cursor:pointer;padding:4px;min-width:40px;min-height:40px;display:flex;align-items:center;justify-content:center" onclick="closeModal('m-cobro')">✕</button>
    </div>
    <div style="padding:12px 16px 0">
      <div class="cobro-resumen" id="cobro-resumen"></div>
      <!-- Modo -->
      <div style="font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;margin-bottom:6px">Modo de cobro</div>
      <div class="seg" id="cobro-modo">
        <button type="button" data-modo="todo" class="on" onclick="setModo('todo')">Todo junto</button>
        <button type="button" data-modo="iguales" onclick="setModo('iguales')">Iguales</button>
        <button type="button" data-modo="items" onclick="setModo('items')">Por ítems</button>
        <button type="button" data-modo="montos" onclick="setModo('montos')">Montos libres</button>
      </div>
      <!-- Config por modo -->
      <div class="cobro-config" id="cobro-config"></div>
      <!-- Partes -->
      <div id="cobro-partes"></div>
    </div>
    <!-- Pie fijo -->
    <div class="cobro-foot">
      <div class="cobro-saldo" id="cobro-saldo"></div>
      <button class="btn dark" id="cobro-confirmar" type="button" onclick="confirmarCobro()">Confirmar cobro</button>
    </div>
  </div>
</div>
<!-- SELECTOR DE CAJA (multi_caja) -->
<div class="modal" id="m-turno" aria-hidden="true">
  <div class="sheet" style="padding:18px" role="dialog" aria-modal="true">
    <div style="font-weight:900;font-size:15px;margin-bottom:4px">¿Cuál caja?</div>
    <div style="font-size:12px;color:#888;margin-bottom:12px">Hay varias cajas abiertas. Elige la que corresponde.</div>
    <div id="m-turno-list" style="display:flex;flex-direction:column;gap:7px;margin-bottom:10px"></div>
    <button class="btn" style="background:#eee;color:#555" onclick="closeModal('m-turno')">Cancelar</button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script src="<?= APP_URL ?>/assets/js/plano-render.js?v=<?= @filemtime(__DIR__ . '/../assets/js/plano-render.js') ?: time() ?>"></script>
<script>
var API = '<?= APP_URL ?>/api/mozo.php';
var CSRF = <?= json_encode($csrf) ?>;
var UPLOAD = '<?= UPLOAD_URL ?>';
var UBI = <?= (int)$ubiSel ?>;
var st = { pin:'', emp:0, pisos:[], pi:0, cuenta:null, borrador:[], catProd:[], catCat:null, prodSel:null, comN:2, comMesa:0, anul:null, lastGeo:null };

function $(id){ return document.getElementById(id); }
function showView(v){ document.querySelectorAll('.view').forEach(function(x){x.classList.remove('on');}); $(v).classList.add('on'); }
function openModal(id){ $(id).classList.add('on'); } function closeModal(id){ $(id).classList.remove('on'); }
function toast(t){ var n=$('toast'); n.textContent=t; n.style.display='block'; setTimeout(function(){n.style.display='none';},2200); }
function get(a){ return fetch(API+'?action='+a).then(function(r){return r.json();}); }
function post(a, body){ var fd=new FormData(); fd.append('action',a); Object.keys(body||{}).forEach(function(k){fd.append(k,body[k]);}); return fetch(API+'?action='+a,{method:'POST',headers:{'X-CSRF-Token':CSRF},body:fd}).then(function(r){return r.json();}); }

// geo: cachear última posición; refrescar antes de escribir
function geo(){ return new Promise(function(res){ if(!navigator.geolocation){res(null);return;} navigator.geolocation.getCurrentPosition(function(p){ st.lastGeo={lat:p.coords.latitude,lng:p.coords.longitude}; res(st.lastGeo); }, function(){ res(st.lastGeo); }, {enableHighAccuracy:true,timeout:6000,maximumAge:30000}); }); }
function withGeo(body){ body=body||{}; if(st.lastGeo){ body.lat=st.lastGeo.lat; body.lng=st.lastGeo.lng; } return body; }

// ---- PIN ----
function loadMozos(){
  get('mozos&ubicacion_id='+UBI).then(function(d){
    var box=$('pin-mozos'); box.innerHTML='';
    (d.mozos||[]).forEach(function(m){
      var b=document.createElement('button'); b.className='btn'; b.style.background='#fff'; b.textContent=m.nombre;
      b.onclick=function(){ st.emp=m.id; $('pin-step').textContent='Hola '+m.nombre+', tu PIN'; $('pin-mozos').style.display='none'; $('pin-pad').style.display='block'; renderDots(); };
      box.appendChild(b);
    });
  });
}
function renderDots(){ var dots=document.querySelectorAll('#pin-pad .pindots span'); dots.forEach(function(s,i){ s.classList.toggle('on', i<st.pin.length); }); }
document.addEventListener('click', function(e){ var k=e.target.getAttribute && e.target.getAttribute('data-k'); if(k===null||k===undefined)return;
  if(k==='x'){ st.pin=st.pin.slice(0,-1); } else if(k==='c'){ st.pin=''; st.emp=0; $('pin-mozos').style.display='flex'; $('pin-pad').style.display='none'; $('pin-step').textContent='Elige tu nombre'; return; }
  else if(st.pin.length<4){ st.pin+=k; }
  renderDots();
  if(st.pin.length===4){ doLogin(); }
});
function doLogin(){
  post('login_pin', {ubicacion_id:UBI, empleado_id:st.emp, pin:st.pin}).then(function(d){
    if(d.ok){ $('plano-mozo').textContent=d.nombre+' 👤'; geo(); enterApp(); }
    else { $('pin-err').textContent=d.error||'PIN incorrecto'; st.pin=''; renderDots(); }
  });
}

// ---- App ----
function enterApp(){ loadPlano(); showView('v-plano'); pollEstados(); navPush(); }
function navPush(){ try{ history.pushState(null,''); }catch(e){} }
function loadPlano(){ get('plano').then(function(d){ st.pisos=d.pisos||[]; st.pi=0; drawPlano(); }); }
function drawPlano(){
  var tabs=$('plano-tabs'); tabs.innerHTML='';
  st.pisos.forEach(function(p,i){ var t=document.createElement('span'); t.className='chip'+(i===st.pi?' on':''); t.textContent=p.nombre; t.onclick=function(){ st.pi=i; drawPlano(); }; tabs.appendChild(t); });
  var piso=st.pisos[st.pi]; if(!piso){ $('plano-board').innerHTML='<p style="padding:24px;text-align:center;color:#888">Este local no tiene plano. Pídele al admin que lo arme.</p>'; return; }
  $('plano-piso').textContent=piso.nombre;
  refreshEstados();
}
var EST={estados:{},montos:{},minutos:{},uN:20,uR:30};
function refreshEstados(){
  var piso=st.pisos[st.pi]; if(!piso)return;
  var board=$('plano-board');
  // alto disponible: desde el borde superior del tablero hasta el fondo de la pantalla
  var avail=Math.max(220, window.innerHeight - board.getBoundingClientRect().top - 12);
  PlanoRender.draw(board, piso, {uploadUrl:UPLOAD, estados:EST.estados, montos:EST.montos, minutos:EST.minutos, umbralNaranja:EST.uN, umbralRojo:EST.uR, maxHeight:avail, onMesaTap:onMesaTap});
}
function pollEstados(){
  get('plano_estados').then(function(d){
    if(d.ok){ EST={estados:d.estados||{},montos:d.montos||{},minutos:d.minutos||{},uN:d.umbral_naranja||20,uR:d.umbral_rojo||30}; if($('v-plano').classList.contains('on')) refreshEstados(); }
  }, function(){ /* error de red: ignorar, igual reprogramamos */ })
  .then(function(){ setTimeout(pollEstados, 5000); });
}

function onMesaTap(mesaId){
  // ocupada → ficha de info ; libre → pedir comensales y abrir
  if(EST.estados[mesaId]==='ocupada'){ openMesaInfo(mesaId); }
  else { st.comMesa=mesaId; st.comN=2; $('com-mesa').textContent=mesaNum(mesaId); $('com-n').textContent='2'; openModal('m-com'); }
}
function minDesde(at){ try{ var t=new Date(String(at).replace(' ','T')); return Math.max(0,Math.round((Date.now()-t.getTime())/60000)); }catch(e){ return 0; } }
function openMesaInfo(mesaId){
  get('mesa_info&mesa_id='+mesaId).then(function(d){
    if(!d.ok){ toast(d.error||'No se pudo abrir'); return; }
    var c=d.cuenta; st.cuenta=c;
    var rondas=c.comandas.length;
    var items=[]; c.comandas.forEach(function(co){ (co.items||[]).forEach(function(it){ if(!it.anulado) items.push(it.qty+'× '+it.nombre); }); });
    var resumen=items.slice(0,6).join(' · ')+(items.length>6?' …':'');
    var mins=c.abierta_at?minDesde(c.abierta_at):0;
    $('m-mesa-in').innerHTML=
      '<div style="padding:15px 16px 4px;display:flex;justify-content:space-between;align-items:flex-start">'+
        '<div><div style="font-weight:900;font-size:19px">Mesa '+esc(c.mesa_numero||'')+'</div>'+
          '<div style="font-size:12px;color:#888;margin-top:2px">👥 '+c.num_comensales+(c.mozo_nombre?(' · '+esc(c.mozo_nombre)):'')+'</div></div>'+
        '<div style="font-weight:900;font-size:21px">S/ '+Number(c.total).toFixed(0)+'</div></div>'+
      '<div style="padding:0 16px;font-size:11px;color:#888">⏱ Abierta '+mins+' min · '+rondas+' ronda'+(rondas===1?'':'s')+'</div>'+
      (resumen?('<div style="margin:9px 16px 0;padding-top:8px;border-top:1px solid #eee;font-size:12px;color:#555;line-height:1.5">'+esc(resumen)+'</div>'):'')+
      '<div style="padding:13px 16px">'+
        '<button class="btn" onclick="verCuentaDesdeInfo()">Ver / agregar</button>'+
        '<button class="btn dark" style="margin-top:8px" onclick="verCuentaDesdeInfo(); setTimeout(function(){ document.getElementById(\'btn-cobrar\').click(); }, 600)">Cobrar</button>'+
        '<button class="btn" style="background:#eee;color:#555;margin-top:8px" onclick="closeModal(\'m-mesa\')">Cerrar</button>'+
      '</div>';
    openModal('m-mesa');
  });
}
function verCuentaDesdeInfo(){ closeModal('m-mesa'); if(st.cuenta) loadCuenta(st.cuenta.id); }
function mesaNum(id){ var n='?'; st.pisos.forEach(function(p){ (p.mesas||[]).forEach(function(m){ if(m.id==id) n=m.numero; }); }); return n; }
function comStep(d){ st.comN=Math.max(0, st.comN+d); $('com-n').textContent=st.comN; }
function confirmAbrir(){ closeModal('m-com'); abrirYver(st.comMesa, st.comN); }
function abrirYver(mesaId, n){
  geo().then(function(){ post('abrir_cuenta', withGeo({mesa_id:mesaId, num_comensales:n})).then(function(d){
    if(!d.ok){ toast(d.error||'No se pudo'); return; }
    loadCuenta(d.cuenta_id);
  }); });
}

// ---- Cuenta ----
function loadCuenta(cid){ get('cuenta&cuenta_id='+cid).then(function(d){ if(!d.ok){toast('Error');return;} st.cuenta=d.cuenta; renderCuenta(); showView('v-cuenta'); }); }
function renderCuenta(){
  var c=st.cuenta; $('cta-mesa').textContent=c.mesa_numero||''; $('cta-com').textContent=c.num_comensales; $('cta-total').textContent='S/ '+c.total.toFixed(0); $('cat-mesa').textContent=c.mesa_numero||'';
  var b=$('cta-body'); b.innerHTML='';
  if(!c.comandas.length){ b.innerHTML='<p style="padding:24px;text-align:center;color:#888">Cuenta vacía. Agrega el primer pedido.</p>'; return; }
  c.comandas.forEach(function(co){
    var h=document.createElement('div');
    h.innerHTML='<div style="padding:7px 13px;font-size:9px;font-weight:800;color:#999;text-transform:uppercase;background:#efece4">Ronda '+co.ronda+' · '+esc(co.estado)+'</div>';
    b.appendChild(h);
    co.items.forEach(function(it, idx){
      var anul=!!it.anulado;
      var mods=(it.modificadores||[]).map(function(m){return m.nombre;}).join(' · ');
      var unit=(it.precio||0)+((it.modificadores||[]).reduce(function(s,m){return s+(m.precio||0);},0));
      var r=document.createElement('div'); r.className='row';
      r.innerHTML='<div class="'+(anul?'anul':'')+'">'+it.qty+'× '+esc(it.nombre)+(mods?'<br><small style="color:#999">'+esc(mods)+'</small>':'')+(it.nota?'<br><small style="color:#c98a00">Nota: '+esc(it.nota)+'</small>':'')+'</div>'+
        '<div style="text-align:right"><b class="'+(anul?'anul':'')+'">S/ '+(unit*it.qty).toFixed(0)+'</b>'+(anul?'':(co.estado==='pendiente'||co.estado==='en_preparacion'?'<br><span style="color:#dc2626;font-size:11px;font-weight:700">anular</span>':''))+'</div>';
      if(!anul && (co.estado==='pendiente'||co.estado==='en_preparacion')){ r.querySelector('span').onclick=function(){ openAnular(co.pedido_id, idx, it.qty+'× '+it.nombre); }; }
      b.appendChild(r);
    });
  });
}
function esc(s){ return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

// ---- Anular ----
function openAnular(pedidoId, idx, label){ st.anul={pedido_id:pedidoId, item_idx:idx}; $('anul-tit').textContent='Anular «'+label+'»';
  var box=$('anul-motivos'); box.innerHTML=''; ['El cliente lo rechazó','Error del mozo','Otro'].forEach(function(mo){ var b=document.createElement('button'); b.className='btn'; b.style.background='#FFBBC8'; b.style.color='#1E1E1E'; b.textContent=mo; b.onclick=function(){ doAnular(mo); }; box.appendChild(b); }); openModal('m-anul'); }
function doAnular(motivo){ closeModal('m-anul'); geo().then(function(){ post('anular', withGeo({cuenta_id:st.cuenta.id, pedido_id:st.anul.pedido_id, item_idx:st.anul.item_idx, motivo:motivo})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} loadCuenta(st.cuenta.id); }); }); }

// ---- Catálogo + borrador ----
function openCatalogo(){ st.borrador=[]; updBorr(); if(!st.catProd.length){ get('menu').then(function(d){ st.catProd=d.productos||[]; st.catCat=(d.categorias||[])[0]||null; drawCat(); showView('v-cat'); }); } else { drawCat(); showView('v-cat'); } }
function drawCat(){
  var cats=[]; st.catProd.forEach(function(p){ if(cats.indexOf(p.categoria)<0) cats.push(p.categoria); });
  var tabs=$('cat-tabs'); tabs.innerHTML='';
  cats.forEach(function(c){ var t=document.createElement('span'); t.className='chip'+(c===st.catCat?' on':''); t.textContent=c; t.onclick=function(){ st.catCat=c; drawCat(); }; tabs.appendChild(t); });
  var list=$('cat-list'); list.innerHTML='';
  st.catProd.filter(function(p){return p.categoria===st.catCat;}).forEach(function(p){
    var r=document.createElement('div'); r.className='row'; r.innerHTML='<div>'+esc(p.nombre)+(p.grupos&&p.grupos.length?'<br><small style="color:#999">toca para modificar</small>':'')+'</div><div style="display:flex;align-items:center;gap:9px"><b>S/ '+Number(p.precio).toFixed(0)+'</b><span class="plus">+</span></div>';
    r.onclick=function(){ openProd(p); };
    list.appendChild(r);
  });
}
function openProd(p){ st.prodSel={p:p, qty:1, sel:{}, nota:''}; renderProd(); openModal('m-prod'); }
function renderProd(){
  var s=st.prodSel, p=s.p;
  var html='<div style="padding:15px 16px 6px"><div style="font-weight:900;font-size:17px">'+esc(p.nombre)+'</div><div style="color:#888;font-size:12px">S/ '+Number(p.precio).toFixed(2)+'</div></div><div style="padding:6px 16px">';
  (p.grupos||[]).forEach(function(g){
    var multi=(g.tipo==='multiple');
    html+='<div style="font-size:10px;font-weight:800;color:#888;text-transform:uppercase;margin:8px 0 2px">'+esc(g.nombre)+'</div>';
    (g.opciones||[]).forEach(function(o){
      var on=(s.sel[g.id]&&s.sel[g.id][o.id]);
      html+='<div class="opt" data-g="'+g.id+'" data-o="'+o.id+'" data-multi="'+(multi?1:0)+'" data-precio="'+o.precio+'" data-nombre="'+esc(o.nombre)+'"><span class="mark '+(multi?'':'rad')+(on?' on':'')+'"></span> '+esc(o.nombre)+(parseFloat(o.precio)>0?'<span style="margin-left:auto;color:#888">+S/ '+Number(o.precio).toFixed(0)+'</span>':'')+'</div>';
    });
  });
  html+='<div style="font-size:10px;font-weight:800;color:#888;text-transform:uppercase;margin:8px 0 4px">Nota para cocina</div><input id="prod-nota" placeholder="Sin cebolla…" value="'+esc(s.nota)+'" style="width:100%;padding:9px 11px;border:1.5px solid #ddd;border-radius:8px;font-size:13px"></div>';
  // pie en 2 filas
  html+='<div style="border-top:1px solid #eee;padding:11px 16px 16px"><div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:11px"><span style="font-size:11px;font-weight:800;color:#888;text-transform:uppercase">Cantidad</span><div style="display:flex;align-items:center;gap:14px"><button class="qbtn" onclick="prodQty(-1)">−</button><b id="prod-qty" style="font-size:18px;min-width:24px;text-align:center">'+s.qty+'</b><button class="qbtn" onclick="prodQty(1)">+</button></div></div><button class="btn dark" onclick="addBorr()">Agregar · S/ <span id="prod-tot">'+prodTotal().toFixed(0)+'</span></button></div>';
  $('m-prod-in').innerHTML=html;
  $('m-prod-in').querySelectorAll('.opt').forEach(function(el){ el.onclick=function(){ toggleOpt(el); }; });
}
function toggleOpt(el){ var g=el.getAttribute('data-g'), o=el.getAttribute('data-o'), multi=el.getAttribute('data-multi')==='1'; var s=st.prodSel; s.sel[g]=s.sel[g]||{};
  if(multi){ if(s.sel[g][o]) delete s.sel[g][o]; else s.sel[g][o]={precio:parseFloat(el.getAttribute('data-precio')),nombre:el.getAttribute('data-nombre')}; }
  else { s.sel[g]={}; s.sel[g][o]={precio:parseFloat(el.getAttribute('data-precio')),nombre:el.getAttribute('data-nombre')}; }
  renderProd();
}
function prodQty(d){ st.prodSel.qty=Math.max(1, st.prodSel.qty+d); renderProd(); }
function prodTotal(){ var s=st.prodSel; var base=parseFloat(s.p.precio); var mods=0; Object.keys(s.sel).forEach(function(g){ Object.keys(s.sel[g]).forEach(function(o){ mods+=s.sel[g][o].precio; }); }); return (base+mods)*s.qty; }
function addBorr(){ var s=st.prodSel; var mods=[]; Object.keys(s.sel).forEach(function(g){ Object.keys(s.sel[g]).forEach(function(o){ mods.push({nombre:s.sel[g][o].nombre, precio:s.sel[g][o].precio}); }); });
  var nota=($('prod-nota')||{}).value||'';
  st.borrador.push({product_id:s.p.id, nombre:s.p.nombre, precio:parseFloat(s.p.precio), qty:s.qty, modificadores:mods, nota:nota});
  closeModal('m-prod'); updBorr();
}
function borrTotal(){ return st.borrador.reduce(function(s,it){ var m=it.modificadores.reduce(function(a,x){return a+x.precio;},0); return s+(it.precio+m)*it.qty; },0); }
function updBorr(){ var n=st.borrador.length;
  $('cat-foot').style.display=n?'block':'none';
  var b=$('cat-borr-btn'); if(b) b.textContent='🛒 Ver borrador · '+n+' ítem'+(n===1?'':'s')+' · S/ '+borrTotal().toFixed(0); }
function openBorrador(){
  if(!st.borrador.length){ toast('El borrador está vacío'); return; }
  var rows=st.borrador.map(function(it,i){
    var msum=it.modificadores.reduce(function(a,x){return a+x.precio;},0);
    var lt=(it.precio+msum)*it.qty;
    var mods=it.modificadores.map(function(m){return m.nombre;}).join(' · ');
    var minus = it.qty<=1 ? '🗑' : '−';
    return '<div style="padding:11px 14px;border-bottom:1px solid #e7e3da;background:#fff">'+
      '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px">'+
        '<div style="min-width:0"><b>'+esc(it.nombre)+'</b>'+(mods?'<br><small style="color:#999">'+esc(mods)+'</small>':'')+'</div>'+
        '<b style="white-space:nowrap">S/ '+lt.toFixed(0)+'</b>'+
      '</div>'+
      '<div style="display:flex;align-items:center;gap:12px;margin-top:9px">'+
        '<button class="qbtn'+(it.qty<=1?' danger':'')+'" onclick="borrQty('+i+',-1)">'+minus+'</button>'+
        '<b style="font-size:17px;min-width:22px;text-align:center">'+it.qty+'</b>'+
        '<button class="qbtn" onclick="borrQty('+i+',1)">+</button>'+
        '<input type="text" placeholder="Nota para cocina…" value="'+esc(it.nota||'')+'" oninput="borrNota('+i+',this.value)" style="flex:1;min-width:0;padding:7px 10px;border:1.5px solid #ddd;border-radius:8px;font-size:13px">'+
      '</div>'+
    '</div>';
  }).join('');
  $('m-borr-in').innerHTML=
    '<div style="padding:14px 16px 6px;font-weight:900;font-size:16px">Borrador · Mesa '+esc(st.cuenta.mesa_numero||'')+'</div>'+
    '<div style="max-height:50dvh;overflow:auto">'+rows+'</div>'+
    '<div style="padding:12px 16px;border-top:1px solid #eee">'+
      '<div style="display:flex;justify-content:space-between;font-weight:900;font-size:15px;margin-bottom:10px"><span>Total</span><span>S/ '+borrTotal().toFixed(0)+'</span></div>'+
      '<button class="btn dark" onclick="enviarComanda()">🍳 Enviar a cocina</button>'+
      '<button class="btn" style="background:#eee;color:#555;margin-top:8px" onclick="closeModal(\'m-borr\')">Seguir agregando</button>'+
    '</div>';
  openModal('m-borr');
}
function quitarBorr(i){ st.borrador.splice(i,1); updBorr(); if(st.borrador.length) openBorrador(); else closeModal('m-borr'); }
function borrQty(i,d){ var it=st.borrador[i]; if(!it)return; if(d<0 && it.qty<=1){ quitarBorr(i); return; } it.qty=Math.max(1,(it.qty||1)+d); updBorr(); openBorrador(); }
function borrNota(i,v){ if(st.borrador[i]) st.borrador[i].nota=v; }
function enviarComanda(){ if(!st.borrador.length)return; geo().then(function(){ post('enviar_comanda', withGeo({cuenta_id:st.cuenta.id, items:JSON.stringify(st.borrador)})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} st.borrador=[]; closeModal('m-borr'); toast('Enviado a cocina · Ronda '+d.ronda); loadCuenta(st.cuenta.id); }); }); }

function goPlano(){ showView('v-plano'); refreshEstados(); }

// ============================================================
// PRECUENTA
// ============================================================
document.getElementById('btn-precuenta').addEventListener('click', function() {
  if (!st.cuenta) return;
  post('precuenta', {cuenta_id: st.cuenta.id}).then(function(d) {
    if (!d.ok) { toast(d.error || 'No se pudo generar la precuenta'); return; }
    window.location.href = 'rawbt:base64,' + d.b64;
    // Refrescar el plano (la mesa pasará a estado "precuenta" = rosa)
    setTimeout(refreshEstados, 1200);
  }).catch(function() { toast('Error de red'); });
});

document.getElementById('btn-cobrar').addEventListener('click', function() {
  if (!st.cuenta) return;
  // Refrescar la cuenta desde el servidor para obtener monto_cobrar/pagado/falta actualizados
  get('cuenta&cuenta_id=' + st.cuenta.id).then(function(d) {
    if (!d.ok) { toast('Error al cargar cuenta'); return; }
    st.cuenta = d.cuenta;
    openCobro();
  });
});

// ============================================================
// COBRO
// ============================================================
var cobro = { modo: 'todo', metodos: [], partes: [], descTipo: null, descValor: 0, nIguales: 2, itemsAsign: {} };
var _cobMetCargados = false;

function repartoCentavos(total, n) {
  n = Math.max(1, n | 0);
  var cent = Math.round(total * 100), base = Math.floor(cent / n), resto = cent - base * n, out = [];
  for (var i = 0; i < n; i++) out.push(Math.round((base + (i === n - 1 ? resto : 0)) / 100 * 100) / 100);
  return out;
}

function openCobro() {
  cobro.modo = 'todo'; cobro.descTipo = null; cobro.descValor = 0; cobro.nIguales = 2; cobro.itemsAsign = {};
  // Seleccionar botón de modo "todo"
  document.querySelectorAll('#cobro-modo button').forEach(function(b) { b.classList.toggle('on', b.getAttribute('data-modo') === 'todo'); });
  pintarResumen();
  function doOpen() { setModo('todo'); openModal('m-cobro'); }
  if (_cobMetCargados) { doOpen(); return; }
  get('metodos').then(function(d) {
    cobro.metodos = d.metodos || [];
    _cobMetCargados = true;
    doOpen();
  });
}

function pintarResumen() {
  var c = st.cuenta;
  var falta = c.falta != null ? c.falta : c.monto_cobrar;
  var html = '<div class="cr-row"><span>Total cuenta</span><span class="cr-total">S/ ' + Number(c.monto_cobrar).toFixed(2) + '</span></div>';
  if (c.pagado > 0) html += '<div class="cr-row"><span class="cr-pagado">Ya pagado</span><span class="cr-pagado">S/ ' + Number(c.pagado).toFixed(2) + '</span></div>';
  html += '<div class="cr-row"><b>Por cobrar</b><b class="cr-falta">S/ ' + Number(falta).toFixed(2) + '</b></div>';
  $('cobro-resumen').innerHTML = html;
}

function faltaActual() {
  var c = st.cuenta;
  return c.falta != null ? Number(c.falta) : Number(c.monto_cobrar);
}

function setModo(modo) {
  cobro.modo = modo;
  document.querySelectorAll('#cobro-modo button').forEach(function(b) { b.classList.toggle('on', b.getAttribute('data-modo') === modo); });
  var cfg = $('cobro-config'); var partes = $('cobro-partes');
  var falta = faltaActual();

  if (modo === 'todo') {
    cfg.innerHTML = renderDescuento();
    bindDescuento();
    cobro.partes = [{ monto: falta, pagos: [{ metodo: cobro.metodos[0] ? cobro.metodos[0].nombre : '', monto: falta }], comp: null }];
    partes.innerHTML = renderPartes();
    bindPartes();
  } else if (modo === 'iguales') {
    cfg.innerHTML = '<div class="cobro-config">' + renderDescuento() + '<label style="margin-top:10px">Número de partes iguales</label>' +
      '<div style="display:flex;align-items:center;gap:14px;margin-top:4px">' +
      '<button class="qbtn" type="button" onclick="cambiarNIguales(-1)">−</button>' +
      '<b id="ig-n" style="font-size:22px;min-width:26px;text-align:center">' + cobro.nIguales + '</b>' +
      '<button class="qbtn" type="button" onclick="cambiarNIguales(1)">+</button></div></div>';
    bindDescuento();
    buildPartesIguales();
    partes.innerHTML = renderPartes();
    bindPartes();
  } else if (modo === 'items') {
    cfg.innerHTML = ''; // sin descuento en modo ítems
    buildPartesItems(1);
    partes.innerHTML = renderPartes() + renderItemsGrid();
    bindPartes(); bindItemsGrid();
  } else if (modo === 'montos') {
    cfg.innerHTML = '<div class="cobro-config">' + renderDescuento() + '<div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between"><span style="font-size:12px;font-weight:800;color:var(--muted)">Partes</span>' +
      '<button type="button" class="btn-addpago" style="width:auto;padding:6px 14px" onclick="addParteMontos()">+ Parte</button></div></div>';
    bindDescuento();
    cobro.partes = [{ monto: falta, pagos: [{ metodo: cobro.metodos[0] ? cobro.metodos[0].nombre : '', monto: falta }], comp: null }];
    partes.innerHTML = renderPartes();
    bindPartes();
  }
  actualizarSaldo();
}

function buildPartesIguales() {
  var falta = faltaActual();
  var montos = repartoCentavos(falta, cobro.nIguales);
  cobro.partes = montos.map(function(m) {
    return { monto: m, pagos: [{ metodo: cobro.metodos[0] ? cobro.metodos[0].nombre : '', monto: m }], comp: null };
  });
}

function buildPartesItems(n) {
  // Construye n partes vacías con pagos vacíos; los ítems se asignan por el grid
  cobro.partes = [];
  for (var i = 0; i < n; i++) cobro.partes.push({ monto: 0, pagos: [], comp: null });
  cobro.itemsAsign = {};
}

function cambiarNIguales(d) {
  cobro.nIguales = Math.max(1, cobro.nIguales + d);
  var el = $('ig-n'); if (el) el.textContent = cobro.nIguales;
  buildPartesIguales();
  $('cobro-partes').innerHTML = renderPartes();
  bindPartes();
  actualizarSaldo();
}

function addParteMontos() {
  cobro.partes.push({ monto: 0, pagos: [], comp: null });
  $('cobro-partes').innerHTML = renderPartes();
  bindPartes();
  actualizarSaldo();
}

// ---- Render de partes ----
function renderPartes() {
  if (!cobro.partes.length) return '';
  return cobro.partes.map(function(pt, pi) {
    var html = '<div class="parte" data-pi="' + pi + '">';
    html += '<div class="parte-head"><span>Parte ' + (pi + 1) + (cobro.partes.length > 1 ? ' · S/ ' + Number(pt.monto).toFixed(2) : '') + '</span>';
    if (cobro.modo === 'montos' && cobro.partes.length > 1) html += '<button type="button" style="background:none;border:none;color:var(--danger);font-size:12px;font-weight:800;cursor:pointer" onclick="eliminarParte(' + pi + ')">Quitar</button>';
    html += '</div>';
    html += '<div class="parte-body">';
    // Monto editable en modo montos
    if (cobro.modo === 'montos') {
      html += '<div><label style="font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase">Monto</label>' +
        '<input type="number" inputmode="decimal" min="0" step="0.01" class="pago-monto-parte" data-pi="' + pi + '" value="' + pt.monto.toFixed(2) + '" style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-size:15px;font-weight:800;text-align:right;background:var(--surface);margin-top:3px"></div>';
    }
    // Líneas de pago
    pt.pagos.forEach(function(pg, gi) {
      html += '<div class="pago-row" data-pi="' + pi + '" data-gi="' + gi + '">' +
        '<select class="sel-met" data-pi="' + pi + '" data-gi="' + gi + '">' +
        cobro.metodos.map(function(m) { return '<option value="' + esc(m.nombre) + '"' + (m.nombre === pg.metodo ? ' selected' : '') + '>' + esc(m.nombre) + '</option>'; }).join('') +
        (cobro.metodos.length === 0 ? '<option value="Efectivo" selected>Efectivo</option>' : '') +
        '</select>' +
        '<input type="number" inputmode="decimal" min="0" step="0.01" class="inp-monto" data-pi="' + pi + '" data-gi="' + gi + '" value="' + Number(pg.monto).toFixed(2) + '">' +
        (pt.pagos.length > 1 ? '<button type="button" class="rm" data-pi="' + pi + '" data-gi="' + gi + '" onclick="quitarPago(' + pi + ',' + gi + ')">✕</button>' : '') +
      '</div>';
    });
    html += '<button type="button" class="btn-addpago" onclick="addPago(' + pi + ')">+ Medio de pago</button>';
    // Comprobante
    html += '<div class="comp-toggle" onclick="toggleComp(' + pi + ')">' +
      '<span class="mark rad" id="cmark-' + pi + '"></span>' +
      '<span>¿Emitir comprobante?</span></div>';
    html += '<div class="comp-fields" id="cfields-' + pi + '">' +
      '<label>Tipo</label><select class="comp-tipo" data-pi="' + pi + '">' +
      ['ticket','boleta','factura'].map(function(t) { return '<option value="' + t + '"' + ((pt.comp && pt.comp.tipo === t) ? ' selected' : '') + '>' + t.charAt(0).toUpperCase() + t.slice(1) + '</option>'; }).join('') +
      '</select>' +
      '<label>Nombre / Razón social</label><input type="text" class="comp-nombre" data-pi="' + pi + '" placeholder="Nombre del cliente" value="' + esc((pt.comp && pt.comp.cliente_nombre) || '') + '">' +
      '<label>DNI / RUC</label><input type="text" inputmode="numeric" maxlength="11" class="comp-doc" data-pi="' + pi + '" placeholder="00000000" value="' + esc((pt.comp && pt.comp.cliente_documento) || '') + '">' +
      '<label>Correo (opcional)</label><input type="email" class="comp-email" data-pi="' + pi + '" placeholder="correo@ejemplo.com" value="' + esc((pt.comp && pt.comp.cliente_email) || '') + '">' +
      '<div style="font-size:10px;color:var(--muted);margin-top:2px">Autocompletado DNI/RUC no disponible en la app del mozo — ingresá los datos manualmente.</div>' +
    '</div>';
    html += '</div></div>';
    return html;
  }).join('');
}

// ---- Items grid (modo items) ----
function allItemsNoAnulados() {
  var items = [];
  (st.cuenta.comandas || []).forEach(function(co) {
    (co.items || []).forEach(function(it, idx) {
      if (!it.anulado) items.push({ key: co.pedido_id + ':' + idx, nombre: it.nombre, qty: it.qty, precio: it.precio, mods: it.modificadores || [] });
    });
  });
  return items;
}

function renderItemsGrid() {
  var items = allItemsNoAnulados();
  if (!items.length) return '';
  var nPartes = cobro.partes.length;
  var html = '<div style="margin-top:10px;font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Asignar ítems</div>' +
    '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px"><span style="font-size:12px;color:var(--muted)">Partes: ' + nPartes + '</span>' +
    '<button type="button" class="btn-addpago" style="width:auto;padding:5px 12px;font-size:12px" onclick="addParteItems()">+ Parte</button></div>';
  html += '<div class="items-grid">';
  items.forEach(function(it) {
    var msum = it.mods.reduce(function(s, m) { return s + (m.precio || 0); }, 0);
    var lt = (it.precio + msum) * it.qty;
    var cur = cobro.itemsAsign[it.key] != null ? cobro.itemsAsign[it.key] : 0;
    html += '<div class="ig-row">' +
      '<div class="ig-assign">' + esc(it.qty + '× ' + it.nombre) + '</div>' +
      '<div class="ig-monto">S/ ' + lt.toFixed(0) + '</div>' +
      '<div class="ig-sel"><select data-ikey="' + esc(it.key) + '">' +
      '<option value="0">—</option>' +
      cobro.partes.map(function(_, pi) { return '<option value="' + (pi + 1) + '"' + (cur === pi + 1 ? ' selected' : '') + '>P' + (pi + 1) + '</option>'; }).join('') +
      '</select></div></div>';
  });
  html += '</div>';
  return html;
}

function addParteItems() {
  cobro.partes.push({ monto: 0, pagos: [], comp: null });
  $('cobro-partes').innerHTML = renderPartes() + renderItemsGrid();
  bindPartes(); bindItemsGrid();
  actualizarSaldo();
}

function bindItemsGrid() {
  document.querySelectorAll('#cobro-partes .ig-sel select').forEach(function(sel) {
    sel.onchange = function() {
      var k = this.getAttribute('data-ikey');
      cobro.itemsAsign[k] = parseInt(this.value, 10) || 0;
      recalcItemsPartes();
    };
  });
}

function recalcItemsPartes() {
  var items = allItemsNoAnulados();
  cobro.partes.forEach(function(pt, pi) { pt.monto = 0; pt.pagos = []; });
  items.forEach(function(it) {
    var pn = cobro.itemsAsign[it.key];
    if (!pn) return;
    var pi = pn - 1;
    if (!cobro.partes[pi]) return;
    var msum = it.mods.reduce(function(s, m) { return s + (m.precio || 0); }, 0);
    cobro.partes[pi].monto = Math.round((cobro.partes[pi].monto + (it.precio + msum) * it.qty) * 100) / 100;
  });
  cobro.partes.forEach(function(pt) {
    if (pt.monto > 0 && !pt.pagos.length) pt.pagos = [{ metodo: cobro.metodos[0] ? cobro.metodos[0].nombre : 'Efectivo', monto: pt.monto }];
    else if (pt.monto > 0) pt.pagos[0].monto = pt.monto;
  });
  // Re-render solo las partes (no el grid de ítems para no perder foco)
  var partesDiv = $('cobro-partes');
  var grid = partesDiv.querySelector('.items-grid') ? partesDiv.innerHTML.slice(partesDiv.innerHTML.indexOf('<div style="margin-top:10px')) : '';
  partesDiv.innerHTML = renderPartes() + renderItemsGrid();
  bindPartes(); bindItemsGrid();
  actualizarSaldo();
}

// ---- Descuento ----
function renderDescuento() {
  return '<div style="margin-top:4px"><div style="font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Descuento (opcional)</div>' +
    '<div class="desc-row"><div class="desc-tipo">' +
    '<button type="button" data-dt="porcentaje" class="' + (cobro.descTipo === 'porcentaje' ? 'on' : '') + '" onclick="setDescTipo(\'porcentaje\')">%</button>' +
    '<button type="button" data-dt="monto" class="' + (cobro.descTipo === 'monto' ? 'on' : '') + '" onclick="setDescTipo(\'monto\')">S/</button>' +
    '<button type="button" data-dt="" class="' + (!cobro.descTipo ? 'on' : '') + '" onclick="setDescTipo(\'\')">Sin desc.</button>' +
    '</div>' +
    '<input type="number" inputmode="decimal" min="0" id="inp-desc" placeholder="0" value="' + (cobro.descTipo ? cobro.descValor : '') + '" ' + (cobro.descTipo ? '' : 'disabled') + ' style="width:80px;flex:none;padding:9px 10px;border:1.5px solid var(--line);border-radius:9px;font-size:14px;font-weight:800;text-align:right;background:var(--surface)">' +
    '</div></div>';
}

function setDescTipo(dt) {
  cobro.descTipo = dt || null; cobro.descValor = 0;
  // Re-render solo el bloque de config (preserva modo)
  $('cobro-config').innerHTML = cobro.modo === 'iguales'
    ? renderDescuento() + '<label style="margin-top:10px">Número de partes iguales</label>' +
      '<div style="display:flex;align-items:center;gap:14px;margin-top:4px"><button class="qbtn" type="button" onclick="cambiarNIguales(-1)">−</button><b id="ig-n" style="font-size:22px;min-width:26px;text-align:center">' + cobro.nIguales + '</b><button class="qbtn" type="button" onclick="cambiarNIguales(1)">+</button></div>'
    : renderDescuento() + (cobro.modo === 'montos' ? '<div style="margin-top:10px;display:flex;align-items:center;justify-content:space-between"><span style="font-size:12px;font-weight:800;color:var(--muted)">Partes</span><button type="button" class="btn-addpago" style="width:auto;padding:6px 14px" onclick="addParteMontos()">+ Parte</button></div>' : '');
  bindDescuento();
}

function bindDescuento() {
  var inp = $('inp-desc'); if (!inp) return;
  inp.oninput = function() { cobro.descValor = parseFloat(this.value) || 0; actualizarSaldo(); };
}

// ---- Bind de partes ----
function bindPartes() {
  // Métodos de pago
  document.querySelectorAll('#cobro-partes .sel-met').forEach(function(sel) {
    sel.onchange = function() {
      var pi = +this.getAttribute('data-pi'), gi = +this.getAttribute('data-gi');
      cobro.partes[pi].pagos[gi].metodo = this.value;
    };
  });
  // Montos de pago
  document.querySelectorAll('#cobro-partes .inp-monto').forEach(function(inp) {
    inp.oninput = function() {
      var pi = +this.getAttribute('data-pi'), gi = +this.getAttribute('data-gi');
      cobro.partes[pi].pagos[gi].monto = parseFloat(this.value) || 0;
      actualizarSaldo();
    };
  });
  // Monto de parte (modo montos)
  document.querySelectorAll('#cobro-partes .pago-monto-parte').forEach(function(inp) {
    inp.oninput = function() {
      var pi = +this.getAttribute('data-pi');
      cobro.partes[pi].monto = parseFloat(this.value) || 0;
      // Actualizar el único pago de la parte para que coincida
      if (cobro.partes[pi].pagos.length === 1) cobro.partes[pi].pagos[0].monto = cobro.partes[pi].monto;
      actualizarSaldo();
    };
  });
  // Comprobante toggle: ya tiene onclick="toggleComp(N)" inline en el HTML generado.
  // Comprobante fields
  document.querySelectorAll('#cobro-partes .comp-tipo').forEach(function(sel) {
    sel.onchange = function() {
      var pi = +this.getAttribute('data-pi');
      if (cobro.partes[pi].comp) cobro.partes[pi].comp.tipo = this.value;
    };
  });
  document.querySelectorAll('#cobro-partes .comp-nombre').forEach(function(inp) {
    inp.oninput = function() {
      var pi = +this.getAttribute('data-pi');
      if (cobro.partes[pi].comp) cobro.partes[pi].comp.cliente_nombre = this.value;
    };
  });
  document.querySelectorAll('#cobro-partes .comp-doc').forEach(function(inp) {
    inp.oninput = function() {
      var pi = +this.getAttribute('data-pi');
      if (cobro.partes[pi].comp) cobro.partes[pi].comp.cliente_documento = this.value;
    };
  });
  document.querySelectorAll('#cobro-partes .comp-email').forEach(function(inp) {
    inp.oninput = function() {
      var pi = +this.getAttribute('data-pi');
      if (cobro.partes[pi].comp) cobro.partes[pi].comp.cliente_email = this.value;
    };
  });
}

function toggleComp(pi) {
  var mark = $('cmark-' + pi); var fields = $('cfields-' + pi);
  if (!mark || !fields) return;
  var on = !mark.classList.contains('on');
  mark.classList.toggle('on', on);
  fields.classList.toggle('on', on);
  if (on) {
    cobro.partes[pi].comp = cobro.partes[pi].comp || { tipo: 'ticket', cliente_nombre: '', cliente_documento: '', cliente_email: '' };
  } else {
    cobro.partes[pi].comp = null;
  }
}

function addPago(pi) {
  cobro.partes[pi].pagos.push({ metodo: cobro.metodos[0] ? cobro.metodos[0].nombre : 'Efectivo', monto: 0 });
  $('cobro-partes').innerHTML = cobro.modo === 'items' ? renderPartes() + renderItemsGrid() : renderPartes();
  bindPartes(); if (cobro.modo === 'items') bindItemsGrid();
  actualizarSaldo();
}

function quitarPago(pi, gi) {
  cobro.partes[pi].pagos.splice(gi, 1);
  $('cobro-partes').innerHTML = cobro.modo === 'items' ? renderPartes() + renderItemsGrid() : renderPartes();
  bindPartes(); if (cobro.modo === 'items') bindItemsGrid();
  actualizarSaldo();
}

function eliminarParte(pi) {
  cobro.partes.splice(pi, 1);
  $('cobro-partes').innerHTML = renderPartes();
  bindPartes();
  actualizarSaldo();
}

// ---- Saldo / validación visual ----
function calcDescMonto() {
  if (!cobro.descTipo) return 0;
  var v = cobro.descValor;
  if (cobro.descTipo === 'porcentaje') return Number(st.cuenta.monto_cobrar) * Math.min(100, Math.max(0, v)) / 100;
  return Math.min(Number(st.cuenta.monto_cobrar), Math.max(0, v));
}

function actualizarSaldo() {
  var faltaBase = faltaActual();
  var desc = 0;
  if (cobro.modo !== 'items' && st.cuenta.pagado <= 0) desc = calcDescMonto();
  var objetivo = Math.round((faltaBase - desc) * 100) / 100;
  var sumPagos = 0;
  cobro.partes.forEach(function(pt) {
    pt.pagos.forEach(function(pg) { sumPagos += pg.monto || 0; });
  });
  sumPagos = Math.round(sumPagos * 100) / 100;
  var saldoEl = $('cobro-saldo');
  var diff = Math.round((sumPagos - objetivo) * 100) / 100;
  if (Math.abs(diff) < 0.02) {
    saldoEl.textContent = 'Cobro cuadrado · S/ ' + sumPagos.toFixed(2);
    saldoEl.className = 'cobro-saldo ok';
  } else if (diff > 0) {
    saldoEl.textContent = 'Excede en S/ ' + diff.toFixed(2);
    saldoEl.className = 'cobro-saldo err';
  } else {
    saldoEl.textContent = 'Faltan S/ ' + Math.abs(diff).toFixed(2) + ' por asignar';
    saldoEl.className = 'cobro-saldo err';
  }
}

// ---- Confirmar cobro ----
var _cobTurnoId = null;

function confirmarCobro(turnoId) {
  if (!st.cuenta) return;
  // Leer datos de comprobante del DOM (por si el usuario escribió y no disparó oninput)
  cobro.partes.forEach(function(pt, pi) {
    var nm = document.querySelector('.comp-nombre[data-pi="' + pi + '"]');
    var dc = document.querySelector('.comp-doc[data-pi="' + pi + '"]');
    var em = document.querySelector('.comp-email[data-pi="' + pi + '"]');
    var tp = document.querySelector('.comp-tipo[data-pi="' + pi + '"]');
    if (pt.comp) {
      if (nm) pt.comp.cliente_nombre = nm.value;
      if (dc) pt.comp.cliente_documento = dc.value;
      if (em) pt.comp.cliente_email = em.value;
      if (tp) pt.comp.tipo = tp.value;
    }
  });

  var faltaBase = faltaActual();
  var desc = (cobro.modo !== 'items' && st.cuenta.pagado <= 0) ? calcDescMonto() : 0;
  var objetivo = Math.round((faltaBase - desc) * 100) / 100;
  var sumPagos = 0;
  cobro.partes.forEach(function(pt) { pt.pagos.forEach(function(pg) { sumPagos += pg.monto || 0; }); });
  sumPagos = Math.round(sumPagos * 100) / 100;
  if (Math.abs(sumPagos - objetivo) > 0.02) { toast('Los pagos no cuadran con el total a cobrar'); return; }
  if (cobro.modo === 'items') {
    var items = allItemsNoAnulados();
    var sinAsignar = items.filter(function(it) { return !cobro.itemsAsign[it.key]; });
    if (sinAsignar.length && !confirm('Hay ' + sinAsignar.length + ' ítem(s) sin asignar a ninguna parte. ¿Continuar?')) return;
  }

  var payload = {
    modo: cobro.modo,
    descuento: cobro.descTipo ? { tipo: cobro.descTipo, valor: cobro.descValor } : null,
    partes: cobro.partes.map(function(pt) {
      var p = { pagos: pt.pagos.map(function(pg) { return { metodo: pg.metodo, monto: pg.monto }; }) };
      if (cobro.modo === 'items') {
        p.item_keys = Object.keys(cobro.itemsAsign).filter(function(k) { var n = cobro.itemsAsign[k]; return n && cobro.partes.indexOf(pt) === n - 1; });
      } else {
        p.monto = pt.monto;
      }
      if (pt.comp) p.comprobante = pt.comp;
      return p;
    })
  };
  if (turnoId) payload.turno_id = turnoId;

  var btn = $('cobro-confirmar'); btn.disabled = true; btn.textContent = 'Procesando…';

  geo().then(function() {
    var fd = new FormData(); fd.append('action', 'cobrar'); fd.append('cuenta_id', st.cuenta.id); fd.append('payload', JSON.stringify(payload));
    if (st.lastGeo) { fd.append('lat', st.lastGeo.lat); fd.append('lng', st.lastGeo.lng); }
    return fetch(API + '?action=cobrar', { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd, credentials: 'same-origin' }).then(function(r) { return r.json(); });
  }).then(function(d) {
    btn.disabled = false; btn.textContent = 'Confirmar cobro';
    if (d.sin_caja) {
      toast('No hay caja abierta en el local');
      return;
    }
    if (d.multi_caja) {
      // Mostrar selector de caja
      var list = $('m-turno-list'); list.innerHTML = '';
      (d.turnos || []).forEach(function(t) {
        var b = document.createElement('button'); b.className = 'btn'; b.style.background = '#fff'; b.style.border = '1.5px solid var(--line)';
        b.textContent = 'Caja abierta ' + (t.usuario || '') + ' · ' + (t.abierto_en || '');
        b.onclick = function() { closeModal('m-turno'); confirmarCobro(t.id); };
        list.appendChild(b);
      });
      openModal('m-turno');
      return;
    }
    if (!d.ok) { toast(d.error || 'No se pudo cobrar'); return; }
    // Avisar si hay comprobantes con error
    var pendientes = (d.comprobantes || []).filter(function(c) { return c.estado === 'error' || c.estado === 'pendiente'; });
    if (pendientes.length) toast('Cobrado · ' + pendientes.length + ' comprobante(s) pendiente(s) de emisión');
    if (d.cerrada) {
      closeModal('m-cobro');
      refreshEstados();
      showView('v-plano');
      setTimeout(function() { toast('Mesa cobrada'); }, 300);
    } else {
      // Pago parcial: refrescar cuenta y quedar en cobro
      get('cuenta&cuenta_id=' + st.cuenta.id).then(function(dcu) {
        if (dcu.ok) st.cuenta = dcu.cuenta;
        pintarResumen();
        toast('Pago parcial registrado · falta S/ ' + (d.falta || 0).toFixed(2));
        actualizarSaldo();
      });
    }
  }).catch(function() {
    btn.disabled = false; btn.textContent = 'Confirmar cobro';
    toast('Error de red');
  });
}

// arranque: ¿ya hay sesión?
// Botón atrás del navegador → retrocede dentro de la app (cierra modal / vuelve de vista).
window.addEventListener('popstate', function(){
  var m=document.querySelector('.modal.on');
  if(m){ m.classList.remove('on'); navPush(); return; }                              // cerrar modal abierto
  if($('v-cat').classList.contains('on')){ showView('v-cuenta'); navPush(); return; } // catálogo → cuenta
  if($('v-cuenta').classList.contains('on')){ goPlano(); return; }                     // cuenta → plano (home)
  // en plano / PIN: dejar que salga
});
// Cerrar cualquier modal tocando fuera de la hoja.
document.querySelectorAll('.modal').forEach(function(m){ m.addEventListener('click', function(e){ if(e.target===m) closeModal(m.id); }); });

get('sesion').then(function(d){ if(d.ok&&d.mozo){ st.emp=d.mozo.emp; $('plano-mozo').textContent=d.mozo.nombre+' 👤'; geo(); enterApp(); } else { $('pin-ubi').textContent=''; loadMozos(); } });
</script>
<?php endif; ?>
</body>
</html>
