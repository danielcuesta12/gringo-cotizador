/* Tracking de páginas públicas — define window.TRACK_URL antes de cargar este archivo. */
(function () {
  function sid() {
    try {
      var s = localStorage.getItem('eg_sid');
      if (!s) { s = Date.now().toString(36) + Math.random().toString(36).slice(2, 10); localStorage.setItem('eg_sid', s); }
      return s;
    } catch (e) { return ''; }
  }
  function src() {
    try {
      var v = new URLSearchParams(location.search).get('src');
      if (v) { try { sessionStorage.setItem('eg_src', v); } catch (e) {} return v; }
      return sessionStorage.getItem('eg_src') || '';
    } catch (e) { return ''; }
  }
  window.track = function (event, page, opts) {
    opts = opts || {};
    var payload = JSON.stringify({
      event: event, page: page,
      ubicacion_id: opts.ubicacion_id || 0,
      meta: opts.meta || {},
      sid: sid(), src: src(), ref: document.referrer || ''
    });
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(window.TRACK_URL, new Blob([payload], { type: 'application/json' }));
      } else {
        fetch(window.TRACK_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload, keepalive: true }).catch(function () {});
      }
    } catch (e) {}
  };
})();
