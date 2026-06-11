# Carta Fase 2 — Carta de venta: temas día/noche + toggle + carrito

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Llevar a `carta/index.php` (carta de venta) el mismo sistema de temas día/noche con toggle que ya tiene el menú, incluyendo los dos carritos (lateral desktop + inferior móvil), sin tocar el pago.

**Architecture:** Mismo sistema de variables CSS gobernado por `<html data-theme="noche|dia">` que `carta/menu.php`, con el mismo `localStorage['carta_theme']` (la elección del cliente se comparte entre menú y carta de venta). Se tokeniza solo el `<style>` principal (carta + carritos), preservando el look oscuro actual en `noche`.

**Tech Stack:** PHP 8, CSS custom properties, JS vanilla. Fuentes: `ArialNarrowBold` se sirve local; `Kimmy` y `DINMed` ya van embebidas en base64 dentro del archivo (no se tocan).

**Rama:** `rediseno-carta-fase2` (ya creada desde main). **Verificación:** `php -l` + greps estructurales; la confirmación visual la hace un humano en navegador con una ubicación real (`?slug=dk-begonias`). No hay BD local.

## ALCANCE (confirmado con el usuario)

**SÍ se tematiza:** la superficie de la carta (header, ítems, categorías, búsqueda, hoja de detalle) y **los dos carritos** (`.carrito-desktop`, `.carrito-mobile` y sus subclases) — todo lo que vive en el `<style>` principal (líneas ~37–520).

**NO se toca (zona de pago / fuera de alcance):**
- El segundo bloque `<style>` con las reglas `#izipay-container .kr-*` (formulario de tarjeta Izipay, ~líneas 531–542). Queda claro a propósito.
- Cualquier estilo **inline** (`style="..."`) en el `<body>`: modales de "Pedido enviado / código GR-XXXX / pago rechazado" (azul `#1B1F4B` / dorado `#F5C200`). Se quedan como están.
- Las fuentes embebidas en base64 (`Kimmy`, `DINMed`).
- Colores **semánticos**: `#25D366` (botón WhatsApp), `#16a34a`/`#dc2626`/`#777` (puntos de estado del horario). Se mantienen fijos en ambos temas.

## Estructura de archivos

- Modify: `carta/index.php` — toda la Fase 2 aquí.
- (Sin archivos nuevos: `assets/fonts/Arial_Narrow_Bold.ttf` ya existe desde la Fase 1.)

---

## Tarea 1: Fuente ArialNarrowBold local

**Files:** Modify `carta/index.php` (bloque `@font-face` de ArialNarrowBold, ~líneas 48–53).

**Contexto:** Las `@font-face` de `Kimmy` (línea ~38) y `DINMed` (~43) usan `src: url('data:font/otf;base64,...')` — embebidas, NO se tocan. Solo la de `ArialNarrowBold` apunta a `/marcona/fonts/Arial_Narrow_Bold.ttf`.

- [ ] **Step 1: Apuntar ArialNarrowBold a Lima**

Buscar la declaración `@font-face` cuyo `font-family: 'ArialNarrowBold'` tiene `src: url('/marcona/fonts/Arial_Narrow_Bold.ttf') format('truetype');` y cambiar SOLO esa línea `src` a:

```css
      src: url('<?= APP_URL ?>/assets/fonts/Arial_Narrow_Bold.ttf') format('truetype');
```

No tocar las `@font-face` de Kimmy ni DINMed (las base64).

- [ ] **Step 2: Verificar**

Run: `php -l carta/index.php` → "No syntax errors detected".
Run: `grep -c "/marcona/" carta/index.php` → debe dar `0`.

- [ ] **Step 3: Commit**

```bash
git add carta/index.php
git commit -m "feat(carta venta): ArialNarrowBold servida desde Lima (sin /marcona)"
```

---

## Tarea 2: Sistema de tokens + tematizar el `<style>` principal (sin cambio visual en noche)

**Files:** Modify `carta/index.php` — bloque `:root` (líneas 55–66) y los literales de color del `<style>` principal (≈ líneas 67–520).

**Contexto:** Igual que se hizo en `menu.php`. Es un **refactor seguro**: en `noche` la página debe verse **idéntica** a hoy. El `:root` actual define `--yellow,--dark,--card,--card2,--text,--muted,--dim,--pink,--border,--font`.

