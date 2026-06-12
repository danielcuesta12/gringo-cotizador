<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAdmin();

$ubis = [];
try {
    $ubis = Database::fetchAll(
        "SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, nombre"
    );
} catch (Exception $e) { /* table may not exist yet */ }

$pageTitle  = 'Reportes';
$activePage = 'reportes';
$extraHead  = '<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Reportes</h1>
    <p>Exporta datos de ventas, ítems y caja a Excel (.xlsx)</p>
  </div>
</div>

<!-- ── Filters ─────────────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:18px;padding:20px 22px">
  <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">

    <div>
      <label class="form-label" for="rp-desde">Desde</label><br>
      <input type="date" id="rp-desde" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:14px">
    </div>

    <div>
      <label class="form-label" for="rp-hasta">Hasta</label><br>
      <input type="date" id="rp-hasta" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:14px">
    </div>

    <?php if (count($ubis) > 1): ?>
    <div>
      <label class="form-label" for="rp-ubi">Ubicación</label><br>
      <select id="rp-ubi" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:14px">
        <option value="0">Todas</option>
        <?php foreach ($ubis as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= clean($u['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php else: ?>
    <input type="hidden" id="rp-ubi" value="0">
    <?php endif; ?>

    <div>
      <label class="form-label" for="rp-origen">Origen</label><br>
      <select id="rp-origen" style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:14px">
        <option value="">Todos</option>
        <option value="carta">Carta</option>
        <option value="pos">POS</option>
      </select>
    </div>

  </div>
</div>

<!-- ── Export buttons ──────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-bottom:32px">

  <button onclick="exportPedidos()" class="rp-btn">
    <span class="rp-btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
    </span>
    <span class="rp-btn-label">Pedidos</span>
    <span class="rp-btn-sub">1 hoja · detalle completo</span>
  </button>

  <button onclick="exportVentas()" class="rp-btn">
    <span class="rp-btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
    </span>
    <span class="rp-btn-label">Ventas — resumen</span>
    <span class="rp-btn-sub">3 hojas · día, método, ubicación</span>
  </button>

  <button onclick="exportCategorias()" class="rp-btn">
    <span class="rp-btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
    </span>
    <span class="rp-btn-label">Ítems vendidos</span>
    <span class="rp-btn-sub">3 hojas · categorías, detalle, mods</span>
  </button>

  <button onclick="exportCaja()" class="rp-btn">
    <span class="rp-btn-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2M2 12h20"/></svg>
    </span>
    <span class="rp-btn-label">Caja / turnos</span>
    <span class="rp-btn-sub">1 hoja · arqueo de turnos</span>
  </button>

</div>

<style>
.rp-btn {
  display:flex;flex-direction:column;align-items:flex-start;gap:6px;
  padding:18px 20px;border:none;border-radius:12px;cursor:pointer;
  background:#FFDF00;color:#1A1A1A;text-align:left;
  transition:transform .12s,box-shadow .12s;
  box-shadow:0 2px 8px rgba(0,0,0,.10);
}
.rp-btn:hover { transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.16); }
.rp-btn:active { transform:translateY(0); }
.rp-btn-icon { display:flex;align-items:center;color:#1A1A1A; }
.rp-btn-label { font-weight:700;font-size:15px;line-height:1.2; }
.rp-btn-sub { font-size:12px;opacity:.65;font-weight:500; }
</style>

<script>
(function () {
  var API = '<?= APP_URL ?>/api/reportes.php';

  /* Default date range: first of current month → today */
  (function setDefaultDates() {
    var today = new Date();
    var y = today.getFullYear();
    var m = String(today.getMonth() + 1).padStart(2, '0');
    var d = String(today.getDate()).padStart(2, '0');
    document.getElementById('rp-desde').value = y + '-' + m + '-01';
    document.getElementById('rp-hasta').value = y + '-' + m + '-' + d;
  })();

  /* Build query-string from current filter values */
  function qs(action) {
    var desde = document.getElementById('rp-desde').value;
    var hasta = document.getElementById('rp-hasta').value;
    var ubi   = document.getElementById('rp-ubi').value || '0';
    var orig  = document.getElementById('rp-origen').value;
    return API + '?action=' + action
      + '&desde=' + encodeURIComponent(desde)
      + '&hasta=' + encodeURIComponent(hasta)
      + '&ubicacion_id=' + encodeURIComponent(ubi)
      + '&origen=' + encodeURIComponent(orig);
  }

  /* Helpers */
  function fmtFecha(dt) {
    if (!dt) return '';
    return dt.substring(0, 10).split('-').reverse().join('/');
  }
  function fmtHora(dt) {
    if (!dt) return '';
    return dt.substring(11, 16);
  }
  function fmtDt(dt) {
    if (!dt) return '';
    return fmtFecha(dt) + ' ' + fmtHora(dt);
  }
  function dl(wb, filename) {
    XLSX.writeFile(wb, filename);
  }
  function colWidths(arr) { return arr.map(function(w){ return {wch: w}; }); }

  /* Guard */
  function xlsxReady() {
    if (typeof XLSX === 'undefined') {
      alert('No se pudo cargar la librería Excel. Verifica tu conexión e intenta de nuevo.');
      return false;
    }
    return true;
  }

  /* ── 1. Pedidos ─────────────────────────────────────────────────────────── */
  window.exportPedidos = function () {
    if (!xlsxReady()) return;
    fetch(qs('pedidos'), {credentials: 'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (!data.ok) { alert('Error: ' + data.error); return; }
        var pedidos = data.pedidos || [];
        if (!pedidos.length) { alert('No hay pedidos en el rango seleccionado.'); return; }

        var headers = ['#Pedido','Fecha','Hora','Origen','Ubicación','Cliente','Productos','Total','Método pago','Estado'];
        var rows = pedidos.map(function(p) {
          var items = [];
          try { items = JSON.parse(p.items_json || '[]'); } catch(e) { items = []; }
          var prods = items.map(function(i){ return i.qty + 'x ' + i.nombre; }).join('; ');
          return [
            p.id,
            fmtFecha(p.created_at),
            fmtHora(p.created_at),
            p.origen === 'pos' ? 'POS' : 'Carta',
            p.ubicacion,
            p.cliente,
            prods,
            Number(p.total),
            p.metodo_pago,
            p.estado
          ];
        });

        var ws = XLSX.utils.aoa_to_sheet([headers].concat(rows));
        ws['!cols'] = colWidths([10,12,8,8,16,22,50,12,14,14]);
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Pedidos');

        var desde = document.getElementById('rp-desde').value;
        var hasta = document.getElementById('rp-hasta').value;
        dl(wb, 'pedidos-' + desde + '_a_' + hasta + '.xlsx');
      })
      .catch(function(e){ alert('Error de red: ' + e.message); });
  };

  /* ── 2. Ventas — resumen ────────────────────────────────────────────────── */
  window.exportVentas = function () {
    if (!xlsxReady()) return;
    fetch(qs('pedidos'), {credentials: 'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (!data.ok) { alert('Error: ' + data.error); return; }
        var pedidos = data.pedidos || [];
        if (!pedidos.length) { alert('No hay pedidos en el rango seleccionado.'); return; }

        /* Group by date */
        var byDia = {};
        var byMetodo = {};
        var byUbi = {};
        pedidos.forEach(function(p) {
          var fecha = (p.created_at || '').substring(0, 10);
          var met   = p.metodo_pago || 'Sin método';
          var ubi   = p.ubicacion   || 'Sin ubicación';
          var tot   = Number(p.total);

          if (!byDia[fecha])   byDia[fecha]   = {n: 0, total: 0};
          byDia[fecha].n++;   byDia[fecha].total   += tot;

          if (!byMetodo[met]) byMetodo[met]   = {n: 0, total: 0};
          byMetodo[met].n++; byMetodo[met].total  += tot;

          if (!byUbi[ubi])    byUbi[ubi]      = {n: 0, total: 0};
          byUbi[ubi].n++;    byUbi[ubi].total    += tot;
        });

        function buildSheet(map, col1Label) {
          var hdrs = [col1Label, 'N° pedidos', 'Total'];
          var rows = Object.keys(map).map(function(k) {
            return [k, map[k].n, Number(map[k].total.toFixed(2))];
          });
          rows.sort(function(a, b){ return b[2] - a[2]; });
          var sumN   = rows.reduce(function(s, r){ return s + r[1]; }, 0);
          var sumTot = rows.reduce(function(s, r){ return s + r[2]; }, 0);
          rows.push(['TOTAL', sumN, Number(sumTot.toFixed(2))]);
          var ws = XLSX.utils.aoa_to_sheet([hdrs].concat(rows));
          ws['!cols'] = colWidths([20, 12, 14]);
          return ws;
        }

        /* Sort byDia keys chronologically */
        var byDiaSorted = {};
        Object.keys(byDia).sort().forEach(function(k){ byDiaSorted[k] = byDia[k]; });

        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, buildSheet(byDiaSorted, 'Fecha'),     'Por día');
        XLSX.utils.book_append_sheet(wb, buildSheet(byMetodo,    'Método'),    'Por método');
        XLSX.utils.book_append_sheet(wb, buildSheet(byUbi,       'Ubicación'), 'Por ubicación');

        var desde = document.getElementById('rp-desde').value;
        var hasta = document.getElementById('rp-hasta').value;
        dl(wb, 'ventas-' + desde + '_a_' + hasta + '.xlsx');
      })
      .catch(function(e){ alert('Error de red: ' + e.message); });
  };

  /* ── 3. Ítems vendidos + modificadores ─────────────────────────────────── */
  window.exportCategorias = function () {
    if (!xlsxReady()) return;
    fetch(qs('categorias'), {credentials: 'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (!data.ok) { alert('Error: ' + data.error); return; }
        var cats = data.categorias || [];
        if (!cats.length) { alert('No hay ítems en el rango seleccionado.'); return; }

        /* Sheet 1: Por categoría — grouped with sub-rows */
        var hdrs1 = ['Categoría', 'Producto', 'Unidades', 'Total'];
        var rows1 = [];
        cats.forEach(function(cat) {
          rows1.push([cat.categoria, '', cat.qty, Number(Number(cat.monto).toFixed(2))]);
          (cat.items || []).forEach(function(it) {
            rows1.push(['', it.nombre, it.qty, Number(Number(it.total).toFixed(2))]);
          });
        });
        rows1.push(['TOTAL', '', data.total_qty, Number(Number(data.total_monto).toFixed(2))]);
        var ws1 = XLSX.utils.aoa_to_sheet([hdrs1].concat(rows1));
        ws1['!cols'] = colWidths([22, 30, 12, 14]);

        /* Sheet 2: Detalle — flat */
        var hdrs2 = ['Categoría', 'Producto', 'Unidades', 'Total'];
        var rows2 = [];
        cats.forEach(function(cat) {
          (cat.items || []).forEach(function(it) {
            rows2.push([cat.categoria, it.nombre, it.qty, Number(Number(it.total).toFixed(2))]);
          });
        });
        rows2.push(['TOTAL', '', data.total_qty, Number(Number(data.total_monto).toFixed(2))]);
        var ws2 = XLSX.utils.aoa_to_sheet([hdrs2].concat(rows2));
        ws2['!cols'] = colWidths([22, 30, 12, 14]);

        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws1, 'Por categoría');
        XLSX.utils.book_append_sheet(wb, ws2, 'Detalle');

        /* Sheet 3: Modificadores (only if present) */
        var mods = data.modificadores || [];
        if (mods.length) {
          var hdrs3 = ['Modificador', 'Veces', 'Ingreso extra'];
          var rows3 = mods.map(function(m) {
            return [m.nombre, m.qty, Number(Number(m.ingreso).toFixed(2))];
          });
          rows3.push(['TOTAL', data.mod_total_qty, Number(Number(data.mod_total_ingreso).toFixed(2))]);
          var ws3 = XLSX.utils.aoa_to_sheet([hdrs3].concat(rows3));
          ws3['!cols'] = colWidths([28, 10, 14]);
          XLSX.utils.book_append_sheet(wb, ws3, 'Modificadores');
        }

        var desde = document.getElementById('rp-desde').value;
        var hasta = document.getElementById('rp-hasta').value;
        dl(wb, 'items-vendidos-' + desde + '_a_' + hasta + '.xlsx');
      })
      .catch(function(e){ alert('Error de red: ' + e.message); });
  };

  /* ── 4. Caja / turnos ───────────────────────────────────────────────────── */
  window.exportCaja = function () {
    if (!xlsxReady()) return;
    fetch(qs('caja'), {credentials: 'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data) {
        if (!data.ok) { alert('Error: ' + data.error); return; }
        var turnos = data.turnos || [];
        if (!turnos.length) { alert('No hay turnos de caja en el rango seleccionado.'); return; }

        var headers = [
          '#Turno','Cajero','Ubicación','Apertura','Cierre','Estado',
          'Caja inicial','Ingresos','Gastos','Ventas efectivo',
          'Ventas tarjeta','Ventas QR','Ventas otros',
          'Total ventas','N° pedidos','Caja esperada','Caja real','Diferencia'
        ];
        var rows = turnos.map(function(t) {
          return [
            t.id,
            t.cajero,
            t.ubicacion,
            fmtDt(t.abierto_en),
            fmtDt(t.cerrado_en),
            t.estado,
            Number(t.monto_inicial    || 0),
            Number(t.ingreso_efectivo || 0),
            Number(t.gastos_total     || 0),
            Number(t.total_efectivo   || 0),
            Number(t.total_tarjeta    || 0),
            Number(t.total_qr         || 0),
            Number(t.total_otros      || 0),
            Number(t.total_ventas     || 0),
            Number(t.total_pedidos    || 0),
            Number(t.caja_esperada    || 0),
            Number(t.caja_real        || 0),
            Number(t.diferencia       || 0)
          ];
        });

        var ws = XLSX.utils.aoa_to_sheet([headers].concat(rows));
        ws['!cols'] = colWidths([8,18,16,18,18,10,13,13,13,14,14,12,12,13,11,13,11,11]);
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Turnos');

        var desde = document.getElementById('rp-desde').value;
        var hasta = document.getElementById('rp-hasta').value;
        dl(wb, 'caja-turnos-' + desde + '_a_' + hasta + '.xlsx');
      })
      .catch(function(e){ alert('Error de red: ' + e.message); });
  };

})();
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
