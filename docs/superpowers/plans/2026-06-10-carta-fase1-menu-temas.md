# Carta Fase 1 — Menú solo-lectura: temas día/noche + toggle + fuentes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir `carta/menu.php` (menú solo-lectura) a un sistema de temas día/noche conmutable, con fuentes servidas desde Lima y descripciones más legibles, sin regresión visual del modo oscuro actual.

**Architecture:** El menú renderiza por JS (`api/carta.php`). Se introduce un sistema de variables CSS gobernado por `<html data-theme="noche|dia">`. Primero se tokeniza preservando el look oscuro actual (refactor sin cambio visual), luego se añade el tema crema y un toggle que resuelve el tema por localStorage o por hora.

**Tech Stack:** PHP 8, CSS custom properties, JS vanilla, fuentes locales en `assets/fonts/`.

**Rama:** `rediseno-carta`. **Verificación:** principalmente visual (preview en navegador) + `php -l`. No hay suite de tests automatizada para vistas; cada tarea termina con una verificación visual concreta y un commit.

---

## Estructura de archivos

- Modify: `carta/menu.php` — toda la Fase 1 ocurre aquí (CSS `:root`/temas, `@font-face`, header con toggle, JS de tema, tamaño de descripción).
- Create: `assets/fonts/Arial_Narrow_Bold.ttf` — fuente condensada traída de producción.
- Decisión de fuente: `Kimmy` (ver Tarea 1).

No se tocan `api/carta.php`, el admin, ni `carta/index.php` en esta fase.

---

## Tarea 1: Fuentes locales

**Files:**
- Create: `assets/fonts/Arial_Narrow_Bold.ttf`
- Modify: `carta/menu.php:23-33` (bloque `@font-face`)

**Contexto:** Hoy `menu.php` declara `@font-face` apuntando a `/marcona/fonts/Kimmy.woff2` (404 en prod) y `/marcona/fonts/Arial_Narrow_Bold.ttf` (200 en prod). `Kimmy` ya no carga (cae a fallback). `ArialNarrowBold` se usa en títulos de sección, nombres y precios.

**Decisión `Kimmy` (requiere confirmación del usuario antes de ejecutar):** como el archivo no existe ni local ni en prod, se reemplaza su uso por **`Gilroy-Bold`** (ya presente en `assets/fonts/`, display bold de marca). En la Fase 1 `Kimmy` no se usa en `menu.php` salvo la declaración `@font-face`, así que basta con eliminar esa declaración. (El uso real de `Kimmy` está en `carta/index.php`, que se aborda en la Fase 2.)

- [ ] **Step 1: Traer ArialNarrowBold a Lima desde producción**

Run:
```bash
curl -fsS https://elgringo.pe/marcona/fonts/Arial_Narrow_Bold.ttf -o assets/fonts/Arial_Narrow_Bold.ttf && ls -la assets/fonts/Arial_Narrow_Bold.ttf
```
Expected: archivo creado, tamaño > 0 bytes (≈ varias decenas de KB).

- [ ] **Step 2: Reescribir el bloque `@font-face` de menu.php**

Reemplazar `carta/menu.php` líneas 23-33 (las dos declaraciones `@font-face` actuales) por:

```css
    @font-face {
      font-family: 'ArialNarrowBold';
      src: url('<?= APP_URL ?>/assets/fonts/Arial_Narrow_Bold.ttf') format('truetype');
      font-display: swap;
    }
    @font-face {
      font-family: 'GilroyBold';
      src: url('<?= APP_URL ?>/assets/fonts/Gilroy-Bold.ttf') format('truetype');
      font-display: swap;
    }
```

(Se elimina la declaración de `Kimmy`; se añade `GilroyBold` disponible por si se quiere usar como display.)

- [ ] **Step 3: Verificar lint**

Run: `php -l carta/menu.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add assets/fonts/Arial_Narrow_Bold.ttf carta/menu.php
git commit -m "feat(carta): fuentes servidas desde Lima (ArialNarrowBold local, drop Kimmy roto)"
```

---

## Tarea 2: Tokenizar a variables CSS preservando el modo oscuro (sin cambio visual)

**Files:**
- Modify: `carta/menu.php` — bloque `:root` (línea ~35-46) y todos los literales de color del `<style>`.

**Contexto:** El objetivo de esta tarea es un **refactor seguro**: la página debe verse **idéntica** a hoy. Se define el set de tokens y se establece el tema `noche` con los valores actuales; se reemplazan los literales por `var(--token)`. El tema día se añade en la Tarea 3.

- [ ] **Step 1: Definir tokens base + tema noche en `:root`**

Reemplazar el bloque `:root { … }` actual por:

