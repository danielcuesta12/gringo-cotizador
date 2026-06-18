# Finanzas — Gastos con categorías/subcategorías, multi-línea y conexiones

**Fecha:** 2026-06-18
**Módulo:** Finanzas (`admin/gastos/`)
**Estado:** diseño aprobado, pendiente de plan de implementación

## Contexto

Hoy el registro de gastos (`admin/gastos/`, tabla `gastos` + `gasto_categorias`) solo
maneja gastos de **empresa** y **préstamos**, con una **categoría plana** (creación rápida
inline) y **tags libres**. Es un gasto = una línea.

Se quiere convertirlo en un **módulo financiero completo y conectado**: categorías con
subcategorías (Embutidos→Tocino, Verduras→Tomate), gastos multi-línea tipo recibo, enganche
opcional con inventario, reportes, integración al dashboard y absorción de los gastos del
arqueo POS. Todo debe poder **nacer desde el registro de gastos**.

## Decisiones tomadas (brainstorming)

1. **Subcategorías ↔ inventario: híbrido.** Categoría>subcategoría son taxonomía propia para
   reportes, pero **cada línea puede opcionalmente enlazarse a un insumo** para alimentar stock.
2. **Estructura: multi-línea desde el inicio.** Cabecera (`gastos`) + líneas (`gasto_items`).
   El form arranca con **1 línea** y permite "agregar otra".
3. **Alcance del módulo completo:** reportes por categoría/subcategoría + integración al
   dashboard (utilidad) + absorción de gastos del POS. **Fuera de alcance:** presupuestos/metas.
4. **Patrón transversal obligatorio:** todo campo de selección de catálogo es un **combobox con
   búsqueda en vivo + crear ítem al vuelo** (no `<select>` plano). Aplica a categoría,
   subcategoría, insumo, proveedor.

## 1. Modelo de datos

### `gasto_categorias` (existente, sin cambios)
Categorías padre: `id`, `nombre` (único). Ej.: Embutidos, Verduras, Servicios.

### `gasto_subcategorias` (nueva)
```sql
id            INT UNSIGNED PK AUTO_INCREMENT
categoria_id  INT UNSIGNED NOT NULL  -- FK lógica a gasto_categorias
nombre        VARCHAR(80) NOT NULL
UNIQUE (categoria_id, nombre)
KEY (categoria_id)
```
Ej.: Tocino dentro de Embutidos, Tomate dentro de Verduras.

### `gastos` (cabecera — evoluciona)
Se mantiene: `tipo` (empresa/préstamo), `ubicacion_id`, `usuario_id`, `fecha`, `tags`,
`foto`, `nota`, `estado`, `pagado_at`, `pagado_por`, `created_at`.
- `monto` → pasa a ser el **total cacheado** (suma de las líneas).
- `concepto` → título/descripción opcional del recibo.
- `categoria_id` → se conserva por compatibilidad pero **deja de usarse** (baja a las líneas).
- **Nuevas columnas:**
  - `origen ENUM('manual','pos') NOT NULL DEFAULT 'manual'`
  - `turno_id INT UNSIGNED NULL` (cuando `origen='pos'`, enlaza a `pos_turnos`)
  - `proveedor_id INT UNSIGNED NULL` (opcional, enlaza a `proveedores`)

### `gasto_items` (nueva — el detalle)
```sql
id               INT UNSIGNED PK AUTO_INCREMENT
gasto_id         INT UNSIGNED NOT NULL          -- FK a gastos, ON DELETE CASCADE (lógico)
concepto         VARCHAR(200) NULL
monto            DECIMAL(10,2) NOT NULL DEFAULT 0
categoria_id     INT UNSIGNED NULL
subcategoria_id  INT UNSIGNED NULL
insumo_id        INT UNSIGNED NULL              -- enganche inventario (opcional)
cantidad         DECIMAL(12,3) NULL             -- requerido si insumo_id (para costo unitario)
inv_movimiento_id INT UNSIGNED NULL             -- idempotencia del enganche
created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
KEY (gasto_id), KEY (categoria_id), KEY (subcategoria_id)
```

### Migración de datos existentes
Por cada fila actual de `gastos`, crear **una** fila en `gasto_items` con su `monto`,
`categoria_id` y `concepto`. La cabecera conserva `monto` como total. Nada se pierde; el
histórico aparece en los reportes nuevos.

### Ejes de clasificación resultantes
- **categoría > subcategoría** (estructurado, por línea).
- **tags** (libre, transversal, a nivel cabecera) — se mantiene porque ya existe y sirve para
  etiquetas como proveedor/urgente fuera de la jerarquía.

## 2. Registro de gasto (`admin/gastos/form.php`)

**Cabecera:** tipo (solo admin; no-admin siempre préstamo), fecha, tienda, **proveedor
(opcional)**, foto (cámara/galería, se borra a los 2 meses), nota, tags.

**Líneas** (arranca con 1, botón "➕ Agregar línea", cada línea eliminable):
- `concepto` (opcional) · **`monto`** (requerido) · **categoría** · **subcategoría** ·
  botón opcional "📦 vincular a insumo" → despliega **insumo** + **cantidad**.
- **Total** auto-sumado, mostrado abajo; `gastos.monto` = suma de líneas.

**Validación:** al menos 1 línea con `monto > 0`. Si una línea tiene `insumo_id`, exige
`cantidad > 0`.

**Combobox búsqueda en vivo + crear al vuelo** (patrón transversal) en categoría,
subcategoría (filtra dentro de la categoría elegida), insumo y proveedor. La tienda también
filtrable por consistencia (aunque sean pocas).

