# Subrecetas con stock + producción · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que una subreceta marcada como "lleva stock" se produzca por lotes (consumiendo insumos), tenga stock propio por local, se despache entre locales, y al venderse descuente de su stock en vez de explotar insumos.

**Architecture:** Capa paralela a los insumos: tablas `subreceta_stock` + `subreceta_movimientos` con helpers espejo (`subMovimiento`/`subProducir`/`subTransferir`) en `includes/inventario.php`. La venta usa un nuevo `recetaConsumo()` que reparte cada componente entre insumos y subrecetas-con-stock (las sin stock se explotan a insumos como hoy). El reparto puro vive en `includes/costeo.php` y tiene tests. El costeo (`recetaCosto`/simulador) NO cambia.

**Tech Stack:** PHP 8.0+ puro + PDO (clase `Database`), MySQL/MariaDB, JS vanilla inline, layout admin existente.

## Global Constraints

- **Sin emojis pictográficos** en ninguna superficie. Símbolos tipográficos (`✕ − × · →`) o SVG de línea.
- **SQL siempre con `?`** (prepared statements); nunca concatenar variables de usuario.
- **`verifyCsrf()`** en todo POST de admin.
- **Permisos:** `produccion.php` y la extensión de despacho en `operar.php` → `inv_stock` (misma familia que Operar/Stock). El toggle en el editor → `inv_recetas`. La vista de stock → `inv_stock`.
- **Colores de marca por variable** con fallback (`var(--c-brand,#FFDF00)`, `var(--pink,#FFBBC8)`, `var(--black,#1E1E1E)`). El rojo de alerta `#dc2626` es semántico.
- **Stock puede ir NEGATIVO** (nunca se bloquea la venta; el negativo es la alerta). Coherente con el inventario actual.
- **Costeo teórico:** el food cost no cambia; sigue usando `subrecetaCostoUM`. Las subrecetas NO guardan costo de producción.
- **Producción multi-local:** cualquier ubicación de `ubicacionesConInventario()` (`activa=1 OR es_almacen=1`).
- **Tolerancia a migración no aplicada:** guard `subrecetaStockListo()` en todo helper que toque las tablas nuevas (try/catch → vacío/0/false); el toggle `lleva_stock` solo se lee/escribe si `subrecetaStockListo()`.
- **Eventos fuera de alcance:** `eventoConsumoTeorico`/`salida_evento.php` NO se tocan (siguen explotando a insumos).
- Layout admin estándar; `require config/database/helpers/inventario` → `requirePermission` → `$pageTitle`/`$activePage` → `layout-top`/`layout-bottom`.

## File Structure

- `includes/costeo.php` — **modificar**. Agregar `repartirConsumo()` (pura, testeable).
- `tests/subreceta_consumo_test.php` — **nuevo**. Aserciones de `repartirConsumo` (sin DB).
- `install/61_subrecetas_stock.sql` — **nuevo**. `ALTER subrecetas ADD lleva_stock` + tablas `subreceta_stock`, `subreceta_movimientos`.
- `install/check_migraciones.sql` — **modificar**. Fila #61.
- `includes/inventario.php` — **modificar**. `subrecetaStockListo`, `subMovimiento`, `subProducir`, `subTransferir`, `recetaConsumo`; switch en `descontarStockPedido`.
- `admin/inventory/subreceta_form.php` — **modificar**. Toggle `lleva_stock`.
- `admin/inventory/produccion.php` — **nuevo**. Pantalla de producción.
- `admin/layout-top.php` — **modificar**. Link "Producción".
- `admin/inventory/operar.php` — **modificar**. Despacho del almacén incluye subrecetas.
- `admin/inventory/stock.php` — **modificar**. Sección "Subrecetas" (stock por local).

---

### Task 1: Reparto puro venta (insumos vs subrecetas-con-stock) + tests

**Files:**
- Modify: `includes/costeo.php` (agregar función al final, antes del cierre)
- Test: `tests/subreceta_consumo_test.php`

**Interfaces:**
- Produces: `repartirConsumo(array $componentes, callable $subLoader): array` → `['insumos'=>[insumo_id=>cantidad], 'subrecetas'=>[subreceta_id=>cantidad]]`.
  - `$componentes`: filas `['tipo'=>'insumo'|'subreceta','ref_id'=>int,'cantidad'=>float]`.
  - `$subLoader(int $subId)`: devuelve `['lleva_stock'=>bool,'rendimiento'=>float,'items'=>[['insumo_id'=>int,'cantidad'=>float],...]]` o `null`.
  - Regla: insumo → `insumos`; subreceta con `lleva_stock` → `subrecetas`; subreceta sin stock → explota a `insumos` con `(item.cantidad/rendimiento)×cantidad` (rendimiento ≤0 → se omite). Acumula (`+=`).

- [ ] **Step 1: Write the failing test**

Create `tests/subreceta_consumo_test.php`:

```php
<?php
require __DIR__ . '/../includes/costeo.php';

$fails = 0;
function check($label, $got, $exp, $eps = 0.0001) {
    global $fails;
    $ok = abs((float)$got - (float)$exp) < $eps;
    if (!$ok) { $fails++; echo "FAIL: $label — got " . var_export($got, true) . ", expected " . var_export($exp, true) . "\n"; }
    else { echo "ok: $label\n"; }
}

// Loader de prueba: sub 10 lleva stock (rend 4); sub 20 NO lleva stock (rend 2, insumo 3 x6); sub 30 rend 0
$loader = function($id) {
    if ($id === 10) return ['lleva_stock'=>true,  'rendimiento'=>4.0, 'items'=>[['insumo_id'=>1,'cantidad'=>8],['insumo_id'=>2,'cantidad'=>4]]];
    if ($id === 20) return ['lleva_stock'=>false, 'rendimiento'=>2.0, 'items'=>[['insumo_id'=>3,'cantidad'=>6]]];
    if ($id === 30) return ['lleva_stock'=>false, 'rendimiento'=>0.0, 'items'=>[['insumo_id'=>4,'cantidad'=>5]]];
    return null;
};

$comp = [
    ['tipo'=>'insumo',    'ref_id'=>1,  'cantidad'=>2],     // insumo directo
    ['tipo'=>'subreceta', 'ref_id'=>10, 'cantidad'=>1],     // con stock -> subrecetas[10]=1 (NO explota)
    ['tipo'=>'subreceta', 'ref_id'=>20, 'cantidad'=>0.5],   // sin stock -> insumo 3 += (6/2)*0.5 = 1.5
    ['tipo'=>'subreceta', 'ref_id'=>30, 'cantidad'=>1],     // rendimiento 0 -> se omite
];
$r = repartirConsumo($comp, $loader);

check('insumo 1 directo', $r['insumos'][1] ?? 0, 2);
check('insumo 3 explotado de sub20', $r['insumos'][3] ?? 0, 1.5);
check('insumo 2 NO aparece (sub10 lleva stock)', isset($r['insumos'][2]) ? 1 : 0, 0);
check('insumo 4 NO aparece (rend 0)', isset($r['insumos'][4]) ? 1 : 0, 0);
check('subreceta 10 con stock', $r['subrecetas'][10] ?? 0, 1);
check('subreceta 20 NO en subrecetas', isset($r['subrecetas'][20]) ? 1 : 0, 0);

// Acumulación: mismo insumo dos veces
$r2 = repartirConsumo([
    ['tipo'=>'insumo','ref_id'=>5,'cantidad'=>1],
    ['tipo'=>'insumo','ref_id'=>5,'cantidad'=>3],
], $loader);
check('acumula insumo 5', $r2['insumos'][5] ?? 0, 4);

echo $fails === 0 ? "\nALL OK\n" : "\n$fails FAIL(S)\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/subreceta_consumo_test.php`
Expected: FAIL — `Call to undefined function repartirConsumo()`.

- [ ] **Step 3: Write minimal implementation**

En `includes/costeo.php`, antes del final del archivo, agregar:

```php
if (!function_exists('repartirConsumo')) {
    /**
     * Reparte el consumo de una receta entre insumos y subrecetas-con-stock.
     * Las subrecetas sin stock se explotan a insumos; las con stock se descuentan aparte.
     * @param array $componentes filas ['tipo'=>'insumo'|'subreceta','ref_id'=>int,'cantidad'=>float]
     * @param callable $subLoader fn(int $subId): ['lleva_stock'=>bool,'rendimiento'=>float,'items'=>[['insumo_id'=>int,'cantidad'=>float],...]]|null
     * @return array ['insumos'=>[insumo_id=>cantidad], 'subrecetas'=>[subreceta_id=>cantidad]]
     */
    function repartirConsumo(array $componentes, callable $subLoader): array
    {
        $insumos = [];
        $subs = [];
        foreach ($componentes as $c) {
            $cant = (float)($c['cantidad'] ?? 0);
            $ref  = (int)($c['ref_id'] ?? 0);
            $tipo = (($c['tipo'] ?? 'insumo') === 'subreceta') ? 'subreceta' : 'insumo';
            if ($cant <= 0 || $ref <= 0) continue;
            if ($tipo === 'insumo') {
                $insumos[$ref] = ($insumos[$ref] ?? 0) + $cant;
                continue;
            }
            $info = $subLoader($ref);
            if (!$info) continue;
            if (!empty($info['lleva_stock'])) {
                $subs[$ref] = ($subs[$ref] ?? 0) + $cant;
            } else {
                $rend = (float)($info['rendimiento'] ?? 0);
                if ($rend <= 0) continue;
                foreach (($info['items'] ?? []) as $it) {
                    $iid = (int)($it['insumo_id'] ?? 0);
                    if ($iid <= 0) continue;
                    $insumos[$iid] = ($insumos[$iid] ?? 0) + ((float)($it['cantidad'] ?? 0) / $rend) * $cant;
                }
            }
        }
        return ['insumos' => $insumos, 'subrecetas' => $subs];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/subreceta_consumo_test.php`
Expected: `ALL OK`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/costeo.php tests/subreceta_consumo_test.php
git commit -m "feat(subrecetas): reparto puro consumo (insumos vs subrecetas-con-stock) + tests"
```

---

### Task 2: Migración 61 (lleva_stock + subreceta_stock + subreceta_movimientos)

**Files:**
- Create: `install/61_subrecetas_stock.sql`
- Modify: `install/check_migraciones.sql` (fila #61 antes del `) m;` final)

**Interfaces:**
- Produces (esquema): `subrecetas.lleva_stock TINYINT`, `subreceta_stock(subreceta_id,ubicacion_id,stock,stock_min)` PK(subreceta_id,ubicacion_id), `subreceta_movimientos(id,ubicacion_id,subreceta_id,tipo,cantidad,costo_unitario,motivo,ref,pedido_id,user_id,created_at)`.

- [ ] **Step 1: Crear la migración**

Create `install/61_subrecetas_stock.sql`:

```sql
-- 61_subrecetas_stock.sql — Subrecetas con stock + producción por lotes. Idempotente.

