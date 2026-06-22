# Subrecetas con stock + producción por lotes · Diseño

**Fecha:** 2026-06-22
**Módulo:** Inventario / Costeo — producción y stock de subrecetas
**Estado:** diseño aprobado, pendiente de plan
**Antecede:** `2026-06-22-costeo-recetas-subrecetas-design.md` (Fase 1: subrecetas que **explotan a insumos**, sin stock propio). Esto agrega el modelo **Opción B** que la Fase 1 dejó fuera de alcance.

## Contexto

Hoy una subreceta (salsa, masa, aderezo) es una **fórmula**: al vender un producto que la usa, se **explota a insumos** y se descuentan esos insumos. No refleja que en la cocina se **prepara un lote por adelantado** y se consume durante días. Este diseño agrega, **por subreceta (opt-in)**, un modelo de **producción por lotes con stock propio**: producir un lote consume insumos y genera stock de subreceta; ese stock se despacha entre locales; y la venta descuenta del stock de subreceta en vez de explotar insumos.

## Decisiones tomadas (brainstorming)

1. **Opt-in por subreceta** (`subrecetas.lleva_stock`): las marcadas se producen y llevan stock; las demás siguen explotando a insumos (comportamiento Fase 1, sin cambios).
2. **Sin stock al vender → vende y deja stock en negativo** (nunca frena el servicio; el negativo es la alerta de "producir/despachar más"). Igual que el inventario actual.
3. **Producción simple (sin merma en v1):** ingresás cuántos lotes; consume insumos = `subreceta_items × lotes` y suma `rendimiento × lotes` al stock. (Merma = futuro.)
4. **Tablas paralelas** (`subreceta_stock` + `subreceta_movimientos`), NO se mete `subreceta_id` al ledger de insumos.
5. **Costeo teórico:** el food cost sigue usando el costo teórico vivo (`subrecetaCostoUM`, desde el costo actual de los insumos). La subreceta **no** guarda un costo de producción. El costeo (`recetaCosto`, simulador) **no cambia**.
6. **Producción en cualquier local con almacenamiento** (`ubicacionesConInventario()` = `activa=1 OR es_almacen=1`), no solo el almacén central. Consume insumos y suma stock en **ese mismo local**.

## Modelo de datos

### `subrecetas` (alterar)
```sql
ALTER TABLE subrecetas
  ADD COLUMN lleva_stock TINYINT(1) NOT NULL DEFAULT 0;   -- opt-in: produce y lleva stock
```
(NO se agrega `costo_unitario`: el costeo es teórico.)

### `subreceta_stock` — nueva (espejo de `insumo_stock`)
```sql
subreceta_id INT UNSIGNED NOT NULL
ubicacion_id INT UNSIGNED NOT NULL
stock        DECIMAL(12,3) NOT NULL DEFAULT 0.000   -- puede ir NEGATIVO
stock_min    DECIMAL(12,3) NOT NULL DEFAULT 0.000
PRIMARY KEY (subreceta_id, ubicacion_id)
KEY (ubicacion_id)
```

### `subreceta_movimientos` — nueva (ledger, espejo de `inventario_movimientos`)
```sql
id           INT UNSIGNED PK AUTO_INCREMENT
ubicacion_id INT UNSIGNED NOT NULL
subreceta_id INT UNSIGNED NOT NULL
tipo         ENUM('produccion','transferencia','venta','ajuste','merma') NOT NULL
cantidad     DECIMAL(12,3) NOT NULL          -- con signo: + entra, − sale
costo_unitario DECIMAL(10,4) NULL            -- snapshot teórico al momento (informativo, no usado en food cost)
motivo       VARCHAR(160) NULL
ref          VARCHAR(60)  NULL               -- enlaza transferencia / producción
pedido_id    INT UNSIGNED NULL
user_id      INT UNSIGNED NULL
created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
KEY (subreceta_id), KEY (ubicacion_id), KEY (pedido_id)
```

Migración `61_subrecetas_stock.sql` (idempotente; guard `subrecetaStockListo()`). Fila en `check_migraciones.sql`.

## Helpers (`includes/inventario.php`)

- `subrecetaStockListo(): bool` — guard de tabla.
- `subMovimiento(int $ubi, int $subId, string $tipo, float $cant, array $opts=[]): int` — espejo de `invMovimiento`: inserta en `subreceta_movimientos` y hace upsert de `subreceta_stock` (`ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock)`). Tolerante (try/catch → 0).
- `subProducir(int $ubi, int $subId, float $lotes): array` — **transaccional** (`Database::getInstance()`): valida que la subreceta `lleva_stock`, `rendimiento>0`, `lotes>0`; descuenta cada insumo de `subreceta_items × lotes` con `invMovimiento($ubi, insumo, 'ajuste'…)` (motivo "Producción: <subreceta>"); suma `rendimiento × lotes` con `subMovimiento($ubi,$subId,'produccion',+…)`. Devuelve `['ok'=>bool,'producido'=>float,'ref'=>string]`. Si falla una pata → rollback. (No bloquea por falta de insumos: permite negativo, coherente con el resto — pero avisa en la UI cuáles quedan bajo cero.)
- `subTransferir(int $origen, int $destino, array $subs, string $motivo='Despacho'): string` — espejo de `invTransferir` para `{subreceta_id => cantidad}` (dos patas `transferencia` con `ref` común, transaccional).
- `recetaConsumo(int $productId): array` — **núcleo de la venta**: recorre `recetaComponentes`; devuelve `['insumos'=>[insumo_id=>cant], 'subrecetas'=>[subreceta_id=>cant]]`:
  - componente insumo → `insumos`
  - subreceta con `lleva_stock=1` → `subrecetas` (se descuenta de su stock)
  - subreceta con `lleva_stock=0` → se **explota a insumos** (como hoy) y va a `insumos`
  Reemplaza a `recetaExplotaInsumos` **solo** en el descuento de stock; `recetaExplotaInsumos`/`recetaCosto` (costeo teórico) **no cambian** (explotan todo a insumos para el costo).

