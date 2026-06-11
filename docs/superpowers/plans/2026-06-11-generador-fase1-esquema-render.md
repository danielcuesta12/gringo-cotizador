# Generador de cartas PDF · Fase 1 — Esquema + Render

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Crear las tablas del generador y el render imprimible `carta/carta-print.php` que dibuja una carta guardada (tamaños/ancho/columnas/tema por carta), validable con datos de ejemplo.

**Architecture:** Tres tablas nuevas (`cartas`, `carta_secciones`, `carta_items`). Un render propio (copia del layout probado de `carta/banner.php`) que lee esas tablas y aplica los tamaños como variables CSS, el ancho como `body{width}`, y columnas por sección. No toca nada existente.

**Tech Stack:** MySQL/InnoDB, PHP 8 (PDO via `Database`), CSS print (`@page`, mm, custom properties), JS vanilla.

**Rama:** `generador-cartas`. **Aislamiento:** solo archivos NUEVOS; no se modifica `carta/banner.php`, `admin/locations/*`, ni `products`. **Verificación:** `php -l` + revisión humana abriendo el render con la carta de ejemplo.

## Estructura de archivos

- Create: `install/cartas.sql` — esquema de las 3 tablas + una carta de ejemplo (id 1) para validar el render.
- Create: `carta/carta-print.php` — render imprimible desde las tablas (`?id=&theme=&preview=`).

---

## Tarea 1: Esquema `install/cartas.sql`

**Files:** Create `install/cartas.sql`.

- [ ] **Step 1: Crear el archivo SQL**

Crear `install/cartas.sql` con exactamente este contenido:

```sql
-- ============================================================
-- Generador de cartas PDF — cartas a medida (independiente de products/ubicaciones)
-- ============================================================
CREATE TABLE IF NOT EXISTS `cartas` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`       VARCHAR(120) NOT NULL,
  `tema`         ENUM('noche','dia') NOT NULL DEFAULT 'noche',
  `ancho_mm`     SMALLINT UNSIGNED NOT NULL DEFAULT 420,
  `size_section` DECIMAL(4,1) NOT NULL DEFAULT 24.0,
  `size_name`    DECIMAL(4,1) NOT NULL DEFAULT 18.0,
  `size_price`   DECIMAL(4,1) NOT NULL DEFAULT 16.0,
  `size_desc`    DECIMAL(4,1) NOT NULL DEFAULT 14.0,
  `size_photo`   DECIMAL(4,1) NOT NULL DEFAULT 60.0,
  `size_header`  DECIMAL(4,1) NOT NULL DEFAULT 55.0,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `carta_secciones` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `carta_id`   INT UNSIGNED NOT NULL,
  `nombre`     VARCHAR(120) NOT NULL,
  `columnas`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `sort_order` SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_secc_carta` (`carta_id`),
  CONSTRAINT `fk_secc_carta` FOREIGN KEY (`carta_id`) REFERENCES `cartas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `carta_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `carta_id`    INT UNSIGNED NOT NULL,
  `seccion_id`  INT UNSIGNED NOT NULL,
  `nombre`      VARCHAR(160) NOT NULL,
  `descripcion` VARCHAR(500) NULL,
  `precio`      DECIMAL(10,2) NOT NULL DEFAULT 0,
  `foto`        VARCHAR(255) NULL,
  `sort_order`  SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_item_carta` (`carta_id`),
  INDEX `idx_item_secc` (`seccion_id`),
  CONSTRAINT `fk_item_carta` FOREIGN KEY (`carta_id`) REFERENCES `cartas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_secc` FOREIGN KEY (`seccion_id`) REFERENCES `carta_secciones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carta de ejemplo para validar el render (id 1). Borrable luego desde el admin.
INSERT IGNORE INTO `cartas` (`id`,`nombre`,`tema`) VALUES (1, 'Carta de ejemplo', 'noche');
INSERT IGNORE INTO `carta_secciones` (`id`,`carta_id`,`nombre`,`columnas`,`sort_order`) VALUES
  (1, 1, 'Smash Burgers', 1, 1),
  (2, 1, 'Bebidas', 2, 2);
INSERT IGNORE INTO `carta_items` (`carta_id`,`seccion_id`,`nombre`,`descripcion`,`precio`,`foto`,`sort_order`) VALUES
  (1, 1, 'Gringo Smash', 'Smash 110gr, cheddar, pickles, tocino, salsa gringo', 28.90, NULL, 1),
  (1, 1, 'BBQ Smash',    'Smash 110gr, emmental, onion strings, BBQ, tocino',    28.90, NULL, 2),
  (1, 2, 'Pink Lemonade', NULL, 6.00, NULL, 1),
  (1, 2, 'Limonada Clásica', NULL, 5.00, NULL, 2);
```

- [ ] **Step 2: Commit**

