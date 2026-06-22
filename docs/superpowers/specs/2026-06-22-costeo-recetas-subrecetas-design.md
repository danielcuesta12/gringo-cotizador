# Costeo & Recetas — Subrecetas + food cost · Diseño

**Fecha:** 2026-06-22
**Módulo:** Inventario / Costeo (recetas, subrecetas, food cost)
**Estado:** diseño aprobado, pendiente de plan

## Contexto

Reconstruir en el app (backeado en BD) el prototipo `asesorias/Costeo-Recetas.html`: un costeador **Insumos → Subrecetas (preps) → Recetas → food cost/margen en vivo + dashboard de ranking**. Hoy el app ya tiene `insumos` (costo base) y `recetas` (ficha técnica de un producto, solo insumos). Esto agrega **subrecetas** como segundo tipo de componente, el **food cost en vivo** en el editor, un **simulador de precio** y un **dashboard de costeo**.

Se decidió **explotar subrecetas a insumos** para stock/salida (Opción A): la subreceta no lleva stock propio; es un atado reusable de insumos que se costea y, al vender/salir, descuenta los insumos subyacentes.

## Decisiones tomadas (brainstorming)

1. **Migrar `recetas` → `receta_componentes`** (tipo `insumo|subreceta`), con backfill de lo existente como `insumo`.
2. **Dashboard de Costeo** = **página nueva** en el grupo Inventario, con **permiso nuevo `inv_costeo`**.
3. **De cero** con las recetas actuales del usuario (NO importar el JSON del prototipo).
4. **Food cost con precio jugable:** el precio en el editor es editable y recalcula food cost/margen en vivo; guardar es opcional (escribe el precio del producto). Además **precio sugerido por food cost objetivo**.
5. **Subrecetas explotan a insumos** (sin stock propio); sirven en recetas Y en la salida masiva (futuro: sumarlas como ítems que explotan).
6. **Búsqueda en vivo + crear al vuelo** (combobox `EGCombo`) en todo selector de insumo/subreceta. Sin anidar subrecetas dentro de subrecetas (v1).
7. **Sin emojis** (íconos SVG de línea); `brandHead()`; permisos; multi-empresa.

## Faseo

- **Fase 1:** subrecetas + `receta_componentes` + ficha técnica + food cost/simulador en el editor de receta + costeo (helpers/stock).
- **Fase 2:** dashboard de Costeo (ranking, KPIs, alertas) — solo lectura.

## Modelo de datos

### `subrecetas` (preps) — nueva
```sql
id          INT UNSIGNED PK AUTO_INCREMENT
nombre      VARCHAR(120) NOT NULL
unidad      VARCHAR(20)  NOT NULL DEFAULT 'unidad'   -- UM del rendimiento (K/L/U/g/ml…)
rendimiento DECIMAL(12,3) NOT NULL DEFAULT 1.000     -- cuánto produce un lote
activo      TINYINT(1) NOT NULL DEFAULT 1
created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
```
**Costo de la subreceta** = Σ(insumo.costo_unitario × cantidad). **Costo por UM** = costo total / rendimiento (lo que se usa al costear en una receta).

### `subreceta_items` — nueva (ingredientes de una subreceta = insumos)
```sql
subreceta_id INT UNSIGNED NOT NULL
insumo_id    INT UNSIGNED NOT NULL
cantidad     DECIMAL(12,3) NOT NULL DEFAULT 0.000
PRIMARY KEY (subreceta_id, insumo_id)
KEY (insumo_id)
```

### `receta_componentes` — reemplaza el rol de `recetas` (insumo **o** subreceta)
```sql
id          INT UNSIGNED PK AUTO_INCREMENT
product_id  INT UNSIGNED NOT NULL
tipo        ENUM('insumo','subreceta') NOT NULL
ref_id      INT UNSIGNED NOT NULL          -- insumo_id o subreceta_id según tipo
cantidad    DECIMAL(12,3) NOT NULL DEFAULT 0.000
UNIQUE KEY (product_id, tipo, ref_id)
KEY (product_id)
```
**Migración:** crear la tabla y **backfill** desde `recetas`: `INSERT ... SELECT product_id, 'insumo', insumo_id, cantidad FROM recetas`. La tabla `recetas` se **mantiene** por compatibilidad/transición (los lectores nuevos usan `receta_componentes`; un guard `recetaComponentesListo()` permite caer a `recetas` si la migración no está aplicada).

### `receta_ficha` — nueva (ficha técnica por producto)
```sql
product_id    INT UNSIGNED PK         -- 1:1 con products
porciones     INT NOT NULL DEFAULT 1
procedimiento TEXT NULL
montaje       TEXT NULL
notas         TEXT NULL               -- alérgenos / notas
```
(El **precio** y la **categoría** ya viven en `products`/`location_products`; no se duplican.)