```css
    :root {
      --font: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    html[data-theme="noche"] {
      --bg:        #1A1A1A;   /* body, search-bar */
      --bg-deep:   #111111;   /* category-bar, bottom-bar */
      --surface:   #242424;   /* card de ítem, search inner */
      --surface-2: #2a2a2a;   /* hover, chips, mod-option, cat-pill, like-btn */
      --surface-3: #333333;   /* bordes mod-option */
      --sheet:     #1e1e1e;   /* detail sheet */
      --text:      #FFFFFF;
      --text-soft: #aaaaaa;   /* detail-desc */
      --muted:     #999999;
      --dim:       #666666;
      --faint:     #444444;
      --accent:    #FCDA13;   /* amarillo: precios, activos, acentos */
      --accent-ink:#1A1A1A;   /* texto sobre amarillo */
      --accent-tint: rgba(252,218,19,.15);
      --accent-tint-strong: rgba(252,218,19,.7);
      --pink:      #FAB8C0;
      --pink-tint: rgba(250,184,192,.2);
      --green:     #34d399;
      --green-tint: rgba(52,211,153,.15);
      --error:     #e53935;
      --border:    rgba(255,255,255,0.08);
      --hairline:  rgba(255,255,255,0.06);
      --overlay:   rgba(0,0,0,0.72);     /* dim del detail-overlay */
      --on-accent-soft: rgba(0,0,0,0.12);/* texto/badge sobre el header amarillo */
      --header-bg: var(--accent);
      --header-text: var(--accent-ink);
    }
```

- [ ] **Step 2: Reemplazar literales por tokens (mapeo)**

En todo el `<style>` de `menu.php`, sustituir cada literal por su token según esta tabla. (El antiguo `:root` usaba `--yellow/--dark/--card/--sheet/--pink/--border`; renómbralos a los nuevos.)

| Literal actual | Token |
|---|---|
| `var(--yellow)`, `#FCDA13` | `var(--accent)` |
| `var(--dark)`, `#1A1A1A` (fondo) | `var(--bg)` |
| `#1A1A1A` (texto sobre amarillo) | `var(--accent-ink)` |
| `#111` | `var(--bg-deep)` |
| `var(--card)`, `#242424` | `var(--surface)` |
| `#2a2a2a` | `var(--surface-2)` |
| `#333` | `var(--surface-3)` |
| `var(--sheet)`, `#1e1e1e` | `var(--sheet)` |
| `var(--text)`, `#fff` | `var(--text)` |
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
| `rgba(255,255,255,0.06/.07/.05/.04/.1/.18)` | `var(--hairline)` |
| `rgba(252,218,19,.15/.12/.10)` | `var(--accent-tint)` |
| `rgba(252,218,19,0.7)` | `var(--accent-tint-strong)` |
| `rgba(0,0,0,0.72)` | `var(--overlay)` |
| `rgba(0,0,0,0.12)` | `var(--on-accent-soft)` |

En `header { background: var(--yellow) }` usar `var(--header-bg)`; el `.logo { filter: brightness(0) }` y textos del header (`.schedule-badge`, `.ig-link`) usar `var(--header-text)`.

- [ ] **Step 3: Fijar el tema por defecto en el `<html>`**

En `menu.php`, cambiar la etiqueta de apertura del documento a:

```html
<html lang="es" data-theme="noche">
```

- [ ] **Step 4: Verificar lint y preview sin regresión**

Run: `php -l carta/menu.php`
Expected: `No syntax errors detected`.

Luego abrir el menú en el navegador (ver “Cómo previsualizar” al final) y confirmar que **se ve idéntico al actual** (oscuro). Revisar: header amarillo, cards, badges, hoja de detalle, barra de categorías, búsqueda, skeleton.

- [ ] **Step 5: Commit**

```bash
git add carta/menu.php
git commit -m "refactor(carta): tokenizar colores del menú a variables CSS (tema noche, sin cambio visual)"
```

---

## Tarea 3: Tema día (crema)

**Files:**
- Modify: `carta/menu.php` — añadir bloque `html[data-theme="dia"]` tras el de `noche`.

- [ ] **Step 1: Añadir el bloque del tema día**

Justo después del bloque `html[data-theme="noche"] { … }`:

```css
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
      --accent:    #1E1E1E;    /* en día el "acento" de precio es negro */
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

- [ ] **Step 2: Ajustes específicos del tema día**

El `.logo { filter: brightness(0) }` deja el logo negro; en el header oscuro del tema día se ve mal. Hacer el filtro condicional:

```css
    .logo { height: 40px; width: auto; object-fit: contain; }
    html[data-theme="noche"] .logo { filter: brightness(0); }   /* logo negro sobre header amarillo */
    html[data-theme="dia"]   .logo { filter: brightness(0) invert(1); } /* logo claro sobre header negro */