```bash
git add install/cartas.sql
git commit -m "feat(generador): esquema de cartas/secciones/items + carta de ejemplo"
```

**Nota de despliegue (para el usuario, no es un step ejecutable):** aplicar `install/cartas.sql` en la BD (local y prod) antes de usar el render. Igual que otros `install/*.sql` del proyecto.

---

## Tarea 2: Render `carta/carta-print.php`

**Files:** Create `carta/carta-print.php`.

**Contexto:** Es una **copia adaptada** del layout de `carta/banner.php` (filas foto + nombre/desc + precio en columna, header, tema noche/crema, `print-color-adjust:exact`, `@page` de una página continua medida por JS). Diferencias: lee de las tablas nuevas; los tamaños vienen de la carta como variables CSS (`--sz-*`) inyectadas en `<html style>`; el ancho es `body{width:var(--ancho)}`; una sección con `columnas=2` usa grilla de 2 columnas.

- [ ] **Step 1: Crear el archivo**

Crear `carta/carta-print.php` con exactamente este contenido:

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$id      = cleanInt($_GET['id'] ?? 0);
$preview = isset($_GET['preview']);
$c       = $id ? Database::fetch("SELECT * FROM cartas WHERE id = ?", [$id]) : null;
if (!$c) { http_response_code(404); echo 'Carta no encontrada.'; exit; }
$theme = ($_GET['theme'] ?? $c['tema']) === 'dia' ? 'dia' : 'noche';

$logoRel = getSetting('company_logo_b', '') ?: getSetting('company_logo', '');
$logoUrl = $logoRel ? UPLOAD_URL . $logoRel : '';

