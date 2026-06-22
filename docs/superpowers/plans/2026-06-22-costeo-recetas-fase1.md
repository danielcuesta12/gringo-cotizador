# Costeo & Recetas — Fase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar subrecetas (preps) al costeo, hacer que las recetas mezclen insumos+subrecetas, y dar food cost/margen en vivo con simulador de precio en el editor de receta.

**Architecture:** Las recetas pasan de `recetas (product_id,insumo_id,cantidad)` a `receta_componentes (product_id, tipo insumo|subreceta, ref_id, cantidad)` con backfill. Las subrecetas (`subrecetas` + `subreceta_items`) son atados de insumos con rendimiento que **explotan a insumos** al costear y al descontar stock; nunca tienen stock propio. Toda lectura de stock/costo pasa por un único helper `recetaExplotaInsumos()`. El food cost del editor es JS en vivo (simulación, no persiste); la matemática pura vive en `includes/costeo.php` y está cubierta por tests.

**Tech Stack:** PHP 8.0+ puro + PDO (clase `Database`), MySQL/MariaDB, JS vanilla inline, layout admin existente. Sin frameworks, sin build.

## Global Constraints

- **Sin emojis pictográficos** en NINGUNA superficie (UI, copy, botones). Usar símbolos tipográficos (`✕ − × · → ✓`) o SVG de línea. (El placeholder 🔍 y la papelera 🗑 del editor actual son deuda; reemplazar por SVG/texto al tocar esos archivos.)
- **SQL siempre con `?`** (prepared statements); nunca concatenar variables.
- **`verifyCsrf()`** en todo POST de admin y en escrituras de API (token por header `X-CSRF-Token`).
- **`requirePermission('inv_recetas')`** en cada página nueva. Phase 1 NO crea permisos nuevos (`inv_costeo` es Fase 2).
- **Colores de marca por variable** con fallback: `var(--c-brand,#FFDF00)`, `var(--pink,#FFBBC8)`, `var(--black,#1E1E1E)`. Semáforo food cost (verde `#16a34a` ≤35% / naranja `#ca8a04` ≤42% / rojo `#dc2626`) es semántico, no de marca.
- **IGV** desde `getSetting('igv_pct','18')` (entero como porcentaje, p.ej. 18).
- **Idempotencia de migración**: `CREATE TABLE IF NOT EXISTS`, backfill guardado por `NOT EXISTS`.
- **Tolerancia a migración no aplicada**: helpers en `try/catch` que devuelven vacío/0; guards `subrecetasListo()`/`recetaComponentesListo()` para degradar a leer `recetas`.
- **Patrón de selector existente** (live-search + crear al vuelo inline contra `/api/insumos.php`) se reusa; NO se introduce una librería de combobox nueva.
- Layout admin estándar: `require config/database/helpers` → `requirePermission` → `$pageTitle`/`$activePage` → `include layout-top.php` … `include layout-bottom.php`.

## File Structure

