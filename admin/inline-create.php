<?php
/**
 * Modal reutilizable de "crear al vuelo".
 * Inclúyelo UNA vez por página (antes de layout-bottom).
 * API JS:
 *   inlineCreate({
 *     title:    'Nuevo proveedor',
 *     endpoint: '<?= APP_URL ?>/api/proveedores.php',
 *     action:   'crear',
 *     csrf:     '<?= csrfToken() ?>',
 *     fields:  [{ key:'nombre', label:'Nombre', placeholder:'…' },
 *               { key:'tipo', label:'Tipo', type:'select', options:[{value,label},…], value:'x' }],
 *     onCreated: function(data){ ... }   // data = respuesta JSON {ok:true, ...}
 *   });
 */
?>
<div id="ic-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.5);z-index:5000;align-items:center;justify-content:center;padding:18px">
  <div style="width:380px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.32)">
    <div id="ic-title" style="background:#fafafb;padding:13px 16px;border-bottom:1px solid var(--border,#eee);font-weight:800;color:var(--ink,#1e1e1e)">Crear</div>
    <div id="ic-body" style="padding:16px;display:flex;flex-direction:column;gap:12px"></div>
    <div style="display:flex;gap:8px;padding:0 16px 16px">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="icClose()">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" id="ic-save" onclick="icSubmit()">Crear y agregar</button>
    </div>
  </div>
</div>
<script>
(function(){
  var cur = null;
  function fieldEl(f){
    var wrap = document.createElement('div');
    var lab = document.createElement('label');
    lab.textContent = f.label || f.key;
    lab.style.cssText = 'display:block;font-size:13px;font-weight:600;margin-bottom:5px;color:var(--text-secondary,#555)';
    wrap.appendChild(lab);
    var el;
    if (f.type === 'select') {
      el = document.createElement('select');
      (f.options || []).forEach(function(o){ var op = document.createElement('option'); op.value = o.value; op.textContent = o.label; el.appendChild(op); });
    } else {
      el = document.createElement('input');
      el.type = 'text';
      if (f.inputmode) el.inputMode = f.inputmode;
      if (f.placeholder) el.placeholder = f.placeholder;
    }
    el.id = 'ic-f-' + f.key;
    el.style.cssText = 'width:100%;padding:10px 12px;border:1.5px solid var(--border,#ddd);border-radius:9px;font-size:14px;background:#fff;color:var(--ink,#1e1e1e)';
    if (f.value != null) el.value = f.value;
    el.addEventListener('keydown', function(e){ if (e.key === 'Enter' && el.tagName !== 'SELECT') { e.preventDefault(); icSubmit(); } });
    wrap.appendChild(el);
    return wrap;
  }
  window.inlineCreate = function(opts){
    cur = opts || {};
    document.getElementById('ic-title').textContent = cur.title || 'Crear';
    var body = document.getElementById('ic-body');
    body.innerHTML = '';
    (cur.fields || []).forEach(function(f){ body.appendChild(fieldEl(f)); });
    document.getElementById('ic-save').disabled = false;
    document.getElementById('ic-ov').style.display = 'flex';
    var first = body.querySelector('input,select');
    if (first) setTimeout(function(){ first.focus(); }, 30);
  };
  window.icClose = function(){ document.getElementById('ic-ov').style.display = 'none'; cur = null; };
  window.icSubmit = function(){
    if (!cur) return;
    var btn = document.getElementById('ic-save');
    var params = new URLSearchParams();
    params.set('action', cur.action || 'crear');
    var faltaNombre = false;
    (cur.fields || []).forEach(function(f){
      var v = (document.getElementById('ic-f-' + f.key) || {}).value || '';
      params.set(f.key, v);
    });
    btn.disabled = true;
    fetch(cur.endpoint, { method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'X-CSRF-Token': cur.csrf }, body: params })
      .then(function(r){ return r.json(); })
      .then(function(d){
        btn.disabled = false;
        if (d && d.ok) { var cb = cur.onCreated; icClose(); if (cb) cb(d); }
        else { alert((d && d.error) || 'No se pudo crear'); }
      })
      .catch(function(){ btn.disabled = false; alert('Error de red, intenta de nuevo.'); });
  };
  document.getElementById('ic-ov').addEventListener('click', function(e){ if (e.target === this) icClose(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && document.getElementById('ic-ov').style.display === 'flex') icClose(); });
})();
</script>
