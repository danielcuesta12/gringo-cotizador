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
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f5f2ec;color:#1E1E1E;height:100dvh;overflow:hidden}
.view{display:none;flex-direction:column;height:100dvh}
.view.on{display:flex}
.top{background:#1E1E1E;color:#fff;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;font-weight:800}
.top .y{color:#FFDF00}
.top button{background:rgba(255,255,255,.14);border:none;color:#fff;border-radius:8px;padding:7px 10px;font-weight:800;font-size:13px}
.body{flex:1;overflow:auto;-webkit-overflow-scrolling:touch}
.foot{background:#fff;border-top:1px solid #e7e3da;padding:11px 13px}
.btn{display:block;width:100%;text-align:center;background:#FFDF00;color:#1E1E1E;font-weight:900;border:none;border-radius:12px;padding:14px;font-size:15px}
.btn.dark{background:#1E1E1E;color:#FFDF00}
.btn.red{background:#dc2626;color:#fff}
.key{background:#fff;border:none;border-radius:12px;padding:16px 0;font-size:22px;font-weight:800}
.pindots{display:flex;gap:11px;justify-content:center;margin:14px 0}
.pindots span{width:14px;height:14px;border-radius:50%;border:2px solid #ccc}
.pindots span.on{background:#1E1E1E;border-color:#1E1E1E}
.row{display:flex;justify-content:space-between;align-items:center;padding:11px 13px;border-bottom:1px solid #e7e3da;background:#fff}
.tag{font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px;background:#efece4;color:#777}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:flex-end;z-index:50}
.modal.on{display:flex}
.sheet{background:#fff;border-radius:18px 18px 0 0;width:100%;max-height:92dvh;overflow:auto;padding-bottom:env(safe-area-inset-bottom)}
.opt{display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:14px}
.mark{width:20px;height:20px;border-radius:6px;border:2px solid #ccc;flex-shrink:0}
.mark.on{background:#1E1E1E;border-color:#1E1E1E}
.mark.rad{border-radius:50%}.mark.rad.on{background:#FFDF00;border-color:#FFDF00;box-shadow:inset 0 0 0 4px #fff}
.chip{display:inline-block;font-size:12px;font-weight:800;padding:7px 12px;border-radius:9px;background:#eee;color:#555;white-space:nowrap}
.chip.on{background:#1E1E1E;color:#FFDF00}
.anul{text-decoration:line-through;color:#bbb}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#1E1E1E;color:#fff;padding:10px 16px;border-radius:10px;font-weight:700;font-size:13px;z-index:80;display:none}
</style>
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
    <div style="text-align:center;font-size:10px;color:#999;margin-top:8px;font-weight:700">Precuenta y cobro → Sub-build C</div>
  </div>
</div>

<!-- CATÁLOGO (modal de pantalla completa) -->
<div class="view" id="v-cat">
  <div class="top"><button onclick="showView('v-cuenta')">‹</button><span>Agregar a Mesa <span class="y" id="cat-mesa"></span></span><span></span></div>
  <div id="cat-tabs" style="display:flex;gap:6px;padding:8px 10px;overflow:auto;background:#efece4"></div>
  <div class="body" id="cat-list"></div>
  <div class="foot" id="cat-foot" style="background:#FFEFBC;border-top-color:#e7d99a;display:none">
    <div style="font-size:11px;font-weight:800;color:#8a6d00;margin-bottom:7px" id="cat-borr"></div>
    <button class="btn dark" onclick="enviarComanda()">🍳 Enviar a cocina</button>
  </div>
</div>

<!-- modal producto -->
<div class="modal" id="m-prod"><div class="sheet" id="m-prod-in"></div></div>
<!-- modal comensales -->
<div class="modal" id="m-com"><div class="sheet" style="padding:18px">
  <div style="font-weight:900;font-size:16px;margin-bottom:4px">Abrir Mesa <span id="com-mesa"></span></div>
  <div style="font-size:12px;color:#888;margin-bottom:12px">¿Cuántos comensales? (opcional)</div>
  <div style="display:flex;align-items:center;gap:14px;justify-content:center;margin-bottom:14px">
    <button class="key" style="width:46px" onclick="comStep(-1)">−</button><b id="com-n" style="font-size:22px">2</b><button class="key" style="width:46px" onclick="comStep(1)">+</button>
  </div>
  <button class="btn" onclick="confirmAbrir()">Abrir cuenta</button>
</div></div>
<!-- modal anular -->
<div class="modal" id="m-anul"><div class="sheet" style="padding:18px">
  <div style="font-weight:900;font-size:15px;margin-bottom:10px" id="anul-tit">Anular</div>
  <div id="anul-motivos" style="display:flex;flex-direction:column;gap:7px;margin-bottom:12px"></div>
  <button class="btn" style="background:#eee;color:#555" onclick="closeModal('m-anul')">Cancelar</button>
</div></div>

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
function enterApp(){ loadPlano(); showView('v-plano'); pollEstados(); }
function loadPlano(){ get('plano').then(function(d){ st.pisos=d.pisos||[]; st.pi=0; drawPlano(); }); }
function drawPlano(){
  var tabs=$('plano-tabs'); tabs.innerHTML='';
  st.pisos.forEach(function(p,i){ var t=document.createElement('span'); t.className='chip'+(i===st.pi?' on':''); t.textContent=p.nombre; t.onclick=function(){ st.pi=i; drawPlano(); }; tabs.appendChild(t); });
  var piso=st.pisos[st.pi]; if(!piso){ $('plano-board').innerHTML='<p style="padding:24px;text-align:center;color:#888">Este local no tiene plano. Pídele al admin que lo arme.</p>'; return; }
  $('plano-piso').textContent=piso.nombre;
  refreshEstados();
}
var EST={estados:{},montos:{}};
function refreshEstados(){
  var piso=st.pisos[st.pi]; if(!piso)return;
  PlanoRender.draw($('plano-board'), piso, {uploadUrl:UPLOAD, estados:EST.estados, montos:EST.montos, onMesaTap:onMesaTap});
}
function pollEstados(){
  get('plano_estados').then(function(d){
    if(d.ok){ EST={estados:d.estados||{},montos:d.montos||{}}; if($('v-plano').classList.contains('on')) refreshEstados(); }
  }, function(){ /* error de red: ignorar, igual reprogramamos */ })
  .then(function(){ setTimeout(pollEstados, 5000); });
}

function onMesaTap(mesaId){
  // ¿ocupada? abre su cuenta : pide comensales
  if(EST.estados[mesaId]==='ocupada'){ abrirYver(mesaId, 0); }
  else { st.comMesa=mesaId; st.comN=2; $('com-mesa').textContent=mesaNum(mesaId); $('com-n').textContent='2'; openModal('m-com'); }
}
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
    var r=document.createElement('div'); r.className='row'; r.innerHTML='<div>'+esc(p.nombre)+(p.grupos&&p.grupos.length?'<br><small style="color:#999">toca para modificar</small>':'')+'</div><div style="display:flex;align-items:center;gap:9px"><b>S/ '+Number(p.precio).toFixed(0)+'</b><span style="width:26px;height:26px;border-radius:50%;background:#FFDF00;color:#1E1E1E;font-weight:900;display:flex;align-items:center;justify-content:center">+</span></div>';
    r.onclick=function(){ openProd(p); };
    list.appendChild(r);
  });
}
function openProd(p){ st.prodSel={p:p, qty:1, sel:{}, nota:''}; renderProd(); openModal('m-prod'); }
function renderProd(){
  var s=st.prodSel, p=s.p;
  var html='<div style="padding:15px 16px 6px"><div style="font-weight:900;font-size:17px">'+esc(p.nombre)+'</div><div style="color:#888;font-size:12px">S/ '+Number(p.precio).toFixed(2)+'</div></div><div style="padding:6px 16px">';
  (p.grupos||[]).forEach(function(g){
    var multi=(parseInt(g.max_select||1)!==1);
    html+='<div style="font-size:10px;font-weight:800;color:#888;text-transform:uppercase;margin:8px 0 2px">'+esc(g.nombre)+'</div>';
    (g.opciones||[]).forEach(function(o){
      var on=(s.sel[g.id]&&s.sel[g.id][o.id]);
      html+='<div class="opt" data-g="'+g.id+'" data-o="'+o.id+'" data-multi="'+(multi?1:0)+'" data-precio="'+o.precio+'" data-nombre="'+esc(o.nombre)+'"><span class="mark '+(multi?'':'rad')+(on?' on':'')+'"></span> '+esc(o.nombre)+(parseFloat(o.precio)>0?'<span style="margin-left:auto;color:#888">+S/ '+Number(o.precio).toFixed(0)+'</span>':'')+'</div>';
    });
  });
  html+='<div style="font-size:10px;font-weight:800;color:#888;text-transform:uppercase;margin:8px 0 4px">Nota para cocina</div><input id="prod-nota" placeholder="Sin cebolla…" value="'+esc(s.nota)+'" style="width:100%;padding:9px 11px;border:1.5px solid #ddd;border-radius:8px;font-size:13px"></div>';
  // pie en 2 filas
  html+='<div style="border-top:1px solid #eee;padding:11px 16px 16px"><div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:11px"><span style="font-size:11px;font-weight:800;color:#888;text-transform:uppercase">Cantidad</span><div style="display:flex;align-items:center;gap:14px"><button class="key" style="width:40px" onclick="prodQty(-1)">−</button><b id="prod-qty" style="font-size:18px">'+s.qty+'</b><button class="key" style="width:40px" onclick="prodQty(1)">+</button></div></div><button class="btn dark" onclick="addBorr()">Agregar · S/ <span id="prod-tot">'+prodTotal().toFixed(0)+'</span></button></div>';
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
function updBorr(){ var n=st.borrador.length; var tot=st.borrador.reduce(function(s,it){ var m=it.modificadores.reduce(function(a,x){return a+x.precio;},0); return s+(it.precio+m)*it.qty; },0);
  $('cat-foot').style.display=n?'block':'none'; $('cat-borr').textContent='Borrador · '+n+' ítems · S/ '+tot.toFixed(0); }
function enviarComanda(){ if(!st.borrador.length)return; geo().then(function(){ post('enviar_comanda', withGeo({cuenta_id:st.cuenta.id, items:JSON.stringify(st.borrador)})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} st.borrador=[]; toast('Enviado a cocina · Ronda '+d.ronda); loadCuenta(st.cuenta.id); }); }); }

function goPlano(){ showView('v-plano'); refreshEstados(); }

// arranque: ¿ya hay sesión?
get('sesion').then(function(d){ if(d.ok&&d.mozo){ st.emp=d.mozo.emp; $('plano-mozo').textContent=d.mozo.nombre+' 👤'; geo(); enterApp(); } else { $('pin-ubi').textContent=''; loadMozos(); } });
</script>
<?php endif; ?>
</body>
</html>