- [ ] **Step 1: Reemplazar el `:root { ... }` (líneas 55–66) por los bloques de tema**

```css
    :root {
      --font: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    html[data-theme="noche"] {
      --bg:        #1A1A1A;
      --bg-deep:   #111111;
      --surface:   #242424;
      --surface-2: #2a2a2a;
      --surface-3: #333333;
      --sheet:     #1e1e1e;
      --text:      #FFFFFF;
      --text-soft: #aaaaaa;
      --muted:     #999999;
      --dim:       #666666;
      --faint:     #444444;
      --accent:    #FCDA13;
      --accent-ink:#1A1A1A;
      --accent-tint: rgba(252,218,19,.15);
      --accent-tint-strong: rgba(252,218,19,.7);
      --pink:      #FAB8C0;
      --pink-tint: rgba(250,184,192,.2);
      --green:     #34d399;
      --green-tint: rgba(52,211,153,.15);
      --error:     #e53935;
      --border:    rgba(255,255,255,0.08);
      --hairline:  rgba(255,255,255,0.06);
      --overlay:   rgba(0,0,0,0.72);
      --on-accent-soft: rgba(0,0,0,0.12);
      --header-bg: var(--accent);
      --header-text: var(--accent-ink);
    }
    html[data-theme="dia"] {
      --bg:        #FFEFBC;
      --bg-deep:   #f6e2a8;
      --surface:   #ffffff;
      --surface-2: #f1ede2;
      --surface-3: #e4ddca;
      --sheet:     #ffffff;
      --text:      #1E1E1E;
      --text-soft: #6f6750;
      --muted:     #7a6f55;
      --dim:       #8a7d63;
      --faint:     #b3a888;
      --accent:    #1E1E1E;
      --accent-ink:#FFEFBC;
      --accent-tint: rgba(30,30,30,.08);
      --accent-tint-strong: rgba(30,30,30,.6);
      --pink:      #b03a63;
      --pink-tint: #FFBBC8;
      --green:     #1f8a4c;
      --green-tint: rgba(31,138,76,.14);
      --error:     #c0392b;
      --border:    rgba(30,30,30,0.14);
      --hairline:  rgba(30,30,30,0.10);
      --overlay:   rgba(30,20,0,0.45);
      --on-accent-soft: rgba(255,239,188,0.7);
      --header-bg: #1E1E1E;
      --header-text: #FFEFBC;
    }
```

- [ ] **Step 2: Set the default theme on the html tag**

Cambiar `<html lang="es">` (línea ~30) por `<html lang="es" data-theme="noche">`.

- [ ] **Step 3: Tokenizar los literales del `<style>` PRINCIPAL (líneas ~67–520) según el mapeo**

Sustituir cada literal en las reglas CSS del primer `<style>` por su token. Mapeo:

| Literal actual | Token |
|---|---|
| `var(--yellow)`, `#FCDA13` | `var(--accent)` |
| `var(--dark)`, `#1A1A1A` (fondo) | `var(--bg)` |
| `#1A1A1A` (texto sobre amarillo: `.item-price`, pills activas, `.ig-link svg fill`, badges del header) | `var(--accent-ink)` |
| `#111` | `var(--bg-deep)` |
| `var(--card)`, `#242424` | `var(--surface)` |
| `var(--card2)`, `#2c2c2c`, `#2a2a2a` | `var(--surface-2)` |
| `#333` | `var(--surface-3)` |
| `#1e1e1e` | `var(--sheet)` |
| `var(--text)`, `#fff` (texto/fondo de superficie oscura) | `var(--text)` |
| `#aaa` | `var(--text-soft)` |
| `var(--muted)`, `#999` | `var(--muted)` |
| `#888`, `#666`, `#555` | `var(--dim)` |
| `#444` | `var(--faint)` |
| `var(--pink)`, `#FAB8C0` | `var(--pink)` |
| `rgba(250,184,192,.2)` | `var(--pink-tint)` |
| `#34d399` | `var(--green)` |
| `rgba(52,211,153,.15)` | `var(--green-tint)` |
| `#e53935` | `var(--error)` |
| `var(--border)`, `rgba(255,255,255,0.08)` | `var(--border)` |
| `rgba(255,255,255,0.04/.05/.06/.07/.1/.18)` (overlays/bordes blancos sutiles) | `var(--hairline)` |
| `rgba(252,218,19,.10/.12/.15)` | `var(--accent-tint)` |
| `rgba(252,218,19,0.7)` | `var(--accent-tint-strong)` |
| `rgba(0,0,0,0.72)` | `var(--overlay)` |
| `rgba(0,0,0,0.12)` | `var(--on-accent-soft)` |
| `header { background: ... }` | `var(--header-bg)` |