$secs = Database::fetchAll("SELECT * FROM carta_secciones WHERE carta_id = ? ORDER BY sort_order, id", [$id]);
foreach ($secs as &$s) {
    $s['items'] = Database::fetchAll("SELECT * FROM carta_items WHERE seccion_id = ? ORDER BY sort_order, id", [(int)$s['id']]);
}
unset($s);
?>
<!DOCTYPE html>
<html lang="es" data-theme="<?= $theme ?>" style="--sz-section:<?= (float)$c['size_section'] ?>mm;--sz-name:<?= (float)$c['size_name'] ?>mm;--sz-price:<?= (float)$c['size_price'] ?>mm;--sz-desc:<?= (float)$c['size_desc'] ?>mm;--sz-photo:<?= (float)$c['size_photo'] ?>mm;--sz-header:<?= (float)$c['size_header'] ?>mm;--ancho:<?= (int)$c['ancho_mm'] ?>mm;">
<head>
<meta charset="UTF-8">
<title>Carta · <?= clean($c['nombre']) ?></title>
<style>
  @font-face { font-family:'ArialNarrowBold'; src:url('<?= APP_URL ?>/assets/fonts/Arial_Narrow_Bold.ttf') format('truetype'); font-display:swap; }
  html[data-theme="noche"] { --bg:#161412; --surface:#211e1b; --text:#ffffff; --muted:#9a9089; --accent:#FFDF00; --section:#FFEFBC; --divider:rgba(255,255,255,.18); --header-bg:#FFDF00; --header-text:#1A1A1A; }
  html[data-theme="dia"]   { --bg:#FFEFBC; --surface:#ffffff; --text:#1E1E1E; --muted:#7a6f55; --accent:#1E1E1E; --section:#1E1E1E; --divider:rgba(30,30,30,.25); --header-bg:#1E1E1E; --header-text:#FFEFBC; }
  * { box-sizing:border-box; margin:0; padding:0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  html, body { background:var(--bg); -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  body { width:var(--ancho); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; color:var(--text); -webkit-font-smoothing:antialiased; }
  .banner-header { background:var(--header-bg); color:var(--header-text); text-align:center; padding:18mm 14mm; }
  .banner-header img { height:var(--sz-header); width:auto; object-fit:contain; }
  html[data-theme="noche"] .banner-header img { filter:brightness(0); }
  html[data-theme="dia"]   .banner-header img { filter:brightness(0) invert(1); }
  .banner-header .brandtxt { font-weight:900; font-size:24mm; letter-spacing:1mm; line-height:.95; }
  .banner-body { padding:14mm 16mm 18mm; }
  .sec { margin-bottom:14mm; break-inside:avoid; }
  .sec-title { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:var(--sz-section); letter-spacing:2.5mm; text-transform:uppercase; color:var(--section); font-weight:700; padding-bottom:4mm; margin-bottom:8mm; border-bottom:1.4mm solid var(--divider); }
  .sec-rows.cols2 { display:grid; grid-template-columns:1fr 1fr; column-gap:14mm; }
  .row { display:flex; gap:10mm; align-items:center; padding:6mm 0; break-inside:avoid; }
  .sec-rows.cols1 > .row + .row { border-top:.5mm solid var(--divider); }
  .sec-rows.cols2 > .row { border-top:.5mm solid var(--divider); }
  .row-foto { width:var(--sz-photo); height:var(--sz-photo); border-radius:7mm; object-fit:cover; flex-shrink:0; background:var(--surface); }
  .row-foto-ph { width:var(--sz-photo); height:var(--sz-photo); border-radius:7mm; flex-shrink:0; background:var(--surface); }
  .row-main { flex:1; min-width:0; }
  .row-name { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:var(--sz-name); text-transform:uppercase; letter-spacing:.5mm; line-height:1; font-weight:700; }
  .row-desc { font-size:var(--sz-desc); color:var(--muted); line-height:1.3; margin-top:2.5mm; }
  .row-price { font-family:'ArialNarrowBold','Arial Narrow',Arial,sans-serif; font-size:var(--sz-price); font-weight:700; color:var(--accent); white-space:nowrap; flex-shrink:0; text-align:right; padding-left:8mm; }
  .printbar { position:fixed; top:10px; right:10px; z-index:10; display:flex; gap:8px; font-family:-apple-system,sans-serif; }
  .printbar button { padding:10px 16px; border:none; border-radius:10px; background:#1A1A1A; color:#fff; font-weight:700; font-size:14px; cursor:pointer; box-shadow:0 4px 14px rgba(0,0,0,.3); }
  @media print { .printbar { display:none !important; } }
</style>
</head>
<body>
  <?php if (!$preview): ?><div class="printbar"><button onclick="window.print()">Imprimir / Guardar PDF</button></div><?php endif; ?>

  <div class="banner-header">
    <?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl) ?>" alt="El Gringo"><?php else: ?><div class="brandtxt">EL GRINGO</div><?php endif; ?>
  </div>

  <div class="banner-body">
    <?php if (empty($secs)): ?>
      <div style="text-align:center;color:var(--muted);font-size:6mm;padding:20mm 0">Esta carta aún no tiene ítems.</div>
    <?php endif; ?>
    <?php foreach ($secs as $s): ?>
    <div class="sec">
      <div class="sec-title"><?= clean($s['nombre']) ?></div>
      <div class="sec-rows cols<?= ((int)$s['columnas'] === 2) ? '2' : '1' ?>">
        <?php foreach ($s['items'] as $p): ?>
        <div class="row">
          <?php if ($p['foto']): ?>
            <img class="row-foto" src="<?= htmlspecialchars(UPLOAD_URL . $p['foto']) ?>" alt="">
          <?php else: ?>
            <div class="row-foto-ph"></div>
          <?php endif; ?>
          <div class="row-main">
            <div class="row-name"><?= clean($p['nombre']) ?></div>
            <?php if (trim((string)$p['descripcion']) !== ''): ?><div class="row-desc"><?= clean($p['descripcion']) ?></div><?php endif; ?>
          </div>
          <div class="row-price"><?= formatMoney((float)$p['precio']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <script>
    var ANCHO_MM = <?= (int)$c['ancho_mm'] ?>;
    window.addEventListener('load', function () {
      var px = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
      var mm = Math.ceil(px * 25.4 / 96) + 2;
      var st = document.createElement('style');
      st.textContent = '@page { size: ' + ANCHO_MM + 'mm ' + mm + 'mm; margin: 0; }';
      document.head.appendChild(st);
    });
  </script>
</body>
</html>
```

- [ ] **Step 2: Verificar lint**

Run: `php -l carta/carta-print.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add carta/carta-print.php
git commit -m "feat(generador): render imprimible carta-print.php (tamaños/ancho/columnas/tema por carta)"
```

---

## Verificación final de la fase

- [ ] `php -l carta/carta-print.php` sin errores.
- [ ] (Humano, tras aplicar `install/cartas.sql`) Abrir `…/carta/carta-print.php?id=1` → se ve la carta de ejemplo: header de marca, sección "Smash Burgers" (1 columna), sección "Bebidas" (2 columnas), precios con `formatMoney`.
- [ ] `…/carta/carta-print.php?id=1&theme=dia` → versión crema.
- [ ] `…/carta/carta-print.php?id=1&preview=1` → sin el botón de imprimir (para el iframe del editor).
- [ ] Imprimir a PDF (Chrome): una página al ancho de la carta (420 mm por defecto).
- [ ] Aislamiento: `git diff main...generador-cartas` no toca `carta/banner.php`, `admin/locations/*`, ni `products`.

## Pendiente (sus propios planes)

- **Fase 2:** `api/cartas.php` (CRUD secciones/ítems, cargar-desde-ubicación, subir-foto).
- **Fase 3:** `admin/cartas/index.php` + `admin/cartas/editor.php` (editor de 2 paneles) + entrada en el sidebar.
