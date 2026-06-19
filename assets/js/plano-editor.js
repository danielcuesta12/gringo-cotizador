/* PlanoEditor — editor de lienzo del plano. Vanilla, sin deps.
   Maneja pisos en memoria; cada cambio re-renderiza; guarda vía api/mesas.php. */
(function () {
  'use strict';
  var GRID = 10;
  var zoom = 0; // escala de la vista (0 = aún sin calcular → encuadra el piso)
  var api, csrf, uploadUrl;
  var st = { ubi: 0, pisos: [], pi: 0, sel: null }; // sel = {kind:'mesa'|'elem', ref:obj}
  var tmpSeq = -1; // ids temporales negativos para nuevos
  var mount, elCanvas, elProps, elTabs, elWrap, elSizer;

  // Factor para encuadrar todo el piso en la ventana (sin pasar de 1:1).
  function fitFactor(p) {
    var availW = ((elWrap && elWrap.clientWidth) || p.ancho) - 4;
    var maxH = Math.round(window.innerHeight * 0.72);
    var f = Math.min(availW / p.ancho, maxH / p.alto, 1);
    return f > 0 ? f : 1;
  }
  function zoomLabel() { var l = mount && mount.querySelector('.pe-zlbl'); if (l) l.textContent = Math.round(zoom * 100) + '%'; }
  // Aplica el zoom actual: dimensiona el área scrolleable y escala el lienzo (sin reconstruir).
  function applyZoom() {
    var p = piso(); if (!p || !elSizer) return;
    elSizer.style.width = Math.ceil(p.ancho * zoom) + 'px';
    elSizer.style.height = Math.ceil(p.alto * zoom) + 'px';
    elCanvas.style.transform = 'scale(' + zoom + ')';
    zoomLabel();
  }
  function setZoom(z) { zoom = Math.max(0.2, Math.min(3, z)); applyZoom(); }
  function zoomBy(f) { setZoom(zoom * f); }
  function fitToWindow() { zoom = fitFactor(piso()); applyZoom(); }

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
    if (st.pisos.length > 1) {
      var delp = document.createElement('span');
      delp.className = 'pe-tab';
      delp.style.color = '#dc2626';
      delp.textContent = '🗑 Piso';
      delp.title = 'Eliminar el piso actual';
      delp.addEventListener('click', function () {
        var p = piso();
        if (!confirm('¿Eliminar el piso "' + p.nombre + '" y todas sus mesas? No se puede deshacer.')) return;
        post('eliminar_piso', { piso_id: p.id }).then(function (d) {
          if (d.ok) { st.pisos.splice(st.pi, 1); st.pi = 0; st.sel = null; renderAll(); }
        });
      });
      elTabs.appendChild(delp);
    }
  }

  // ---------- canvas ----------
  function renderCanvas() {
    var p = piso();
    if (!(zoom > 0)) zoom = fitFactor(p); // primer render / piso nuevo → encuadrar
    elSizer.style.width = Math.ceil(p.ancho * zoom) + 'px';
    elSizer.style.height = Math.ceil(p.alto * zoom) + 'px';
    elCanvas.innerHTML = '';
    elCanvas.style.width = p.ancho + 'px';
    elCanvas.style.height = p.alto + 'px';
    elCanvas.style.transformOrigin = 'top left';
    elCanvas.style.transform = 'scale(' + zoom + ')';
    zoomLabel();
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
    if (selected) { addHandles(d, obj); addDeleteBadge(d); }
    return d;
  }

  // Botón ✕ de borrado directo, visible sobre el elemento seleccionado (táctil/mouse).
  function addDeleteBadge(node) {
    var x = document.createElement('span');
    x.setAttribute('data-handle', '1'); // que el drag lo ignore
    x.textContent = '✕';
    x.title = 'Eliminar';
    x.style.cssText = 'position:absolute;left:-8px;top:-8px;width:18px;height:18px;background:#dc2626;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;cursor:pointer;z-index:5;';
    x.addEventListener('pointerdown', function (ev) { ev.preventDefault(); ev.stopPropagation(); deleteSelected(); });
    node.appendChild(x);
  }

  // ---------- drag (mover) ----------
  function attachDrag(node, kind, obj) {
    node.addEventListener('pointerdown', function (ev) {
      if (ev.target.getAttribute('data-handle')) return; // resize maneja aparte
      ev.preventDefault();
      st.sel = { kind: kind, ref: obj };
      renderProps();
      var rect = elCanvas.getBoundingClientRect();
      // rect es el tamaño VISUAL (ya escalado); escala efectiva = rect.width / ancho lógico.
      var eff = rect.width / piso().ancho || 1;
      var ox = (ev.clientX - rect.left) / eff - obj.pos_x;
      var oy = (ev.clientY - rect.top) / eff - obj.pos_y;
      function move(e) {
        obj.pos_x = Math.max(0, snap((e.clientX - rect.left) / eff - ox));
        obj.pos_y = Math.max(0, snap((e.clientY - rect.top) / eff - oy));
        node.style.left = obj.pos_x + 'px';
        node.style.top = obj.pos_y + 'px';
      }
      function up() { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); renderCanvas(); }
      document.addEventListener('pointermove', move);
      document.addEventListener('pointerup', up);
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
      var eff = rect.width / piso().ancho || 1;
      function move(e) {
        obj.ancho = Math.max(20, snap((e.clientX - rect.left) / eff - obj.pos_x));
        obj.alto = Math.max(20, snap((e.clientY - rect.top) / eff - obj.pos_y));
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
    if (!st.sel) { renderPlanoForm(); return; }
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
    del.addEventListener('click', deleteSelected);
    elProps.appendChild(del);
    var hint = document.createElement('p');
    hint.textContent = '(o tecla Suprimir / ✕ en el elemento)';
    hint.style.cssText = 'margin-top:4px;font-size:10px;color:#aaa;';
    elProps.appendChild(hint);
  }

  // Panel "Forma del plano" (cuando no hay nada seleccionado): presets + tamaño personalizado.
  function renderPlanoForm() {
    var p = piso() || { ancho: 1000, alto: 700 };
    var presets = [['Cuadrado', 1000, 1000], ['Horizontal', 1200, 700], ['Vertical', 700, 1100]];
    var html = '<div style="font-size:10px;font-weight:800;text-transform:uppercase;color:#888;margin-bottom:6px">Forma del plano</div>';
    presets.forEach(function (pr) {
      var on = (p.ancho === pr[1] && p.alto === pr[2]);
      html += '<button type="button" class="pe-formp" data-w="' + pr[1] + '" data-h="' + pr[2] + '" style="display:block;width:100%;text-align:left;margin-bottom:5px;padding:8px 10px;border-radius:8px;border:1.5px solid ' + (on ? '#FFDF00' : '#ddd') + ';background:' + (on ? '#fffbe6' : '#fff') + ';font-weight:700;font-size:13px;cursor:pointer">' + pr[0] + ' <span style="color:#999;font-weight:400">' + pr[1] + '×' + pr[2] + '</span></button>';
    });
    html += '<div style="font-size:10px;font-weight:800;text-transform:uppercase;color:#888;margin:10px 0 4px">Personalizado</div>';
    html += '<div style="display:flex;gap:6px;align-items:center"><input id="pe-dw" type="number" value="' + p.ancho + '" style="width:50%;padding:6px;border:1.5px solid #ddd;border-radius:7px;font-size:13px"><span>×</span><input id="pe-dh" type="number" value="' + p.alto + '" style="width:50%;padding:6px;border:1.5px solid #ddd;border-radius:7px;font-size:13px"></div>';
    html += '<button type="button" id="pe-dapply" style="width:100%;margin-top:7px;background:#1E1E1E;color:#fff;border:none;border-radius:8px;padding:8px;font-weight:800;cursor:pointer;font-size:13px">Aplicar tamaño</button>';
    html += '<p style="font-size:10px;color:#aaa;margin-top:10px">Toca una mesa o elemento para editarlo.</p>';
    elProps.innerHTML = html;
    elProps.querySelectorAll('.pe-formp').forEach(function (b) {
      b.addEventListener('click', function () { setDims(parseInt(b.getAttribute('data-w')), parseInt(b.getAttribute('data-h'))); });
    });
    var ap = document.getElementById('pe-dapply');
    if (ap) ap.addEventListener('click', function () {
      setDims(parseInt(document.getElementById('pe-dw').value), parseInt(document.getElementById('pe-dh').value));
    });
  }

  // Cambia las dimensiones lógicas del piso (forma) y persiste.
  function setDims(w, h) {
    w = Math.max(300, Math.min(4000, w || 1000));
    h = Math.max(300, Math.min(4000, h || 700));
    var p = piso(); if (!p) return;
    p.ancho = w; p.alto = h;
    zoom = 0; renderCanvas(); renderProps(); // re-encuadrar a la nueva forma
    post('set_piso_dims', { piso_id: p.id, ancho: w, alto: h });
  }

  // Borra el elemento seleccionado (mesa o decoración) del piso actual.
  function deleteSelected() {
    if (!st.sel) return;
    var arr = st.sel.kind === 'mesa' ? piso().mesas : piso().elementos;
    var i = arr.indexOf(st.sel.ref); if (i >= 0) arr.splice(i, 1);
    st.sel = null; renderCanvas(); renderProps();
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
  function renderAll() { zoom = 0; renderTabs(); renderCanvas(); renderProps(); }

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
          '<div class="pe-tool" data-t="fit">⤢ Ajustar vista</div>' +
          '<div class="pe-zoombar"><button type="button" data-z="out" title="Alejar">−</button><span class="pe-zlbl">100%</span><button type="button" data-z="in" title="Acercar">+</button></div>' +
          '<div class="pe-save">Guardar</div>' +
          '<div class="pe-status"></div>' +
        '</div>' +
        '<div class="pe-canvas-wrap"><div class="pe-canvas-sizer"><div class="pe-canvas"></div></div></div>' +
        '<div class="pe-props"></div>' +
      '</div>';
    elTabs = mount.querySelector('.pe-tabs');
    elWrap = mount.querySelector('.pe-canvas-wrap');
    elSizer = mount.querySelector('.pe-canvas-sizer');
    elCanvas = mount.querySelector('.pe-canvas');
    elProps = mount.querySelector('.pe-props');
    var statusEl = mount.querySelector('.pe-status');
    mount.querySelector('[data-t="mesaR"]').addEventListener('click', function () { addMesa('redonda'); });
    mount.querySelector('[data-t="mesaC"]').addEventListener('click', function () { addMesa('cuadrada'); });
    mount.querySelector('[data-t="etiqueta"]').addEventListener('click', function () { addElem('etiqueta'); });
    mount.querySelector('[data-t="forma"]').addEventListener('click', function () { addElem('forma'); });
    mount.querySelector('[data-t="fit"]').addEventListener('click', fitToWindow);
    mount.querySelector('[data-z="out"]').addEventListener('click', function () { zoomBy(0.83); });
    mount.querySelector('[data-z="in"]').addEventListener('click', function () { zoomBy(1.2); });
    mount.querySelector('.pe-save').addEventListener('click', function () { save(statusEl); });
    mount.querySelector('input[type=file]').addEventListener('change', function () { if (this.files[0]) subirFondo(this.files[0], statusEl); });
    // clic en vacío deselecciona
    elWrap.addEventListener('pointerdown', function (e) {
      if (e.target === elWrap || e.target === elSizer || e.target === elCanvas) { st.sel = null; renderCanvas(); renderProps(); }
    });
    // al cambiar el tamaño de la ventana, re-aplicar el zoom (conserva el nivel actual)
    var _rsz = null;
    window.addEventListener('resize', function () { clearTimeout(_rsz); _rsz = setTimeout(function () { if (st.pisos.length) applyZoom(); }, 150); });
    // tecla Suprimir / Backspace borra lo seleccionado (salvo escribiendo en un campo)
    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Delete' && e.key !== 'Backspace') return;
      if (!st.sel) return;
      var t = document.activeElement;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA')) return;
      e.preventDefault();
      deleteSelected();
    });
    if (!st.pisos.length) {
      // crear un primer piso por defecto
      post('crear_piso', { ubicacion_id: st.ubi, nombre: 'Piso 1' }).then(function (d) { if (d.ok) { st.pisos.push(d.piso); renderAll(); } });
    } else { renderAll(); }
  }

  window.PlanoEditor = { init: init };
})();
