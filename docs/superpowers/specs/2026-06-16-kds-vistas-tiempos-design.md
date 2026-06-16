# KDS — vistas (todo/tipo/categoría) + tiempos editables — Diseño

**Fecha:** 2026-06-16

## Objetivo
Mejorar el KDS con: (1) **umbrales de tiempo editables** (naranja/rojo), (2) **3 vistas** — Todo junto · Por tipo · Por categoría (esta solo admin), (3) en "Por categoría", **partir** los pedidos multi-categoría en una tarjeta por categoría y **ocultar** categorías, (4) marcar el **drag** con 📌 y permitir "soltar".

## Decisiones (confirmadas como defaults)
1. **Tiempos** → localStorage por pantalla/ubicación (`kds_tn_<ubi>`, `kds_tr_<ubi>`). Ajuste instantáneo, sin BD.
2. **Listo en pedido partido** → cada parte se marca lista localmente; cuando **todas** las partes (categorías) de ese pedido están listas en la pantalla, se llama `kds_update marcar_listo` (descuenta stock + sale). Pensado para foodtruck con UNA pantalla KDS.
3. **Categoría del ítem** → `api/kds_pedidos.php` enriquece cada ítem con `categoria` (nombre) + `categoria_id` (join products→categories). Ítems sin categoría → "Sin categoría".

## Mockup
`docs/superpowers/specs/mockups/kds-completo.html` (aprobado).

## Cambios

### `api/kds_pedidos.php`
Tras decodificar items, una sola consulta `SELECT id, category_id FROM products WHERE id IN (...)` + nombres de categoría, y se adjunta `categoria`/`categoria_id` a cada ítem. Tolerante (si falla, items quedan sin categoría → "Sin categoría").

### `admin/kds/index.php`
- PHP: `$isAdmin = isAdmin();` → `IS_ADMIN` en JS.
- **Config editable:** `cfg.tn/tr` se cargan de localStorage por ubi (default 10/20). Botón **⚙️ Tiempos** abre popover con 2 inputs (naranja/rojo) → guarda + `rKDS()`.
- **Vistas:** segmento Todo/Por tipo/Por categoría (la 3ª solo si `IS_ADMIN`). Estado `vista` en localStorage. `rKDS()` despacha:
  - **all:** render incremental actual (intacto, sin flicker).
  - **tipo / categoría:** render por **carriles** (lanes). Rebuild con firma para evitar flicker innecesario.
- **Tarjeta reutilizable** `cardHTML(p, opts)`: opts `{items, tipoTag, split, idSuffix, parte}`.
- **Split por categoría:** en vista categoría, por cada categoría con ítems del pedido se genera una tarjeta `kc-<id>-<catSlug>` con solo esos ítems + sello "✂️ parte de #NNN". "Listo" marca la parte (localStorage `kds_partes_<ubi>` = set de `id:catSlug`); al completar todas las categorías del pedido → `marcar_listo` real.
- **Ocultar categorías:** chips (solo vista categoría) → set `catOcultas` en localStorage; las filas ocultas no se muestran.
- **Drag:** las tarjetas fijadas (en `mm`) muestran 📌 y un botón **soltar** que las quita de `mm` (vuelven al orden automático). En vistas por carril, el drag reordena dentro del carril.
- `tick()` actualiza temporizadores/colores en las 3 vistas (ids únicos por tarjeta).

## No-goals
- Coordinación de "partes listas" entre varias pantallas KDS (es por pantalla; el foodtruck usa una).
- Cambiar el flujo de aceptación WhatsApp, POS, ni la BD de pedidos.

## Pruebas (manual)
1. ⚙️ cambia naranja a 5 / rojo a 8 → las tarjetas recolorean al instante; recarga → persiste.
2. Por tipo → carriles Salón/Delivery/Recojo.
3. Por categoría (admin) → carriles por categoría; pedido con 2 categorías sale como 2 tarjetas con mismo #; chips ocultan categorías.
4. Marca Listo una parte → queda lista; al marcar la última parte, el pedido sale y descuenta stock.
5. Arrastra una tarjeta → 📌 + "soltar" la devuelve al orden automático.
6. Usuario no-admin → no ve la vista "Por categoría".
