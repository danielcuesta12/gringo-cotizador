/* PlanoEditor — editor de lienzo del plano. Vanilla, sin deps.
   Maneja pisos en memoria; cada cambio re-renderiza; guarda vía api/mesas.php. */
(function () {
  'use strict';
  var GRID = 10;
  var api, csrf, uploadUrl;
  var st = { ubi: 0, pisos: [], pi: 0, sel: null }; // sel = {kind:'mesa'|'elem', ref:obj}
  var tmpSeq = -1; // ids temporales negativos para nuevos
  var mount, elCanvas, elProps, elTabs;

  function snap(v) { return Math.round(v / GRID) * GRID; }
  function piso() { return st.pisos[st.pi]; }
  function post(action, body) {
    body = body || {};
    var fd = new FormData();
    fd.append('action', action);
    Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
    return fetch(api + '?action=' + action, { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd })
      .then(function (r) { return r.json(); });
  }

  // ---------- toolbar + tabs ----------
  function renderTabs() {
    elTabs.innerHTML = '';
    st.pisos.forEach(function (p, i) {
      var t = document.createElement('span');
      t.className = 'pe-tab' + (i === st.pi ? ' on' : '');
      t.textContent = p.nombre;
      t.addEventListener('click', function () { st.pi = i; st.sel = null; renderAll(); });
      t.addEventListener('dblclick', function () {
        var nv = prompt('Nombre del piso:', p.nombre);
        if (nv && nv.trim()) { p.nombre = nv.trim(); post('renombrar_piso', { piso_id: p.id, nombre: p.nombre }); renderTabs(); }
      });
      elTabs.appendChild(t);
    });
    var add = document.createElement('span');
    add.className = 'pe-tab pe-add';
    add.textContent = '＋ Piso';
    add.addEventListener('click', function () {
      var nombre = prompt('Nombre del nuevo piso:', 'Piso ' + (st.pisos.length + 1));
      if (!nombre) return;
      post('crear_piso', { ubicacion_id: st.ubi, nombre: nombre }).then(function (d) {
        if (d.ok) { st.pisos.push(d.piso); st.pi = st.pisos.length - 1; st.sel = null; renderAll(); }
      });
    });
    elTabs.appendChild(add);
  }

  // ---------- canvas ----------
  function renderCanvas() {
    var p = piso();
    elCanvas.innerHTML = '';
    elCanvas.style.width = p.ancho + 'px';
    elCanvas.style.height = p.alto + 'px';
    if (p.fondo_img) {
      var bg = document.createElement('img');
      bg.src = uploadUrl + p.fondo_img;
      bg.style.cssText = 'position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;opacity:.4;pointer-events:none;';
      elCanvas.appendChild(bg);
    }
    p.elementos.forEach(function (e) { elCanvas.appendChild(nodeFor('elem', e)); });
    p.mesas.forEach(function (m) { elCanvas.appendChild(nodeFor('mesa', m)); });
  }

  function nodeFor(kind, obj) {
    var d = document.createElement('div');
    var selected = st.sel && st.sel.ref === obj;
    var base = 'position:absolute;left:' + obj.pos_x + 'px;top:' + obj.pos_y + 'px;width:' + obj.ancho + 'px;height:' + obj.alto + 'px;box-sizing:border-box;cursor:move;user-select:none;';
    if (kind === 'mesa') {
      base += 'background:#fff;border:2px solid ' + (selected ? '#FFDF00' : '#ccc') + ';' +
        'border-radius:' + (obj.forma === 'redonda' ? '50%' : '12px') + ';' +
        'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
        (selected ? 'box-shadow:0 0 0 3px rgba(255,223,0,.3);' : '');
      var num = document.createElement('b'); num.style.cssText = 'font-size:16px;'; num.textContent = obj.numero;
      var sub = document.createElement('span'); sub.style.cssText = 'font-size:8px;color:#888;font-weight:700;'; sub.textContent = obj.capacidad + ' pers';
      d.appendChild(num); d.appendChild(sub);
    } else if (obj.tipo === 'etiqueta') {
      base += 'display:flex;align-items:center;font-weight:800;color:#1E1E1E;font-size:13px;' + (selected ? 'outline:2px solid #FFDF00;' : '');
      d.textContent = obj.texto || '(texto)';
    } else {
      base += 'background:#1E1E1E;opacity:.8;border-radius:5px;' + (selected ? 'outline:2px solid #FFDF00;' : '');
    }
    d.style.cssText = base;
    attachDrag(d, kind, obj);
    if (selected) addHandles(d, obj);
    return d;
  }

  // ---------- drag (mover) ----------
  function attachDrag(node, kind, obj) {
    node.addEventListener('pointerdown', function (ev) {
      if (ev.target.getAttribute('data-handle')) return; // resize maneja aparte
      ev.preventDefault();
      st.sel = { kind: kind, ref: obj };
      renderProps();
      var rect = elCanvas.getBoundingClientRect();
      var ox = ev.clientX - rect.left - obj.pos_x;
      var oy = ev.clientY - rect.top - obj.pos_y;
      function move(e) {
        obj.pos_x = Math.max(0, snap(e.clientX - rect.left - ox));
        obj.pos_y = Math.max(0, snap(e.clientY - rect.top - oy));
        node.style.left = obj.pos_x + 'px';
        node.style.top = obj.pos_y + 'px';
      }
      function up() { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); renderCanvas(); }
      document.addEventListener('pointermove', move);
      document.addEventListener('pointerup', up);
      renderCanvas();
    });
  }

  // ---------- resize (tirador esquina inferior-derecha) ----------
  function addHandles(node, obj) {
    var h = document.createElement('span');
    h.setAttribute('data-handle', '1');
    h.style.cssText = 'position:absolute;right:-6px;bottom:-6px;width:12px;height:12px;background:#FFDF00;border:2px solid #1E1E1E;border-radius:50%;cursor:nwse-resize;';
    h.addEventListener('pointerdown', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      var rect = elCanvas.getBoundingClientRect();
      function move(e) {
        obj.ancho = Math.max(20, snap(e.clientX - rect.left - obj.pos_x));
        obj.alto = Math.max(20, snap(e.clientY - rect.top - obj.pos_y));
        node.style.width = obj.ancho + 'px';
        node.style.height = obj.alto + 'px';
      }
      function up() { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); }
      document.addEventListener('pointermove', move);
      document.addEventListener('pointerup', up);
    });
    node.appendChild(h);
  }

  // ---------- panel de propiedades ----------
  function renderProps() {
    elProps.innerHTML = '';
    if (!st.sel) { elProps.innerHTML = '<p style="color:#888;font-size:12px">Selecciona un elemento para editarlo.</p>'; return; }
    var o = st.sel.ref;
    if (st.sel.kind === 'mesa') {
      elProps.appendChild(field('Número / nombre', inputText(o.numero, function (v) { o.numero = v; renderCanvas(); })));
      elProps.appendChild(field('Comensales', stepper(o.capacidad, function (v) { o.capacidad = v; renderCanvas(); })));
      elProps.appendChild(field('Forma', formaToggle(o)));
    } else if (o.tipo === 'etiqueta') {
      elProps.appendChild(field('Texto', inputText(o.texto || '', function (v) { o.texto = v; renderCanvas(); })));
    } else {
      elProps.appendChild(field('Forma decorativa', span('Arrástrala y redimensiónala en el lienzo.')));
    }
    var del = document.createElement('button');
    del.textContent = '🗑 Eliminar';
    del.style.cssText = 'margin-top:10px;background:none;border:none;color:#dc2626;font-weight:800;cursor:pointer;font-size:13px;';
    del.addEventListener('click', function () {
      var arr = st.sel.kind === 'mesa' ? piso().mesas : piso().elementos;
      var i = arr.indexOf(o); if (i >= 0) arr.splice(i, 1);
      st.sel = null; renderCanvas(); renderProps();
    });
    elProps.appendChild(del);
  }

  function field(label, control) {
    var wrap = document.createElement('div'); wrap.style.cssText = 'margin-bottom:10px;';
    var l = document.createElement('div'); l.textContent = label; l.style.cssText = 'font-size:10px;font-weight:800;text-transform:uppercase;color:#888;margin-bottom:3px;';
    wrap.appendChild(l); wrap.appendChild(control); return wrap;
  }
  function span(txt) { var s = document.createElement('p'); s.textContent = txt; s.style.cssText = 'font-size:12px;color:#888;'; return s; }
  function inputText(val, onChange) {
    var i = document.createElement('input'); i.type = 'text'; i.value = val;
    i.style.cssText = 'width:100%;padding:7px 9px;border:1.5px solid #ddd;border-radius:7px;font-size:13px;';
    i.addEventListener('input', function () { onChange(i.value); });
    return i;
  }
  function stepper(val, onChange) {
    var box = document.createElement('div'); box.style.cssText = 'display:flex;align-items:center;gap:10px;';
    var minus = btn('−'), plus = btn('＋'), b = document.createElement('b'); b.textContent = val; b.style.fontSize = '15px';
    minus.addEventListener('click', function () { val = Math.max(1, val - 1); b.textContent = val; onChange(val); });
    plus.addEventListener('click', function () { val = val + 1; b.textContent = val; onChange(val); });
    box.appendChild(minus); box.appendChild(b); box.appendChild(plus); return box;
  }
  function btn(t) { var x = document.createElement('span'); x.textContent = t; x.style.cssText = 'background:#1E1E1E;color:#fff;width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:800;cursor:pointer;'; return x; }
  function formaToggle(o) {
    var box = document.createElement('div'); box.style.cssText = 'display:flex;gap:8px;';
    ['cuadrada', 'redonda'].forEach(function (f) {
      var s = document.createElement('span');
      s.style.cssText = 'width:28px;height:28px;border:2px solid ' + (o.forma === f ? '#FFDF00' : '#ccc') + ';cursor:pointer;border-radius:' + (f === 'redonda' ? '50%' : '6px') + ';';
      s.addEventListener('click', function () { o.forma = f; renderCanvas(); renderProps(); });
      box.appendChild(s);
    });
    return box;
  }

  // ---------- crear elementos ----------
  function addMesa(forma) {
    var p = piso();
    p.mesas.push({ id: tmpSeq--, numero: String(p.mesas.length + 1), capacidad: 4, forma: forma, pos_x: 40, pos_y: 40, ancho: 60, alto: 60 });
    renderCanvas();
  }
  function addElem(tipo) {
    var p = piso();
    p.elementos.push({ id: tmpSeq--, tipo: tipo, texto: tipo === 'etiqueta' ? 'Texto' : null, pos_x: 40, pos_y: 40, ancho: tipo === 'etiqueta' ? 90 : 120, alto: tipo === 'etiqueta' ? 24 : 18 });
    renderCanvas();
  }

  // ---------- guardar ----------
  function save(statusEl) {
    var p = piso();
    statusEl.textContent = 'Guardando…';
    post('guardar_piso', { piso_id: p.id, mesas: JSON.stringify(p.mesas), elementos: JSON.stringify(p.elementos) })
      .then(function (d) {
        if (d.ok) {
          // reemplazar ids temporales por los reales
          if (d.idmap) {
            p.mesas.forEach(function (m) { if (d.idmap[String(m.id)]) m.id = d.idmap[String(m.id)]; });
            p.elementos.forEach(function (e) { if (d.idmap[String(e.id)]) e.id = d.idmap[String(e.id)]; });
          }
          statusEl.textContent = 'Guardado ✓';
          renderCanvas();
        } else { statusEl.textContent = 'Error al guardar'; }
        setTimeout(function () { statusEl.textContent = ''; }, 2500);
      });
  }

  function subirFondo(file, statusEl) {
    var p = piso();
    var fd = new FormData(); fd.append('action', 'subir_fondo'); fd.append('piso_id', p.id); fd.append('fondo', file);
    statusEl.textContent = 'Subiendo fondo…';
    fetch(api + '?action=subir_fondo', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) { p.fondo_img = d.fondo_img; renderCanvas(); } statusEl.textContent = d.ok ? 'Fondo listo ✓' : 'Error'; setTimeout(function () { statusEl.textContent = ''; }, 2500); });
  }

  // ---------- render maestro ----------
  function renderAll() { renderTabs(); renderCanvas(); renderProps(); }

  function init(opts) {
    api = window.EG_MESAS_API; csrf = window.EG_CSRF; uploadUrl = window.EG_UPLOAD_URL || '';
    st.ubi = opts.ubicacionId; st.pisos = opts.pisos || []; st.pi = 0; st.sel = null;
    mount = opts.mount;
    mount.innerHTML =
      '<div class="pe-tabs"></div>' +
      '<div class="pe-main">' +
        '<div class="pe-tools">' +
          '<div class="pe-tool" data-t="mesaR">⬤ Mesa redonda</div>' +
          '<div class="pe-tool" data-t="mesaC">▢ Mesa cuadrada</div>' +
          '<div class="pe-tool" data-t="etiqueta">🔤 Etiqueta</div>' +
          '<div class="pe-tool" data-t="forma">▬ Barra / pared</div>' +
          '<label class="pe-tool" style="cursor:pointer">🖼 Fondo<input type="file" accept="image/*" style="display:none"></label>' +
          '<div class="pe-save">Guardar</div>' +
          '<div class="pe-status"></div>' +
        '</div>' +
        '<div class="pe-canvas-wrap"><div class="pe-canvas"></div></div>' +
        '<div class="pe-props"></div>' +
      '</div>';
    elTabs = mount.querySelector('.pe-tabs');
    elCanvas = mount.querySelector('.pe-canvas');
    elProps = mount.querySelector('.pe-props');
    var statusEl = mount.querySelector('.pe-status');
    mount.querySelector('[data-t="mesaR"]').addEventListener('click', function () { addMesa('redonda'); });
    mount.querySelector('[data-t="mesaC"]').addEventListener('click', function () { addMesa('cuadrada'); });
    mount.querySelector('[data-t="etiqueta"]').addEventListener('click', function () { addElem('etiqueta'); });
    mount.querySelector('[data-t="forma"]').addEventListener('click', function () { addElem('forma'); });
    mount.querySelector('.pe-save').addEventListener('click', function () { save(statusEl); });
    mount.querySelector('input[type=file]').addEventListener('change', function () { if (this.files[0]) subirFondo(this.files[0], statusEl); });
    // clic en vacío deselecciona
    mount.querySelector('.pe-canvas-wrap').addEventListener('pointerdown', function (e) {
      if (e.target === this || e.target === elCanvas) { st.sel = null; renderCanvas(); renderProps(); }
    });
    if (!st.pisos.length) {
      // crear un primer piso por defecto
      post('crear_piso', { ubicacion_id: st.ubi, nombre: 'Piso 1' }).then(function (d) { if (d.ok) { st.pisos.push(d.piso); renderAll(); } });
    } else { renderAll(); }
  }

  window.PlanoEditor = { init: init };
})();
