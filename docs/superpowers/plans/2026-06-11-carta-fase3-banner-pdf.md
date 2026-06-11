# Carta Fase 3 — Banner / PDF 42 cm para el food truck

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generar una carta imprimible tipo banner de **42 cm de ancho × alto continuo**, con fotos grandes, en tema día o noche, exportable a PDF para la imprenta; accionable desde el admin por ubicación.

**Architecture:** Página pública de impresión `carta/banner.php?slug=&theme=` que consulta los datos server-side (misma fuente que el menú: `location_products`+`products`+`categories`), renderiza HTML a 420 mm con unidades mm, y usa JS para fijar `@page { size: 420mm <alto medido> }` → una sola página continua al "Guardar como PDF". Una página-herramienta en el admin (`admin/locations/banner.php?id=`) ofrece el selector día/noche y abre el banner; un botón "Banner" por fila en la lista de ubicaciones la enlaza.

**Tech Stack:** PHP 8 (PDO via `Database`), CSS print (`@page`, unidades mm), JS vanilla. Sin librerías de PDF (se usa "Guardar como PDF" del navegador).

**Rama:** `rediseno-carta-fase3` (creada desde main). **Verificación:** `php -l` + revisión humana imprimiendo a PDF y midiendo el ancho (42 cm).

## Contexto / decisiones

- Los datos de la carta salen de la misma consulta que `api/carta.php`: `location_products lp JOIN products p JOIN categories c`, agrupado por categoría, con `p.name, p.description, p.image (→ UPLOAD_URL.image), lp.price, lp.available`. El banner solo lista ítems **disponibles** (`lp.available = 1`).
- `carta/banner.php` es **público** (sin login) — solo muestra datos de menú ya públicos; lo que es "del dueño" es el botón en el admin. Esto evita fricción de sesión al imprimir/compartir.
- Tema vía `?theme=noche|dia` (default `noche`). Reusa los mismos tokens de color día/noche de las cartas.
- Fotos: `UPLOAD_URL . p.image` (URLs absolutas), las mismas del producto. El usuario confirmó que sirven.
- Logo: `getSetting('company_logo_b','') ?: getSetting('company_logo','')` → `UPLOAD_URL . $rel` (igual que `menu.php`).

## Estructura de archivos

- Create: `carta/banner.php` — la vista imprimible 420 mm (núcleo).
- Create: `admin/locations/banner.php` — página-herramienta del admin (selector tema + abrir banner + instrucciones), modelada en `admin/locations/qr.php`.
- Modify: `admin/locations/index.php` — botón "Banner" por fila (junto a "QR").

---

## Tarea 1: `carta/banner.php` (vista imprimible 42 cm)

**Files:** Create `carta/banner.php`.

- [ ] **Step 1: Crear el archivo completo**

Crear `carta/banner.php` con exactamente este contenido:

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$slug  = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['slug'] ?? '');
$theme = ($_GET['theme'] ?? 'noche') === 'dia' ? 'dia' : 'noche';
$ubi   = $slug ? Database::fetch("SELECT * FROM ubicaciones WHERE slug = ? AND activa = 1", [$slug]) : null;
if (!$ubi) { http_response_code(404); echo 'Carta no encontrada.'; exit; }
$ubiId = (int) $ubi['id'];

$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';

$rows = Database::fetchAll(
    "SELECT c.name AS cat_name, c.sort_order AS cat_order,
            p.name AS pname, p.description AS pdesc, p.image AS pimg,
            lp.price AS price
     FROM location_products lp
     JOIN products p ON p.id = lp.product_id AND p.active = 1
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE lp.location_id = ? AND lp.available = 1
     ORDER BY c.sort_order, c.name, lp.sort_order, p.sort_order, p.name",
    [$ubiId]
);