**Reglas de juicio y EXCLUSIONES (críticas):**
- Solo el **primer** `<style>` (≈67–520). NO tocar el segundo `<style>` (`#izipay-container .kr-*`, ~531–542).
- NO tocar ningún `style="..."` inline en el `<body>` (modales/botones de pago).
- NO tocar las `@font-face` base64.
- **Mantener fijos (no tokenizar):** `#25D366` (WhatsApp), `#16a34a` y `#dc2626` (puntos de estado open/closed), `#777` (punto neutro). Son semánticos.
- Para `.logo { ...; filter: brightness(0); }`: dejar tal cual en esta tarea (se ajusta en la Tarea 3).
- Para `#1A1A1A`: si es fondo → `var(--bg)`; si es texto sobre amarillo → `var(--accent-ink)`.
- `rgba(0,0,0,0)` (estados transparentes de inicio de overlay): dejar como están.
- Cualquier literal en el `<style>` principal no listado y que sea claramente un color de marca/superficie: mapear al token por rol y reportarlo; no dejar marca hardcodeada en reglas vivas del `<style>` principal.

- [ ] **Step 4: Verificar lint y auditoría**

Run: `php -l carta/index.php` → "No syntax errors detected".
Run (auditar marca suelta en el primer `<style>`; ignora inline/izipay/base64): `awk 'NR>=67 && NR<=520' carta/index.php | grep -nE '#FCDA13|#242424|#2c2c2c|#2a2a2a|#1e1e1e' | grep -v 'data-theme'` — los hits restantes deben estar justificados (p.ej. dentro de los bloques de token) o corregirse. Reportar lo que quede y por qué.

- [ ] **Step 5: Commit**

```bash
git add carta/index.php
git commit -m "refactor(carta venta): tokenizar carta y carritos a variables CSS (tema noche, sin cambio visual)"
```

---

## Tarea 3: Logo condicional + toggle día/noche

**Files:** Modify `carta/index.php` — regla `.logo`, `<head>`, `<header>`, y el `<script>` principal.

- [ ] **Step 1: Logo filter condicional**

Reemplazar la regla `.logo { height: 40px; width: auto; object-fit: contain; filter: brightness(0); }` por:

```css
    .logo { height: 40px; width: auto; object-fit: contain; }
    html[data-theme="noche"] .logo { filter: brightness(0); }
    html[data-theme="dia"]   .logo { filter: brightness(0) invert(1); }
```

- [ ] **Step 2: Estilos del toggle (en el primer `<style>`)**

```css
    .theme-toggle {
      width: 34px; height: 34px; border-radius: 50%;
      border: none; cursor: pointer;
      background: var(--on-accent-soft); color: var(--header-text);
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0; transition: background .15s;
    }
    .theme-toggle svg { width: 18px; height: 18px; }
    .theme-toggle .ico-sol  { display: none; }
    .theme-toggle .ico-luna { display: block; }
    html[data-theme="dia"] .theme-toggle .ico-sol  { display: block; }
    html[data-theme="dia"] .theme-toggle .ico-luna { display: none; }
```

- [ ] **Step 3: Script anti-flash como PRIMER elemento del `<head>`**

Insertar justo después de `<head>` (antes de cualquier `<meta>`/`<style>`):

```html
<script>
  (function () {
    try {
      var saved = localStorage.getItem('carta_theme');
      var theme = (saved === 'dia' || saved === 'noche') ? saved : null;
      if (!theme) { var h = new Date().getHours(); theme = (h >= 18 || h < 7) ? 'noche' : 'dia'; }
      document.documentElement.setAttribute('data-theme', theme);
    } catch (e) {
      document.documentElement.setAttribute('data-theme', 'noche');
    }
  })();
</script>
```

(Misma clave `carta_theme` que el menú: la elección del cliente se comparte entre ambas cartas.)

- [ ] **Step 4: Botón toggle en el `<header>`**

En el `<header>` (igual que en el menú), reemplazar el bloque del enlace de Instagram por un contenedor a la derecha con el toggle + IG:

