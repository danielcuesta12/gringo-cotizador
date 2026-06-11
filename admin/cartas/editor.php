<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

$id  = cleanInt($_GET['id'] ?? 0);
$cta = $id ? Database::fetch("SELECT * FROM cartas WHERE id = ?", [$id]) : null;
if (!$cta) { flashMessage('error', 'Carta no encontrada.'); redirect('/admin/cartas/index.php'); }

$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY nombre");

$pageTitle  = 'Editar carta';
$activePage = 'cartas-pdf';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/cartas/index.php">Generador de cartas PDF</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= clean($cta['nombre']) ?></span>
</div>

<style>
  .ed-top { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
  .ed-top input.ed-nombre { font-weight:700; font-size:15px; padding:9px 12px; border:1px solid var(--border); border-radius:9px; min-width:200px; flex:1; }
  .ed-wrap { display:flex; gap:16px; align-items:flex-start; }
  .ed-left { flex:1.1; min-width:0; }
  .ed-right { flex:1; min-width:0; position:sticky; top:14px; }
  @media (max-width:900px){ .ed-wrap{ flex-direction:column; } .ed-right{ position:static; width:100%; } }
  .ed-sec { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:12px; margin-bottom:12px; }
  .ed-sec-head { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
  .ed-sec-head input.sec-nombre { font-weight:700; border:1px solid var(--border); border-radius:8px; padding:7px 10px; flex:1; min-width:120px; }
  .ed-coltoggle { display:inline-flex; border:1px solid var(--border); border-radius:8px; overflow:hidden; }
  .ed-coltoggle button { border:none; background:#fff; padding:7px 11px; font-size:12px; font-weight:700; cursor:pointer; color:var(--text-secondary); }
  .ed-coltoggle button.on { background:var(--red); color:#fff; }
  .ed-item { display:flex; gap:10px; align-items:center; background:#fafafa; border:1px solid var(--border); border-radius:9px; padding:8px; margin-bottom:7px; }
  .ed-item img, .ed-item .ph { width:40px; height:40px; border-radius:7px; object-fit:cover; background:#ececec; flex-shrink:0; }
  .ed-item .nm { font-weight:600; font-size:13px; }
  .ed-item .pr { font-size:12px; color:var(--text-secondary); }
  .ed-mini { width:30px; height:30px; border:1px solid var(--border); background:#fff; border-radius:7px; cursor:pointer; font-size:14px; color:var(--text-secondary); }
  .ed-right .pv-bar { display:flex; align-items:center; gap:6px; margin-bottom:8px; }
  .ed-tema { display:inline-flex; background:#eee; border-radius:8px; padding:2px; margin-left:auto; }
  .ed-tema button { border:none; background:none; padding:5px 12px; border-radius:6px; font-size:12px; font-weight:700; color:var(--text-secondary); cursor:pointer; }
  .ed-tema button.on { background:#1a1a1a; color:#fff; }
  #preview { width:100%; height:520px; border:1px solid var(--border); border-radius:10px; background:#fff; display:block; }
  .ed-sizes { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:14px; margin-top:12px; }
  .ed-sizes h4 { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); margin-bottom:12px; }
  .ed-slider { margin-bottom:10px; }
  .ed-slider label { display:flex; justify-content:space-between; font-size:12px; color:var(--text-secondary); margin-bottom:3px; }
  .ed-slider input[type=range] { width:100%; }
  .ed-overlay { position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; z-index:1000; padding:20px; }
  .ed-overlay.open { display:flex; }
  .ed-modal { background:#fff; border-radius:14px; padding:22px; max-width:440px; width:100%; }
  .ed-modal .field { margin-bottom:12px; }
  .ed-modal label { display:block; font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:4px; }
  .ed-modal input, .ed-modal textarea, .ed-modal select { width:100%; padding:9px 11px; border:1px solid var(--border); border-radius:8px; font-size:14px; font-family:inherit; }
  .ed-modal-actions { display:flex; gap:8px; margin-top:6px; }
</style>

<div class="ed-top">
  <input type="text" class="ed-nombre" id="ed-nombre" value="<?= clean($cta['nombre']) ?>" onchange="saveMeta()">
  <select id="ed-ubi" style="padding:9px 11px;border:1px solid var(--border);border-radius:9px">
    <option value="">Cargar desde ubicación…</option>
    <?php foreach ($ubis as $u): ?><option value="<?= (int)$u['id'] ?>"><?= clean($u['nombre']) ?></option><?php endforeach; ?>
  </select>
  <button type="button" class="btn btn-ghost" onclick="cargarUbicacion()">Cargar</button>
  <button type="button" class="btn btn-primary" onclick="generarPDF()" style="gap:6px">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
    Generar PDF
  </button>
</div>

<div class="ed-wrap">
  <div class="ed-left">
    <div id="builder"></div>
    <button type="button" class="btn btn-ghost" onclick="addSeccion()" style="margin-top:4px">+ Agregar sección</button>
  </div>
  <div class="ed-right">
    <div class="pv-bar">
      <span style="font-size:12px;font-weight:700;color:var(--text-secondary)">Vista previa</span>
      <span class="ed-tema" id="ed-tema">
        <button type="button" data-t="noche" onclick="setTema('noche')">🌙 Noche</button>
        <button type="button" data-t="dia" onclick="setTema('dia')">☀️ Crema</button>
      </span>
    </div>
    <iframe id="preview"></iframe>
    <div class="ed-sizes">
      <h4>Tamaños (mm) y ancho</h4>
      <div class="ed-slider"><label>Ancho del banner <span id="v-ancho"></span></label><input type="range" id="s-ancho" min="100" max="1200" step="10" oninput="onSize()"></div>
      <div class="ed-slider"><label>Título de sección <span id="v-section"></span></label><input type="range" id="s-section" min="6" max="60" step="0.5" oninput="onSize()"></div>
      <div class="ed-slider"><label>Nombre <span id="v-name"></span></label><input type="range" id="s-name" min="6" max="48" step="0.5" oninput="onSize()"></div>
      <div class="ed-slider"><label>Precio <span id="v-price"></span></label><input type="range" id="s-price" min="6" max="48" step="0.5" oninput="onSize()"></div>
      <div class="ed-slider"><label>Descripción <span id="v-desc"></span></label><input type="range" id="s-desc" min="4" max="40" step="0.5" oninput="onSize()"></div>
      <div class="ed-slider"><label>Foto <span id="v-photo"></span></label><input type="range" id="s-photo" min="20" max="120" step="1" oninput="onSize()"></div>
      <div class="ed-slider"><label>Header / logo <span id="v-header"></span></label><input type="range" id="s-header" min="20" max="120" step="1" oninput="onSize()"></div>
    </div>
  </div>
</div>

<!-- MODAL ÍTEM -->
<div class="ed-overlay" id="itemOverlay">
  <div class="ed-modal">
    <div style="font-weight:700;font-size:16px;margin-bottom:14px" id="im-title">Ítem</div>
    <input type="hidden" id="im-id"><input type="hidden" id="im-foto">
    <div class="field"><label>Nombre</label><input type="text" id="im-nombre"></div>
    <div class="field"><label>Precio (S/)</label><input type="text" inputmode="decimal" id="im-precio" placeholder="0.00"></div>
    <div class="field"><label>Descripción</label><textarea id="im-desc" rows="2"></textarea></div>
    <div class="field"><label>Sección</label><select id="im-seccion"></select></div>
    <div class="field"><label>Foto</label>
      <div style="display:flex;gap:10px;align-items:center">
        <img id="im-foto-pv" src="" alt="" style="display:none;width:48px;height:48px;border-radius:8px;object-fit:cover;border:1px solid var(--border)">
        <input type="file" id="im-file" accept="image/jpeg,image/png,image/webp" onchange="uploadFoto(this)">
      </div>
    </div>
    <div class="ed-modal-actions">
      <button type="button" class="btn btn-danger btn-sm" id="im-del" style="margin-right:auto;display:none" onclick="delItem()">Eliminar</button>
      <button type="button" class="btn btn-ghost" onclick="closeItem()">Cancelar</button>
      <button type="button" class="btn btn-primary" onclick="saveItem()">Guardar</button>
    </div>
  </div>
</div>

<script>
  var CARTA_ID = <?= (int)$id ?>;
  var CSRF  = <?= json_encode(csrfToken()) ?>;
  var API   = '<?= APP_URL ?>/api/cartas.php';
  var PRINT = '<?= APP_URL ?>/carta/carta-print.php';
  var UPLOAD_URL = <?= json_encode(UPLOAD_URL) ?>;
  var state = null;

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function fmtMoney(v){ return 'S/ ' + (parseFloat(v)||0).toFixed(2); }

  function apiGet(action, params){
    var qs = Object.keys(params||{}).map(function(k){ return k+'='+encodeURIComponent(params[k]); }).join('&');
    return fetch(API+'?action='+action+(qs?'&'+qs:''), {credentials:'same-origin'}).then(function(r){ return r.json(); });
  }
  function apiPost(action, data){
    var body = new URLSearchParams();
    Object.keys(data).forEach(function(k){
      var v = data[k];
      if (Array.isArray(v)) v.forEach(function(x){ body.append(k, x); });
      else body.append(k, v);
    });
    return fetch(API+'?action='+action, {method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':CSRF}, body:body}).then(function(r){ return r.json(); });
  }

  var _pvTimer=null;
  function refreshPreview(){
    clearTimeout(_pvTimer);
    _pvTimer = setTimeout(function(){
      var t = (state && state.carta && state.carta.tema === 'dia') ? 'dia' : 'noche';
      document.getElementById('preview').src = PRINT+'?id='+CARTA_ID+'&preview=1&theme='+t+'&t='+Date.now();
    }, 300);
  }

  function load(){
    apiGet('get', {id:CARTA_ID}).then(function(res){
      if (!res.ok){ showToast && showToast('No se pudo cargar', true); return; }
      state = res;
      // meta controls
      document.getElementById('ed-nombre').value = res.carta.nombre || '';
      setSliderVals(res.carta);
      setTemaUI(res.carta.tema);
      renderBuilder();
      refreshPreview();
    });
  }

  function setSliderVals(c){
    [['ancho','ancho_mm'],['section','size_section'],['name','size_name'],['price','size_price'],['desc','size_desc'],['photo','size_photo'],['header','size_header']]
      .forEach(function(p){
        var el = document.getElementById('s-'+p[0]); el.value = c[p[1]];
        document.getElementById('v-'+p[0]).textContent = (p[0]==='ancho'? c[p[1]]+'mm' : parseFloat(c[p[1]])+'mm');
      });
  }
  function setTemaUI(t){
    document.querySelectorAll('#ed-tema button').forEach(function(b){ b.classList.toggle('on', b.dataset.t === (t==='dia'?'dia':'noche')); });
  }

  function renderBuilder(){
    var box = document.getElementById('builder'); box.innerHTML='';
    (state.secciones||[]).forEach(function(s, si){
      var sec = document.createElement('div'); sec.className='ed-sec';
      var items = (s.items||[]).map(function(it, ii){
        var thumb = it.foto ? '<img src="'+esc(UPLOAD_URL+it.foto)+'" alt="">' : '<div class="ph"></div>';
        return '<div class="ed-item">'+thumb+
          '<div style="flex:1;min-width:0"><div class="nm">'+esc(it.nombre)+'</div><div class="pr">'+fmtMoney(it.precio)+'</div></div>'+
          '<button class="ed-mini" title="Subir" onclick="moveItem('+s.id+','+it.id+',-1)">↑</button>'+
          '<button class="ed-mini" title="Bajar" onclick="moveItem('+s.id+','+it.id+',1)">↓</button>'+
          '<button class="btn btn-ghost btn-sm" onclick="openItem('+s.id+','+it.id+')">Editar</button>'+
          '<button class="ed-mini" style="color:var(--red)" onclick="delItem('+it.id+')">✕</button>'+
        '</div>';
      }).join('');
      sec.innerHTML =
        '<div class="ed-sec-head">'+
          '<input class="sec-nombre" value="'+esc(s.nombre)+'" onchange="renameSeccion('+s.id+', this.value)">'+
          '<span class="ed-coltoggle"><button class="'+(s.columnas==1?'on':'')+'" onclick="setCols('+s.id+',1)">1 col</button><button class="'+(s.columnas==2?'on':'')+'" onclick="setCols('+s.id+',2)">2 col</button></span>'+
          '<button class="ed-mini" title="Subir sección" onclick="moveSeccion('+s.id+',-1)">↑</button>'+
          '<button class="ed-mini" title="Bajar sección" onclick="moveSeccion('+s.id+',1)">↓</button>'+
          '<button class="ed-mini" style="color:var(--red)" title="Borrar sección" onclick="delSeccion('+s.id+')">🗑</button>'+
        '</div>'+
        '<div>'+items+'</div>'+
        '<button class="btn btn-ghost btn-sm" onclick="openItem('+s.id+',0)" style="margin-top:4px">+ Agregar ítem</button>';
      box.appendChild(sec);
    });
  }

  // ── META / sliders / tema ──
  var _metaTimer=null;
  function saveMeta(){
    clearTimeout(_metaTimer);
    _metaTimer = setTimeout(function(){
      var d = {
        id: CARTA_ID,
        nombre: document.getElementById('ed-nombre').value,
        tema: (state && state.carta.tema === 'dia') ? 'dia' : 'noche',
        ancho_mm: document.getElementById('s-ancho').value,
        size_section: document.getElementById('s-section').value,
        size_name: document.getElementById('s-name').value,
        size_price: document.getElementById('s-price').value,
        size_desc: document.getElementById('s-desc').value,
        size_photo: document.getElementById('s-photo').value,
        size_header: document.getElementById('s-header').value
      };
      apiPost('save_meta', d).then(function(){ refreshPreview(); });
    }, 400);
  }
  function onSize(){
    // refleja el número en vivo
    [['ancho','mm'],['section','mm'],['name','mm'],['price','mm'],['desc','mm'],['photo','mm'],['header','mm']].forEach(function(p){
      document.getElementById('v-'+p[0]).textContent = document.getElementById('s-'+p[0]).value+'mm';
    });
    saveMeta();
  }
  function setTema(t){ if(!state) return; state.carta.tema = (t==='dia'?'dia':'noche'); setTemaUI(state.carta.tema); saveMeta(); refreshPreview(); }

  // ── SECCIONES ──
  function addSeccion(){ apiPost('seccion_create', {carta_id:CARTA_ID, nombre:'Nueva sección'}).then(load); }
  function renameSeccion(id, val){ var s=findSec(id); apiPost('seccion_update', {id:id, nombre:val, columnas:(s?s.columnas:1)}).then(load); }
  function setCols(id, n){ var s=findSec(id); apiPost('seccion_update', {id:id, nombre:(s?s.nombre:''), columnas:n}).then(load); }
  function delSeccion(id){ if(!confirm('¿Borrar esta sección y sus ítems?')) return; apiPost('seccion_delete', {id:id}).then(load); }
  function moveSeccion(id, dir){
    var ids = state.secciones.map(function(s){ return s.id; });
    var i = ids.indexOf(id), j = i+dir; if (i<0||j<0||j>=ids.length) return;
    ids.splice(j,0, ids.splice(i,1)[0]);
    apiPost('seccion_reorder', {carta_id:CARTA_ID, 'ids[]':ids}).then(load);
  }
  function findSec(id){ return (state.secciones||[]).find(function(s){ return s.id==id; }); }

  // ── ÍTEMS ──
  function openItem(secId, itemId){
    var it=null; if(itemId){ var s=findSec(secId); it=(s&&s.items||[]).find(function(x){return x.id==itemId;}); }
    document.getElementById('im-title').textContent = it?'Editar ítem':'Nuevo ítem';
    document.getElementById('im-id').value = it?it.id:'';
    document.getElementById('im-nombre').value = it?it.nombre:'';
    document.getElementById('im-precio').value = it?it.precio:'';
    document.getElementById('im-desc').value = it&&it.descripcion?it.descripcion:'';
    document.getElementById('im-foto').value = it&&it.foto?it.foto:'';
    var pv=document.getElementById('im-foto-pv');
    if(it&&it.foto){ pv.src=UPLOAD_URL+it.foto; pv.style.display='block'; } else { pv.style.display='none'; pv.src=''; }
    document.getElementById('im-file').value='';
    document.getElementById('im-del').style.display = it?'block':'none';
    // select de secciones
    var sel=document.getElementById('im-seccion'); sel.innerHTML='';
    (state.secciones||[]).forEach(function(s){ var o=document.createElement('option'); o.value=s.id; o.textContent=s.nombre; if(s.id==secId)o.selected=true; sel.appendChild(o); });
    document.getElementById('itemOverlay').classList.add('open');
  }
  function closeItem(){ document.getElementById('itemOverlay').classList.remove('open'); }
  function uploadFoto(input){
    var f=input.files[0]; if(!f) return;
    var fd=new FormData(); fd.append('foto', f);
    fetch(API+'?action=upload_foto', {method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token':CSRF}, body:fd})
      .then(function(r){return r.json();}).then(function(res){
        if(res.ok){ document.getElementById('im-foto').value=res.foto; var pv=document.getElementById('im-foto-pv'); pv.src=UPLOAD_URL+res.foto; pv.style.display='block'; }
        else { alert(res.error||'Error al subir'); }
      });
  }
  function saveItem(){
    var id=document.getElementById('im-id').value;
    var secId=document.getElementById('im-seccion').value;
    var d={ nombre:document.getElementById('im-nombre').value||'Ítem', precio:document.getElementById('im-precio').value||0,
            descripcion:document.getElementById('im-desc').value, foto:document.getElementById('im-foto').value, seccion_id:secId };
    if(id){ d.id=id; apiPost('item_update', d).then(function(){ closeItem(); load(); }); }
    else { d.carta_id=CARTA_ID; apiPost('item_create', d).then(function(){ closeItem(); load(); }); }
  }
  function delItem(id){
    var realId = id || document.getElementById('im-id').value;
    if(!realId) return;
    if(!confirm('¿Eliminar este ítem?')) return;
    apiPost('item_delete', {id:realId}).then(function(){ closeItem(); load(); });
  }
  function moveItem(secId, itemId, dir){
    var s=findSec(secId); if(!s) return;
    var ids=(s.items||[]).map(function(x){return x.id;});
    var i=ids.indexOf(itemId), j=i+dir; if(i<0||j<0||j>=ids.length) return;
    ids.splice(j,0, ids.splice(i,1)[0]);
    apiPost('item_reorder', {carta_id:CARTA_ID, seccion_id:secId, 'ids[]':ids}).then(load);
  }

  // ── UBICACIÓN / PDF ──
  function cargarUbicacion(){
    var ubi=document.getElementById('ed-ubi').value;
    if(!ubi){ alert('Elige una ubicación.'); return; }
    if(!confirm('Esto agregará los ítems de la ubicación a esta carta. ¿Continuar?')) return;
    apiPost('cargar_ubicacion', {carta_id:CARTA_ID, ubicacion_id:ubi}).then(function(res){
      if(res.ok) load(); else alert(res.error||'Error');
    });
  }
  function generarPDF(){
    var t=(state&&state.carta.tema==='dia')?'dia':'noche';
    window.open(PRINT+'?id='+CARTA_ID+'&theme='+t, '_blank');
  }

  load();
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