-- Toggle opt-in: la subreceta se produce y lleva stock propio.
ALTER TABLE `subrecetas`
  ADD COLUMN IF NOT EXISTS `lleva_stock` TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `subreceta_stock` (
  `subreceta_id` INT UNSIGNED NOT NULL,
  `ubicacion_id` INT UNSIGNED NOT NULL,
  `stock`        DECIMAL(12,3) NOT NULL DEFAULT 0.000,   -- puede ir negativo
  `stock_min`    DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`subreceta_id`,`ubicacion_id`),
  KEY `idx_ss_ubi` (`ubicacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `subreceta_movimientos` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ubicacion_id`   INT UNSIGNED NOT NULL,
  `subreceta_id`   INT UNSIGNED NOT NULL,
  `tipo`           ENUM('produccion','transferencia','venta','ajuste','merma') NOT NULL,
  `cantidad`       DECIMAL(12,3) NOT NULL,
  `costo_unitario` DECIMAL(10,4) NULL,
  `motivo`         VARCHAR(160) NULL,
  `ref`            VARCHAR(60)  NULL,
  `pedido_id`      INT UNSIGNED NULL,
  `user_id`        INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sm_sub` (`subreceta_id`),
  KEY `idx_sm_ubi` (`ubicacion_id`),
  KEY `idx_sm_pedido` (`pedido_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

> Nota: `ADD COLUMN IF NOT EXISTS` es válido en MariaDB 10.3+ (el target del proyecto). Si el motor fuese MySQL puro sin soporte, se aplicaría sin `IF NOT EXISTS` una sola vez; el proyecto corre MariaDB, así que se mantiene idempotente.

- [ ] **Step 2: Agregar la fila al verificador**

En `install/check_migraciones.sql`, justo **antes** del `) m;` final, agregar:

```sql
  UNION ALL SELECT '61 subrecetas_stock        (subrecetas.lleva_stock)', COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='subrecetas' AND column_name='lleva_stock'
```

- [ ] **Step 3: Verificar**

Run: `grep -c "CREATE TABLE IF NOT EXISTS" install/61_subrecetas_stock.sql`
Expected: `2`

- [ ] **Step 4: Commit**

```bash
git add install/61_subrecetas_stock.sql install/check_migraciones.sql
git commit -m "feat(subrecetas): migración 61 — lleva_stock + subreceta_stock + subreceta_movimientos"
```

---

### Task 3: Helpers de stock/producción + switch de venta (`includes/inventario.php`)

**Files:**
- Modify: `includes/inventario.php` (agregar helpers tras los de subrecetas existentes; modificar `descontarStockPedido`)

**Interfaces:**
- Consumes: `repartirConsumo()` (Task 1), `recetaComponentes()` (existente), `invMovimiento()` (existente).
- Produces:
  - `subrecetaStockListo(): bool`
  - `subMovimiento(int $ubi, int $subId, string $tipo, float $cant, array $opts=[]): int`
  - `subProducir(int $ubi, int $subId, float $lotes): array` → `['ok'=>bool,'producido'=>float,'ref'=>string,'error'=>string]`
  - `subTransferir(int $origen, int $destino, array $subs, string $motivo='Despacho'): string`
  - `recetaConsumo(int $productId): array` → `['insumos'=>[id=>cant],'subrecetas'=>[id=>cant]]`

- [ ] **Step 1: Agregar los helpers**

En `includes/inventario.php`, después de la función `recetaExplotaInsumos` (o junto a los helpers de subrecetas de la Fase 1), agregar:

```php
/** ¿Existe ya la capa de stock de subrecetas (migración 61)? */
function subrecetaStockListo(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (bool) Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='subreceta_stock'");
    } catch (Exception $e) { $ok = false; }
    return $ok;
}

/** Movimiento de stock de subreceta (espejo de invMovimiento). Devuelve id del movimiento (0 si falla). */
function subMovimiento(int $ubi, int $subId, string $tipo, float $cant, array $opts = []): int
{
    if (!$subId || !$ubi || $cant == 0 || !subrecetaStockListo()) return 0;
    try {
        $id = Database::insert(
            "INSERT INTO subreceta_movimientos (ubicacion_id,subreceta_id,tipo,cantidad,costo_unitario,motivo,ref,pedido_id,user_id)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [
                $ubi, $subId, $tipo, $cant,
                $opts['costo_unitario'] ?? null,
                $opts['motivo'] ?? null,
                $opts['ref'] ?? null,
                $opts['pedido_id'] ?? null,
                $opts['user_id'] ?? (currentUser()['id'] ?? null),
            ]
        );
        Database::execute(
            "INSERT INTO subreceta_stock (subreceta_id,ubicacion_id,stock) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE stock = stock + VALUES(stock)",
            [$subId, $ubi, $cant]
        );
        return $id;
    } catch (Exception $e) { return 0; }
}

/**
 * Produce N lotes de una subreceta en una ubicación: consume insumos y suma stock de subreceta.
 * Transaccional. Permite que insumos queden negativos (no bloquea). @return array
 */
function subProducir(int $ubi, int $subId, float $lotes): array
{
    if ($ubi <= 0 || $subId <= 0 || $lotes <= 0) return ['ok'=>false,'error'=>'Datos inválidos','producido'=>0,'ref'=>''];
    if (!subrecetaStockListo()) return ['ok'=>false,'error'=>'Falta aplicar la migración 61','producido'=>0,'ref'=>''];
    $s = Database::fetch("SELECT nombre, lleva_stock, rendimiento FROM subrecetas WHERE id=?", [$subId]);
    if (!$s || empty($s['lleva_stock'])) return ['ok'=>false,'error'=>'La subreceta no lleva stock','producido'=>0,'ref'=>''];
    $rend = (float)$s['rendimiento'];
    if ($rend <= 0) return ['ok'=>false,'error'=>'Rendimiento inválido','producido'=>0,'ref'=>''];
    $items = Database::fetchAll("SELECT insumo_id, cantidad FROM subreceta_items WHERE subreceta_id=?", [$subId]);
    if (!$items) return ['ok'=>false,'error'=>'La subreceta no tiene insumos','producido'=>0,'ref'=>''];

    $pdo = Database::getInstance();
    try {
        $pdo->beginTransaction();
        $ref = 'PRD-' . date('YmdHis') . '-' . $subId;
        $lotesTxt = rtrim(rtrim(number_format($lotes, 3, '.', ''), '0'), '.');
        foreach ($items as $it) {
            $consumo = (float)$it['cantidad'] * $lotes;
            $mid = invMovimiento($ubi, (int)$it['insumo_id'], 'ajuste', -$consumo,
                ['motivo' => 'Producción: ' . $s['nombre'], 'ref' => $ref]);
            if (!$mid) throw new \RuntimeException('Falló el consumo de un insumo');
        }
        $producido = $rend * $lotes;
        $sid = subMovimiento($ubi, $subId, 'produccion', $producido,
            ['motivo' => 'Producción · ' . $lotesTxt . ' lote(s)', 'ref' => $ref]);
        if (!$sid) throw new \RuntimeException('Falló el alta de stock de subreceta');
        $pdo->commit();
        return ['ok'=>true, 'producido'=>$producido, 'ref'=>$ref, 'error'=>''];
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok'=>false, 'error'=>'No se pudo registrar la producción', 'producido'=>0, 'ref'=>''];
    }
}

/** Despacha subrecetas entre locales (espejo de invTransferir). @param array $subs subreceta_id=>cantidad. */
function subTransferir(int $origen, int $destino, array $subs, string $motivo = 'Despacho'): string
{
    if ($origen <= 0 || $destino <= 0 || $origen === $destino || !subrecetaStockListo()) return '';
    $ref = 'TRF-' . date('YmdHis') . '-' . $origen . '-' . $destino;
    $pdo = Database::getInstance();
    try {
        $pdo->beginTransaction();
        $hubo = false;
        foreach ($subs as $sid => $cant) {
            $sid = (int)$sid; $cant = (float)$cant;
            if ($sid <= 0 || $cant <= 0) continue;
            $o = subMovimiento($origen,  $sid, 'transferencia', -$cant, ['motivo'=>$motivo, 'ref'=>$ref]);
            $i = subMovimiento($destino, $sid, 'transferencia',  $cant, ['motivo'=>$motivo.' (recibido)', 'ref'=>$ref]);
            if (!$o || !$i) throw new \RuntimeException('Falló una pata de la transferencia');
            $hubo = true;
        }
        if (!$hubo) { $pdo->rollBack(); return ''; }
        $pdo->commit();
        return $ref;
    } catch (\Throwable $e) { $pdo->rollBack(); return ''; }
}

/**
 * Reparte el consumo de venta de un producto: insumos a descontar + subrecetas-con-stock a descontar.
 * Las subrecetas sin stock se explotan a insumos. @return ['insumos'=>[id=>cant],'subrecetas'=>[id=>cant]]
 */
function recetaConsumo(int $productId): array
{
    $comps = recetaComponentes($productId);
    return repartirConsumo($comps, function ($subId) {
        try {
            $s = Database::fetch("SELECT lleva_stock, rendimiento FROM subrecetas WHERE id=?", [$subId]);
            if (!$s) return null;
            $items = Database::fetchAll("SELECT insumo_id, cantidad FROM subreceta_items WHERE subreceta_id=?", [$subId]);
            return ['lleva_stock' => !empty($s['lleva_stock']), 'rendimiento' => (float)$s['rendimiento'], 'items' => $items];
        } catch (Exception $e) {
            // Pre-migración (sin columna lleva_stock): tratar como sin stock -> explota (comportamiento Fase 1)
            try {
                $s = Database::fetch("SELECT rendimiento FROM subrecetas WHERE id=?", [$subId]);
                if (!$s) return null;
                $items = Database::fetchAll("SELECT insumo_id, cantidad FROM subreceta_items WHERE subreceta_id=?", [$subId]);
                return ['lleva_stock' => false, 'rendimiento' => (float)$s['rendimiento'], 'items' => $items];
            } catch (Exception $e2) { return null; }
        }
    });
}
```

- [ ] **Step 2: Cambiar `descontarStockPedido` para descontar insumos Y subrecetas**

En `descontarStockPedido`, reemplazar el bloque interno del `foreach ($items as $it)` (el que hoy usa `recetaExplotaInsumos`) por:

```php
        foreach ($items as $it) {
            $pid = (int)($it['product_id'] ?? 0);
            $qty = (float)($it['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) continue;
            $consumo = recetaConsumo($pid);
            $motivo = 'Venta · pedido #' . str_pad((string)$pedidoId, 3, '0', STR_PAD_LEFT);
            foreach ($consumo['insumos'] as $insumoId => $cant) {
                invMovimiento($ubi, (int)$insumoId, 'venta', -((float)$cant * $qty),
                    ['pedido_id' => $pedidoId, 'motivo' => $motivo]);
            }
            foreach ($consumo['subrecetas'] as $subId => $cant) {
                subMovimiento($ubi, (int)$subId, 'venta', -((float)$cant * $qty),
                    ['pedido_id' => $pedidoId, 'motivo' => $motivo]);
            }
        }
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l includes/inventario.php`
Expected: `No syntax errors detected in includes/inventario.php`

- [ ] **Step 4: Verificar que `descontarStockPedido` ya no usa `recetaExplotaInsumos`**

Run: `grep -n "recetaExplotaInsumos" includes/inventario.php`
Expected: las coincidencias quedan en `recetaCosto` y posiblemente `eventoConsumoTeorico` (que NO se tocan), pero NO dentro de `descontarStockPedido`. Leer el cuerpo de `descontarStockPedido` para confirmar que usa `recetaConsumo`.

- [ ] **Step 5: Commit**

```bash
git add includes/inventario.php
git commit -m "feat(subrecetas): subMovimiento/subProducir/subTransferir/recetaConsumo + venta descuenta stock de subreceta"
```

---

### Task 4: Toggle "lleva stock" en el editor de subreceta

**Files:**
- Modify: `admin/inventory/subreceta_form.php` (POST: guardar `lleva_stock`; UI: checkbox)

**Interfaces:**
- Consumes: `subrecetaStockListo()` (Task 3).

- [ ] **Step 1: Guardar `lleva_stock` en el POST (gated)**

En `admin/inventory/subreceta_form.php`, dentro del bloque `if ($_SERVER['REQUEST_METHOD'] === 'POST')`, tras leer `$rend`, agregar:

```php
    $llevaStock = (subrecetaStockListo() && !empty($_POST['lleva_stock'])) ? 1 : 0;
```

Y modificar las dos sentencias de guardado para incluir `lleva_stock` **solo si la capa existe**. Reemplazar el bloque actual de UPDATE/INSERT por:

```php
    if ($id) {
        if (subrecetaStockListo()) {
            Database::execute("UPDATE subrecetas SET nombre=?, unidad=?, rendimiento=?, lleva_stock=? WHERE id=?", [$nombre, $unidad, $rend, $llevaStock, $id]);
        } else {
            Database::execute("UPDATE subrecetas SET nombre=?, unidad=?, rendimiento=? WHERE id=?", [$nombre, $unidad, $rend, $id]);
        }
    } else {
        if (subrecetaStockListo()) {
            $id = Database::insert("INSERT INTO subrecetas (nombre,unidad,rendimiento,lleva_stock,activo) VALUES (?,?,?,?,1)", [$nombre, $unidad, $rend, $llevaStock]);
        } else {
            $id = Database::insert("INSERT INTO subrecetas (nombre,unidad,rendimiento,activo) VALUES (?,?,?,1)", [$nombre, $unidad, $rend]);
        }
    }
```

- [ ] **Step 2: Mostrar el toggle en el formulario**

En `admin/inventory/subreceta_form.php`, leer el valor actual: tras cargar `$sub`, no hace falta nada extra (usar `$sub['lleva_stock']`). En el grid de campos (nombre/rendimiento/unidad), **después** de ese grid y antes del label "Insumos de la preparación", agregar (solo si la capa existe):

```php
      <?php if (subrecetaStockListo()): ?>
      <label style="display:flex;align-items:center;gap:9px;margin:4px 0 16px;cursor:pointer;font-size:14px">
        <input type="checkbox" name="lleva_stock" value="1" <?= ($id && !empty($sub['lleva_stock'])) ? 'checked' : '' ?> style="width:18px;height:18px">
        <span><strong>Se produce y lleva stock.</strong> Se prepara por lote (consume insumos) y se descuenta de su propio stock al vender. Si lo dejás apagado, la subreceta explota a insumos como hasta ahora.</span>
      </label>
      <?php endif; ?>
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/inventory/subreceta_form.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add admin/inventory/subreceta_form.php
git commit -m "feat(subrecetas): toggle «se produce y lleva stock» en el editor"
```

---

### Task 5: Pantalla de producción + link en sidebar

**Files:**
- Create: `admin/inventory/produccion.php`
- Modify: `admin/layout-top.php` (link "Producción" tras "Operar")

**Interfaces:**
- Consumes: `subProducir()`, `subrecetaStockListo()` (Task 3); `ubicacionesConInventario()`, `inventarioListo()` (existentes). `$activePage='inv-produccion'`.

- [ ] **Step 1: Crear `produccion.php`**

Create `admin/inventory/produccion.php`:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_stock');
$ready = inventarioListo() && subrecetaStockListo();

$ubicaciones = $ready ? ubicacionesConInventario() : [];
$ubiF = cleanInt($_GET['ubi'] ?? ($_POST['ubicacion_id'] ?? 0)) ?: ($ubicaciones[0]['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    verifyCsrf();
    $ubi   = cleanInt($_POST['ubicacion_id'] ?? 0);
    $subId = cleanInt($_POST['subreceta_id'] ?? 0);
    $lotes = cleanFloat($_POST['lotes'] ?? 0);
    $res = subProducir($ubi, $subId, $lotes);
    if ($res['ok']) {
        $prod = rtrim(rtrim(number_format($res['producido'], 3, '.', ''), '0'), '.');
        flashMessage('success', "Producción registrada: +$prod de stock.");
    } else {
        flashMessage('error', $res['error'] ?: 'No se pudo registrar la producción.');
    }
    redirect('/admin/inventory/produccion.php?ubi=' . $ubi);
}

$subs = $ready ? Database::fetchAll(
    "SELECT id, nombre, unidad, rendimiento FROM subrecetas WHERE activo=1 AND lleva_stock=1 ORDER BY nombre"
) : [];
// Items por subreceta (para el preview de consumo)
$itemsBySub = [];
if ($subs) {
    foreach (Database::fetchAll(
        "SELECT si.subreceta_id, si.cantidad, i.nombre, i.unidad
           FROM subreceta_items si JOIN insumos i ON i.id=si.insumo_id
          WHERE si.subreceta_id IN (" . implode(',', array_map(fn($s) => (int)$s['id'], $subs)) . ")") as $r) {
        $itemsBySub[(int)$r['subreceta_id']][] = ['nombre'=>$r['nombre'],'unidad'=>$r['unidad'],'cantidad'=>(float)$r['cantidad']];
    }
}
function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$pageTitle  = 'Producción';
$activePage = 'inv-produccion';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left">
  <h1>Producción de subrecetas</h1>
  <p>Preparar un lote consume insumos y suma stock de la subreceta en este local</p>
</div></div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <h3>Falta aplicar la migración</h3>
    <p>Aplica <code>install/61_subrecetas_stock.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php elseif (empty($subs)): ?>
  <div class="card"><div class="empty-state">
    <h3>No hay subrecetas con stock</h3>
    <p>Marca «Se produce y lleva stock» en una subreceta para poder producirla.</p>
  </div></div>
<?php else: ?>

<form method="post" class="card" style="max-width:560px"><div class="card-body">
  <?= csrfField() ?>
  <div class="form-group"><label>Local de producción</label>
    <select name="ubicacion_id">
      <?php foreach ($ubicaciones as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $ubiF==$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option>
      <?php endforeach; ?>
    </select></div>

  <div class="form-group"><label>Subreceta</label>
    <select name="subreceta_id" id="pr-sub" onchange="prPreview()">
      <?php foreach ($subs as $s): ?>
        <option value="<?= (int)$s['id'] ?>" data-rend="<?= (float)$s['rendimiento'] ?>" data-unidad="<?= clean($s['unidad']) ?>"><?= clean($s['nombre']) ?> (rinde <?= nf($s['rendimiento']) ?> <?= clean($s['unidad']) ?>/lote)</option>
      <?php endforeach; ?>
    </select></div>

  <div class="form-group"><label>Lotes a preparar</label>
    <input type="text" inputmode="decimal" name="lotes" id="pr-lotes" value="1" oninput="prPreview()" style="max-width:140px"></div>

  <div id="pr-preview" style="background:#fafafb;border:1px solid var(--border,#eee);border-radius:10px;padding:14px;margin:6px 0 16px;font-size:13px"></div>

  <button type="submit" class="btn btn-primary">Registrar producción</button>
</div></form>

<script>
const PR_ITEMS = <?= json_encode($itemsBySub, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
function prPreview(){
  const sel = document.getElementById('pr-sub');
  const opt = sel.options[sel.selectedIndex];
  const rend = parseFloat(opt.dataset.rend)||0;
  const unidad = opt.dataset.unidad||'';
  const lotes = parseFloat(document.getElementById('pr-lotes').value)||0;
  const items = PR_ITEMS[sel.value] || [];
  let html = '<strong>Producirá:</strong> ' + (rend*lotes).toFixed(3).replace(/\.?0+$/,'') + ' ' + unidad + ' de stock<br><strong>Consume:</strong>';
  if (!items.length) { html += ' (sin insumos)'; }
  else {
    html += '<ul style="margin:6px 0 0;padding-left:18px">';
    items.forEach(function(it){ html += '<li>' + it.nombre + ': ' + (it.cantidad*lotes).toFixed(3).replace(/\.?0+$/,'') + ' ' + it.unidad + '</li>'; });
    html += '</ul>';
  }
  document.getElementById('pr-preview').innerHTML = html;
}
prPreview();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Agregar el link al sidebar**

En `admin/layout-top.php`, buscar el link de "Operar" (`inv-operar` / `operar.php`) dentro del grupo Inventario. Justo **después** de su bloque `<?php endif; ?>`, agregar:

```php
        <?php if (can('inv_stock')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/produccion.php"
           class="nav-link <?php echo ($activePage??'')==='inv-produccion'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v6l4 2M6 8l-3 4v8a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-8l-3-4"/></svg></span> Producción
        </a>
        <?php endif; ?>
```

(Si no existe un link "Operar" con `inv-operar`, insertarlo tras el link de "Stock" en el grupo Inventario, manteniendo el patrón `<?php if (can('inv_stock')): ?>...<?php endif; ?>`.)

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/inventory/produccion.php && php -l admin/layout-top.php`
Expected: ambos `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add admin/inventory/produccion.php admin/layout-top.php
git commit -m "feat(subrecetas): pantalla de producción por lotes + link en sidebar"
```

---

### Task 6: Despacho de subrecetas en `operar.php`

**Files:**
- Modify: `admin/inventory/operar.php` (rama almacén/salidas: incluir subrecetas en el despacho)

**Interfaces:**
- Consumes: `subTransferir()`, `subrecetaStockListo()` (Task 3).

- [ ] **Step 1: Manejar las subrecetas en el POST del despacho**

En `admin/inventory/operar.php`, dentro de `if ($modo === 'salidas') { if ($esAlmacen) { ... } }`, **después** de calcular `$ref = invTransferir(...)` y su flash (pero antes del `_back(...)`), reemplazar el cierre por una versión que también despache subrecetas. Concretamente, sustituir:

```php
            $ref = invTransferir($ubiF, $destino, $items, 'Despacho');
            flashMessage($ref ? 'success' : 'error', $ref ? 'Despacho realizado: ' . count($items) . ' insumo(s) transferido(s).' : 'No se pudo completar el despacho.');
            _back($ubiF, 'salidas');
```

por:

```php
            // Subrecetas a despachar (si la capa existe)
            $subItems = [];
            if (subrecetaStockListo()) {
                $cantSub = is_array($_POST['cant_sub'] ?? null) ? $_POST['cant_sub'] : [];
                $subStock = [];
                foreach (Database::fetchAll("SELECT subreceta_id, stock FROM subreceta_stock WHERE ubicacion_id=?", [$ubiF]) as $ss) {
                    $subStock[(int)$ss['subreceta_id']] = (float)$ss['stock'];
                }
                foreach ($cantSub as $sid => $v) {
                    $sid = (int)$sid; $v = cleanFloat($v);
                    if ($sid <= 0 || $v <= 0) continue;
                    if ($v > ($subStock[$sid] ?? 0) + 0.0001) { $faltan++; continue; }
                    $subItems[$sid] = $v;
                }
                if ($faltan) {
                    flashMessage('error', "No hay stock suficiente en $faltan ítem(s). No se despachó nada — corrige las cantidades.");
                    _back($ubiF, 'salidas');
                }
            }
            if (!$items && !$subItems) { flashMessage('error', 'No ingresaste cantidades.'); _back($ubiF, 'salidas'); }
            $refI = $items    ? invTransferir($ubiF, $destino, $items, 'Despacho')    : '';
            $refS = $subItems ? subTransferir($ubiF, $destino, $subItems, 'Despacho') : '';
            $okI = !$items || $refI; $okS = !$subItems || $refS;
            flashMessage(($okI && $okS) ? 'success' : 'error',
                ($okI && $okS)
                    ? 'Despacho realizado: ' . count($items) . ' insumo(s) y ' . count($subItems) . ' subreceta(s).'
                    : 'No se pudo completar parte del despacho.');
            _back($ubiF, 'salidas');
```

> Nota: el chequeo `if (!$items)` previo (línea ~61 que abortaba con "No ingresaste cantidades") debe quitarse/ajustarse para no abortar antes de evaluar subrecetas. Verificar al editar que esa guardia anterior no corte el flujo (la nueva guardia `if (!$items && !$subItems)` la reemplaza).

- [ ] **Step 2: Agregar la sección de subrecetas al formulario de despacho**

En `admin/inventory/operar.php`, en el bloque que renderiza el despacho del almacén (`<?php if ($modo === 'salidas' && $esAlmacen): ?>` / la tabla de insumos), agregar **después** de la tabla de insumos una sección para subrecetas con stock en el origen (solo si la capa existe):

```php
  <?php if (subrecetaStockListo()):
      $subDisp = Database::fetchAll(
          "SELECT s.id, s.nombre, s.unidad, COALESCE(ss.stock,0) stock
             FROM subrecetas s JOIN subreceta_stock ss ON ss.subreceta_id=s.id AND ss.ubicacion_id=?
            WHERE s.activo=1 AND s.lleva_stock=1 AND ss.stock > 0 ORDER BY s.nombre", [$ubiF]);
      if ($subDisp): ?>
  <div class="card" style="margin-top:16px"><div class="card-header"><span class="card-title">Subrecetas a despachar</span></div>
    <div class="table-wrap" style="border:none;border-radius:0"><table class="data-table">
      <thead><tr><th>Subreceta</th><th>Disponible</th><th style="width:160px">Cantidad a despachar</th></tr></thead>
      <tbody>
        <?php foreach ($subDisp as $s): ?>
        <tr>
          <td><strong><?= clean($s['nombre']) ?></strong></td>
          <td><?= nf($s['stock']) ?> <span style="color:var(--text-muted);font-size:12px"><?= clean($s['unidad']) ?></span></td>
          <td><input type="text" inputmode="decimal" name="cant_sub[<?= (int)$s['id'] ?>]" placeholder="0" style="width:120px"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; endif; ?>
```

(Esta sección debe quedar **dentro del mismo `<form>`** del despacho. Verificar al editar que el `<form>` envuelve tanto la tabla de insumos como esta sección, y que `nf()` está disponible en `operar.php` — si no, usar `number_format`.)

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/inventory/operar.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add admin/inventory/operar.php
git commit -m "feat(subrecetas): despacho del almacén incluye subrecetas (subTransferir)"
```

---

### Task 7: Stock de subrecetas en `stock.php`

**Files:**
- Modify: `admin/inventory/stock.php` (sección "Subrecetas" mostrando `subreceta_stock` del local)

**Interfaces:**
- Consumes: `subrecetaStockListo()` (Task 3). Reusa `$ubiF`, `$ready`, `nf()` de `stock.php`.

- [ ] **Step 1: Cargar el stock de subrecetas**

En `admin/inventory/stock.php`, dentro del `if ($ready && $ubiF) { ... }` (tras armar `$rows` de insumos), agregar:

```php
    $subRows = [];
    if (subrecetaStockListo()) {
        $subRows = Database::fetchAll(
            "SELECT s.id, s.nombre, s.unidad,
                    COALESCE(ss.stock,0) stock, COALESCE(ss.stock_min,0) stock_min
             FROM subrecetas s
             LEFT JOIN subreceta_stock ss ON ss.subreceta_id = s.id AND ss.ubicacion_id = ?
             WHERE s.activo = 1 AND s.lleva_stock = 1
             ORDER BY (COALESCE(ss.stock,0) <= COALESCE(ss.stock_min,0)) DESC, s.nombre",
            [$ubiF]
        );
    }
```

(Si `$ready` es false, `$subRows` queda `[]`. Inicializar `$subRows = [];` junto a `$rows = [];` arriba para que exista siempre.)

- [ ] **Step 2: Renderizar la sección de subrecetas**

En `admin/inventory/stock.php`, **después** del `<div class="card">` que cierra la tabla de insumos (antes del `<?php endif; ?>` final del `else`), agregar:

```php
<?php if (!empty($subRows)): ?>
<div class="card" style="margin-top:18px">
  <div class="card-header"><span class="card-title">Subrecetas (stock propio)</span></div>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th>Subreceta</th><th>Stock</th><th>Mínimo</th></tr></thead>
      <tbody>
        <?php foreach ($subRows as $r): $low = $r['stock'] <= $r['stock_min']; $neg = $r['stock'] < 0; ?>
        <tr<?= $low ? ' style="background:rgba(220,38,38,.05)"' : '' ?>>
          <td>
            <strong><?= clean($r['nombre']) ?></strong>
            <?php if ($neg): ?><span class="badge badge-danger" style="margin-left:6px;font-size:10px">Negativo</span>
            <?php elseif ($low): ?><span class="badge badge-danger" style="margin-left:6px;font-size:10px">Bajo mínimo</span><?php endif; ?>
          </td>
          <td><strong style="<?= $low?'color:#dc2626':'' ?>"><?= nf($r['stock']) ?></strong> <span style="color:var(--text-muted);font-size:12px"><?= clean($r['unidad']) ?></span></td>
          <td style="color:var(--text-secondary)"><?= nf($r['stock_min']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/inventory/stock.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add admin/inventory/stock.php
git commit -m "feat(subrecetas): sección de stock de subrecetas en Stock por ubicación"
```

---

## Self-Review

**1. Spec coverage:**
- `subrecetas.lleva_stock` + `subreceta_stock` + `subreceta_movimientos` → Task 2. ✓
- `subMovimiento`/`subProducir`/`subTransferir`/`recetaConsumo` → Task 3. ✓
- Venta descuenta stock de subreceta (con stock) / explota (sin stock) → Task 1 (reparto) + Task 3 (`descontarStockPedido`). ✓
- Negativos permitidos → ninguna guardia bloquea el descuento de venta (subMovimiento no valida stock). ✓
- Producción simple (lotes, sin merma), multi-local → Task 5 (`subProducir` consume insumos×lotes, suma rendimiento×lotes; selector `ubicacionesConInventario`). ✓
- Despacho extendido a subrecetas → Task 6. ✓
- Toggle en editor → Task 4. ✓
- Vista de stock de subrecetas → Task 7. ✓
- Costeo sin cambios (teórico) → no se toca `recetaCosto`/simulador. ✓
- Eventos fuera de alcance → `eventoConsumoTeorico`/`salida_evento` no se tocan. ✓
- Guards `subrecetaStockListo()` en todos los helpers + UI. ✓

**2. Placeholder scan:** sin TBD/TODO; todo paso con código u orden concreta. ✓

**3. Type consistency:** `repartirConsumo`/`recetaConsumo` devuelven `['insumos'=>[id=>cant],'subrecetas'=>[id=>cant]]`, consumido igual en `descontarStockPedido`. `subProducir` devuelve `['ok','producido','ref','error']`, consumido en `produccion.php`. `subMovimiento(ubi,subId,tipo,cant,opts)` y `subTransferir(origen,destino,subs,motivo)` consistentes entre Task 3, 5 y 6. ✓

**Nota de desviación del spec:** el spec sugirió gatear producción con `inv_movimientos`; el plan usa `inv_stock` para alinearse con su página hermana `operar.php`/`stock.php` (ambas `inv_stock`). El `stock_min` de subrecetas se muestra (no se edita en `stock.php`, igual que los insumos, que se ajustan en `ajuste.php`); editar el mínimo de subrecetas queda como mejora futura.
