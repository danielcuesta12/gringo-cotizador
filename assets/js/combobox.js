/* EGCombo — combobox con búsqueda en vivo + crear al vuelo. Vanilla, sin deps. */
(function () {
  'use strict';
  function api() { return (window.EG_GASTOS_API || '/api/gastos.php'); }

  function depValue(el) {
    var sel = el.getAttribute('data-dep');
    if (!sel) return '';
    var scope = el.closest('[data-egc-scope]') || document;
    var dep = scope.querySelector(sel);
    return dep ? (dep.value || '') : '';
  }

  function setup(el) {
    if (el.getAttribute('data-egc-ready')) return;
    el.setAttribute('data-egc-ready', '1');
    var input  = el.querySelector('.egc-input');
    var hidden = el.querySelector('.egc-id');
    var menu   = el.querySelector('.egc-menu');
    var searchAction = el.getAttribute('data-search');
    var createAction = el.getAttribute('data-create');
    var csrf   = el.getAttribute('data-csrf') || '';
    var depKey = el.getAttribute('data-dep-create-key') || '';
    var timer = null, lastQ = '';

    function close() { menu.classList.remove('on'); menu.innerHTML = ''; }
    function open()  { menu.classList.add('on'); }

    function pick(id, nombre) {
      hidden.value = id;
      input.value = nombre;
      close();
      el.dispatchEvent(new CustomEvent('egc:change', { bubbles: true, detail: { id: id, nombre: nombre } }));
    }

    function render(list, q) {
      menu.innerHTML = '';
      list.forEach(function (it) {
        var d = document.createElement('div');
        d.className = 'egc-opt';
        d.textContent = it.nombre;
        d.addEventListener('mousedown', function (e) { e.preventDefault(); pick(it.id, it.nombre); });
        menu.appendChild(d);
      });
      var exact = list.some(function (it) { return it.nombre.toLowerCase() === q.toLowerCase(); });
      if (q && !exact && createAction) {
        var c = document.createElement('div');
        c.className = 'egc-opt egc-create';
        c.textContent = '➕ Crear «' + q + '»';
        c.addEventListener('mousedown', function (e) { e.preventDefault(); create(q); });
        menu.appendChild(c);
      }
      if (menu.children.length) open(); else close();
    }

    function search(q) {
      var url = api() + '?action=' + encodeURIComponent(searchAction) + '&q=' + encodeURIComponent(q);
      var dep = depValue(el);
      if (depKey && dep) url += '&' + depKey + '=' + encodeURIComponent(dep);
      fetch(url, { headers: { 'X-CSRF-Token': csrf } })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok) render(d.items || [], q); })
        .catch(function () { close(); });
    }

    function create(nombre) {
      var body = 'action=' + encodeURIComponent(createAction) + '&nombre=' + encodeURIComponent(nombre);
      var dep = depValue(el);
      if (depKey && dep) body += '&' + depKey + '=' + encodeURIComponent(dep);
      fetch(api(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: body
      })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok && d.item) pick(d.item.id, d.item.nombre); })
        .catch(function () {});
    }

    input.addEventListener('input', function () {
      hidden.value = ''; // al teclear se invalida la selección previa
      var q = input.value.trim();
      lastQ = q;
      clearTimeout(timer);
      timer = setTimeout(function () { if (q === lastQ) search(q); }, 180);
    });
    input.addEventListener('focus', function () { search(input.value.trim()); });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
  }

  window.EGCombo = {
    init: function (root) {
      (root || document).querySelectorAll('.egc[data-egc]').forEach(setup);
    }
  };
  document.addEventListener('DOMContentLoaded', function () { window.EGCombo.init(document); });
})();
