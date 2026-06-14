# Reportes Excel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an admin "Reportes" page that exports four rich Excel (.xlsx) reports (Pedidos, Ventas, Ítems/Modificadores, Caja) using SheetJS client-side, backed by a JSON API endpoint.

**Architecture:** A single PHP API file (`api/reportes.php`) serves JSON for three actions (`pedidos`, `categorias`, `caja`). A single admin page (`admin/reportes/index.php`) renders filters and four export buttons; all Excel building and downloading happens in vanilla JS via SheetJS CDN. The nav link is added to `admin/layout-top.php` in the Operación · POS y Cartas group, gated by `isAdmin()`.

**Tech Stack:** PHP 8 + MySQL/PDO via `Database::` helpers, SheetJS `xlsx@0.18.5` from CDN, vanilla JS (no framework), existing admin layout (`layout-top.php` / `layout-bottom.php`).

---

## File Map

| File | Action |
|------|--------|
| `api/reportes.php` | **Create** — JSON backend, three actions |
| `admin/reportes/index.php` | **Create** — admin page with filters + 4 export buttons + JS |
| `admin/layout-top.php` | **Modify** — add "Reportes" nav link in Operación group |

---

## Task 1: Create `api/reportes.php`

**Files:**
- Create: `api/reportes.php`

- [ ] **Step 1: Create the file with boilerplate, auth, and filter parsing**

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Sin permiso']); exit; }

$action = clean($_GET['action'] ?? '');
$desde  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : '';
$hasta  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : '';
$ubi    = cleanInt($_GET['ubicacion_id'] ?? 0);
$origen = in_array($_GET['origen'] ?? '', ['carta', 'pos'], true) ? $_GET['origen'] : '';

