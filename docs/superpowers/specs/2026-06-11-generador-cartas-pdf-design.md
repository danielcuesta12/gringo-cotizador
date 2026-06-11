# Generador de cartas PDF — Diseño

**Fecha:** 2026-06-11
**Rama:** `generador-cartas`
**Estado:** Diseño aprobado (editor validado con mockup en companion visual)

---

## Contexto

La Fase 3 del rediseño creó `carta/banner.php`: genera un banner imprimible de 42 cm a partir de los datos de una **ubicación** (tabla `location_products`), accionable desde `admin/locations/`. Tamaños y ancho están **fijos** en el código.

El usuario quiere un **Generador de cartas PDF**: armar cartas a medida (cargar la de una ubicación como base + agregar/quitar ítems libremente + subir fotos + ajustar tamaños, ancho y columnas + tema) y generar el mismo banner. Como **sección propia** del admin.

**Requisito clave — aislamiento/seguridad:** el generador es un **módulo paralelo**. NO modifica `carta/banner.php`, `admin/locations/*`, ni `products`. Si el generador falla, el banner por ubicación (ya en producción) sigue intacto. El precio es algo de **código duplicado** (el layout de impresión se copia), aceptado a cambio del aislamiento.

## Objetivos

1. Sección admin "Generador de cartas PDF" con lista de cartas guardadas y un editor.
2. Editor de dos paneles: izquierda arma la carta (secciones + ítems, cargar-desde-ubicación, ítems libres con foto, quitar, reordenar); derecha preview en vivo + controles de tamaño/ancho/columnas/tema + generar PDF.
3. Cartas **guardadas y reutilizables** (editar y reimprimir cuando cambian precios).
4. Render propio que produce el banner (ancho ajustable, alto automático, tamaños por carta, columnas por sección, tema noche/crema), exportable a PDF como el banner actual.

## No-objetivos (fuera de alcance)

- No se toca `carta/banner.php` ni el módulo de ubicaciones ni `products`.
- Formato: **solo banner** (ancho configurable, alto automático). Nada de pantalla/A4 ni altura fija por ahora.
- No se "unifica" el banner por ubicación con el generador (posible a futuro, no ahora).

---

## Decisiones de diseño (aprobadas)

1. **Cargar desde ubicación = copia/snapshot.** Trae los ítems disponibles de la ubicación a la carta (agrupados por su categoría → secciones + ítems). Editarlos en el generador **no afecta** `products` ni `ubicaciones`.
2. **Ítems mezclados.** Una sección puede tener ítems cargados + ítems libres juntos.
3. **Secciones libres.** Crear, renombrar, reordenar, borrar.
4. **Fotos de ítems libres** vía `uploadImage($file, 'carta')` → `uploads/carta/`.
5. **Tamaños por carta** (mm): título de sección, nombre, precio, descripción, foto, header/logo. El render los usa en vez de valores fijos.
6. **Ancho del banner ajustable** por carta (default 420 mm). Altura **automática** (crece con el contenido).
7. **Columnas por sección** (1 ó 2), independiente entre secciones.

---

## Arquitectura / componentes

**Datos (tablas nuevas, en `install/cartas.sql`):**

```
cartas
  id            INT PK AI
  nombre        VARCHAR(120) NOT NULL
  tema          ENUM('noche','dia') NOT NULL DEFAULT 'noche'
  ancho_mm      SMALLINT NOT NULL DEFAULT 420
  size_section  DECIMAL(4,1) NOT NULL DEFAULT 24.0
  size_name     DECIMAL(4,1) NOT NULL DEFAULT 18.0
  size_price    DECIMAL(4,1) NOT NULL DEFAULT 16.0
  size_desc     DECIMAL(4,1) NOT NULL DEFAULT 14.0
  size_photo    DECIMAL(4,1) NOT NULL DEFAULT 60.0
  size_header   DECIMAL(4,1) NOT NULL DEFAULT 55.0
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

carta_secciones
  id          INT PK AI
  carta_id    INT NOT NULL (FK → cartas.id ON DELETE CASCADE)
  nombre      VARCHAR(120) NOT NULL
  columnas    TINYINT NOT NULL DEFAULT 1   -- 1 ó 2
  sort_order  SMALLINT NOT NULL DEFAULT 0

carta_items
  id          INT PK AI
  carta_id    INT NOT NULL (FK → cartas.id ON DELETE CASCADE)
  seccion_id  INT NOT NULL (FK → carta_secciones.id ON DELETE CASCADE)
  nombre      VARCHAR(160) NOT NULL
  descripcion VARCHAR(500) NULL
  precio      DECIMAL(10,2) NOT NULL DEFAULT 0
  foto        VARCHAR(255) NULL   -- ruta relativa desde uploads/ (carta/img_xxx.jpg)
  sort_order  SMALLINT NOT NULL DEFAULT 0
```

