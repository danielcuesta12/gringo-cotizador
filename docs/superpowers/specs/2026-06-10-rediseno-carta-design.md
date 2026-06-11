# Rediseño de la Carta — Diseño

**Fecha:** 2026-06-10
**Rama:** `rediseno-carta`
**Estado:** Diseño aprobado (validado con mockups en companion visual)

---

## Contexto

Lima (`elgringo-cotizador`) tiene dos cartas públicas, traídas "tal cual" de Marcona y nunca rediseñadas:

- `carta/menu.php` — **menú solo-lectura** (740 líneas). Renderiza productos por JS: `fetch('/api/carta.php?ubicacion_id=' + CARTA_ID)` → `buildSeccion()` → `innerHTML`. Tema oscuro.
- `carta/index.php` — **carta de venta** (1851 líneas). Carrito, pedido por WhatsApp e Izipay. Mismo lenguaje visual oscuro.

Ambas son **por ubicación** (slug → tabla `ubicaciones`), con precios/disponibilidad por local (`location_products`). Las fuentes (`Kimmy`, `ArialNarrowBold`) se referencian desde `/marcona/fonts/…` (cruzado al deploy de Marcona) — **rotas si Marcona no está**.

Ahora que el sistema de marca tiene una paleta definida (crema `#FFEFBC` · rosa `#FFBBC8` · amarillo `#FFDF00` · negro `#1E1E1E`), se rediseñan ambas cartas con un sistema visual común.

## Objetivos

1. Un **sistema de temas** común a ambas cartas: **noche** (oscuro cálido) y **día** (crema), conmutable por el cliente.
2. Un **layout de fila** unificado (foto + nombre/desc/precio al lado), usado en pantalla y en impresión.
3. Una **carta imprimible tipo banner** de **42 cm de ancho × alto libre**, con fotos grandes, legible de lejos, para el food truck. Exportable a PDF respetando el tema activo.
4. Traer las **fuentes** a Lima y corregir los `@font-face`.

## No-objetivos (fuera de alcance)

- No se toca la **lógica de pago** (Izipay / WhatsApp) ni el modelo de datos del menú — solo se re-tematiza la UI.
- No se cambia el **admin** ni `api/carta.php` (el contrato de datos se mantiene).
- No se rediseña el flujo de pedido; solo su apariencia.

---

## Dirección visual (validada)

Se exploraron 3 direcciones (A nocturna premium, B crema editorial, C pop cartel). Se eligió un **sistema de dos temas** que combina A y B como **día/noche**, sobre un layout único.

### Sistema de temas

`<html data-theme="noche|dia">` controla todo vía variables CSS. El markup (generado por JS) es único; el tema solo cambia colores. Tokens:

| Token | noche | día (crema) |
|---|---|---|
| `--bg` | `#161412` | `#FFEFBC` |
| `--surface` (card/hoja) | `#211e1b` / hoja `#1e1e1e` | filas planas con divisor / hoja carrito `#ffffff` |
| `--text` | `#ffffff` | `#1E1E1E` |
| `--muted` (descripción) | `#8a8178` | `#7a6f55` |
| `--accent` (precio, controles) | `#FFDF00` | `#1E1E1E` |
| `--header-bg` / `--header-text` | `#FFDF00` / `#1A1A1A` | `#1E1E1E` / `#FFEFBC` |
| `--divider` | `rgba(255,255,255,.07)` | `rgba(30,30,30,.16)` |
| label de sección | crema `#FFEFBC` | negro con subrayado de 2–3px |
| botón Izipay | borde tenue | relleno amarillo `#FFDF00` |

### Toggle día/noche

- Control **sol/luna** en el header.
- Persiste la elección en `localStorage` (clave `carta_theme`).
- **Primera visita:** arranca por la hora — `noche` si la hora local es ≥ 18 o < 7, si no `día`. Después, la elección del cliente manda siempre.
- Aplica a ambas cartas (solo-lectura y venta).

### Layout de fila (compartido pantalla + banner)

- **Foto cuadrada a la izquierda** + a la derecha: nombre (mayúsculas, `ArialNarrowBold`), **descripción** y precio.
- **Descripción más grande** que hoy: ~16px en pantalla (hoy 15px con recorte a 2 líneas) y permitir hasta 3 líneas. En el banner, escala proporcional.
- Mismo componente de fila en menú, carta de venta y banner; solo cambia el tamaño.

### Carrito (carta de venta)

Re-tematizado, misma funcionalidad actual:
- **Barra flotante** colapsada: "🛒 N productos · S/ total · toca para ver tu pedido".
- **Hoja del pedido**: por línea foto + nombre + adicionales + **stepper de cantidad** [− n +] + precio; subtotal/total; botones **Pedir por WhatsApp** (verde) y **Pagar con Izipay** (acento del tema).
- En noche: hoja oscura, acentos amarillos. En día: hoja blanca sobre crema, Izipay amarillo.

### Banner / PDF (42 cm)