function rout(array $d): never { echo json_encode($d); exit; }
```

- [ ] **Step 2: Add the `action=pedidos` handler**

Append after the boilerplate (before the closing `?>` — there is none; the file ends with PHP):

```php
// ── action=pedidos ────────────────────────────────────────────────────────────
if ($action === 'pedidos') {
    try {
        $where  = "estado <> 'cancelado'";
        $params = [];
        if ($desde !== '') { $where .= " AND DATE(created_at) >= ?"; $params[] = $desde; }
        if ($hasta !== '')  { $where .= " AND DATE(created_at) <= ?"; $params[] = $hasta; }
        if ($ubi > 0)       { $where .= " AND ubicacion_id = ?";      $params[] = $ubi; }
        if ($origen !== '')  { $where .= " AND origen = ?";            $params[] = $origen; }

        $pedidos = Database::fetchAll(
            "SELECT p.id, p.created_at, p.origen,
                    COALESCE(u.nombre, '') AS ubicacion,
                    p.metodo_pago, p.estado, p.total, p.items_json,
                    COALESCE(NULLIF(p.cliente_nombre,''), NULLIF(p.nombre,''), p.notas_pos, '') AS cliente
             FROM pedidos p
             LEFT JOIN ubicaciones u ON u.id = p.ubicacion_id
             WHERE {$where}
             ORDER BY p.id DESC",
            $params
        );
        rout(['ok' => true, 'pedidos' => $pedidos]);
    } catch (Exception $e) {
        rout(['ok' => false, 'error' => $e->getMessage()]);
    }
}
```

- [ ] **Step 3: Add the `action=categorias` handler**

```php
// ── action=categorias ─────────────────────────────────────────────────────────
if ($action === 'categorias') {
    try {
        // Build the same pedidos WHERE (items only)
        $where  = "estado <> 'cancelado'";
        $params = [];
        if ($desde !== '') { $where .= " AND DATE(created_at) >= ?"; $params[] = $desde; }
        if ($hasta !== '')  { $where .= " AND DATE(created_at) <= ?"; $params[] = $hasta; }
        if ($ubi > 0)       { $where .= " AND ubicacion_id = ?";      $params[] = $ubi; }
        if ($origen !== '')  { $where .= " AND origen = ?";            $params[] = $origen; }

        $rows = Database::fetchAll(
            "SELECT items_json FROM pedidos WHERE {$where}",
            $params
        );

        // Build product→category maps
        $prods     = Database::fetchAll(
            "SELECT p.id, p.name AS pnombre,
                    COALESCE(c.name, 'Sin categoría') AS cat
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id"
        );
        $catById   = [];  // int id  → string cat
        $catByName = [];  // lowercase name → string cat
        foreach ($prods as $pr) {
            $catById[(int)$pr['id']] = $pr['cat'];
            $catByName[mb_strtolower(trim($pr['pnombre']))] = $pr['cat'];
        }

        // Aggregation structures
        $cats      = [];  // [cat] => ['qty'=>int, 'monto'=>float, 'items'=>[nombre=>['qty','total']]]
        $totalQty  = 0;
        $totalMonto = 0.0;
        $mods      = [];  // [nombre] => ['qty'=>int, 'ingreso'=>float]
        $modTotalQty    = 0;
        $modTotalIngreso = 0.0;

        foreach ($rows as $row) {
            $items = json_decode($row['items_json'] ?? '[]', true);
            if (!is_array($items)) continue;

            foreach ($items as $it) {
                $pid   = (int)($it['id'] ?? $it['producto_id'] ?? 0);
                $nombre = trim($it['nombre'] ?? '?');
                $qty   = (int)($it['qty'] ?? 1);
                $total = (float)($it['precio_total'] ?? $it['subtotal']
                         ?? (($it['precio'] ?? 0) * $qty));
                $cat   = $catById[$pid]
                      ?? ($catByName[mb_strtolower($nombre)] ?? 'Sin categoría');

                // Category aggregate
                if (!isset($cats[$cat])) {
                    $cats[$cat] = ['qty' => 0, 'monto' => 0.0, 'items' => []];
                }
                $cats[$cat]['qty']   += $qty;
                $cats[$cat]['monto'] += $total;
                $totalQty            += $qty;
                $totalMonto          += $total;

                // Item within category
                if (!isset($cats[$cat]['items'][$nombre])) {
                    $cats[$cat]['items'][$nombre] = ['qty' => 0, 'total' => 0.0];
                }
                $cats[$cat]['items'][$nombre]['qty']   += $qty;
                $cats[$cat]['items'][$nombre]['total'] += $total;

                // Modifiers
                foreach (($it['modificadores'] ?? []) as $mod) {
                    $mn = trim($mod['nombre'] ?? '');
                    if ($mn === '') continue;
                    $mp = (float)($mod['precio'] ?? $mod['precio_adicional'] ?? 0);
                    if (!isset($mods[$mn])) {
                        $mods[$mn] = ['qty' => 0, 'ingreso' => 0.0];
                    }
                    $mods[$mn]['qty']     += $qty;
                    $mods[$mn]['ingreso'] += $mp * $qty;
                    $modTotalQty          += $qty;
                    $modTotalIngreso      += $mp * $qty;
                }
            }
        }

        // Sort: categories by monto desc
        uasort($cats, fn($a, $b) => $b['monto'] <=> $a['monto']);

        // Build output array + sort items within each cat by qty desc
        $categoriasOut = [];
        foreach ($cats as $catNombre => $cData) {
            uasort($cData['items'], fn($a, $b) => $b['qty'] <=> $a['qty']);
            $itemsArr = [];
            foreach ($cData['items'] as $iNombre => $iData) {
                $itemsArr[] = ['nombre' => $iNombre, 'qty' => $iData['qty'], 'total' => $iData['total']];
            }
            $categoriasOut[] = [
                'categoria' => $catNombre,
                'qty'       => $cData['qty'],
                'monto'     => $cData['monto'],
                'items'     => $itemsArr,
            ];
        }

        // Sort mods by qty desc
        uasort($mods, fn($a, $b) => $b['qty'] <=> $a['qty']);
        $modsOut = [];
        foreach ($mods as $mn => $md) {
            $modsOut[] = ['nombre' => $mn, 'qty' => $md['qty'], 'ingreso' => $md['ingreso']];
        }

        rout([
            'ok'               => true,
            'categorias'       => $categoriasOut,
            'total_qty'        => $totalQty,
            'total_monto'      => $totalMonto,
            'modificadores'    => $modsOut,
            'mod_total_qty'    => $modTotalQty,
            'mod_total_ingreso' => $modTotalIngreso,
        ]);
    } catch (Exception $e) {
        rout(['ok' => false, 'error' => $e->getMessage()]);
    }
}
```

- [ ] **Step 4: Add the `action=caja` handler and fallback**

```php
// ── action=caja ───────────────────────────────────────────────────────────────
if ($action === 'caja') {
    try {
        $where  = '1=1';
        $params = [];
        if ($desde !== '') { $where .= " AND DATE(t.abierto_en) >= ?"; $params[] = $desde; }
        if ($hasta !== '')  { $where .= " AND DATE(t.abierto_en) <= ?"; $params[] = $hasta; }
        if ($ubi > 0)       { $where .= " AND t.ubicacion_id = ?";      $params[] = $ubi; }

        $turnos = Database::fetchAll(
            "SELECT t.*,
                    COALESCE(u.name,    '') AS cajero,
                    COALESCE(ub.nombre, '') AS ubicacion
             FROM pos_turnos t
             LEFT JOIN users       u  ON u.id  = t.usuario_id
             LEFT JOIN ubicaciones ub ON ub.id = t.ubicacion_id
             WHERE {$where}
             ORDER BY t.id DESC",
            $params
        );
        rout(['ok' => true, 'turnos' => $turnos]);
    } catch (Exception $e) {
        rout(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── Unknown action ────────────────────────────────────────────────────────────
rout(['ok' => false, 'error' => 'Acción desconocida']);
```

- [ ] **Step 5: Lint the file**

```bash
php -l /Users/daniel/Documents/Proyectos/elgringo-cotizador/api/reportes.php
```

Expected output: `No syntax errors detected in api/reportes.php`

---

## Task 2: Create `admin/reportes/index.php`

**Files:**
- Create: `admin/reportes/index.php` (directory `admin/reportes/` must be created first)

- [ ] **Step 1: Create the directory**

```bash
mkdir -p /Users/daniel/Documents/Proyectos/elgringo-cotizador/admin/reportes
```

- [ ] **Step 2: Write the PHP scaffold + HTML filters card**

Create `/Users/daniel/Documents/Proyectos/elgringo-cotizador/admin/reportes/index.php`:

```php
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
  function totalRow(label, cols, numCols) {
    /* Build a TOTAL row: label in first col, then blanks until numCols, with totals provided inline */
    return [label].concat(numCols.slice(1));
  }

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

          if (!byDia[fecha])    byDia[fecha]    = {n: 0, total: 0};
          byDia[fecha].n++;   byDia[fecha].total   += tot;

          if (!byMetodo[met])  byMetodo[met]   = {n: 0, total: 0};
          byMetodo[met].n++; byMetodo[met].total  += tot;

          if (!byUbi[ubi])     byUbi[ubi]      = {n: 0, total: 0};
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
            Number(t.monto_inicial   || 0),
            Number(t.ingreso_efectivo || 0),
            Number(t.gastos_total    || 0),
            Number(t.total_efectivo  || 0),
            Number(t.total_tarjeta   || 0),
            Number(t.total_qr        || 0),
            Number(t.total_otros     || 0),
            Number(t.total_ventas    || 0),
            Number(t.total_pedidos   || 0),
            Number(t.caja_esperada   || 0),
            Number(t.caja_real       || 0),
            Number(t.diferencia      || 0)
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
```

- [ ] **Step 3: Lint the PHP file**

```bash
php -l /Users/daniel/Documents/Proyectos/elgringo-cotizador/admin/reportes/index.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Check JS syntax**

Run from the project root:

```bash
node -e '
const fs=require("fs");
const h=fs.readFileSync("admin/reportes/index.php","utf8");
const m=h.match(/<script>([\s\S]*?)<\/script>/g)||[];
let js=m.map(s=>s.replace(/<\/?script>/g,"")).join("\n;\n")
  .replace(/<\?=[\s\S]*?\?>/g,"0")
  .replace(/<\?php[\s\S]*?\?>/g,"");
fs.writeFileSync("/tmp/rep.js",js);
require("child_process").execSync("node --check /tmp/rep.js",{stdio:"inherit"});
console.log("JS OK");
'
```

Expected: `JS OK`

---

## Task 3: Add "Reportes" nav link to `admin/layout-top.php`

**Files:**
- Modify: `admin/layout-top.php` (lines 114–180, the Operación · POS y Cartas group)

- [ ] **Step 1: Locate the POS · Clientes link block**

The link for `pos_clientes` ends around line 162 with `</a>`. The "Reportes" link will be inserted **after** this `</a>` and **before** the `cartas_pdf` link.

- [ ] **Step 2: Add the guard to the group's OR-condition**

The group's outer `if` guard is on line 115:
```php
<?php if (can('pos_terminal') || can('pedidos') || can('kds') || can('pos_monitor') || can('pos_caja') || can('pos_clientes') || can('cartas_pdf') || can('pos_metodos')): ?>
```

Append `|| isAdmin()` so the section shows when only admin is logged in. Replace the condition:

```php
<?php if (can('pos_terminal') || can('pedidos') || can('kds') || can('pos_monitor') || can('pos_caja') || can('pos_clientes') || can('cartas_pdf') || can('pos_metodos') || isAdmin()): ?>
```

- [ ] **Step 3: Insert the Reportes link after pos_clientes and before cartas_pdf**

Find this block (around line 157–162):
```php
        <?php if (can('pos_clientes')): ?>
        <a href="<?php echo APP_URL; ?>/admin/pos/clientes.php"
           class="nav-link <?php echo ($activePage??'')==='pos-clientes'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span> POS · Clientes
        </a>
        <?php endif; ?>
```

After the `<?php endif; ?>` of `pos_clientes`, insert:

```php
        <?php if (isAdmin()): ?>
        <a href="<?php echo APP_URL; ?>/admin/reportes/index.php"
           class="nav-link <?php echo ($activePage??'')==='reportes'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="8" rx="1"/><rect x="12" y="6" width="3" height="12" rx="1"/><rect x="17" y="13" width="3" height="5" rx="1"/></svg></span> Reportes
        </a>
        <?php endif; ?>
```

- [ ] **Step 4: Lint layout-top.php**

```bash
php -l /Users/daniel/Documents/Proyectos/elgringo-cotizador/admin/layout-top.php
```

Expected: `No syntax errors detected`

---

## Task 4: Final verification

- [ ] **Step 1: Lint all three modified/created files**

```bash
php -l /Users/daniel/Documents/Proyectos/elgringo-cotizador/api/reportes.php
php -l /Users/daniel/Documents/Proyectos/elgringo-cotizador/admin/reportes/index.php
php -l /Users/daniel/Documents/Proyectos/elgringo-cotizador/admin/layout-top.php
```

All three expected: `No syntax errors detected`

- [ ] **Step 2: JS check**

```bash
node -e '
const fs=require("fs");
const h=fs.readFileSync("admin/reportes/index.php","utf8");
const m=h.match(/<script>([\s\S]*?)<\/script>/g)||[];
let js=m.map(s=>s.replace(/<\/?script>/g,"")).join("\n;\n")
  .replace(/<\?=[\s\S]*?\?>/g,"0")
  .replace(/<\?php[\s\S]*?\?>/g,"");
fs.writeFileSync("/tmp/rep.js",js);
require("child_process").execSync("node --check /tmp/rep.js",{stdio:"inherit"});
console.log("JS OK");
'
```

Expected: `JS OK`

- [ ] **Step 3: Confirm no git commit was made**

```bash
git -C /Users/daniel/Documents/Proyectos/elgringo-cotizador status
```

Expected: new untracked files visible, nothing committed.
