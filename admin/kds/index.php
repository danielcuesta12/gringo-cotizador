<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('kds');

// Ubicaciones activas para el selector (tolerante a que la tabla no exista aún)
$ubicaciones = [];
try { $ubicaciones = Database::fetchAll("SELECT id,nombre,es_principal FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, sort_order, nombre"); }
catch (Exception $e) { $ubicaciones = []; }
$defaultUbi = $ubicaciones[0]['id'] ?? 0;

$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';
$csrf    = csrfToken();
$esAdmin = isAdmin();
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="KDS">
<meta name="mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#161412">
<link rel="apple-touch-icon" href="<?= appIcon(APP_URL.'/assets/img/favicon-180.png') ?>">
<link rel="manifest" href="<?= APP_URL ?>/manifest.php?app=kds">
<title>KDS · El Gringo</title>
<link rel="icon" href="<?= appIcon(APP_URL.'/assets/img/favicon.png') ?>">
<style>
*{box-sizing:border-box;margin:0;padding:0}body{background:#0f0f0f;font-family:-apple-system,BlinkMacSystemFont,sans-serif;min-height:100vh}
#ks{display:flex;flex-direction:column;min-height:100vh}
.kt{background:#1a1a1a;padding:10px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #2a2a2a;flex-wrap:wrap;gap:8px}
.kti{color:#F5E6D0;font-size:12px;font-weight:600;letter-spacing:1px;text-transform:uppercase}
.km{display:flex;gap:12px;align-items:center;font-size:11px;flex-wrap:wrap}
.kg{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;padding:14px}
.kc{border-radius:8px;overflow:hidden;cursor:grab}
.kc.gray{background:#1a1a1a;border:1px solid #333}.kc.green{background:#0d2010;border:1px solid #166534}.kc.orange{background:#1c1000;border:1px solid #9a3412}.kc.red{background:#1c0000;border:1px solid #7f1d1d;animation:kp 1.5s ease-in-out infinite}
@keyframes kp{0%,100%{border-color:#7f1d1d}50%{border-color:#ef4444}}
.kch{padding:9px 12px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,0.06)}
.kch.gray{background:rgba(255,255,255,0.03)}.kch.green{background:rgba(74,222,128,0.08)}.kch.orange{background:rgba(251,146,60,0.08)}.kch.red{background:rgba(248,113,113,0.1)}
.kon{font-size:26px;font-weight:700;color:#F5E6D0}
.kti2{font-size:26px;font-weight:500;font-variant-numeric:tabular-nums}.kti2.green{color:#4ade80}.kti2.orange{color:#fb923c}.kti2.red{color:#f87171}
.ktp{font-size:14px;padding:1px 7px;border-radius:8px;font-weight:500}
.ktp.delivery{background:rgba(74,222,128,0.15);color:#4ade80}.ktp.recojo{background:rgba(96,165,250,0.15);color:#60a5fa}.ktp.salon{background:rgba(252,218,19,0.18);color:var(--c-brand,#FCDA13);font-weight:700;letter-spacing:.5px}
.kpay{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;background:rgba(252,218,19,0.18);color:var(--c-brand,#FCDA13);padding:3px 9px;border-radius:8px;white-space:nowrap}.kpay svg{width:13px;height:13px}
.kc.pay{border-color:#a88300;animation:kpay 1.6s ease-in-out infinite}.kch.pay{background:rgba(252,218,19,0.08)}@keyframes kpay{0%,100%{border-color:#7a6000}50%{border-color:var(--c-brand,#FCDA13)}}
.kcb{padding:14px}.kcl{font-size:18px;font-weight:700;color:#F5E6D0;margin-bottom:2px}.khr{font-size:14px;color:#888;margin-bottom:5px}
.kit{display:flex;flex-direction:column;gap:6px}.ki{font-size:18px;color:#ccc;display:flex;gap:5px}.kiq{color:#F5C200;font-weight:500}
.kim{font-size:12px;color:#777;font-style:italic;padding-left:22px}
.kcf{padding:8px 12px;border-top:1px solid rgba(255,255,255,0.06);display:flex;gap:6px}
.bls{flex:1;padding:7px;height:52px;border:none;border-radius:5px;font-size:18px;font-weight:600;cursor:pointer;text-transform:uppercase}
.bls.green{background:#166534;color:#4ade80}.bls.green:hover{background:#15803d}.bls.orange{background:#9a3412;color:#fb923c}.bls.red{background:#7f1d1d;color:#f87171}
.bcl{padding:7px 10px;height:52px;border:none;border-radius:5px;background:#3a0000;color:#f87171;font-size:18px;font-weight:600;cursor:pointer}
.bac{flex:1;padding:7px;height:52px;border:none;border-radius:5px;background:#166534;color:#4ade80;font-size:18px;font-weight:600;cursor:pointer;text-transform:uppercase}
.ke{text-align:center;padding:60px 20px;color:#333;font-size:13px;grid-column:1/-1}
.fsmode{position:fixed;top:0;left:0;width:100vw;height:100vh;background:#0f0f0f;z-index:9999;display:flex;flex-direction:column;overflow-y:auto}
.bw{font-size:9px;background:rgba(255,255,255,0.15);color:#ccc;padding:1px 7px;border-radius:8px;font-weight:600}
#ba,#bfs{background:rgba(255,255,255,0.1);border:1px solid #333;color:#888;padding:3px 10px;border-radius:12px;font-size:10px;cursor:pointer}
#bsal{background:none;border:1px solid #333;color:#888;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:11px;text-decoration:none}
.ubisel{background:#222;border:1px solid #333;color:#F5E6D0;padding:5px 10px;border-radius:8px;font-size:12px;outline:none}
.cnt{display:flex;align-items:center;gap:4px}
#bhist{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,0.1);border:1px solid #333;color:#888;padding:3px 10px;border-radius:12px;font-size:10px;cursor:pointer}
#bhist svg{width:13px;height:13px}#bhist:active{transform:scale(0.96)}
#hist-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:440;opacity:0;visibility:hidden;transition:opacity .35s ease}#hist-overlay.open{opacity:1;visibility:visible}
#hist-panel{position:fixed;top:0;right:0;height:100vh;width:320px;background:#141414;border-left:1px solid #2a2a2a;z-index:450;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .35s cubic-bezier(0.32,0.72,0,1);will-change:transform}#hist-panel.open{transform:translateX(0)}
.hist-handle{display:none}
.hist-head{display:flex;align-items:center;justify-content:space-between;padding:16px 16px 12px}.hist-title{font-size:15px;font-weight:700;color:#F5E6D0;letter-spacing:.3px}.hist-close{background:none;border:none;color:#888;font-size:20px;line-height:1;cursor:pointer;padding:2px 6px}.hist-close:active{transform:scale(0.9)}
.hist-stats{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;padding:0 14px 12px}.hist-stat{background:#1c1c1c;border:1px solid #2a2a2a;border-radius:10px;padding:10px 12px}.hist-stat-num{font-size:21px;font-weight:700;color:#F5E6D0;line-height:1.05;font-variant-numeric:tabular-nums}.hist-stat-lbl{font-size:10px;color:#777;text-transform:uppercase;letter-spacing:.5px;margin-top:3px}.hist-stat.money .hist-stat-num{color:#25D366}
.hist-list{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:4px 14px 24px;display:flex;flex-direction:column;gap:8px}
.hist-card{background:#1a1a1a;border:1px solid #2a2a2a;border-left:3px solid #555;border-radius:8px;padding:10px 12px;cursor:pointer;transition:background .15s}.hist-card:hover{background:#1e1e1e}
.hist-card.listo{border-left-color:#25D366}.hist-card.cancelado{border-left-color:#555;opacity:.55}.hist-card.activo{border-left-color:var(--c-brand,#FCDA13)}
.hist-card-top{display:flex;gap:10px;align-items:flex-start}.hist-card-main{flex:1;min-width:0}.hist-card-num{font-size:15px;font-weight:700;color:#F5E6D0}.hist-card-cli{font-size:12px;color:#aaa;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.hist-card-items{font-size:12px;color:#666;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hist-card-side{display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0}.hist-card-hora{font-size:11px;color:#555;font-variant-numeric:tabular-nums}
.hist-badge{font-size:10px;padding:2px 8px;border-radius:8px;font-weight:600;white-space:nowrap}.hist-badge.listo{background:rgba(37,211,102,0.15);color:#25D366}.hist-badge.cancelado{background:rgba(255,255,255,0.08);color:#888}.hist-badge.activo{background:rgba(252,218,19,0.15);color:var(--c-brand,#FCDA13)}
.hist-empty{text-align:center;color:#444;font-size:12px;padding:40px 0}
.hist-detail{max-height:0;overflow:hidden;transition:max-height .3s cubic-bezier(.32,.72,0,1)}.hist-detail.open{max-height:800px}
.hist-detail-inner{padding:8px 0 4px;border-top:.5px solid #222;margin-top:8px}
.hist-detail-item{display:flex;justify-content:space-between;align-items:flex-start;font-size:11px;padding:4px 0;border-bottom:.5px solid #1e1e1e;gap:8px}.hist-detail-item:last-child{border:none}
.hist-detail-item-name{color:#ccc;flex:1}.hist-detail-item-mods{font-size:10px;color:#555;font-style:italic;margin-top:1px}.hist-detail-item-price{color:#fff;font-weight:600;white-space:nowrap}
.hist-detail-foot{display:flex;justify-content:space-between;align-items:center;margin-top:6px;padding-top:6px;border-top:.5px solid #2a2a2a}.hist-detail-metodo{font-size:10px;color:#666}.hist-detail-total{font-size:13px;font-weight:700;color:#F5C200}
#kg{transition:margin-right .35s cubic-bezier(0.32,0.72,0,1)}
@media(min-width:769px){body.hist-open #kg{margin-right:320px}}
@media(max-width:768px){#hist-panel{top:auto;bottom:0;left:0;right:0;width:100%;height:70vh;border-left:none;border-top:1px solid #2a2a2a;border-radius:16px 16px 0 0;transform:translateY(100%)}#hist-panel.open{transform:translateY(0)}.hist-handle{display:block;width:40px;height:4px;border-radius:2px;background:#444;margin:8px auto 0}}
@media(prefers-reduced-motion:reduce){#hist-panel,#hist-overlay,#kg{transition:none}}
/* Alerta de pedido nuevo: flash verde + cartel a pantalla completa */
#kflash{position:fixed;inset:0;z-index:9990;background:#22c55e;opacity:0;pointer-events:none}
#kflash.on{animation:kflash .9s ease-out}
@keyframes kflash{0%{opacity:0}14%{opacity:.55}38%{opacity:0}54%{opacity:.42}100%{opacity:0}}
#kflash-lbl{position:fixed;inset:0;z-index:9991;display:flex;align-items:center;justify-content:center;pointer-events:none;opacity:0}
#kflash-lbl.on{animation:klbl .9s ease-out}
#kflash-lbl span{background:rgba(6,37,15,.92);color:#fff;font-size:30px;font-weight:900;padding:18px 34px;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
@keyframes klbl{0%{opacity:0;transform:scale(.8)}20%{opacity:1;transform:scale(1.04)}70%{opacity:1;transform:scale(1)}100%{opacity:0}}
/* Segmento de vistas + tiempos + chips */
.kseg{display:flex;background:#11141a;border:1px solid #2a2e36;border-radius:9px;overflow:hidden}
.kseg button{background:none;border:none;color:#9aa0aa;font-weight:700;font-size:12px;padding:7px 12px;cursor:pointer;white-space:nowrap}
.kseg button.on{background:var(--c-brand,#FCDA13);color:#1a1a1a}
.ktimes{background:#11141a;border:1px solid #2a2e36;color:#e8e8ea;font-weight:700;font-size:12px;padding:7px 11px;border-radius:9px;cursor:pointer}
.kpop{position:absolute;top:54px;left:0;background:#181b21;border:1px solid #2a2e36;border-radius:12px;padding:15px;width:255px;box-shadow:0 18px 50px rgba(0,0,0,.55);z-index:60;display:none}
.kpop.on{display:block}
.kpop h4{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--c-brand,#FCDA13);margin-bottom:11px}
.kpop .fld{display:flex;align-items:center;gap:8px;margin-bottom:10px;font-size:13px}
.kpop .fld label{flex:1;color:#9aa0aa}
.kpop .fld .dot{width:11px;height:11px;border-radius:50%}
.kpop input{width:60px;background:#11141a;border:1px solid #2a2e36;border-radius:7px;color:#e8e8ea;padding:6px;text-align:center;font-size:14px;font-weight:800}
.kpop .hint{font-size:11px;color:#6b7280;line-height:1.45}
.kchips{display:flex;align-items:center;gap:7px;flex-wrap:wrap;padding:8px 16px;border-bottom:1px solid #2a2e36;background:#13161b}
.kchips .lbl{font-size:10px;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:.5px}
.kchip{font-size:12px;font-weight:800;padding:6px 12px;border-radius:18px;border:1.5px solid #2a2e36;background:#11141a;color:#9aa0aa;cursor:pointer;user-select:none}
.kchip.on{background:var(--c-brand,#FCDA13);color:#1a1a1a;border-color:var(--c-brand,#FCDA13)}
/* Carriles (vistas por tipo / categoría) */
.klane{margin:0 0 14px}
.klane-h{display:flex;align-items:center;gap:9px;font-weight:900;font-size:14px;color:#e8e8ea;margin:0 0 9px;padding-left:11px;border-left:3px solid var(--acc,#666)}
.klane-h .ic{font-size:17px}.klane-h .n{color:#6b7280;font-weight:700;font-size:12px}
.kg.lanes{display:block;padding:14px 14px 0}
.klane .kg{padding:0 0 0 11px}
.ksplit{display:inline-block;font-size:9.5px;font-weight:900;background:#3a2d12;color:var(--c-brand,#FCDA13);border:1px solid #5a4a1e;border-radius:6px;padding:2px 6px;margin-top:6px}
.kc.parte-lista{opacity:.5}
.kpin{cursor:pointer;font-size:12px;margin-left:4px}
</style><?= brandHead() ?>
</head><body>
<div id="kflash"></div>
<div id="kflash-lbl"><span>🔔 ¡Nuevo pedido!</span></div>
<div id="ks">
  <div class="kt">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" alt="" style="height:26px;width:auto"><?php endif; ?>
      <div class="kti">KDS</div>
      <?php if (!empty($ubicaciones)): ?>
      <select class="ubisel" id="ubisel" onchange="changeUbi(this.value)">
        <?php foreach ($ubicaciones as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button id="ba" onclick="uA()">&#128263; Audio</button>
      <button id="bfs" onclick="tFS()">[ ] Full</button>
      <button id="bhist" onclick="tHist()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg><span id="bhist-lbl">Historial</span></button>
      <div class="kseg" id="kseg">
        <button data-v="all" class="on" onclick="setVista('all')">Todo junto</button>
        <button data-v="tipo" onclick="setVista('tipo')">Por tipo</button>
        <?php if ($esAdmin): ?><button data-v="cat" onclick="setVista('cat')">Por categoría</button><?php endif; ?>
      </div>
      <div style="position:relative">
        <button class="ktimes" onclick="togglePop()">&#9881; Tiempos</button>
        <div class="kpop" id="kpop">
          <h4>Umbrales de tiempo</h4>
          <div class="fld"><span class="dot" style="background:#fb923c"></span><label>Naranja a los</label><input id="in-tn" type="number" min="1" oninput="guardarTiempos()"> min</div>
          <div class="fld"><span class="dot" style="background:#f87171"></span><label>Rojo a los</label><input id="in-tr" type="number" min="2" oninput="guardarTiempos()"> min</div>
          <div class="hint">Se ajustan al ritmo del local y se guardan en esta pantalla.</div>
        </div>
      </div>
    </div>
    <div class="km">
      <div class="cnt"><span style="width:8px;height:8px;border-radius:50%;background:var(--c-brand,#FCDA13);display:inline-block"></span><span id="cg" style="color:var(--c-brand,#FCDA13);font-weight:500">0</span><span style="color:#555;margin-left:4px">esperando pago</span></div>
      <div class="cnt"><span style="width:8px;height:8px;border-radius:50%;background:#4ade80;display:inline-block"></span><span id="cgr" style="color:#4ade80;font-weight:500">0</span><span style="color:#555;margin-left:4px">a tiempo</span></div>
      <div class="cnt"><span style="width:8px;height:8px;border-radius:50%;background:#fb923c;display:inline-block"></span><span id="cor" style="color:#fb923c;font-weight:500">0</span><span style="color:#555;margin-left:4px">demorado</span></div>
      <div class="cnt"><span style="width:8px;height:8px;border-radius:50%;background:#f87171;display:inline-block"></span><span id="crd" style="color:#f87171;font-weight:500">0</span><span style="color:#555;margin-left:4px">urgente</span></div>
      <div id="clk" style="color:#555;font-variant-numeric:tabular-nums">--:--:--</div>
      <a id="bsal" href="<?= APP_URL ?>/admin/dashboard.php">← Admin</a>
    </div>
  </div>
  <div class="kchips" id="kchips" style="display:none"><span class="lbl">Mostrar:</span><span id="kchips-box"></span></div>
  <div class="kg" id="kg"><div class="ke">Cargando…</div></div>
  <div id="hist-overlay" onclick="cHist()"></div>
  <aside id="hist-panel"><div class="hist-handle"></div><div class="hist-head"><span class="hist-title">Historial de hoy</span><button class="hist-close" onclick="cHist()">&times;</button></div><div class="hist-stats" id="hist-stats"></div><div class="hist-list" id="hist-list"><div class="hist-empty">Cargando…</div></div></aside>
</div>
<script>
const API="<?= APP_URL ?>/api", CSRF="<?= $csrf ?>";
let UBI=<?= (int)$defaultUbi ?>;
const IS_ADMIN=<?= $esAdmin ? 'true' : 'false' ?>;
let D={pedidos:[],cfg:{tn:10,tr:20,rs:3},ids:new Set()},tmr=null,actx=null;
let mo=[], mm=new Set();
let vista="all", catOcultas=new Set(), partesListas=new Set(), _laneSig="";
function lsKey(k){return k+"_"+UBI;}
function slugCat(s){return String(s||"").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g,"").replace(/[^a-z0-9]+/g,"-").replace(/^-|-$/g,"")||"sin";}
function loadOrder(){
  mo=JSON.parse(localStorage.getItem(lsKey("ko"))||"[]");
  mm=new Set(JSON.parse(localStorage.getItem(lsKey("km"))||"[]"));
  catOcultas=new Set(JSON.parse(localStorage.getItem(lsKey("kco"))||"[]"));
  partesListas=new Set(JSON.parse(localStorage.getItem(lsKey("kpl"))||"[]"));
  var v=localStorage.getItem(lsKey("kv"))||"all"; if(v==="cat"&&!IS_ADMIN)v="all"; vista=v;
  D.cfg.tn=parseFloat(localStorage.getItem(lsKey("ktn")))||10;
  D.cfg.tr=parseFloat(localStorage.getItem(lsKey("ktr")))||20;
  _laneSig="";
  syncVistaUI();
}
function syncVistaUI(){
  var seg=document.getElementById("kseg"); if(seg) seg.querySelectorAll("button").forEach(function(b){b.classList.toggle("on",b.dataset.v===vista);});
  var tn=document.getElementById("in-tn"),tr=document.getElementById("in-tr"); if(tn)tn.value=D.cfg.tn; if(tr)tr.value=D.cfg.tr;
  var ch=document.getElementById("kchips"); if(ch) ch.style.display=(vista==="cat")?"flex":"none";
}
function saveParts(){localStorage.setItem(lsKey("kpl"),JSON.stringify([...partesListas]));}
function setVista(v){if(v==="cat"&&!IS_ADMIN)return;vista=v;localStorage.setItem(lsKey("kv"),v);syncVistaUI();document.getElementById("kg").innerHTML="";_laneSig="";rKDS();}
function togglePop(){document.getElementById("kpop").classList.toggle("on");}
function guardarTiempos(){var tn=parseFloat(document.getElementById("in-tn").value)||10,tr=parseFloat(document.getElementById("in-tr").value)||20;D.cfg.tn=tn;D.cfg.tr=tr;localStorage.setItem(lsKey("ktn"),tn);localStorage.setItem(lsKey("ktr"),tr);_laneSig="";rKDS();}
function toggleCat(sl){if(catOcultas.has(sl))catOcultas.delete(sl);else catOcultas.add(sl);localStorage.setItem(lsKey("kco"),JSON.stringify([...catOcultas]));_laneSig="";rKDS();}
function soltar(id){mm.delete(String(id));sv();_laneSig="";rKDS();}

async function req(ep,b){try{const r=await fetch(API+"/"+ep,{method:"POST",headers:{"Content-Type":"application/json","X-CSRF-Token":CSRF},body:JSON.stringify(b)});return await r.json();}catch(e){return{ok:false};}}

function esc(s){return String(s==null?"":s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;");}

// ── AUDIO (WebAudio, sin assets) ──
function iA(){if(!actx){try{actx=new (window.AudioContext||window.webkitAudioContext)();}catch(e){}}}
function uA(){iA();if(actx&&actx.state==="suspended")actx.resume();beep();var b=document.getElementById("ba");b.innerHTML="&#128266; Audio";b.style.color="#4ade80";b.style.borderColor="#4ade80";b.onclick=null;}
function beep(){if(!actx)return;try{const t=actx.currentTime;[{f:880,at:0},{f:1318.5,at:0.15}].forEach(function(n){[{m:1,type:"triangle",peak:0.6},{m:2,type:"sine",peak:0.22}].forEach(function(p){const o=actx.createOscillator(),g=actx.createGain();o.type=p.type;o.frequency.value=n.f*p.m;o.connect(g);g.connect(actx.destination);const s=t+n.at;g.gain.setValueAtTime(0.0001,s);g.gain.exponentialRampToValueAtTime(p.peak,s+0.012);g.gain.exponentialRampToValueAtTime(0.0001,s+0.5);o.start(s);o.stop(s+0.55);});});}catch(e){}}
function flashKDS(){var f=document.getElementById("kflash"),l=document.getElementById("kflash-lbl");if(!f)return;f.classList.remove("on");l.classList.remove("on");void f.offsetWidth;void l.offsetWidth;f.classList.add("on");l.classList.add("on");}
function alerta(){beep();flashKDS();}

function gc(m){if(m>=D.cfg.tr)return"red";if(m>=D.cfg.tn)return"orange";return"green";}
function ft(m){const mm2=Math.floor(m),ss=Math.floor((m%1)*60);return mm2.toString().padStart(2,"0")+":"+ss.toString().padStart(2,"0");}

function changeUbi(v){UBI=parseInt(v)||0;loadOrder();D.ids=new Set();D.pedidos=[];document.getElementById("kg").innerHTML='<div class="ke">Cargando…</div>';fKDS();if(histOpen)fHist();}

async function init(){loadOrder();await fKDS();if(tmr)clearInterval(tmr);tmr=setInterval(fKDS,(D.cfg.rs||15)*1000);setInterval(tick,1000);}

async function fKDS(){try{const r=await fetch(API+"/kds_pedidos.php?estados=pendiente,en_preparacion&ubicacion_id="+UBI+"&limite=50");const d=await r.json();if(!d.ok)return;const ps=(d.pedidos||[]).filter(p=>["pendiente","en_preparacion"].includes(p.estado));const ni=ps.map(p=>p.id.toString());const hn=ni.some(id=>!D.ids.has(id));if(hn&&D.ids.size>0)alerta();else if(D.ids.size===0&&ps.some(p=>p.estado==="pendiente"))alerta();D.ids=new Set(ni);const rc=Date.now();ps.forEach(p=>{p._base=p.elapsed_seconds?parseFloat(p.elapsed_seconds):0;p._recv=rc;});D.pedidos=ps;rKDS();}catch(e){}}

function srt(ps){const now=Date.now();const pn=ps.filter(p=>p.estado==="pendiente");const ep=ps.filter(p=>p.estado==="en_preparacion");ep.sort((a,b)=>{const ma=a._recv!==undefined?(a._base+(now-a._recv)/1000):0;const mb=b._recv!==undefined?(b._base+(now-b._recv)/1000):0;return mb-ma;});const ai=ep.filter(p=>!mm.has(p.id.toString())).map(p=>p.id.toString());const mi=mo.filter(id=>ps.find(p=>p.id.toString()===id&&mm.has(id)));const mp={};ps.forEach(p=>mp[p.id.toString()]=p);return[...ai,...mi,...pn.map(p=>p.id.toString())].map(id=>mp[id]).filter(Boolean);}

function rKDS(){var g=document.getElementById("kg");if(vista==="all"){g.classList.remove("lanes");renderAll();}else{g.classList.add("lanes");renderLanes();}}

function computeCounts(){var now=Date.now();var ct={gray:0,green:0,orange:0,red:0};D.pedidos.forEach(function(p){if(p.estado==="pendiente"){ct.gray++;return;}var ms=Math.max(0,(p._base+(now-p._recv)/1000)/60);ct[gc(ms)]++;});return ct;}

function srtLane(ps){var now=Date.now();var pn=ps.filter(function(p){return p.estado==="pendiente";});var ep=ps.filter(function(p){return p.estado!=="pendiente";});ep.sort(function(a,b){var ma=a._recv!==undefined?(a._base+(now-a._recv)/1000):0;var mb=b._recv!==undefined?(b._base+(now-b._recv)/1000):0;return mb-ma;});return ep.concat(pn);}

function lanesData(){
  var groups=[];
  if(vista==="tipo"){
    var defs=[["salon","Salón","#a78bfa",function(p){return p.origen==="pos";}],
              ["delivery","Delivery","#38bdf8",function(p){return p.origen!=="pos"&&p.tipo_entrega==="delivery";}],
              ["recojo","Recojo","#34d399",function(p){return p.origen!=="pos"&&p.tipo_entrega!=="delivery";}]];
    defs.forEach(function(d){var ps=srtLane(D.pedidos.filter(d[3]));if(ps.length)groups.push({key:d[0],label:d[1],color:d[2],rows:ps.map(function(p){return {p:p,items:p.items,nCats:1,parteCat:null};})});});
  }else{
    var order=[],by={};
    srtLane(D.pedidos).forEach(function(p){
      var cats={};(p.items||[]).forEach(function(i){var cn=i.categoria||"Sin categoría";(cats[cn]=cats[cn]||[]).push(i);});
      var nC=Object.keys(cats).length;
      Object.keys(cats).forEach(function(cn){var sl=slugCat(cn);if(!by[sl]){by[sl]={key:sl,label:cn,color:"#f59e0b",rows:[]};order.push(sl);}by[sl].rows.push({p:p,items:cats[cn],nCats:nC,parteCat:sl});});
    });
    order.forEach(function(sl){if(!catOcultas.has(sl))groups.push(by[sl]);});
  }
  return groups;
}

function renderChips(){
  var box=document.getElementById("kchips-box");if(!box)return;
  var seen={},order=[];
  D.pedidos.forEach(function(p){(p.items||[]).forEach(function(i){var cn=i.categoria||"Sin categoría";var sl=slugCat(cn);if(!seen[sl]){seen[sl]=cn;order.push(sl);}});});
  box.innerHTML=order.map(function(sl){return '<span class="kchip '+(catOcultas.has(sl)?"":"on")+'" onclick="toggleCat(\''+sl+'\')">'+esc(seen[sl])+'</span>';}).join("")||'<span style="color:#6b7280;font-size:12px">Sin categorías aún</span>';
}

function cardLane(p,items,opts){
  opts=opts||{};
  var ip=p.estado==="pendiente";
  var ms=ip?0:Math.max(0,(p._base+(Date.now()-p._recv)/1000)/60);
  var cl=ip?"gray":gc(ms);
  var done=opts.parteCat&&partesListas.has(p.id+":"+opts.parteCat);
  var it=(items||[]).map(function(i){var mods=(i.modificadores&&i.modificadores.length)?'<div class="kim">'+esc(i.modificadores.map(function(m){return m.nombre;}).join(", "))+'</div>':'';return '<div class="ki"><span class="kiq">'+esc(i.qty)+'x</span><span>'+esc(i.nombre)+'</span></div>'+mods;}).join("");
  var tag='<span class="ktp '+(p.origen==="pos"?"salon":esc(p.tipo_entrega))+'">'+(p.origen==="pos"?"SALÓN":p.tipo_entrega==="delivery"?"Delivery":"Recojo")+'</span>';
  var head='<div class="kch '+cl+(ip?" pay":"")+'"><div style="display:flex;align-items:center;gap:6px"><span class="kon">#'+String(p.id).padStart(3,"0")+'</span>'+tag+'</div>'+(ip?'<span class="kpay">Esperando pago</span>':'<span class="kti2 '+cl+'">'+ft(ms)+'</span>')+'</div>';
  var split=opts.nCats>1?'<span class="ksplit">✂️ parte de #'+String(p.id).padStart(3,"0")+'</span>':'';
  var foot;
  if(ip){foot='<button class="bac" onclick="ac('+p.id+')">Aceptar</button><button class="bcl" onclick="cn('+p.id+')">✕</button>';}
  else if(opts.parteCat){foot='<button class="bls '+cl+'" onclick="lsParte('+p.id+",'"+opts.parteCat+"')\">"+(done?"↩ Deshacer":"Listo")+'</button>';}
  else{foot='<button class="bls '+cl+'" onclick="ls('+p.id+')">Listo</button><button class="bcl" onclick="cn('+p.id+')">✕</button>';}
  return '<div class="kc '+cl+(ip?" pay":"")+(done?" parte-lista":"")+'" data-pid="'+p.id+'">'+head+'<div class="kcb"><div class="kcl">'+esc(p.nombre)+'</div>'+(p.horario?'<div class="khr">'+esc(p.horario)+'</div>':'')+'<div class="kit">'+it+'</div>'+split+(p.comentarios?'<div style="font-size:11px;color:#777;margin-top:6px">'+esc(p.comentarios)+'</div>':'')+'</div><div class="kcf">'+foot+'</div></div>';
}

function lsParte(id,sl){
  var k=id+":"+sl;
  if(partesListas.has(k))partesListas.delete(k);else partesListas.add(k);
  saveParts();
  var p=D.pedidos.find(function(x){return x.id==id;});
  if(p){var cats=new Set((p.items||[]).map(function(i){return slugCat(i.categoria||"Sin categoría");}));var all=[...cats].every(function(s){return partesListas.has(id+":"+s);});if(all){cats.forEach(function(s){partesListas.delete(id+":"+s);});saveParts();ls(id);return;}}
  _laneSig="";rKDS();
}

function renderLanes(){
  var g=document.getElementById("kg");
  if(vista==="cat")renderChips();
  var groups=lanesData();
  var ct=computeCounts();var cm={gray:"cg",green:"cgr",orange:"cor",red:"crd"};Object.keys(cm).forEach(function(k){var el=document.getElementById(cm[k]);if(el)el.textContent=ct[k]||0;});
  var sig=vista+"|"+[...catOcultas].join(",")+"|"+D.cfg.tn+"-"+D.cfg.tr+"|"+groups.map(function(gr){return gr.key+":"+gr.rows.map(function(r){var ip=r.p.estado==="pendiente";var ms=ip?0:Math.max(0,(r.p._base+(Date.now()-r.p._recv)/1000)/60);return r.p.id+(r.parteCat?("-"+r.parteCat):"")+":"+r.p.estado+":"+(ip?"g":gc(ms))+":"+(r.parteCat&&partesListas.has(r.p.id+":"+r.parteCat)?"d":"");}).join("/");}).join("|");
  if(sig===_laneSig)return;_laneSig=sig;
  if(!groups.length){g.innerHTML='<div class="ke">'+(vista==="cat"&&[...catOcultas].length?"Categorías ocultas — enciéndelas arriba":"Sin pedidos pendientes")+'</div>';return;}
  g.innerHTML=groups.map(function(gr){return '<div class="klane"><div class="klane-h" style="--acc:'+gr.color+'">'+esc(gr.label)+' <span class="n">· '+gr.rows.length+'</span></div><div class="kg">'+gr.rows.map(function(r){return cardLane(r.p,r.items,{parteCat:r.parteCat,nCats:r.nCats});}).join("")+'</div></div>';}).join("");
}

function renderAll(){const g=document.getElementById("kg");const now=Date.now();let ct={gray:0,green:0,orange:0,red:0};if(!D.pedidos.length){g.innerHTML='<div class="ke">Sin pedidos pendientes</div>';["cg","cgr","cor","crd"].forEach(id=>document.getElementById(id).textContent=0);return;}const sr=srt(D.pedidos);const nids=new Set(sr.map(p=>p.id.toString()));g.querySelectorAll(".ke").forEach(e=>e.remove());[...g.querySelectorAll(".kc")].forEach(c=>{if(!nids.has(c.dataset.id))c.remove();});sr.forEach((p,i)=>{const ip=p.estado==="pendiente";let ms=0;if(!ip&&p._recv!==undefined)ms=Math.max(0,(p._base+(now-p._recv)/1000)/60);const cl=ip?"gray":gc(ms);ct[cl]++;const cardExtra=ip?" pay":"";const ex=document.getElementById("kc-"+p.id);if(ex&&ex.dataset.estado===p.estado){if(!ex.className.includes(cl)){ex.className="kc "+cl;const h=ex.querySelector(".kch");if(h)h.className="kch "+cl;const btn=ex.querySelector(".bls");if(btn)btn.className="bls "+cl;const ti=ex.querySelector(".kti2");if(ti)ti.className="kti2 "+cl;}const te=document.getElementById("kt-"+p.id);if(te)te.textContent=ft(ms);return;}const it=(p.items||[]).map(i=>{const mods=(i.modificadores&&i.modificadores.length)?'<div class="kim">'+esc(i.modificadores.map(m=>m.nombre).join(", "))+'</div>':'';return '<div class="ki"><span class="kiq">'+esc(i.qty)+'x</span><span>'+esc(i.nombre)+'</span></div>'+mods;}).join("");const html='<div class="kc '+cl+cardExtra+'" id="kc-'+p.id+'" data-id="'+p.id+'" data-estado="'+p.estado+'">'+'<div class="kch '+cl+cardExtra+'">'+'<div style="display:flex;align-items:center;gap:6px"><span class="kon">#'+String(p.id).padStart(3,"0")+'</span>'+'<span class="ktp '+(p.origen==="pos"?"salon":esc(p.tipo_entrega))+'">'+(p.origen==="pos"?"SALÓN":p.tipo_entrega==="delivery"?"Delivery":"Recojo")+'</span>'+(mm.has(String(p.id))?'<span class="kpin" onclick="soltar('+p.id+')" title="Soltar">📌</span>':'')+'</div>'+(ip?'<span class="kpay"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>Esperando pago</span>':'<span class="kti2 '+cl+'" id="kt-'+p.id+'">'+ft(ms)+'</span>')+'</div>'+'<div class="kcb"><div class="kcl">'+esc(p.nombre)+'</div><div class="khr">'+esc(p.horario||"")+'</div>'+'<div class="kit">'+it+'</div>'+(p.comentarios?'<div style="font-size:11px;color:#777;margin-top:6px">'+esc(p.comentarios)+'</div>':'')+'</div>'+'<div class="kcf">'+(ip?'<button class="bac" onclick="ac('+p.id+')">Aceptar</button><button class="bcl" onclick="cn('+p.id+')">✕</button>':'<button class="bls '+cl+'" onclick="ls('+p.id+')">Listo</button><button class="bcl" onclick="cn('+p.id+')">✕</button>')+'</div></div>';const tmp=document.createElement("div");tmp.innerHTML=html;const nc=tmp.firstChild;if(ex)ex.remove();const cs=[...g.querySelectorAll(".kc")];if(i<cs.length)g.insertBefore(nc,cs[i]);else g.appendChild(nc);});const cm={"gray":"cg","green":"cgr","orange":"cor","red":"crd"};Object.entries(cm).forEach(([k,v])=>document.getElementById(v).textContent=ct[k]||0);setTimeout(iDrag,50);}

function tick(){const now=Date.now();const cl=document.getElementById("clk");if(cl)cl.textContent=new Date().toLocaleTimeString("es-PE",{hour:"2-digit",minute:"2-digit",second:"2-digit"});let re=false;
  if(vista==="all"){
    D.pedidos.forEach(p=>{if(p.estado==="pendiente"||p._recv===undefined)return;const ms=Math.max(0,(p._base+(now-p._recv)/1000)/60);const c=gc(ms);const te=document.getElementById("kt-"+p.id);const ce=document.getElementById("kc-"+p.id);if(te)te.textContent=ft(ms);if(ce&&!ce.className.includes(c))re=true;});
    if(re)rKDS();
  }else{
    document.querySelectorAll("#kg .kc[data-pid]").forEach(function(c){var p=D.pedidos.find(function(x){return x.id==c.dataset.pid;});if(!p||p.estado==="pendiente"||p._recv===undefined)return;var ms=Math.max(0,(p._base+(now-p._recv)/1000)/60);var col=gc(ms);var te=c.querySelector(".kti2");if(te)te.textContent=ft(ms);if(!c.className.includes(col))re=true;});
    if(re){_laneSig="";rKDS();}
  }
}

async function ac(id){const r=await req("kds_update.php",{action:"aceptar",id});if(r.ok){const p=D.pedidos.find(x=>x.id==id);if(p){p.estado="en_preparacion";p._base=0;p._recv=Date.now();}rKDS();}}
function purgarPartes(id){var pre=id+":";[...partesListas].forEach(function(k){if(k.indexOf(pre)===0)partesListas.delete(k);});saveParts();}
async function ls(id){const c=document.getElementById("kc-"+id);if(c){c.style.transition="opacity 0.3s,transform 0.3s";c.style.opacity="0";c.style.transform="scale(0.95)";}await req("kds_update.php",{action:"marcar_listo",id});D.pedidos=D.pedidos.filter(p=>p.id!=id);D.ids.delete(id.toString());mm.delete(id.toString());purgarPartes(id);_laneSig="";sv();setTimeout(rKDS,350);if(histOpen)fHist();}
async function cn(id){if(!confirm("¿Cancelar este pedido?"))return;const c=document.getElementById("kc-"+id);if(c){c.style.transition="opacity 0.3s,transform 0.3s";c.style.opacity="0";c.style.transform="scale(0.95)";}await req("kds_update.php",{action:"cancelar",id});D.pedidos=D.pedidos.filter(p=>p.id!=id);D.ids.delete(id.toString());mm.delete(id.toString());purgarPartes(id);_laneSig="";sv();setTimeout(rKDS,350);if(histOpen)fHist();}

function sv(){if(vista==="all"){const cs=document.querySelectorAll("#kg .kc");mo=[...cs].map(c=>c.dataset.id);localStorage.setItem(lsKey("ko"),JSON.stringify(mo));}localStorage.setItem(lsKey("km"),JSON.stringify([...mm]));}

function iDrag(){const cs=document.querySelectorAll("#kg .kc");let ds=null;cs.forEach(c=>{c.draggable=true;c.addEventListener("dragstart",e=>{ds=c;c.style.opacity="0.4";e.dataTransfer.effectAllowed="move";});c.addEventListener("dragend",()=>{c.style.opacity="1";document.querySelectorAll(".kc").forEach(x=>x.style.outline="");if(ds)mm.add(ds.dataset.id);sv();});c.addEventListener("dragover",e=>{e.preventDefault();if(c!==ds)c.style.outline="2px solid #F5C200";});c.addEventListener("dragleave",()=>c.style.outline="");c.addEventListener("drop",e=>{e.preventDefault();c.style.outline="";if(ds&&c!==ds){const g=document.getElementById("kg");const a=[...g.children];if(a.indexOf(ds)<a.indexOf(c))g.insertBefore(ds,c.nextSibling);else g.insertBefore(ds,c);sv();}});});}

function tFS(){const el=document.getElementById("ks");const isFs=el.classList.contains("fsmode");if(!isFs){el.classList.add("fsmode");document.getElementById("bfs").textContent="[x] Exit";const fn=document.documentElement.requestFullscreen||document.documentElement.webkitRequestFullscreen;if(fn)fn.call(document.documentElement).catch(()=>{});}else{el.classList.remove("fsmode");document.getElementById("bfs").textContent="[ ] Full";const fn=document.exitFullscreen||document.webkitExitFullscreen;if(fn)fn.call(document).catch(()=>{});}}

// ── HISTORIAL ──
let histOpen=false,histTmr=null;
function tHist(){histOpen?cHist():oHist();}
function oHist(){histOpen=true;document.getElementById("hist-panel").classList.add("open");document.getElementById("hist-overlay").classList.add("open");document.body.classList.add("hist-open");const l=document.getElementById("bhist-lbl");if(l)l.textContent="Cerrar";fHist();if(histTmr)clearInterval(histTmr);histTmr=setInterval(fHist,30000);}
function cHist(){histOpen=false;document.getElementById("hist-panel").classList.remove("open");document.getElementById("hist-overlay").classList.remove("open");document.body.classList.remove("hist-open");const l=document.getElementById("bhist-lbl");if(l)l.textContent="Historial";if(histTmr){clearInterval(histTmr);histTmr=null;}}
async function fHist(){try{const r=await fetch(API+"/kds_historial.php?ubicacion_id="+UBI);const d=await r.json();if(d.ok)rHist(d);}catch(e){}}
function eHora(ts){try{const dt=new Date(String(ts).replace(" ","T"));return dt.toLocaleTimeString("es-PE",{hour:"2-digit",minute:"2-digit"});}catch(e){return"";}}
function rHist(d){
  const s=d.stats||{};
  document.getElementById("hist-stats").innerHTML=
    '<div class="hist-stat"><div class="hist-stat-num">'+(s.total_pedidos||0)+'</div><div class="hist-stat-lbl">Pedidos</div></div>'+
    '<div class="hist-stat"><div class="hist-stat-num">'+(s.completados||0)+'</div><div class="hist-stat-lbl">Completados</div></div>'+
    '<div class="hist-stat"><div class="hist-stat-num">'+(s.cancelados||0)+'</div><div class="hist-stat-lbl">Cancelados</div></div>'+
    '<div class="hist-stat money"><div class="hist-stat-num">S/'+Number(s.total_monto||0).toFixed(2)+'</div><div class="hist-stat-lbl">Total</div></div>';
  const list=document.getElementById("hist-list");const ps=d.pedidos||[];
  if(!ps.length){list.innerHTML='<div class="hist-empty">Sin pedidos hoy</div>';return;}
  list.innerHTML=ps.map((p,idx)=>{
    let cls,badge;
    if(p.estado==="listo"||p.estado==="entregado"){cls="listo";badge="Listo";}
    else if(p.estado==="cancelado"){cls="cancelado";badge="Cancelado";}
    else{cls="activo";badge="En cocina";}
    const tipo=p.origen==="pos"?"Salón":(p.tipo_entrega==="delivery"?"Delivery":"Recojo");
    let items=Array.isArray(p.items)?p.items:[];
    const itemsHTML=items.length?items.map(it=>{
      const mods=it.modificadores?it.modificadores.map(m=>m.nombre).join(", "):"";
      const precio=parseFloat(it.subtotal||it.precio||0).toFixed(2);
      return '<div class="hist-detail-item"><div class="hist-detail-item-name"><div>'+esc(it.qty||1)+'x '+esc(it.nombre)+'</div>'+(mods?'<div class="hist-detail-item-mods">'+esc(mods)+'</div>':'')+'</div><div class="hist-detail-item-price">S/'+precio+'</div></div>';
    }).join(''):'<div style="font-size:11px;color:#555;padding:4px 0">'+esc(p.resumen||"—")+'</div>';
    const total=parseFloat(p.total||0).toFixed(2);const metodo=p.metodo_pago||"—";
    return '<div class="hist-card '+cls+'" id="hc-'+idx+'" onclick="toggleHistCard('+idx+')"><div class="hist-card-top"><div class="hist-card-main"><div class="hist-card-num">#'+String(p.id).padStart(3,"0")+'</div><div class="hist-card-cli">'+esc(p.cliente)+' · '+tipo+'</div><div class="hist-card-items">'+esc(p.resumen)+'</div></div><div class="hist-card-side"><span class="hist-card-hora">'+eHora(p.created_at)+'</span><span class="hist-badge '+cls+'">'+badge+'</span></div></div><div class="hist-detail" id="hd-'+idx+'"><div class="hist-detail-inner">'+itemsHTML+'<div class="hist-detail-foot"><span class="hist-detail-metodo">'+esc(metodo)+'</span><span class="hist-detail-total">S/'+total+'</span></div></div></div></div>';
  }).join('');
}
function toggleHistCard(idx){const detail=document.getElementById("hd-"+idx);if(detail)detail.classList.toggle("open");}

init();
</script>
<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('<?= APP_URL ?>/sw.js').catch(function(){});}</script>
</body></html>