- Vista de impresión `carta/banner.php?slug=XXX&theme=noche|dia` a **420 mm de ancho exacto**, una sola página continua (`@page { size: 420mm <alto>; margin: 0 }`), fotos grandes, mismo layout de fila a gran escala, legible de lejos.
- **Flujo:** el banner dispara `window.print()` → el usuario elige "Guardar como PDF" → Chrome/Safari respetan el `@page` y producen el PDF a 42 cm exactos → se manda a la imprenta. Se descarta TCPDF (reproduce mal HTML/CSS y fotos); HTML+print da fidelidad total a cambio de un paso manual de guardado (normal para mandar a imprenta desde una compu).
- **Es herramienta del dueño, no del cliente:** el botón "Ver/Descargar banner" va en **`Admin → Ubicaciones`** (por local), con su propio selector día/noche para sacar crema, nocturno o ambos. La carta pública NO expone descarga de PDF.
- Por ubicación (usa los mismos datos por slug).

### Fotos (pipeline)

- La carta consume la foto del producto: `products` → `location_products` → campo imagen (`pimg` → `UPLOAD_URL . pimg`). Se gestionan en **`Admin → Productos → editar → foto`**.
- `uploadImage()` **no redimensiona ni comprime**: guarda tal cual, límite **2 MB**.
- En el banner las fotos son cuadradas junto al texto (~10-12 cm), no a sangre completa → ~800-1500 px de lado bastan. Las fotos actuales muy probablemente sirven; si alguna se ve pixelada, se reemplaza por una mayor en el mismo sitio.
- **No** se añade un campo de "alta resolución" aparte (no hace falta con este layout).

### Fuentes

- Traer `Kimmy.woff2` + `Arial_Narrow_Bold.ttf` (y cualquier otra referenciada por la carta de venta — auditar, p.ej. Gilroy/DINMed) a `assets/fonts/` de Lima y corregir los `@font-face` para que apunten ahí, no a `/marcona/fonts/`.

---

## Arquitectura / componentes

- **CSS de temas**: un bloque de variables por `[data-theme]`, compartido. Idealmente extraído a un parcial reutilizable por ambas cartas y el banner (evitar duplicar tokens en tres archivos).
- **JS de tema**: pequeño módulo que (1) resuelve el tema inicial (localStorage → hora), (2) aplica `data-theme`, (3) maneja el toggle. Reutilizable.
- **Render de ítems**: se mantiene (`api/carta.php` → `buildSeccion`); solo cambian clases/markup de la fila para el nuevo layout.
- **Banner**: nuevo `carta/banner.php?slug=&theme=` que reusa el mismo CSS de fila + tokens, con hoja de estilo de impresión a 420mm.

## Flujo de datos

Sin cambios de contrato: `api/carta.php?ubicacion_id=ID` devuelve secciones + productos (con foto, nombre, descripción, precio, adicionales). El front renderiza. El banner consume los mismos datos.

---

## Plan de trabajo (fases)

1. **Fase 1 — Menú solo-lectura.** Sistema de temas + toggle + fuentes locales en `carta/menu.php`. Layout de fila con descripción más grande. Preview en rama y validación.
2. **Fase 2 — Carta de venta.** Aplicar el mismo sistema de temas a `carta/index.php`, incluyendo el carrito (barra flotante + hoja).
3. **Fase 3 — Banner/PDF.** `carta/banner.php` a 42 cm + botón "Descargar PDF" que respeta el tema, en ambas cartas.

Cada fase se valida en preview antes de continuar. Merge a `main` solo al final, tras revisión (main = producción).

## Criterios de éxito

- El cliente puede alternar día/noche y la elección se recuerda entre visitas.
- En la primera visita el tema arranca acorde a la hora.
- Las dos cartas comparten layout y tokens (un solo sistema, sin estilos duplicados divergentes).
- Las descripciones se leen más cómodas (más grandes) sin romper el layout.
- El carrito conserva toda su función (cantidades, adicionales, WhatsApp, Izipay) re-tematizado.
- El banner exporta a PDF a 42 cm de ancho, una página continua, fotos nítidas, en el tema elegido.
- Las fuentes cargan desde Lima (no dependen de Marcona).

## Pruebas / verificación

- Preview de cada fase en el navegador (rama), en móvil y escritorio.
- Toggle: verificar persistencia (localStorage) y default por hora.
- Carta de venta: probar agregar/quitar, cantidades, adicionales, y que WhatsApp/Izipay sigan funcionando (sin regresión).
- Banner: imprimir a PDF y revisar ancho real (42 cm) y nitidez de fotos en ambos temas.
- Fuentes: validar que cargan sin la ruta de Marcona.

## Dependencias / riesgos

- **Fotos** del banner — son las mismas del producto (Admin → Productos), guardadas sin recomprimir (≤2 MB). El usuario confirma que las actuales sirven; reemplazo puntual si alguna se pixela.
- `@page` a tamaño personalizado con alto continuo: el alto debe fijarse (calculado o suficientemente grande) para evitar paginación; validar en el navegador objetivo.
- La carta de venta es grande (1851 líneas) y toca pago — re-tematizar con cuidado para no romper la lógica.