## Venta — cambio en `descontarStockPedido`

Por cada ítem del pedido: `recetaConsumo(product_id)` × qty →
- insumos → `invMovimiento(ubi, insumo, 'venta', −cant)` (como hoy)
- subrecetas → `subMovimiento(ubi, subreceta, 'venta', −cant, ['pedido_id'=>…])` (descuenta `subreceta_stock` del local que vende; **puede ir negativo**)
Idempotente vía `pedidos.stock_descontado` (sin cambios). Si `subreceta_stock` no migró, `subMovimiento` no rompe (try/catch).

**Eventos (v1, limitación documentada):** `eventoConsumoTeorico` y `salida_evento.php` siguen con **explosión a insumos** para TODA subreceta (no consultan `subreceta_stock`). Razón: el flujo de evento/foodtruck es separado y normalmente se le envían insumos, no stock de subreceta producido. Integrar subreceta-stock a eventos queda **fuera de alcance v1**.

## Producción (`admin/inventory/produccion.php`)

Pantalla nueva, gateada por `inv_movimientos` (acción de inventario, misma familia que `operar.php`; no se crea permiso nuevo). Mobile-first.
- Selector de **ubicación** = `ubicacionesConInventario()` (cualquier local con almacenamiento, incl. almacén central).
- Selector de **subreceta** (solo las que `lleva_stock`), con búsqueda en vivo (patrón existente).
- Input **lotes** (decimal). Muestra preview: insumos a consumir (`item × lotes`, con aviso si algún insumo queda negativo) y stock a producir (`rendimiento × lotes`).
- Confirmar → `subProducir()`. Flash con lo producido + ref. Registra en ambos ledgers.
- Link en el sidebar (grupo Inventario), tras "Operar".

## Despacho (extender `operar.php`)

El modo **Salidas/Despacho** del almacén hoy transfiere insumos (`invTransferir`). Se extiende para que el payload pueda incluir **subrecetas** además de insumos: misma pantalla, una sección extra "Subrecetas a despachar" (solo las que `lleva_stock` y con stock en el origen). Al confirmar: `invTransferir(...)` para insumos **y** `subTransferir(...)` para subrecetas, con validación de stock disponible (avisa, no bloquea salvo que el usuario lo pida). El restaurante recibe stock de subreceta.

## Visibilidad (`admin/inventory/stock.php`)

Agrega una sección/tab **"Subrecetas"** mostrando `subreceta_stock` por local (stock actual, `stock_min`, alerta de bajo/negativo), en paralelo a la de insumos. Editar `stock_min` por local (como insumos).

## Editor de subreceta (`admin/inventory/subreceta_form.php`)

- Toggle **"Se produce y lleva stock"** (`lleva_stock`). Al activarlo, aparece un campo **stock mínimo** (informativo; el stock por local se ajusta en stock/producción).
- Sin más cambios al costeo en vivo (sigue teórico).

## Costeo / food cost — sin cambios

`recetaCosto`, el simulador y la lista de recetas siguen usando el costo **teórico** (`subrecetaCostoUM` desde el costo vivo de insumos), lleve o no stock la subreceta. El stock es para **inventario/operación**, no para el costeo.

## Convenciones / seguridad

- PHP+PDO, prepared statements; `verifyCsrf()` en POST/escrituras; `requirePermission()` (`inv_movimientos` producción/despacho; `inv_recetas` editor; `inv_stock` la vista de stock). Layout admin + `brandHead()`. **Sin emojis** (símbolos/SVG). Multi-empresa.
- Transaccionalidad en `subProducir`/`subTransferir` (mueven varias filas). Negativos permitidos (coherente con inventario actual). Guards (`subrecetaStockListo()`) para degradar si falta la migración.
- Sin framework de tests: `php -l` + scripts de aserción para la lógica pura testeable (p.ej. el reparto de `recetaConsumo` con un loader inyectado) + checklist.

## Fuera de alcance (v1)
- Merma de producción (lotes teóricos vs rendimiento real).
- Stock de subrecetas en eventos/foodtruck (`salida_evento`/`eventoConsumoTeorico` siguen explotando a insumos).
- Vencimiento / lotes con fecha / trazabilidad por lote.
- Subrecetas anidadas (una subreceta dentro de otra).
- Conversión automática de subrecetas existentes (nacen `lleva_stock=0`; el usuario prende el toggle a las que toque).
- Producción que recompute costo de la subreceta (es teórico, no se almacena costo).