$secs = [];
foreach ($rows as $r) {
    $cat = $r['cat_name'] ?: 'Carta';
    $secs[$cat][] = $r;
}
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= $theme ?>">
<head>
<meta charset="UTF-8">
<title>Banner · <?= clean($ubi['nombre']) ?></title>
<style>
  @font-face { font-family:'ArialNarrowBold'; src:url('<?= APP_URL ?>/assets/fonts/Arial_Narrow_Bold.ttf') format('truetype'); font-display:swap; }
  html[data-theme="noche"] {
    --bg:#161412; --surface:#211e1b; --text:#ffffff; --muted:#9a9089;
    --accent:#FFDF00; --section:#FFEFBC; --divider:rgba(255,255,255,.18); --header-bg:#FFDF00; --header-text:#1A1A1A;
  }
  html[data-theme="dia"] {
    --bg:#FFEFBC; --surface:#ffffff; --text:#1E1E1E; --muted:#7a6f55;
    --accent:#1E1E1E; --section:#1E1E1E; --divider:rgba(30,30,30,.25); --header-bg:#1E1E1E; --header-text:#FFEFBC;
  }
  * { box-sizing:border-box; margin:0; padding:0; }
  html, body { background:var(--bg); }
  body { width:420mm; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; color:var(--text); -webkit-font-smoothing:antialiased; }
  .banner-header { background:var(--header-bg); color:var(--header-text); text-align:center; padding:18mm 14mm; }
  .banner-header img { height:34mm; width:auto; object-fit:contain; }
  html[data-theme="noche"] .banner-header img { filter:brightness(0); }
  html[data-theme="dia"]   .banner-header img { filter:brightness(0) invert(1); }
  .banner-header .brandtxt { font-weight:900; font-size:18mm; letter-spacing:1mm; line-height:.95; }
  .banner-header .sub { font-size:5mm; font-weight:800; letter-spacing:3mm; margin-top:3mm; }
  .banner-body { padding:12mm 14mm 16mm; }
  .sec { margin-bottom:12mm; break-inside:avoid; }
  .sec-title { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:11mm; letter-spacing:2mm; text-transform:uppercase; color:var(--section); font-weight:700; padding-bottom:3mm; margin-bottom:6mm; border-bottom:1mm solid var(--divider); }
  .row { display:flex; gap:8mm; align-items:center; padding:5mm 0; break-inside:avoid; }
  .row + .row { border-top:.4mm solid var(--divider); }
  .row-foto { width:46mm; height:46mm; border-radius:6mm; object-fit:cover; flex-shrink:0; background:var(--surface); }
  .row-foto-ph { width:46mm; height:46mm; border-radius:6mm; flex-shrink:0; background:var(--surface); }
  .row-info { flex:1; min-width:0; }
  .row-top { display:flex; align-items:baseline; justify-content:space-between; gap:6mm; }
  .row-name { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:9mm; text-transform:uppercase; letter-spacing:.5mm; line-height:1; font-weight:700; }
  .row-price { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:10mm; font-weight:700; color:var(--accent); white-space:nowrap; }
  .row-desc { font-size:4.5mm; color:var(--muted); line-height:1.3; margin-top:1.5mm; }
  /* Botón de impresión (no sale en el PDF) */
  .printbar { position:fixed; top:10px; right:10px; z-index:10; display:flex; gap:8px; font-family:-apple-system,sans-serif; }
  .printbar button { padding:10px 16px; border:none; border-radius:10px; background:#1A1A1A; color:#fff; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 4px 14px rgba(0,0,0,.3); }
  @media print { .printbar { display:none !important; } }
</style>
</head>
<body>
  <div class="printbar"><button onclick="window.print()">Imprimir / Guardar PDF</button></div>

  <div class="banner-header">
    <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" alt="El Gringo"><?php else: ?>
      <div class="brandtxt">EL GRINGO</div><?php endif; ?>
    <div class="sub">BURGER JOINT · CARTA</div>
  </div>

  <div class="banner-body">
    <?php if (empty($secs)): ?>
      <div style="text-align:center;color:var(--muted);font-size:6mm;padding:20mm 0">Sin productos disponibles en esta ubicación.</div>
    <?php endif; ?>
    <?php foreach ($secs as $cat => $prods): ?>
    <div class="sec">
      <div class="sec-title"><?= clean($cat) ?></div>
      <?php foreach ($prods as $p): ?>
      <div class="row">
        <?php if ($p['pimg']): ?>
          <img class="row-foto" src="<?= htmlspecialchars(UPLOAD_URL . $p['pimg']) ?>" alt="">
        <?php else: ?>
          <div class="row-foto-ph"></div>
        <?php endif; ?>
        <div class="row-info">
          <div class="row-top">
            <div class="row-name"><?= clean($p['pname']) ?></div>
            <div class="row-price"><?= formatMoney((float)$p['price']) ?></div>
          </div>
          <?php if (trim((string)$p['pdesc']) !== ''): ?>
            <div class="row-desc"><?= clean($p['pdesc']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <script>
    // Fija @page a una sola página continua de 420mm de ancho × alto medido.
    window.addEventListener('load', function () {
      var px = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
      var mm = Math.ceil(px * 25.4 / 96) + 2; // px → mm a 96dpi, + colchón
      var st = document.createElement('style');
      st.textContent = '@page { size: 420mm ' + mm + 'mm; margin: 0; }';
      document.head.appendChild(st);
    });
  </script>
</body>
</html>
```

- [ ] **Step 2: Verificar lint**

Run: `php -l carta/banner.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add carta/banner.php
git commit -m "feat(carta): banner imprimible 42cm (carta/banner.php) por ubicación y tema"
```

---

## Tarea 2: `admin/locations/banner.php` (página-herramienta del admin)

**Files:** Create `admin/locations/banner.php`. (Modelada en `admin/locations/qr.php`.)

- [ ] **Step 1: Crear el archivo completo**

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

$id  = cleanInt($_GET['id'] ?? 0);
$loc = $id ? Database::fetch("SELECT * FROM ubicaciones WHERE id = ?", [$id]) : null;
if (!$loc) { flashMessage('error', 'Ubicación no encontrada.'); redirect('/admin/locations/index.php'); }

$bannerBase = APP_URL . '/carta/banner.php?slug=' . rawurlencode($loc['slug']);

$pageTitle  = 'Banner — ' . $loc['nombre'];
$activePage = 'locations';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/locations/index.php">Ubicaciones</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= clean($loc['nombre']) ?> · Banner</span>
</div>

<div class="page-header"><div class="page-header-left"><h1>Banner imprimible — <?= clean($loc['nombre']) ?></h1>
  <p>Gigantografía de 42 cm de ancho para el food truck. Elige el tema, ábrelo e imprime a PDF.</p></div></div>

<div class="card" style="max-width:560px">
  <div class="card-body">
    <label class="form-label">Tema del banner</label>
    <div style="display:flex;gap:10px;margin:6px 0 18px">
      <label style="flex:1;display:flex;align-items:center;gap:8px;border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer">
        <input type="radio" name="bannertheme" value="noche" checked> <span><strong>Nocturna</strong> · fondo oscuro</span>
      </label>
      <label style="flex:1;display:flex;align-items:center;gap:8px;border:1.5px solid var(--border);border-radius:10px;padding:12px;cursor:pointer">
        <input type="radio" name="bannertheme" value="dia"> <span><strong>Crema</strong> · fondo claro</span>
      </label>
    </div>

    <button type="button" class="btn btn-primary" onclick="abrirBanner()" style="gap:6px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
      Abrir banner para imprimir
    </button>

    <div style="margin-top:18px;font-size:13px;color:var(--text-secondary);line-height:1.7">
      <strong>Cómo sacar el PDF:</strong><br>
      1. Pulsa «Abrir banner»: se abre en una pestaña nueva.<br>
      2. Ahí pulsa «Imprimir / Guardar PDF» (o Cmd/Ctrl + P).<br>
      3. En destino elige <strong>Guardar como PDF</strong> y guarda. El PDF sale a 42 cm de ancho exacto.<br>
      4. Envía ese PDF a la imprenta. (Para mejores fotos, usa imágenes de producto en buena resolución desde <em>Productos</em>.)
    </div>
  </div>
</div>

<script>
  var BANNER_BASE = <?= json_encode($bannerBase) ?>;
  function abrirBanner() {
    var t = document.querySelector('input[name="bannertheme"]:checked');
    var theme = t ? t.value : 'noche';
    window.open(BANNER_BASE + '&theme=' + encodeURIComponent(theme), '_blank', 'noopener');
  }
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Verificar lint**

Run: `php -l admin/locations/banner.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add admin/locations/banner.php
git commit -m "feat(admin): herramienta de banner por ubicación (selector tema + abrir/imprimir)"
```

---

## Tarea 3: Botón "Banner" en la lista de ubicaciones

**Files:** Modify `admin/locations/index.php`.

- [ ] **Step 1: Añadir el botón "Banner" junto al de "QR"**

En `admin/locations/index.php`, dentro de `<div class="td-actions">`, justo DESPUÉS del enlace de QR (el `<a ... qr.php?id=...>QR</a>`) y antes del enlace de Editar, insertar:

```html
              <a href="<?= APP_URL ?>/admin/locations/banner.php?id=<?= $u['id'] ?>" class="btn btn-ghost btn-sm" title="Banner imprimible 42cm">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>Banner
              </a>
```

(La columna de acciones tiene `width:200px`; con QR + Banner + Editar + Eliminar puede apretarse. Si se ve apretado, ampliar ese `style="width:200px"` del `<th>` a `width:260px` — hacerlo solo si hace falta y reportarlo.)

- [ ] **Step 2: Verificar lint**

Run: `php -l admin/locations/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add admin/locations/index.php
git commit -m "feat(admin): botón Banner por fila en la lista de ubicaciones"
```

---

## Verificación final de la fase

- [ ] `php -l` sin errores en los tres archivos.
- [ ] (Humano, en preview) Admin → Ubicaciones → «Banner» abre la herramienta; el selector noche/crema + «Abrir banner» abre `carta/banner.php?slug=...&theme=...`.
- [ ] El banner se ve a lo ancho, con header de marca, secciones por categoría, filas foto+nombre/precio/desc grandes, en el tema elegido.
- [ ] Cmd/Ctrl+P → «Guardar como PDF» produce **una sola página** de **42 cm de ancho** (medir), con las fotos nítidas. Probar ambos temas.
- [ ] El banner lista solo ítems disponibles de la ubicación; precios con `formatMoney` (S/).

## Notas / posibles ajustes (post-QA)

- Si el PDF sale paginado en varias hojas (algún navegador ignora el `@page` con alto dinámico), validar en Chrome (mejor soporte) o ajustar el colchón de altura en el JS.
- Tamaños en mm pensados para verse de lejos; si quedan grandes/pequeños tras la primera impresión, se afinan los `font-size`/`--foto` en `banner.php`.