```html
    <div style="margin-left:auto;display:flex;align-items:center;gap:10px">
      <button class="theme-toggle" onclick="toggleTheme()" aria-label="Cambiar tema" type="button">
        <svg class="ico-luna" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        <svg class="ico-sol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
      </button>
      <?php if ($ig): ?>
      <a class="ig-link" href="https://www.instagram.com/<?= clean($ig) ?>/" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
        @<?= clean($ig) ?>
      </a>
      <?php endif; ?>
    </div>
```

Eliminar el bloque `<?php if ($ig): ?> ... ig-link ... <?php endif; ?>` original para no duplicar el enlace.

- [ ] **Step 5: Función `toggleTheme()`**

Añadir al `<script>` principal de la página:

```javascript
    function toggleTheme() {
      var cur = document.documentElement.getAttribute('data-theme') === 'dia' ? 'dia' : 'noche';
      var next = cur === 'dia' ? 'noche' : 'dia';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('carta_theme', next); } catch (e) {}
    }
```

- [ ] **Step 6: Verificar**

Run: `php -l carta/index.php` → "No syntax errors detected".
Run: `grep -c 'class="ig-link"' carta/index.php` → `1`.
Run: `grep -c "function toggleTheme" carta/index.php` → `1`.
Run: `grep -c "carta_theme" carta/index.php` → `2` (IIFE + toggle).

- [ ] **Step 7: Commit**

```bash
git add carta/index.php
git commit -m "feat(carta venta): toggle día/noche con memoria (compartida con el menú) y anti-flash"
```

---

## Tarea 4: Revisión de coherencia del carrito en ambos temas

**Files:** Modify `carta/index.php` (solo si la revisión encuentra defectos en `dia`).

**Contexto:** Los carritos (`.carrito-desktop`, `.carrito-mobile` y subclases) ya quedaron tokenizados en la Tarea 2. Esta tarea es una revisión dirigida para cazar literales blancos/oscuros que rompan legibilidad en `dia` (como pasó con `.mods-count-tag` en el menú).

- [ ] **Step 1: Auditar literales sospechosos en reglas del carrito y afines**

Run: `awk 'NR>=67 && NR<=520' carta/index.php | grep -nE 'rgba\(255,255,255|#fff|#ddd|#e0e0e0' `
Revisar cada hit que sea un **color de texto o de borde** dentro de reglas del `<style>` principal (no inline, no izipay). Para cualquier `color:` que sea blanco/cuasi-blanco hardcodeado y quede invisible en `dia`, cambiarlo al token correcto (`var(--text)`, `var(--muted)`, `var(--dim)` o `var(--border)` según el rol). Reportar qué se cambió.

- [ ] **Step 2: Verificar paridad de tokens entre temas**

Run: `sed -n '/html\[data-theme="noche"\]/,/}/p' carta/index.php | grep -oE -- '--[a-z-]+' | sort -u > /tmp/n2.txt; sed -n '/html\[data-theme="dia"\]/,/}/p' carta/index.php | grep -oE -- '--[a-z-]+' | sort -u > /tmp/d2.txt; diff /tmp/n2.txt /tmp/d2.txt && echo "PARIDAD OK"`
Expected: "PARIDAD OK" (sin diferencias).

- [ ] **Step 3: Lint + commit (si hubo cambios)**

Run: `php -l carta/index.php` → "No syntax errors detected".
```bash
git add carta/index.php
git commit -m "fix(carta venta): legibilidad del carrito en tema día"
```
(Si la auditoría no encontró nada que corregir, omitir el commit y reportarlo.)

---

## Verificación final de la fase

- [ ] `php -l carta/index.php` sin errores; `grep -c "/marcona/"` → 0.
- [ ] Preview (humano) en `?slug=dk-begonias`: noche idéntico al actual; día legible en carta + ambos carritos.
- [ ] Toggle alterna y recuerda; la elección se comparte con el menú (misma clave `carta_theme`).
- [ ] El **formulario Izipay** sigue claro y funcional; los **modales post-pago** sin cambios; el botón **WhatsApp** sigue verde. (Probar agregar al carrito y abrir el flujo de pago sin completar, para confirmar que no se rompió nada.)
- [ ] Fuente ArialNarrowBold carga desde Lima; Kimmy/DINMed (embebidas) intactas.

## Pendiente para su propio plan

- **Fase 3:** Banner/PDF 42 cm en el admin (Admin → Ubicaciones), tema según selector.