- `includes/costeo.php` — **nuevo**. Matemática pura del costeo (sin DB): `foodCostCalc`, `precioSugerido`, `subrecetaCostoUMCalc`, `fcClase`. Único lugar testeable con aserciones.
- `tests/costeo_test.php` — **nuevo**. Script de aserción standalone (sin DB) para `includes/costeo.php`.
- `install/60_costeo_recetas.sql` — **nuevo**. Crea `subrecetas`, `subreceta_items`, `receta_componentes` (+ backfill), `receta_ficha`.
- `install/check_migraciones.sql` — **modificar**. Una fila más (#60).
- `includes/inventario.php` — **modificar**. Requiere `costeo.php`; agrega `subrecetasListo`, `recetaComponentesListo`, `subrecetaCostoTotal`, `subrecetaCostoUM`, `recetaComponentes`, `recetaExplotaInsumos`; reescribe `recetaCosto`; enruta `descontarStockPedido` y `eventoConsumoTeorico` por la explosión.
- `api/insumos.php` — **modificar**. Acción `componentes_buscar` (insumos + subrecetas con costo unificado) para el selector del editor de receta.
- `admin/inventory/subreceta_form.php` — **nuevo**. Editor de subreceta (insumos + rendimiento + costo/UM en vivo).
- `admin/inventory/subrecetas.php` — **nuevo**. Lista de subrecetas.
- `admin/layout-top.php` — **modificar**. Link "Subrecetas" en el grupo Inventario.
- `admin/inventory/receta_form.php` — **modificar**. Componentes mixtos (insumo|subreceta), ficha técnica, simulador de food cost.
- `admin/inventory/recetas.php` — **modificar**. Costo/food cost por `recetaCosto()` (incluye subrecetas) + conteo de componentes.
- `admin/inventory/insumos.php` — **modificar**. Conteo `n_recetas` desde `receta_componentes` (con fallback).

---

### Task 1: Matemática pura de costeo + tests

**Files:**
- Create: `includes/costeo.php`
- Test: `tests/costeo_test.php`

**Interfaces:**
- Produces:
  - `foodCostCalc(float $costoPorcion, float $precioConIgv, float $igvPct): array` → `['neto'=>float,'fc'=>float,'margen'=>float]` (`fc`/`margen` como fracción 0..1).
  - `precioSugerido(float $costoPorcion, float $objetivoPct, float $igvPct): float`
  - `subrecetaCostoUMCalc(float $costoTotalInsumos, float $rendimiento): float`
  - `fcClase(float $fc): string` → `'ok'|'warn'|'bad'` (umbral 0.35 / 0.42).

- [ ] **Step 1: Write the failing test**

Create `tests/costeo_test.php`:

```php
<?php
require __DIR__ . '/../includes/costeo.php';

$fails = 0;
function check($label, $got, $exp, $eps = 0.0001) {
    global $fails;
    $ok = is_string($exp) ? ($got === $exp) : (abs($got - $exp) < $eps);
    if (!$ok) { $fails++; echo "FAIL: $label — got " . var_export($got, true) . ", expected " . var_export($exp, true) . "\n"; }
    else { echo "ok: $label\n"; }
}

// foodCostCalc: costo 3.50, precio con IGV 11.80, IGV 18 -> neto 10.00, fc 0.35, margen 0.65
$r = foodCostCalc(3.50, 11.80, 18);
check('fc.neto', $r['neto'], 10.00);
check('fc.fc', $r['fc'], 0.35);
check('fc.margen', $r['margen'], 0.65);

// precio <= 0 / neto 0 -> ceros, sin división por cero
$z = foodCostCalc(3.50, 0, 18);
check('fc.zero.neto', $z['neto'], 0.0);
check('fc.zero.fc', $z['fc'], 0.0);
check('fc.zero.margen', $z['margen'], 0.0);

// precioSugerido: costo 3.50, objetivo 35%, IGV 18 -> (3.5/0.35)*1.18 = 11.80
check('precioSugerido', precioSugerido(3.50, 35, 18), 11.80);
check('precioSugerido.cero', precioSugerido(3.50, 0, 18), 0.0);

// subrecetaCostoUMCalc: total 20 / rendimiento 5 = 4 ; rendimiento 0 -> 0
check('subUM', subrecetaCostoUMCalc(20, 5), 4.0);
check('subUM.cero', subrecetaCostoUMCalc(20, 0), 0.0);

// fcClase: 0.35 ok, 0.42 warn, 0.50 bad
check('fcClase.ok', fcClase(0.35), 'ok');
check('fcClase.warn', fcClase(0.42), 'warn');
check('fcClase.bad', fcClase(0.50), 'bad');

echo $fails === 0 ? "\nALL OK\n" : "\n$fails FAIL(S)\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/costeo_test.php`
Expected: FAIL — `PHP Warning: require(...includes/costeo.php): Failed to open stream` / "Failed opening required" (el archivo aún no existe).

- [ ] **Step 3: Write minimal implementation**

Create `includes/costeo.php`:

```php
<?php
// Matemática pura del costeo de recetas. Sin acceso a BD — testeable con aserciones.

if (!function_exists('foodCostCalc')) {
    /**
     * Food cost y margen de una porción.
     * @return array ['neto'=>precio sin IGV, 'fc'=>fracción 0..1, 'margen'=>fracción 0..1]
     */
    function foodCostCalc(float $costoPorcion, float $precioConIgv, float $igvPct): array
    {
        $neto = $precioConIgv / (1 + $igvPct / 100);
        if ($neto <= 0) return ['neto' => 0.0, 'fc' => 0.0, 'margen' => 0.0];
        return [
            'neto'   => $neto,
            'fc'     => $costoPorcion / $neto,
            'margen' => ($neto - $costoPorcion) / $neto,
        ];
    }
}

if (!function_exists('precioSugerido')) {
    /** Precio de venta (con IGV) para alcanzar un food cost objetivo (%). */
    function precioSugerido(float $costoPorcion, float $objetivoPct, float $igvPct): float
    {
        if ($objetivoPct <= 0) return 0.0;
        return ($costoPorcion / ($objetivoPct / 100)) * (1 + $igvPct / 100);
    }
}

if (!function_exists('subrecetaCostoUMCalc')) {
    /** Costo por unidad de medida de una subreceta = costo total de insumos / rendimiento. */
    function subrecetaCostoUMCalc(float $costoTotalInsumos, float $rendimiento): float
    {
        if ($rendimiento <= 0) return 0.0;
        return $costoTotalInsumos / $rendimiento;
    }
}

if (!function_exists('fcClase')) {
    /** Semáforo del food cost (fracción 0..1): ok ≤0.35, warn ≤0.42, bad resto. */
    function fcClase(float $fc): string
    {
        if ($fc <= 0.35) return 'ok';
        if ($fc <= 0.42) return 'warn';
        return 'bad';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/costeo_test.php`
Expected: termina con `ALL OK` y exit code 0.

- [ ] **Step 5: Commit**

```bash
git add includes/costeo.php tests/costeo_test.php
git commit -m "feat(costeo): matemática pura de food cost + tests"
```

---

### Task 2: Migración 60 (subrecetas, componentes, ficha) + check

**Files:**
- Create: `install/60_costeo_recetas.sql`
- Modify: `install/check_migraciones.sql` (agregar fila #60 antes del `) m;` final)

**Interfaces:**
- Produces (esquema): tablas `subrecetas(id,nombre,unidad,rendimiento,activo,created_at)`, `subreceta_items(subreceta_id,insumo_id,cantidad)`, `receta_componentes(id,product_id,tipo,ref_id,cantidad)` con `UNIQUE(product_id,tipo,ref_id)`, `receta_ficha(product_id,porciones,procedimiento,montaje,notas)`.

- [ ] **Step 1: Crear el archivo de migración**

Create `install/60_costeo_recetas.sql`:

```sql
-- 60_costeo_recetas.sql — Costeo & Recetas Fase 1.
-- Subrecetas (preps) + componentes mixtos (insumo|subreceta) + ficha técnica. Idempotente.

CREATE TABLE IF NOT EXISTS `subrecetas` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`      VARCHAR(120) NOT NULL,
  `unidad`      VARCHAR(20)  NOT NULL DEFAULT 'unidad',
  `rendimiento` DECIMAL(12,3) NOT NULL DEFAULT 1.000,
  `activo`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subreceta_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `subreceta_items` (
  `subreceta_id` INT UNSIGNED NOT NULL,
  `insumo_id`    INT UNSIGNED NOT NULL,
  `cantidad`     DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`subreceta_id`,`insumo_id`),
  KEY `idx_sri_insumo` (`insumo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `receta_componentes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `tipo`       ENUM('insumo','subreceta') NOT NULL DEFAULT 'insumo',
  `ref_id`     INT UNSIGNED NOT NULL,
  `cantidad`   DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rc` (`product_id`,`tipo`,`ref_id`),
  KEY `idx_rc_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: recetas existentes -> componentes tipo insumo. Solo productos sin componentes (no pisa ediciones posteriores).
INSERT IGNORE INTO `receta_componentes` (`product_id`,`tipo`,`ref_id`,`cantidad`)
SELECT r.`product_id`, 'insumo', r.`insumo_id`, r.`cantidad`
  FROM `recetas` r
 WHERE NOT EXISTS (SELECT 1 FROM `receta_componentes` rc WHERE rc.`product_id` = r.`product_id`);

CREATE TABLE IF NOT EXISTS `receta_ficha` (
  `product_id`    INT UNSIGNED NOT NULL,
  `porciones`     INT NOT NULL DEFAULT 1,
  `procedimiento` TEXT NULL,
  `montaje`       TEXT NULL,
  `notas`         TEXT NULL,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Agregar la fila al verificador**

En `install/check_migraciones.sql`, justo **antes** de la línea final `) m;`, agregar:

```sql
  UNION ALL SELECT '60 costeo_recetas          (tabla subrecetas)',         COUNT(*) FROM information_schema.tables  WHERE table_schema=DATABASE() AND table_name='subrecetas'
```

- [ ] **Step 3: Verificar sintaxis SQL (lectura)**

No hay MySQL local. Verificación: releer el archivo y confirmar (a) todos los `CREATE TABLE` usan `IF NOT EXISTS`; (b) el backfill está guardado por `NOT EXISTS`; (c) la fila #60 quedó dentro del `SELECT ... UNION ALL` y antes de `) m;`. Confirmar que `recetas` (origen del backfill) existe en migraciones previas (sí: módulo inventario).

Run: `grep -c "IF NOT EXISTS" install/60_costeo_recetas.sql`
Expected: `4`

- [ ] **Step 4: Commit**

```bash
git add install/60_costeo_recetas.sql install/check_migraciones.sql
git commit -m "feat(costeo): migración 60 — subrecetas, receta_componentes (+backfill), receta_ficha"
```

---

### Task 3: Helpers de costeo + switch de lecturas de stock (`includes/inventario.php`)

**Files:**
- Modify: `includes/inventario.php` (require costeo.php; nuevos helpers; reescribir `recetaCosto` 49-60; `descontarStockPedido` 79-83; `eventoConsumoTeorico` 158-160)

**Interfaces:**
- Consumes: `subrecetaCostoUMCalc()` de `includes/costeo.php` (Task 1).
- Produces:
  - `subrecetasListo(): bool`, `recetaComponentesListo(): bool`
  - `subrecetaCostoTotal(int $subrecetaId): float`
  - `subrecetaCostoUM(int $subrecetaId): float`
  - `recetaComponentes(int $productId): array` → filas `['tipo'=>'insumo'|'subreceta','ref_id'=>int,'cantidad'=>float]`
  - `recetaExplotaInsumos(int $productId): array` → `insumo_id => cantidad` (subrecetas explotadas)
  - `recetaCosto(int $productId): float` (reescrita, ahora incluye subrecetas)

- [ ] **Step 1: Requerir costeo.php al inicio de inventario.php**

En `includes/inventario.php`, tras la línea 2 (`// Helpers del módulo de inventario...`), agregar:

```php
require_once __DIR__ . '/costeo.php';
```

- [ ] **Step 2: Reemplazar `recetaCosto` (líneas 48-60) por los nuevos helpers**

Reemplazar el bloque completo de `recetaCosto` (actual líneas 48-60) por:

```php
/** ¿Existe la tabla receta_componentes? (para degradar a leer `recetas`). */
function recetaComponentesListo(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='receta_componentes'");
    } catch (Exception $e) { $ok = false; }
    return $ok;
}

/** ¿Existe la tabla subrecetas? */
function subrecetasListo(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='subrecetas'");
    } catch (Exception $e) { $ok = false; }
    return $ok;
}

/** Costo total de insumos de una subreceta (sin dividir por rendimiento). */
function subrecetaCostoTotal(int $subrecetaId): float
{
    if ($subrecetaId <= 0 || !subrecetasListo()) return 0.0;
    try {
        $r = Database::fetch(
            "SELECT COALESCE(SUM(si.cantidad * i.costo_unitario),0) c
               FROM subreceta_items si JOIN insumos i ON i.id = si.insumo_id
              WHERE si.subreceta_id = ?",
            [$subrecetaId]
        );
        return (float)($r['c'] ?? 0);
    } catch (Exception $e) { return 0.0; }
}

/** Costo por unidad de medida de una subreceta = costo total / rendimiento. */
function subrecetaCostoUM(int $subrecetaId): float
{
    if ($subrecetaId <= 0 || !subrecetasListo()) return 0.0;
    try {
        $r = Database::fetch("SELECT rendimiento FROM subrecetas WHERE id = ?", [$subrecetaId]);
        return subrecetaCostoUMCalc(subrecetaCostoTotal($subrecetaId), (float)($r['rendimiento'] ?? 0));
    } catch (Exception $e) { return 0.0; }
}

/** Componentes de la receta de un producto. Lee receta_componentes; si no existe, degrada a `recetas` (todo insumo). */
function recetaComponentes(int $productId): array
{
    if ($productId <= 0) return [];
    try {
        if (recetaComponentesListo()) {
            return Database::fetchAll(
                "SELECT tipo, ref_id, cantidad FROM receta_componentes WHERE product_id = ?",
                [$productId]
            );
        }
        $out = [];
        foreach (Database::fetchAll("SELECT insumo_id, cantidad FROM recetas WHERE product_id = ?", [$productId]) as $r) {
            $out[] = ['tipo' => 'insumo', 'ref_id' => (int)$r['insumo_id'], 'cantidad' => (float)$r['cantidad']];
        }
        return $out;
    } catch (Exception $e) { return []; }
}

/**
 * Explota la receta de un producto a insumos, sumando los insumos de cada subreceta
 * en proporción a su rendimiento. @return array insumo_id => cantidad.
 */
function recetaExplotaInsumos(int $productId): array
{
    $out = [];
    foreach (recetaComponentes($productId) as $c) {
        $cant = (float)$c['cantidad'];
        $ref  = (int)$c['ref_id'];
        if ($cant <= 0 || $ref <= 0) continue;
        if (($c['tipo'] ?? 'insumo') === 'subreceta') {
            try {
                $sr = Database::fetch("SELECT rendimiento FROM subrecetas WHERE id = ?", [$ref]);
                $rend = (float)($sr['rendimiento'] ?? 0);
                if ($rend <= 0) continue;
                foreach (Database::fetchAll("SELECT insumo_id, cantidad FROM subreceta_items WHERE subreceta_id = ?", [$ref]) as $si) {
                    $iid = (int)$si['insumo_id'];
                    $out[$iid] = ($out[$iid] ?? 0) + ((float)$si['cantidad'] / $rend) * $cant;
                }
            } catch (Exception $e) { /* subrecetas no migrado: ignorar este componente */ }
        } else {
            $out[$ref] = ($out[$ref] ?? 0) + $cant;
        }
    }
    return $out;
}

/** Costo de la receta de un producto (insumos directos + subrecetas explotadas). */
function recetaCosto(int $productId): float
{
    try {
        $exp = recetaExplotaInsumos($productId);
        if (!$exp) return 0.0;
        $ids = implode(',', array_map('intval', array_keys($exp)));
        $costos = [];
        foreach (Database::fetchAll("SELECT id, costo_unitario FROM insumos WHERE id IN ($ids)") as $i) {
            $costos[(int)$i['id']] = (float)$i['costo_unitario'];
        }
        $total = 0.0;
        foreach ($exp as $iid => $cant) { $total += ($costos[$iid] ?? 0) * $cant; }
        return $total;
    } catch (Exception $e) { return 0.0; }
}
```

> Nota: `$ids` se arma con `array_map('intval', ...)` sobre claves enteras de un array PHP — no hay entrada de usuario, no rompe la regla de prepared statements (no se interpola texto de usuario).

- [ ] **Step 3: Enrutar `descontarStockPedido` por la explosión**

En `descontarStockPedido`, reemplazar el bloque actual (líneas ~75-84, el `foreach ($items ...)` interno que hace `SELECT ... FROM recetas`) por:

```php
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (float)($it['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            foreach (recetaExplotaInsumos($pid) as $insumoId => $cant) {
                invMovimiento($ubi, (int)$insumoId, 'venta', -((float)$cant * $qty),
                    ['pedido_id' => $pedidoId, 'motivo' => 'Venta · pedido #' . str_pad((string)$pedidoId, 3, '0', STR_PAD_LEFT)]);
            }
        }
```

- [ ] **Step 4: Enrutar `eventoConsumoTeorico` por la explosión**

En `eventoConsumoTeorico`, reemplazar el bloque del producto (líneas ~157-161, el `if ($pid > 0) { foreach (... FROM recetas ...) }`) por:

```php
                    if ($pid > 0) {
                        foreach (recetaExplotaInsumos($pid) as $iid => $cant) {
                            $out[(int)$iid] = ($out[(int)$iid] ?? 0) + (float)$cant * $qty;
                        }
                    }
```

(El bloque de `modificadores` con `receta_modificadores` queda **igual**.)

- [ ] **Step 5: Verificar sintaxis**

Run: `php -l includes/inventario.php`
Expected: `No syntax errors detected in includes/inventario.php`

- [ ] **Step 6: Verificar que no quedan lecturas crudas de `recetas` en inventario.php**

Run: `grep -n "FROM recetas " includes/inventario.php`
Expected: una sola coincidencia, dentro de `recetaComponentes` (el fallback). Si aparece en `recetaCosto`/`descontarStockPedido`/`eventoConsumoTeorico`, no se reemplazó bien.

- [ ] **Step 7: Commit**

```bash
git add includes/inventario.php
git commit -m "feat(costeo): helpers de subrecetas + explosión a insumos; recetaCosto/descontarStock/eventoConsumo subreceta-aware"
```

---

### Task 4: API de búsqueda de componentes (`api/insumos.php`)

**Files:**
- Modify: `api/insumos.php` (agregar acción `componentes_buscar` antes del `echo json_encode(['ok'=>false,'error'=>'Acción inválida']);` final)

**Interfaces:**
- Consumes: `subrecetaCostoUM()` (Task 3) → requiere cargar `includes/inventario.php`.
- Produces: `GET /api/insumos.php?action=componentes_buscar&q=...` → `{ok:true, items:[{tipo:'insumo'|'subreceta', id:int, nombre:string, unidad:string, costo:float}]}`. `costo` = `costo_unitario` (insumo) o costo/UM (subreceta). Insumos primero, luego subrecetas; máx 12 de cada uno.

- [ ] **Step 1: Cargar inventario.php en api/insumos.php**

En `api/insumos.php`, tras la línea `require_once __DIR__ . '/../includes/helpers.php';`, agregar:

```php
require_once __DIR__ . '/../includes/inventario.php';
```

- [ ] **Step 2: Agregar la acción `componentes_buscar`**

Inmediatamente **antes** de la línea final `echo json_encode(['ok'=>false,'error'=>'Acción inválida']);`, insertar:

```php
if ($action === 'componentes_buscar') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok'=>true,'items'=>[]]); exit; }
    $items = [];
    foreach (Database::fetchAll(
        "SELECT id, nombre, unidad, costo_unitario FROM insumos WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 12",
        ['%' . $q . '%']
    ) as $i) {
        $items[] = ['tipo'=>'insumo','id'=>(int)$i['id'],'nombre'=>$i['nombre'],'unidad'=>$i['unidad'],'costo'=>(float)$i['costo_unitario']];
    }
    if (subrecetasListo()) {
        foreach (Database::fetchAll(
            "SELECT id, nombre, unidad FROM subrecetas WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 12",
            ['%' . $q . '%']
        ) as $s) {
            $items[] = ['tipo'=>'subreceta','id'=>(int)$s['id'],'nombre'=>$s['nombre'],'unidad'=>$s['unidad'],'costo'=>subrecetaCostoUM((int)$s['id'])];
        }
    }
    echo json_encode(['ok'=>true, 'items'=>$items]);
    exit;
}
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l api/insumos.php`
Expected: `No syntax errors detected in api/insumos.php`

- [ ] **Step 4: Commit**

```bash
git add api/insumos.php
git commit -m "feat(costeo): api componentes_buscar (insumos + subrecetas con costo/UM)"
```

---

### Task 5: Editor de subreceta (`admin/inventory/subreceta_form.php`)

**Files:**
- Create: `admin/inventory/subreceta_form.php`

**Interfaces:**
- Consumes: `/api/insumos.php?action=buscar` y `action=crear` (existentes); `subrecetaCostoTotal`/`subrecetaCostoUM` (Task 3); `$activePage='inv-subrecetas'` (link en Task 6).
- Produces: páginas `?id=N` (editar) y sin `id` (nueva). POST guarda `subrecetas` + `subreceta_items`; redirige a `subrecetas.php`.

- [ ] **Step 1: Crear el editor**

Create `admin/inventory/subreceta_form.php`:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_recetas');
if (!subrecetasListo()) { flashMessage('error', 'Aplica install/60_costeo_recetas.sql primero.'); redirect('/admin/inventory/recetas.php'); }

$id = cleanInt($_GET['id'] ?? 0);
$sub = $id ? Database::fetch("SELECT * FROM subrecetas WHERE id=?", [$id]) : null;
if ($id && !$sub) { flashMessage('error', 'Subreceta no encontrada.'); redirect('/admin/inventory/subrecetas.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $nombre = clean($_POST['nombre'] ?? '');
    $unidad = clean($_POST['unidad'] ?? 'unidad') ?: 'unidad';
    $rend   = max(0.001, cleanFloat($_POST['rendimiento'] ?? 1));
    if ($nombre === '') { flashMessage('error', 'Falta el nombre.'); redirect('/admin/inventory/subreceta_form.php' . ($id ? '?id='.$id : '')); }
    if ($id) {
        Database::execute("UPDATE subrecetas SET nombre=?, unidad=?, rendimiento=? WHERE id=?", [$nombre, $unidad, $rend, $id]);
    } else {
        $id = Database::insert("INSERT INTO subrecetas (nombre,unidad,rendimiento,activo) VALUES (?,?,?,1)", [$nombre, $unidad, $rend]);
    }
    $ins = $_POST['insumo_id'] ?? [];
    $cant = $_POST['cantidad'] ?? [];
    Database::execute("DELETE FROM subreceta_items WHERE subreceta_id=?", [$id]);
    $seen = [];
    foreach ($ins as $idx => $iid) {
        $iid = (int)$iid; $c = (float)($cant[$idx] ?? 0);
        if ($iid <= 0 || $c <= 0 || isset($seen[$iid])) continue;
        $seen[$iid] = true;
        Database::insert("INSERT INTO subreceta_items (subreceta_id,insumo_id,cantidad) VALUES (?,?,?)", [$id, $iid, $c]);
    }
    flashMessage('success', 'Subreceta guardada.');
    redirect('/admin/inventory/subrecetas.php');
}

$items = $id ? Database::fetchAll(
    "SELECT si.insumo_id, si.cantidad, i.nombre, i.unidad, i.costo_unitario
       FROM subreceta_items si JOIN insumos i ON i.id=si.insumo_id
      WHERE si.subreceta_id=? ORDER BY i.nombre", [$id]) : [];
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = $id ? 'Subreceta · ' . $sub['nombre'] : 'Nueva subreceta';
$activePage = 'inv-subrecetas';
$extraHead  = '<style>
.rec-row{display:flex;gap:8px;margin-bottom:8px;align-items:center}
.rec-nm{flex:1;font-weight:700;color:var(--black,#1E1E1E)}
.rec-row .rec-q{width:120px}
.rec-row .rec-u{width:48px;font-size:12px;color:var(--text-muted)}
.rec-row .rec-del{background:none;border:none;color:#dc2626;cursor:pointer;padding:6px;flex-shrink:0;font-size:16px}
.rec-opt{padding:10px 13px;cursor:pointer;display:flex;justify-content:space-between;align-items:center}
.rec-opt:hover{background:#fffbe9}
.rec-create{color:#1f9d55;font-weight:800;border-top:1px dashed #eee}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/inventory/subrecetas.php">Subrecetas</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= $id ? clean($sub['nombre']) : 'Nueva' ?></span>
</div>

<div class="page-header"><div class="page-header-left"><h1><?= $id ? 'Subreceta · '.clean($sub['nombre']) : 'Nueva subreceta' ?></h1>
  <p>Una preparación base (salsa, masa, aderezo) que luego usás en varias recetas</p></div></div>

<form method="post">
  <?= csrfField() ?>
  <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">
    <div class="card"><div class="card-body">
      <div style="display:grid;grid-template-columns:1fr 130px 90px;gap:12px;margin-bottom:16px">
        <div class="form-group" style="margin:0"><label>Nombre</label>
          <input type="text" name="nombre" value="<?= $id ? clean($sub['nombre']) : '' ?>" required></div>
        <div class="form-group" style="margin:0"><label>Rendimiento</label>
          <input type="text" inputmode="decimal" name="rendimiento" id="sr-rend" value="<?= $id ? nf($sub['rendimiento']) : '1' ?>" oninput="recalc()"></div>
        <div class="form-group" style="margin:0"><label>Unidad</label>
          <select name="unidad">
            <?php foreach (['unidad','g','kg','ml','l','porcion','lonja'] as $u): ?>
              <option value="<?= $u ?>"<?= ($id && $sub['unidad']===$u) ? ' selected' : '' ?>><?= $u ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>

      <label style="font-size:13px;font-weight:700;color:var(--text-muted)">Insumos de la preparación</label>
      <div id="rec-rows" style="margin-top:8px">
        <?php foreach ($items as $r): ?>
        <div class="rec-row">
          <span class="rec-nm"><?= clean($r['nombre']) ?></span>
          <input type="hidden" name="insumo_id[]" value="<?= (int)$r['insumo_id'] ?>" data-costo="<?= (float)$r['costo_unitario'] ?>">
          <input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="<?= nf($r['cantidad']) ?>" oninput="recalc()">
          <span class="rec-u"><?= clean($r['unidad']) ?></span>
          <button type="button" class="rec-del" onclick="this.closest('.rec-row').remove();recalc()">✕</button>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="add-wrap" style="position:relative;margin-top:8px">
        <input type="text" id="rec-add" autocomplete="off" placeholder="Agregar insumo (busca o crea)…"
               oninput="recBuscar(this.value)" onfocus="recBuscar(this.value)"
               style="width:100%;padding:11px 13px;border:1.5px dashed #c9c9d2;border-radius:10px">
        <div id="rec-drop" style="display:none;position:absolute;left:0;right:0;top:48px;background:#fff;border:1px solid var(--border,#eee);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:30;overflow:hidden"></div>
      </div>

      <div style="display:flex;gap:12px;margin-top:18px">
        <button type="submit" class="btn btn-primary">Guardar subreceta</button>
        <a href="<?= APP_URL ?>/admin/inventory/subrecetas.php" class="btn btn-ghost">Cancelar</a>
      </div>
    </div></div>

    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="card"><div class="card-body" style="text-align:center">
        <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Costo total</div>
        <div id="costoTotal" style="font-size:26px;font-weight:800;margin-top:4px">S/ 0.00</div>
        <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-top:12px">Costo por unidad</div>
        <div id="costoUM" style="font-size:26px;font-weight:800;color:var(--c-brand,#FFDF00);-webkit-text-stroke:.3px #1E1E1E;margin-top:4px">S/ 0.00</div>
      </div></div>
    </div>
  </div>
</form>

<script>
const INS_API = '<?= APP_URL ?>/api/insumos.php';
const CSRF = '<?= csrfToken() ?>';
let insPend = '';

function recBuscar(q){
  q = (q||'').trim();
  const drop = document.getElementById('rec-drop');
  if(!q){ drop.style.display='none'; return; }
  fetch(INS_API + '?action=buscar&q=' + encodeURIComponent(q))
    .then(r=>r.json()).then(d=>{
      drop.innerHTML = '';
      (d.items||[]).forEach(i => {
        const o = document.createElement('div'); o.className = 'rec-opt';
        const n = document.createElement('span'); n.textContent = i.nombre;
        const u = document.createElement('span'); u.className = 'rec-u'; u.textContent = i.unidad;
        o.appendChild(n); o.appendChild(u);
        o.addEventListener('click', () => recAgregar(i.id, i.nombre, i.unidad, parseFloat(i.costo_unitario)||0));
        drop.appendChild(o);
      });
      const exacto = (d.items||[]).some(i => i.nombre.toLowerCase() === q.toLowerCase());
      if(!exacto){
        const c = document.createElement('div'); c.className = 'rec-opt rec-create';
        c.textContent = '+ Crear «' + q + '»';
        c.addEventListener('click', () => insAbrir(q));
        drop.appendChild(c);
      }
      drop.style.display = 'block';
    });
}

function recAgregar(id, nombre, unidad, costo){
  costo = parseFloat(costo)||0;
  if (document.querySelector('input[name="insumo_id[]"][value="'+id+'"]')) {
    document.getElementById('rec-drop').style.display='none'; document.getElementById('rec-add').value=''; return;
  }
  const row = document.createElement('div'); row.className='rec-row';
  row.innerHTML = '<span class="rec-nm">'+nombre+'</span>'+
    '<input type="hidden" name="insumo_id[]" value="'+id+'" data-costo="'+costo+'">'+
    '<input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="1" oninput="recalc()">'+
    '<span class="rec-u">'+unidad+'</span>'+
    '<button type="button" class="rec-del" onclick="this.closest(\'.rec-row\').remove();recalc()">✕</button>';
  document.getElementById('rec-rows').appendChild(row);
  document.getElementById('rec-add').value='';
  document.getElementById('rec-drop').style.display='none';
  recalc();
}

function insAbrir(nombre){
  insPend = nombre;
  document.getElementById('ins-name').textContent = nombre;
  document.getElementById('rec-drop').style.display='none';
  document.getElementById('ins-ov').style.display='flex';
}
function insCerrar(){ document.getElementById('ins-ov').style.display='none'; }
function insCrear(){
  const body = new URLSearchParams({action:'crear', nombre:insPend, unidad:document.getElementById('ins-unidad').value, tipo:'ingrediente', costo_unitario:document.getElementById('ins-costo').value||'0'});
  fetch(INS_API, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF}, body})
    .then(r=>r.json()).then(d=>{
      if(d.ok){ recAgregar(d.insumo.id, d.insumo.nombre, d.insumo.unidad, parseFloat(d.insumo.costo_unitario)||0); document.getElementById('ins-costo').value=''; insCerrar(); }
      else { alert(d.error||'No se pudo crear'); }
    });
}

function recalc(){
  let total = 0;
  document.querySelectorAll('#rec-rows .rec-row').forEach(function(row){
    const hid = row.querySelector('input[name="insumo_id[]"]');
    const q = parseFloat(row.querySelector('.rec-q').value) || 0;
    const costo = hid ? parseFloat(hid.dataset.costo)||0 : 0;
    total += costo * q;
  });
  const rend = parseFloat(document.getElementById('sr-rend').value) || 0;
  document.getElementById('costoTotal').textContent = 'S/ ' + total.toFixed(2);
  document.getElementById('costoUM').textContent = 'S/ ' + (rend > 0 ? (total/rend) : 0).toFixed(2);
}

document.addEventListener('click', e=>{ if(!e.target.closest('.add-wrap')){ const d=document.getElementById('rec-drop'); if(d) d.style.display='none'; } });
recalc();
</script>

<div id="ins-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.5);z-index:50;align-items:center;justify-content:center;padding:18px">
  <div style="width:340px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden">
    <div style="background:#fafafb;padding:13px 16px;border-bottom:1px solid var(--border,#eee);font-weight:800">Crear insumo: «<span id="ins-name"></span>»</div>
    <div style="padding:16px">
      <div class="form-group"><label>Unidad</label>
        <select id="ins-unidad"><option value="unidad">unidad</option><option value="g">gramos (g)</option><option value="ml">ml</option><option value="kg">kg</option><option value="l">l</option><option value="lonja">lonja</option><option value="porcion">porción</option></select></div>
      <div class="form-group"><label>Costo por unidad (opcional)</label><input id="ins-costo" inputmode="decimal" placeholder="0.00"></div>
    </div>
    <div style="display:flex;gap:8px;padding:0 16px 16px">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="insCerrar()">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="insCrear()">Crear y agregar</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l admin/inventory/subreceta_form.php`
Expected: `No syntax errors detected in admin/inventory/subreceta_form.php`

- [ ] **Step 3: Commit**

```bash
git add admin/inventory/subreceta_form.php
git commit -m "feat(costeo): editor de subreceta (insumos + rendimiento + costo/UM en vivo)"
```

---

### Task 6: Lista de subrecetas + link en sidebar

**Files:**
- Create: `admin/inventory/subrecetas.php`
- Modify: `admin/layout-top.php` (link "Subrecetas" tras el de Recetas, ~línea 285)

**Interfaces:**
- Consumes: `subrecetasListo`, `subrecetaCostoTotal`, `subrecetaCostoUM` (Task 3); `subreceta_form.php` (Task 5). `$activePage='inv-subrecetas'`.

- [ ] **Step 1: Crear la lista**

Create `admin/inventory/subrecetas.php`:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_recetas');

$ready = subrecetasListo();
$subs = [];
if ($ready) {
    $rows = Database::fetchAll("SELECT id, nombre, unidad, rendimiento FROM subrecetas WHERE activo=1 ORDER BY nombre");
    foreach ($rows as $r) {
        $r['n_items'] = (int)(Database::fetch("SELECT COUNT(*) c FROM subreceta_items WHERE subreceta_id=?", [(int)$r['id']])['c'] ?? 0);
        $r['costo']   = subrecetaCostoTotal((int)$r['id']);
        $r['costo_um'] = subrecetaCostoUM((int)$r['id']);
        $subs[] = $r;
    }
}
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = 'Subrecetas';
$activePage = 'inv-subrecetas';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left"><h1>Subrecetas</h1>
    <p>Preparaciones base (salsas, masas, aderezos) que se costean y se usan dentro de las recetas</p></div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/inventory/subreceta_form.php" class="btn btn-primary">+ Nueva subreceta</a>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <h3>Falta aplicar la migración</h3>
    <p>Aplica <code>install/60_costeo_recetas.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php elseif (empty($subs)): ?>
  <div class="card"><div class="empty-state">
    <h3>Sin subrecetas</h3>
    <p>Crea tu primera preparación base con <strong>+ Nueva subreceta</strong>.</p>
  </div></div>
<?php else: ?>
<div class="card"><div class="table-wrap" style="border:none;border-radius:0">
  <table class="data-table">
    <thead><tr><th>Subreceta</th><th>Insumos</th><th>Rendimiento</th><th>Costo total</th><th>Costo / unidad</th><th style="width:110px"></th></tr></thead>
    <tbody>
      <?php foreach ($subs as $s): ?>
      <tr<?= $s['n_items']==0 ? ' style="opacity:.6"' : '' ?>>
        <td><strong><?= clean($s['nombre']) ?></strong></td>
        <td><?= $s['n_items']==0 ? '<span style="color:var(--text-muted)">Sin insumos</span>' : (int)$s['n_items'].' insumo'.($s['n_items']==1?'':'s') ?></td>
        <td><?= nf($s['rendimiento']) ?> <span style="color:var(--text-muted);font-size:12px"><?= clean($s['unidad']) ?></span></td>
        <td><?= formatMoney($s['costo']) ?></td>
        <td><strong><?= formatMoney($s['costo_um']) ?></strong> <span style="color:var(--text-muted);font-size:12px">/ <?= clean($s['unidad']) ?></span></td>
        <td><a href="<?= APP_URL ?>/admin/inventory/subreceta_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-ghost btn-sm">Editar</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Agregar el link al sidebar**

En `admin/layout-top.php`, justo **después** del bloque del link de Recetas (que termina en `<?php endif; ?>` tras "Recetas y costos", ~línea 285), insertar:

```php
        <?php if (can('inv_recetas')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/subrecetas.php"
           class="nav-link <?php echo ($activePage??'')==='inv-subrecetas'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 2v7c0 1.1.9 2 2 2h0a2 2 0 0 0 2-2V2"/><path d="M5 11v11M11 2v20M15 2c-1.5 0-3 1.5-3 4s1.5 4 3 4v12"/></svg></span> Subrecetas
        </a>
        <?php endif; ?>
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/inventory/subrecetas.php && php -l admin/layout-top.php`
Expected: `No syntax errors detected` en ambos.

- [ ] **Step 4: Commit**

```bash
git add admin/inventory/subrecetas.php admin/layout-top.php
git commit -m "feat(costeo): lista de subrecetas + link en sidebar"
```

---

### Task 7: Editor de receta con componentes mixtos + ficha + simulador de food cost

**Files:**
- Modify: `admin/inventory/receta_form.php` (reescritura completa)
- Modify: `admin/inventory/recetas.php` (costo/food cost vía helpers, conteo de componentes)
- Modify: `admin/inventory/insumos.php` (línea 23: `n_recetas` desde `receta_componentes`)

**Interfaces:**
- Consumes: `componentes_buscar` (Task 4); `recetaComponentes`, `recetaCosto`, `recetaComponentesListo` (Task 3); `foodCostCalc`, `precioSugerido`, `fcClase` (Task 1).
- Produces: receta guardada en `receta_componentes` (tipo insumo|subreceta) + ficha en `receta_ficha`.

- [ ] **Step 1: Reescribir `receta_form.php`**

Reemplazar el contenido completo de `admin/inventory/receta_form.php` por:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_recetas');
if (!inventarioListo()) { flashMessage('error', 'Aplica install/inventario.sql primero.'); redirect('/admin/inventory/recetas.php'); }

$pid  = cleanInt($_GET['product_id'] ?? 0);
$prod = $pid ? Database::fetch("SELECT * FROM products WHERE id=?", [$pid]) : null;
if (!$prod) { flashMessage('error', 'Producto no encontrado.'); redirect('/admin/inventory/recetas.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (recetaComponentesListo()) {
        $tipos = $_POST['comp_tipo'] ?? [];
        $refs  = $_POST['comp_ref'] ?? [];
        $cant  = $_POST['cantidad'] ?? [];
        Database::execute("DELETE FROM receta_componentes WHERE product_id = ?", [$pid]);
        $seen = [];
        foreach ($refs as $idx => $rid) {
            $rid = (int)$rid;
            $tipo = (($tipos[$idx] ?? 'insumo') === 'subreceta') ? 'subreceta' : 'insumo';
            $c = (float)($cant[$idx] ?? 0);
            $key = $tipo . ':' . $rid;
            if ($rid <= 0 || $c <= 0 || isset($seen[$key])) continue;
            $seen[$key] = true;
            Database::insert("INSERT INTO receta_componentes (product_id,tipo,ref_id,cantidad) VALUES (?,?,?,?)", [$pid, $tipo, $rid, $c]);
        }
        // Ficha técnica (upsert)
        $porciones = max(1, cleanInt($_POST['porciones'] ?? 1));
        $proc = clean($_POST['procedimiento'] ?? '');
        $mont = clean($_POST['montaje'] ?? '');
        $nota = clean($_POST['notas'] ?? '');
        Database::execute(
            "INSERT INTO receta_ficha (product_id,porciones,procedimiento,montaje,notas) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE porciones=VALUES(porciones), procedimiento=VALUES(procedimiento), montaje=VALUES(montaje), notas=VALUES(notas)",
            [$pid, $porciones, $proc, $mont, $nota]
        );
    } else {
        // Degradado: tabla nueva no aplicada, guardar como antes en recetas (solo insumos)
        $refs = $_POST['comp_ref'] ?? [];
        $tipos = $_POST['comp_tipo'] ?? [];
        $cant = $_POST['cantidad'] ?? [];
        Database::execute("DELETE FROM recetas WHERE product_id = ?", [$pid]);
        $seen = [];
        foreach ($refs as $idx => $rid) {
            $rid = (int)$rid; $c = (float)($cant[$idx] ?? 0);
            if (($tipos[$idx] ?? 'insumo') !== 'insumo' || $rid <= 0 || $c <= 0 || isset($seen[$rid])) continue;
            $seen[$rid] = true;
            Database::insert("INSERT INTO recetas (product_id,insumo_id,cantidad) VALUES (?,?,?)", [$pid, $rid, $c]);
        }
    }
    flashMessage('success', 'Receta guardada.');
    redirect('/admin/inventory/recetas.php');
}

// Cargar componentes existentes con nombre/unidad/costo unitario por tipo
$comps = [];
foreach (recetaComponentes($pid) as $c) {
    $tipo = ($c['tipo'] ?? 'insumo') === 'subreceta' ? 'subreceta' : 'insumo';
    $ref  = (int)$c['ref_id'];
    if ($tipo === 'subreceta') {
        $s = Database::fetch("SELECT nombre, unidad FROM subrecetas WHERE id=?", [$ref]);
        if (!$s) continue;
        $comps[] = ['tipo'=>'subreceta','ref'=>$ref,'nombre'=>$s['nombre'],'unidad'=>$s['unidad'],'costo'=>subrecetaCostoUM($ref),'cantidad'=>(float)$c['cantidad']];
    } else {
        $i = Database::fetch("SELECT nombre, unidad, costo_unitario FROM insumos WHERE id=?", [$ref]);
        if (!$i) continue;
        $comps[] = ['tipo'=>'insumo','ref'=>$ref,'nombre'=>$i['nombre'],'unidad'=>$i['unidad'],'costo'=>(float)$i['costo_unitario'],'cantidad'=>(float)$c['cantidad']];
    }
}

$ficha = Database::fetch("SELECT * FROM receta_ficha WHERE product_id=?", [$pid]) ?: ['porciones'=>1,'procedimiento'=>'','montaje'=>'','notas'=>''];
$precioRef = (float)(Database::fetch(
    "SELECT lp.price FROM location_products lp JOIN ubicaciones u ON u.id=lp.location_id WHERE lp.product_id=? ORDER BY u.es_principal DESC, u.nombre LIMIT 1",
    [$pid]
)['price'] ?? 0);
$igvPct = (float) getSetting('igv_pct', '18');
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = 'Receta · ' . $prod['name'];
$activePage = 'inv-recetas';
$extraHead  = '<style>
.rec-row{display:flex;gap:8px;margin-bottom:8px;align-items:center}
.rec-nm{flex:1;font-weight:700;color:var(--black,#1E1E1E)}
.rec-tag{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;padding:2px 6px;border-radius:5px;background:#FFEFBC;color:#1E1E1E}
.rec-tag.sub{background:var(--pink,#FFBBC8)}
.rec-row .rec-q{width:110px}
.rec-row .rec-u{width:46px;font-size:12px;color:var(--text-muted)}
.rec-row .rec-del{background:none;border:none;color:#dc2626;cursor:pointer;padding:6px;flex-shrink:0;font-size:16px}
.rec-opt{padding:10px 13px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px}
.rec-opt:hover{background:#fffbe9}
.rec-create{color:#1f9d55;font-weight:800;border-top:1px dashed #eee}
.fc-badge{font-weight:800}
.fc-ok{color:#16a34a}.fc-warn{color:#ca8a04}.fc-bad{color:#dc2626}
@media print{.no-print{display:none!important}}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb no-print">
  <a href="<?= APP_URL ?>/admin/inventory/recetas.php">Recetas</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= clean($prod['name']) ?></span>
</div>

<div class="page-header"><div class="page-header-left"><h1>Receta · <?= clean($prod['name']) ?></h1>
  <p>Insumos y subrecetas que consume una unidad, ficha técnica y food cost</p></div></div>

<form method="post">
  <?= csrfField() ?>
  <div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">
    <div style="display:flex;flex-direction:column;gap:20px">
      <div class="card"><div class="card-header"><span class="card-title">Componentes</span></div><div class="card-body">
        <div id="rec-rows">
          <?php foreach ($comps as $c): ?>
          <div class="rec-row">
            <span class="rec-tag <?= $c['tipo']==='subreceta'?'sub':'' ?>"><?= $c['tipo']==='subreceta'?'Sub':'Insumo' ?></span>
            <span class="rec-nm"><?= clean($c['nombre']) ?></span>
            <input type="hidden" name="comp_tipo[]" value="<?= $c['tipo'] ?>">
            <input type="hidden" name="comp_ref[]" value="<?= (int)$c['ref'] ?>" data-costo="<?= (float)$c['costo'] ?>">
            <input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="<?= nf($c['cantidad']) ?>" oninput="recalc()">
            <span class="rec-u"><?= clean($c['unidad']) ?></span>
            <button type="button" class="rec-del no-print" onclick="this.closest('.rec-row').remove();recalc()">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="add-wrap no-print" style="position:relative;margin-top:8px">
          <input type="text" id="rec-add" autocomplete="off" placeholder="Agregar insumo o subreceta (busca o crea)…"
                 oninput="recBuscar(this.value)" onfocus="recBuscar(this.value)"
                 style="width:100%;padding:11px 13px;border:1.5px dashed #c9c9d2;border-radius:10px">
          <div id="rec-drop" style="display:none;position:absolute;left:0;right:0;top:48px;background:#fff;border:1px solid var(--border,#eee);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:30;overflow:hidden"></div>
        </div>
      </div></div>

      <div class="card"><div class="card-header"><span class="card-title">Ficha técnica</span></div><div class="card-body">
        <div class="form-group"><label>Porciones que rinde</label>
          <input type="text" inputmode="numeric" name="porciones" id="fc-porc" value="<?= (int)$ficha['porciones'] ?>" oninput="recalc()" style="max-width:120px"></div>
        <div class="form-group"><label>Procedimiento</label>
          <textarea name="procedimiento" rows="4"><?= clean($ficha['procedimiento']) ?></textarea></div>
        <div class="form-group"><label>Montaje / emplatado</label>
          <textarea name="montaje" rows="3"><?= clean($ficha['montaje']) ?></textarea></div>
        <div class="form-group" style="margin-bottom:0"><label>Notas / alérgenos</label>
          <textarea name="notas" rows="2"><?= clean($ficha['notas']) ?></textarea></div>
      </div></div>

      <div style="display:flex;gap:12px" class="no-print">
        <button type="submit" class="btn btn-primary">Guardar receta</button>
        <a href="<?= APP_URL ?>/admin/inventory/recetas.php" class="btn btn-ghost">Cancelar</a>
        <button type="button" class="btn btn-ghost" onclick="window.print()">Imprimir ficha</button>
      </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="card"><div class="card-body">
        <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Costo de la receta</div>
        <div id="costoTotal" style="font-size:28px;font-weight:800;margin-top:2px">S/ 0.00</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Costo por porción: <strong id="costoPorc">S/ 0.00</strong></div>
      </div></div>

      <div class="card no-print"><div class="card-header"><span class="card-title">Simulador de food cost</span></div><div class="card-body">
        <div class="form-group"><label>Precio de venta (con IGV)</label>
          <input type="text" inputmode="decimal" id="fc-precio" value="<?= $precioRef > 0 ? nf($precioRef) : '' ?>" placeholder="0.00" oninput="recalc()"></div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
          <span>Precio sin IGV</span><strong id="fc-neto">—</strong></div>
        <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border)">
          <span>Food cost</span><strong id="fc-fc" class="fc-badge">—</strong></div>
        <div style="display:flex;justify-content:space-between;padding:6px 0">
          <span>Margen</span><strong id="fc-margen">—</strong></div>
        <div style="margin-top:14px;padding-top:12px;border-top:1px dashed var(--border)">
          <label style="font-size:13px">Food cost objetivo</label>
          <div style="display:flex;align-items:center;gap:8px;margin-top:4px">
            <input type="text" inputmode="decimal" id="fc-obj" value="35" oninput="recalc()" style="width:70px">
            <span style="color:var(--text-muted)">% →</span>
            <strong id="fc-sugerido" style="color:var(--c-brand,#FFDF00);-webkit-text-stroke:.3px #1E1E1E">S/ 0.00</strong>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Precio sugerido para ese food cost (informativo, no se guarda)</div>
        </div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:12px">Simulación: el precio no se guarda. IGV <?= nf($igvPct) ?>%.</div>
      </div></div>
    </div>
  </div>
</form>

<script>
const INS_API = '<?= APP_URL ?>/api/insumos.php';
const CSRF = '<?= csrfToken() ?>';
const IGV = <?= json_encode($igvPct) ?>;
let insPend = '';

function recBuscar(q){
  q = (q||'').trim();
  const drop = document.getElementById('rec-drop');
  if(!q){ drop.style.display='none'; return; }
  fetch(INS_API + '?action=componentes_buscar&q=' + encodeURIComponent(q))
    .then(r=>r.json()).then(d=>{
      drop.innerHTML = '';
      (d.items||[]).forEach(i => {
        const o = document.createElement('div'); o.className = 'rec-opt';
        const left = document.createElement('span'); left.style.display='flex'; left.style.alignItems='center'; left.style.gap='8px';
        const tag = document.createElement('span'); tag.className = 'rec-tag' + (i.tipo==='subreceta'?' sub':''); tag.textContent = i.tipo==='subreceta'?'Sub':'Insumo';
        const n = document.createElement('span'); n.textContent = i.nombre;
        left.appendChild(tag); left.appendChild(n);
        const u = document.createElement('span'); u.className = 'rec-u'; u.textContent = i.unidad;
        o.appendChild(left); o.appendChild(u);
        o.addEventListener('click', () => recAgregar(i.tipo, i.id, i.nombre, i.unidad, parseFloat(i.costo)||0));
        drop.appendChild(o);
      });
      const exacto = (d.items||[]).some(i => i.nombre.toLowerCase() === q.toLowerCase());
      if(!exacto){
        const c = document.createElement('div'); c.className = 'rec-opt rec-create';
        c.textContent = '+ Crear insumo «' + q + '»';
        c.addEventListener('click', () => insAbrir(q));
        drop.appendChild(c);
      }
      drop.style.display = 'block';
    });
}

function recAgregar(tipo, id, nombre, unidad, costo){
  tipo = tipo === 'subreceta' ? 'subreceta' : 'insumo';
  costo = parseFloat(costo)||0;
  if (document.querySelector('input[name="comp_ref[]"][value="'+id+'"]')) {
    const exist = document.querySelector('input[name="comp_ref[]"][value="'+id+'"]');
    if (exist && exist.previousElementSibling && exist.previousElementSibling.value === tipo) {
      document.getElementById('rec-drop').style.display='none'; document.getElementById('rec-add').value=''; return;
    }
  }
  const tag = tipo==='subreceta' ? 'Sub' : 'Insumo';
  const cls = tipo==='subreceta' ? 'rec-tag sub' : 'rec-tag';
  const row = document.createElement('div'); row.className='rec-row';
  row.innerHTML = '<span class="'+cls+'">'+tag+'</span>'+
    '<span class="rec-nm">'+nombre+'</span>'+
    '<input type="hidden" name="comp_tipo[]" value="'+tipo+'">'+
    '<input type="hidden" name="comp_ref[]" value="'+id+'" data-costo="'+costo+'">'+
    '<input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="1" oninput="recalc()">'+
    '<span class="rec-u">'+unidad+'</span>'+
    '<button type="button" class="rec-del no-print" onclick="this.closest(\'.rec-row\').remove();recalc()">✕</button>';
  document.getElementById('rec-rows').appendChild(row);
  document.getElementById('rec-add').value='';
  document.getElementById('rec-drop').style.display='none';
  recalc();
}

function insAbrir(nombre){
  insPend = nombre;
  document.getElementById('ins-name').textContent = nombre;
  document.getElementById('rec-drop').style.display='none';
  document.getElementById('ins-ov').style.display='flex';
}
function insCerrar(){ document.getElementById('ins-ov').style.display='none'; }
function insCrear(){
  const body = new URLSearchParams({action:'crear', nombre:insPend, unidad:document.getElementById('ins-unidad').value, tipo:'ingrediente', costo_unitario:document.getElementById('ins-costo').value||'0'});
  fetch(INS_API, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF}, body})
    .then(r=>r.json()).then(d=>{
      if(d.ok){ recAgregar('insumo', d.insumo.id, d.insumo.nombre, d.insumo.unidad, parseFloat(d.insumo.costo_unitario)||0); document.getElementById('ins-costo').value=''; insCerrar(); }
      else { alert(d.error||'No se pudo crear'); }
    });
}

function recalc(){
  let total = 0;
  document.querySelectorAll('#rec-rows .rec-row').forEach(function(row){
    const hid = row.querySelector('input[name="comp_ref[]"]');
    const q = parseFloat(row.querySelector('.rec-q').value) || 0;
    const costo = hid ? parseFloat(hid.dataset.costo)||0 : 0;
    total += costo * q;
  });
  const porc = Math.max(1, parseInt(document.getElementById('fc-porc').value) || 1);
  const costoPorc = total / porc;
  document.getElementById('costoTotal').textContent = 'S/ ' + total.toFixed(2);
  document.getElementById('costoPorc').textContent = 'S/ ' + costoPorc.toFixed(2);

  const precio = parseFloat(document.getElementById('fc-precio').value) || 0;
  const neto = precio > 0 ? precio / (1 + IGV/100) : 0;
  const elFc = document.getElementById('fc-fc');
  if (neto > 0) {
    const fc = costoPorc / neto;        // fracción
    const margen = (neto - costoPorc) / neto;
    document.getElementById('fc-neto').textContent = 'S/ ' + neto.toFixed(2);
    elFc.textContent = Math.round(fc*100) + '%';
    elFc.className = 'fc-badge ' + (fc<=0.35?'fc-ok':(fc<=0.42?'fc-warn':'fc-bad'));
    document.getElementById('fc-margen').textContent = 'S/ ' + (neto - costoPorc).toFixed(2) + ' · ' + Math.round(margen*100) + '%';
  } else {
    document.getElementById('fc-neto').textContent = '—';
    elFc.textContent = '—'; elFc.className = 'fc-badge';
    document.getElementById('fc-margen').textContent = '—';
  }

  const obj = parseFloat(document.getElementById('fc-obj').value) || 0;
  const sug = obj > 0 ? (costoPorc / (obj/100)) * (1 + IGV/100) : 0;
  document.getElementById('fc-sugerido').textContent = 'S/ ' + sug.toFixed(2);
}

document.addEventListener('click', e=>{ if(!e.target.closest('.add-wrap')){ const d=document.getElementById('rec-drop'); if(d) d.style.display='none'; } });
recalc();
</script>

<div id="ins-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.5);z-index:50;align-items:center;justify-content:center;padding:18px">
  <div style="width:340px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden">
    <div style="background:#fafafb;padding:13px 16px;border-bottom:1px solid var(--border,#eee);font-weight:800">Crear insumo: «<span id="ins-name"></span>»</div>
    <div style="padding:16px">
      <div class="form-group"><label>Unidad</label>
        <select id="ins-unidad"><option value="unidad">unidad</option><option value="g">gramos (g)</option><option value="ml">ml</option><option value="kg">kg</option><option value="l">l</option><option value="lonja">lonja</option><option value="porcion">porción</option></select></div>
      <div class="form-group"><label>Costo por unidad (opcional)</label><input id="ins-costo" inputmode="decimal" placeholder="0.00"></div>
    </div>
    <div style="display:flex;gap:8px;padding:0 16px 16px">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="insCerrar()">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="insCrear()">Crear y agregar</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Actualizar la lista `recetas.php` para incluir subrecetas en el costo**

En `admin/inventory/recetas.php`, reemplazar la consulta de `$prods` (líneas ~15-21) por una que traiga solo id/name/precio, y calcular costo/conteo con los helpers:

```php
    $prods = Database::fetchAll(
        "SELECT p.id, p.name,
                (SELECT lp.price FROM location_products lp WHERE lp.product_id=p.id AND lp.location_id=? LIMIT 1) AS precio
         FROM products p WHERE p.active=1 ORDER BY p.name",
        [$pid]
    );
    foreach ($prods as &$pr) {
        $pr['n_insumos'] = count(recetaComponentes((int)$pr['id']));
        $pr['costo']     = recetaCosto((int)$pr['id']);
    }
    unset($pr);
```

(El resto de `recetas.php` ya usa `$p['n_insumos']`, `$p['costo']`, `$p['precio']` y calcula food cost inline — no cambia. La etiqueta «X insumos» seguirá sirviendo; describe nº de componentes.)

- [ ] **Step 3: Actualizar `insumos.php` (conteo de recetas que usan el insumo)**

En `admin/inventory/insumos.php` línea 23, reemplazar:

```php
                (SELECT COUNT(*) FROM recetas r WHERE r.insumo_id = i.id) AS n_recetas,
```

por (cuenta en componentes si la tabla existe; si no, en recetas):

```php
                (SELECT COUNT(*) FROM receta_componentes rc WHERE rc.tipo='insumo' AND rc.ref_id = i.id) AS n_recetas,
```

> Si la migración 60 no está aplicada en algún entorno, esta subconsulta fallará. Para tolerar eso, envolver la página igual que hoy con `inventarioListo()`; `receta_componentes` se crea en la misma fase. Si se requiere degradación, ver nota de revisión: el patrón del repo asume migración aplicada tras desplegar. Mantener la versión `receta_componentes` (la migración es parte de esta entrega).

- [ ] **Step 4: Verificar sintaxis de los tres archivos**

Run: `php -l admin/inventory/receta_form.php && php -l admin/inventory/recetas.php && php -l admin/inventory/insumos.php`
Expected: `No syntax errors detected` en los tres.

- [ ] **Step 5: Verificación funcional manual (checklist, sin DB local)**

Revisar a ojo, contra el código:
- El POST de `receta_form.php` escribe `receta_componentes` (no `recetas`) cuando `recetaComponentesListo()` es true, y hace upsert de `receta_ficha`.
- El selector usa `componentes_buscar` y cada fila lleva `comp_tipo[]` + `comp_ref[]` + `cantidad[]`.
- `recalc()` calcula costo, costo/porción, food cost %, margen y precio sugerido con la misma fórmula que `includes/costeo.php` (IGV de `getSetting`).
- Sin emojis (el placeholder 🔍 fue reemplazado por texto).

- [ ] **Step 6: Commit**

```bash
git add admin/inventory/receta_form.php admin/inventory/recetas.php admin/inventory/insumos.php
git commit -m "feat(costeo): receta con insumos+subrecetas, ficha técnica y simulador de food cost"
```

---

## Self-Review

**1. Spec coverage:**
- Subrecetas (`subrecetas`+`subreceta_items`, costo/UM) → Tasks 2,3,5,6. ✓
- `receta_componentes` + backfill → Task 2; lecturas → Task 3; escritura → Task 7. ✓
- Ficha técnica (`receta_ficha`, imprimible) → Tasks 2,7. ✓
- Food cost en vivo + precio jugable (solo simulación) + precio sugerido → Task 7 (JS) sobre matemática de Task 1. ✓
- Subrecetas explotan a insumos (venta + salida masiva/eventos) → Task 3 (`recetaExplotaInsumos` en `descontarStockPedido` y `eventoConsumoTeorico`). ✓
- Búsqueda en vivo + crear al vuelo → Tasks 4,5,7 (patrón inline existente, no EGCombo — desviación documentada en Global Constraints). ✓
- Sin permiso nuevo en Fase 1 (subrecetas bajo `inv_recetas`) → Tasks 5,6. ✓ (`inv_costeo` = Fase 2, fuera de alcance.)
- Dashboard de Costeo → **Fase 2, no en este plan** (declarado fuera de alcance). ✓

**2. Placeholder scan:** Sin TBD/TODO; todo paso con código u orden concreta. ✓

**3. Type consistency:** `recetaExplotaInsumos`→`recetaCosto`/`descontarStockPedido`/`eventoConsumoTeorico` (insumo_id=>cantidad) consistente. API `componentes_buscar` devuelve `{tipo,id,nombre,unidad,costo}`; `recAgregar(tipo,id,nombre,unidad,costo)` y las filas `comp_tipo[]`/`comp_ref[]` lo consumen igual. `foodCostCalc`/`precioSugerido` (fracción) ↔ JS replica la misma fórmula. ✓

**Desviación de spec anotada:** el spec mencionaba `EGCombo`; se sigue el patrón inline ya usado por `receta_form.php`/`api/insumos.php` por consistencia con el código existente.