## 3. Endpoint `api/gastos.php` (nuevo)

`requireLogin()` + `can('gastos')`; `verifyCsrf()` en escrituras (header `X-CSRF-Token`).
Acciones:
- **Lectura (para dropdowns en vivo):** `buscar_categorias`, `buscar_subcategorias`
  (param `categoria_id`), `buscar_insumos`, `buscar_proveedores` → JSON `[{id, nombre}]`.
- **Creación al vuelo:** `crear_categoria`, `crear_subcategoria` (param `categoria_id`),
  `crear_insumo` (nombre + unidad básica), `crear_proveedor` → devuelven `{id, nombre}` para
  auto-seleccionar. `crear_insumo`/`crear_proveedor` reutilizan las tablas/validaciones de
  inventario existentes.

El componente combobox (JS + CSS) se implementa una sola vez y se reutiliza en el registro de
gasto, la gestión de categorías y los filtros de reportes.

## 4. Gestión de categorías (`admin/gastos/categorias.php`, admin)

Árbol categoría > subcategoría: renombrar, mover subcategoría de categoría, eliminar (con
reasignación de los gastos afectados a "Sin categoría"/otra). Ligero; la creación principal
ocurre al vuelo desde el registro.

## 5. Reportes (`admin/gastos/reportes.php`)

Permiso `gastos`. Filtros: rango de fechas (default mes actual), tienda, tipo
(empresa/préstamo/ambos). Contenido:
- Desglose **por categoría con drill-down a subcategoría**: monto, % del total, # de gastos.
- Comparativa vs mes anterior.
- Top subcategorías.
- **Export CSV** reutilizando el patrón de `admin/export.php`.

Los montos se calculan sobre `gasto_items` (join a `gastos` para fecha/tienda/tipo/origen).

## 6. Dashboard (`admin/dashboard.php`, solo admin)

- Panel "**Gastos del mes**" (`tipo='empresa'`) con total y top categorías.
- Tarjeta **utilidad = ingresos consolidados − gastos empresa del mes**.
- Préstamos mostrados aparte (no son gasto operativo, no restan utilidad).
- **Lee solo de la tabla `gastos`/`gasto_items`** → los gastos del POS ya están absorbidos ahí,
  por lo que **no hay doble conteo** (el dashboard no vuelve a leer `pos_turnos.gastos_json`).

## 7. Absorción de gastos del POS

En `api/pos.php` → acción `cerrar_turno`: además de guardar el arqueo como hoy, por cada gasto
del turno insertar:
- un `gastos` con `origen='pos'`, `turno_id`, `ubicacion_id` del turno, `tipo='empresa'`,
  `estado='pagado'`, `fecha` = hoy, `usuario_id` = cajero;
- su `gasto_item` (concepto del gasto; `categoria_id` = NULL o una categoría por defecto
  "Caja/Operación" creada en la migración).

**El cálculo del arqueo NO cambia** (sigue usando su propio `gastos_total`/`gastos_json` para
la caja esperada). La absorción es solo para que esos gastos aparezcan en reportes/dashboard.
**Idempotencia:** `cerrar_turno` solo corre sobre un turno `estado='abierto'` y lo pasa a
`cerrado`, así que no se puede duplicar.

## 8. Enganche con Inventario (híbrido)

Al guardar una línea con `insumo_id` y `cantidad > 0`: llamar a `invEntradaCompra`
(`includes/inventario.php`) con `costo_unitario = monto / cantidad`, `ubicacion_id` de la
cabecera → **sube stock y recalcula costo promedio ponderado**. Guardar el id del movimiento en
`gasto_items.inv_movimiento_id` para **no aplicarlo dos veces**. En edición/eliminación de la
línea: revertir el movimiento previo (o crear uno compensatorio) antes de reaplicar, usando el
guard `inv_movimiento_id`. Reutiliza el módulo de inventario existente; tolerante a inventario
no instalado (`inventarioListo()`).

## 9. Permisos

- Registrar gastos y ver reportes: permiso `gastos` (no-admin solo ve/crea **sus** préstamos,
  como hoy).
- Gestión de categorías y panel del dashboard: solo admin.
- `api/gastos.php`: `requireLogin()` + `can('gastos')`, con `verifyCsrf()` en escrituras.

## 10. Migración y despliegue

Una sola migración `install/55_gastos_subcategorias_items.sql`:
- crea `gasto_subcategorias` y `gasto_items`;
- altera `gastos` (añade `origen`, `turno_id`, `proveedor_id`) con guards de columna;
- backfill: una línea en `gasto_items` por cada gasto existente;
- (opcional) inserta categoría por defecto "Caja/Operación" para la absorción del POS.

Aplicar en phpMyAdmin tras `git pull` (sin tracking automático; añadir a
`install/check_migraciones.sql`).

## Convenciones / seguridad
- PHP puro + PDO, prepared statements (`?`), nunca concatenar variables en SQL.
- `verifyCsrf()` en todo POST del admin y en escrituras de la API.
- Sanitizar con `clean*()`. `requirePermission('gastos')` / `requireAdmin()` por página.
- Marca: negro `#1E1E1E` + amarillo `#FFDF00` + rosa `#FFBBC8` + crema `#FFEFBC`.
- Mobile-first (el registro de gastos se usa desde el celular).

## Fuera de alcance (futuro)
- Presupuestos/metas por categoría con alertas.
- Refactor profundo del arqueo POS para que lea su total desde la tabla `gastos`.
- Cuentas por cobrar / ingresos manuales fuera de ventas.