**Archivos nuevos:**
- `install/cartas.sql` — esquema.
- `admin/cartas/index.php` — lista de cartas + "Nueva carta" + eliminar.
- `admin/cartas/editor.php` — editor de dos paneles (HTML + JS).
- `api/cartas.php` — endpoints JSON del editor (POST, `verifyCsrf()`, `requireAdmin()`): crear carta, guardar meta (nombre/tema/ancho/tamaños), CRUD secciones (crear/renombrar/columnas/reordenar/borrar), CRUD ítems (crear/editar/borrar/reordenar/mover de sección), cargar-desde-ubicación, subir-foto.
- `carta/carta-print.php?id=&theme=&preview=` — render imprimible (copia del layout de `banner.php`, leyendo de las tablas nuevas + tamaños/ancho/columnas de la carta).

**Nav:** nuevo `$activePage = 'cartas-pdf'` + entrada en el sidebar de `admin/layout-top.php`.

## Flujo de datos

1. **Nueva carta** → fila en `cartas` con defaults → abre `editor.php?id=`.
2. **Cargar desde ubicación** (opcional): copia los `location_products` disponibles de la ubicación, agrupados por categoría, a `carta_secciones` + `carta_items` de esta carta (snapshot; no enlaza con products).
3. **Editar**: cada cambio (ítem, sección, tamaño, ancho, columnas, tema) hace POST a `api/cartas.php` (autosave) → el **preview** (iframe a `carta-print.php?id=&preview=1`) se recarga con debounce (~400 ms).
4. **Generar PDF**: botón abre `carta-print.php?id=&theme=` en pestaña nueva → `window.print()` → "Guardar como PDF" (igual que el banner actual).

## Render (`carta/carta-print.php`)

- Copia del layout probado de `banner.php` (filas foto + nombre/desc + precio en columna, header, tema noche/crema, `print-color-adjust:exact`, `@page` de una página continua medida por JS).
- **Diferencias:** lee de `cartas`/`carta_secciones`/`carta_items`; `body { width: <ancho_mm>mm }`; los tamaños se inyectan como variables (`--sz-section`, `--sz-name`, `--sz-price`, `--sz-desc`, `--sz-photo`, `--sz-header`) desde los campos de la carta y las reglas los usan; una sección con `columnas=2` dibuja sus ítems en grilla de 2 columnas.
- `?preview=1`: oculta el botón de imprimir (para el iframe del editor).

## Plan de construcción (fases, en rama `generador-cartas`)

1. **Esquema + render.** `install/cartas.sql` + `carta/carta-print.php`. Validable sembrando una carta a mano.
2. **API.** `api/cartas.php` con todos los endpoints (CRUD + cargar-desde-ubicación + subir-foto).
3. **Admin.** `admin/cartas/index.php` (lista/nueva/borrar) + `admin/cartas/editor.php` (dos paneles: CRUD ítems/secciones, tamaños, ancho, columnas, tema, preview, generar) + entrada en el sidebar.

## Criterios de éxito

- Crear una carta, cargar desde una ubicación, y obtener las secciones/ítems copiados (sin afectar la ubicación real).
- Agregar un ítem libre con foto, quitarlo, reordenar, renombrar secciones, cambiar una sección a 2 columnas.
- Mover sliders de tamaño y el ancho → el preview refleja el cambio.
- Generar PDF: banner al ancho elegido, alto continuo, tamaños y columnas aplicados, en el tema elegido.
- `carta/banner.php` y el módulo de ubicaciones quedan **sin cambios** (verificable: `git diff` no los toca).

## Pruebas / verificación

- `php -l` en cada archivo nuevo.
- Preview humano: editor carga, autosave funciona, preview refresca, generar PDF sale correcto en ambos temas.
- Confirmar aislamiento: el diff de la rama no modifica `carta/banner.php`, `admin/locations/*`, ni `products`/`location_products`.
- Seguridad: `verifyCsrf()` + `requireAdmin()` en todos los POST de `api/cartas.php`; sanitizar entradas (`clean`/`cleanInt`/`cleanFloat`); precios con `cleanFloat`; foto validada por `uploadImage` (2MB, jpg/png/webp).

## Dependencias / riesgos

- El usuario debe aplicar `install/cartas.sql` en su BD (local/prod) — incluir nota de despliegue, como con otros `install/*.sql`.
- Preview por iframe con recarga: si el autosave+reload se siente lento, se puede subir el debounce; aceptable para MVP.
- Código duplicado del layout de impresión (banner.php ↔ carta-print.php): intencional por aislamiento; documentar para una futura unificación opcional.