```

Verificar también que el header use `var(--header-bg)`/`var(--header-text)` (de la Tarea 2) para que en día sea negro con texto crema.

- [ ] **Step 3: Verificar lint y preview del tema día**

Run: `php -l carta/menu.php`
Expected: `No syntax errors detected`.

Forzar el tema día temporalmente cambiando `data-theme="noche"` → `data-theme="dia"` en el `<html>`, recargar y revisar legibilidad (texto negro sobre crema, cards blancas, header negro, badges, hoja de detalle blanca). Volver a `noche` al terminar.

- [ ] **Step 4: Commit**

```bash
git add carta/menu.php
git commit -m "feat(carta): tema día (crema) del menú"
```

---

## Tarea 4: Toggle día/noche con memoria y default por hora

**Files:**
- Modify: `carta/menu.php` — botón en el header + bloque `<script>` de tema (insertar lo antes posible para evitar flash).

- [ ] **Step 1: Script de resolución de tema (anti-flash) en el `<head>`**

Insertar como **primer** elemento dentro de `<head>` (antes del `<style>`), para que el tema se aplique antes de pintar:

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

(Con esto, el atributo `data-theme` del `<html>` puede quedar fijo en `noche` como respaldo; el script lo sobrescribe.)

- [ ] **Step 2: Estilos del botón toggle**

Añadir al `<style>`:

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

- [ ] **Step 3: Botón en el header**

En `menu.php`, dentro de `<header>`, justo antes del `<?php if ($ig): ?>` del enlace de Instagram, insertar (el toggle queda a la derecha del badge, junto a IG):

```html
    <button class="theme-toggle" onclick="toggleTheme()" aria-label="Cambiar tema" type="button">
      <svg class="ico-luna" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      <svg class="ico-sol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
    </button>
```

Nota: si el `.ig-link` usa `margin-left:auto`, mover ese `margin-left:auto` al `.theme-toggle` (o envolver toggle+IG en un contenedor con `margin-left:auto; display:flex; gap:10px; align-items:center`) para que ambos queden a la derecha.

- [ ] **Step 4: Función `toggleTheme()`**

Añadir al `<script>` principal de la página:

```javascript
    function toggleTheme() {
      var cur = document.documentElement.getAttribute('data-theme') === 'dia' ? 'dia' : 'noche';
      var next = cur === 'dia' ? 'noche' : 'dia';
      document.documentElement.setAttribute('data-theme', next);
      try { localStorage.setItem('carta_theme', next); } catch (e) {}
    }
```

- [ ] **Step 5: Verificar lint y comportamiento**

Run: `php -l carta/menu.php`
Expected: `No syntax errors detected`.

En el navegador: pulsar el toggle alterna crema/oscuro; recargar mantiene la última elección (localStorage); en una ventana de incógnito el tema arranca según la hora (noche si son ≥18:00 o <07:00). Confirmar que **no hay flash** del tema equivocado al cargar.

- [ ] **Step 6: Commit**

```bash
git add carta/menu.php
git commit -m "feat(carta): toggle día/noche con memoria y default por hora en el menú"
```

---

## Tarea 5: Descripciones más grandes + pulido de fila

**Files:**
- Modify: `carta/menu.php` — reglas `.item-desc` (≈línea 132-136) y `.item-name`.

**Contexto:** el usuario pidió descripciones más grandes. El layout ya es foto-izquierda + info (no requiere reestructura).

- [ ] **Step 1: Subir el tamaño de la descripción**

Cambiar la regla `.item-desc` por:

```css
    .item-desc {
      font-size: 16px; color: var(--muted); line-height: 1.45;
      overflow: hidden; display: -webkit-box;
      -webkit-line-clamp: 3; -webkit-box-orient: vertical;
    }
```

(De 15px/2 líneas a 16px/3 líneas.)

- [ ] **Step 2: Verificar lint y preview**

Run: `php -l carta/menu.php`
Expected: `No syntax errors detected`.

En el navegador: las descripciones se leen más cómodas, sin romper la altura de la card ni el precio. Revisar en móvil (ancho ~390px) y escritorio.

- [ ] **Step 3: Commit**

```bash
git add carta/menu.php
git commit -m "feat(carta): descripciones del menú más grandes (16px, 3 líneas)"
```

---

## Verificación final de la fase

- [ ] `php -l carta/menu.php` sin errores.
- [ ] Preview en ambos temas: oscuro idéntico al original; crema legible y coherente.
- [ ] Toggle alterna y recuerda; default por hora en sesión nueva; sin flash.
- [ ] ArialNarrowBold carga desde `assets/fonts/` (no desde `/marcona/`).
- [ ] Descripciones más grandes sin romper layout (móvil + escritorio).
- [ ] Quedan pendientes de sus propios planes: **Fase 2** (carta de venta `index.php` + carrito) y **Fase 3** (banner/PDF 42 cm en el admin).

## Cómo previsualizar

El menú es por ubicación (slug). Necesitas un slug de `ubicaciones` activo. Opciones:
- Si hay PHP local configurado contra la BD: `php -S localhost:8080` desde la raíz y abrir `http://localhost:8080/carta/menu.php?slug=<slug>`.
- Si no hay BD local, validar desplegando la rama a un entorno de preview, o revisando el render con datos de una ubicación real. (El render de ítems depende de `api/carta.php` con datos reales.)

> Confirmar con el usuario el slug de prueba y el método de preview antes de ejecutar la verificación visual.
