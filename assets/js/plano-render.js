/* PlanoRender — dibuja un piso del plano en modo read-only. Vanilla, sin deps. */
(function () {
  'use strict';
  var COLORS = {
    libre:      { bg: '#ffffff', border: '#9cc5a1', sub: '#6b8e6f' },
    ocupada:    { bg: '#FFF3B0', border: '#FFDF00', sub: '#8a6d00' },
    precuenta:  { bg: '#FFD8E0', border: '#FF8FA6', sub: '#b03a63' },
    por_cobrar: { bg: '#FFE0B2', border: '#FB8C00', sub: '#9a5b00' }
  };

  function elem(tag, css) {
    var n = document.createElement(tag);
    if (css) n.style.cssText = css;
    return n;
  }

  function draw(container, piso, opts) {
    opts = opts || {};
    var estados = opts.estados || {}, montos = opts.montos || {}, onTap = opts.onMesaTap;
    var W = piso.ancho || 1000, H = piso.alto || 700;
    var cw = container.clientWidth || W;
    var ch = opts.maxHeight || 0; // si se pasa, ajusta a ancho Y alto (llena la pantalla)
    var scale = ch ? Math.min(cw / W, ch / H) : cw / W;
    var stageW = W * scale, stageH = H * scale;
    var boxH = ch || stageH;

    container.innerHTML = '';
    container.style.position = 'relative';
    container.style.overflow = 'hidden';
    container.style.height = boxH + 'px';

    var offX = Math.max(0, (cw - stageW) / 2), offY = Math.max(0, (boxH - stageH) / 2); // centrar
    var stage = elem('div', 'position:absolute;left:' + offX + 'px;top:' + offY + 'px;transform-origin:top left;width:' + W + 'px;height:' + H + 'px;transform:scale(' + scale + ');');

    if (piso.fondo_img) {
      var bg = elem('img', 'position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;opacity:.45;');
      bg.src = (opts.uploadUrl || '') + piso.fondo_img;
      stage.appendChild(bg);
    }

    (piso.elementos || []).forEach(function (e) {
      var d = elem('div', 'position:absolute;left:' + e.pos_x + 'px;top:' + e.pos_y + 'px;width:' + e.ancho + 'px;height:' + e.alto + 'px;');
      if (e.tipo === 'etiqueta') {
        d.textContent = e.texto || '';
        d.style.cssText += 'font-weight:800;color:#1E1E1E;font-size:13px;display:flex;align-items:center;';
      } else {
        d.style.cssText += 'background:#1E1E1E;opacity:.8;border-radius:5px;';
      }
      stage.appendChild(d);
    });

    (piso.mesas || []).forEach(function (m) {
      var st = estados[m.id] || 'libre';
      var c = COLORS[st] || COLORS.libre;
      var d = elem('div',
        'position:absolute;left:' + m.pos_x + 'px;top:' + m.pos_y + 'px;width:' + m.ancho + 'px;height:' + m.alto + 'px;' +
        'background:' + c.bg + ';border:2px solid ' + c.border + ';color:#1E1E1E;' +
        'border-radius:' + (m.forma === 'redonda' ? '50%' : '12px') + ';' +
        'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
        'box-shadow:0 2px 6px rgba(0,0,0,.1);cursor:' + (onTap ? 'pointer' : 'default') + ';user-select:none;');
      d.setAttribute('data-mesa-id', m.id);
      var num = elem('b', 'font-size:16px;line-height:1.1;');
      num.textContent = m.numero;
      d.appendChild(num);
      var sub = elem('span', 'font-size:9px;font-weight:800;color:' + c.sub + ';');
      sub.textContent = (montos[m.id] != null) ? ('S/ ' + Number(montos[m.id]).toFixed(0)) : (m.capacidad + ' pers');
      d.appendChild(sub);
      if (onTap) d.addEventListener('click', function () { onTap(m.id, m); });
      stage.appendChild(d);
    });

    container.appendChild(stage);
  }

  window.PlanoRender = { draw: draw, COLORS: COLORS };
})();