### Permiso `inv_costeo`
Agregar a `includes/permissions.php` (grupo Inventario): clave `inv_costeo` → 'Costeo' → `/admin/inventory/costeo.php`. La página de subrecetas va bajo `inv_recetas` (es parte de recetas); el dashboard de costeo bajo `inv_costeo`.

### Migración
`install/60_costeo_recetas.sql`: crea `subrecetas`, `subreceta_items`, `receta_componentes` (+ backfill desde `recetas`), `receta_ficha`. Fila en `check_migraciones.sql`. Guards `subrecetasListo()`/`recetaComponentesListo()` (try/catch) para no romper si falta aplicar.

## Costeo (helpers en `includes/inventario.php`)

- `subrecetaCostoTotal(int $id): float` — Σ(insumo.costo_unitario × cantidad) de `subreceta_items`.
- `subrecetaCostoUM(int $id): float` — costo total / rendimiento (0 si rendimiento ≤ 0).
- `recetaCosto(int $productId): float` — **reescrito**: Σ componentes; insumo → costo_unitario × cantidad; subreceta → `subrecetaCostoUM` × cantidad. (Lee `receta_componentes`; fallback a `recetas` vía guard.)
- `recetaExplotaInsumos(int $productId): array` — `insumo_id => cantidad_total` explotando subrecetas a insumos (subreceta aporta `cantidad × (item.cantidad / rendimiento)` por insumo). Lo usa **`descontarStockPedido`** (vender) y, a futuro, la salida masiva. Reemplaza la lectura directa de `recetas` en el descuento de stock.
- `recetaFoodCost(float $costoPorcion, float $precioVentaConIgv, float $igvPct): array` — `['neto','fc','margen']`: neto = precio/(1+igv); fc = costoPorcion/neto; margen = (neto−costoPorcion)/neto. Semáforo: ≤0.35 ok · ≤0.42 warn · resto bad.

## Subrecetas (`admin/inventory/subrecetas.php` + editor)

Lista (nombre · nº ingredientes · costo total · **costo/UM** con badge) con búsqueda en vivo. Editor (modal o form): nombre, rendimiento, UM, y filas de ingredientes con **combobox de insumos (`EGCombo`) + crear al vuelo**; costo total y costo/UM se recalculan **en vivo** al editar cantidad/insumo. Gateada por `inv_recetas`.

## Recetas (editor — `admin/inventory/receta_form.php`, ampliado)

- Componentes: filas que eligen **insumo o subreceta** por combobox unificado (insumos + grupo "Subrecetas"), con **crear al vuelo** (insumo o subreceta nueva sin salir). Cantidad por fila; precio y costo de la fila en vivo.
- **Ficha técnica:** porciones, procedimiento, montaje, notas (`receta_ficha`). Imprimible (`@media print`).
- **Food cost en vivo + precio jugable:** campo de **precio de venta** editable (default = precio actual del producto) → recalcula **costo/porción · precio sin IGV · food cost % · margen** al instante (IGV de `getSetting('igv_pct')`). Guardar el precio es opcional (escribe el producto). **Precio sugerido:** input "food cost objetivo %" → muestra `precio sugerido = (costo/porción ÷ objetivo) × (1+IGV)` + botón "usar este precio".
- Recálculo en JS vanilla; semáforo de color en el food cost.

## Dashboard de Costeo (Fase 2 — `admin/inventory/costeo.php`, `inv_costeo`)

Solo lectura: KPIs (food cost promedio, nº platos, alertas con fc>35%), **ranking de platos por food cost** (menor = más rentable) con semáforo, usando el **precio guardado** del producto. Filtros por categoría/búsqueda.

## API (`api/inventario.php` o extender el existente de recetas)

JSON, `requireLogin()` + `can('inv_recetas')`/`can('inv_costeo')`, CSRF en escrituras. Acciones: búsqueda de insumos/subrecetas (para el combobox), **crear insumo/subreceta al vuelo**, guardar subreceta, guardar receta (componentes + ficha + precio), datos del dashboard. El `EGCombo` se apunta a este API por `window.EG_*_API`.

## Convenciones / seguridad
- PHP+PDO, prepared statements; `verifyCsrf()` en escrituras; `requirePermission()`. Layout admin + `brandHead()`. **Sin emojis** (íconos SVG de línea). Multi-empresa.
- Costos globales (insumo.costo_unitario global); food cost usa el precio del producto (per-local = refinamiento futuro).
- Stock: subrecetas **explotan a insumos** (`recetaExplotaInsumos`) — no cambia el modelo de stock por insumo.
- Sin framework de tests: `php -l` + scripts de aserción para la lógica de costeo (subrecetaCostoUM, recetaCosto, foodcost) + checklist.

## Fuera de alcance
- Stock propio de subrecetas / producción en lote (Opción B).
- Anidar subrecetas dentro de subrecetas.
- Importar el JSON del prototipo.
- Food cost por local (precios por ubicación) — refinamiento futuro.
- Sumar subrecetas como ítems explotables en la salida masiva — queda anotado, se hace cuando se toque `operar.php`.
