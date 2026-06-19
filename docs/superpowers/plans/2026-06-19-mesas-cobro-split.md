# Mesas POS Sub-build C — Precuenta, Split & Cobro · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir imprimir una precuenta no fiscal, dividir la cuenta de una mesa en 4 modos, cobrar cada parte con pago mixto y comprobante opcional, cerrar la cuenta y que el dinero entre al arqueo del turno y al dashboard.

**Architecture:** Separación de tres conceptos sobre la cuenta de mesa: **consumo** = comandas (`pedidos origen='mesa'`, ya existen), **dinero** = nueva tabla `cuenta_pagos` (única fuente de verdad de lo cobrado, soporta mixto/split), **fiscal** = pedido-comprobante por parte (reusa `nubefactEmitir`). El mozo cobra desde su app; el dinero se asigna al turno de caja abierto del local. La lógica vive en `includes/cuentas.php`; la app la consume vía `api/mozo.php`; el arqueo (`api/pos.php`) y el dashboard suman `cuenta_pagos`.

**Tech Stack:** PHP 8 puro + PDO (clase `Database`), MySQL/MariaDB, JS vanilla inline, ESC/POS 80mm vía RawBT, NubeFact (SUNAT). Sin framework de tests.

## Global Constraints

- **SQL:** nunca concatenar variables; siempre `?` con prepared statements. Migraciones idempotentes con guards de columna (patrón `information_schema` de `install/57_cuentas.sql`).
- **Seguridad API mozo:** gate por **sesión de mozo** (`mozoEmp()`/`mozoUbi()` en `api/mozo.php`), NO `requireLogin`. `verifyCsrf()` en escrituras (token por header `X-CSRF-Token`). Geocerca dura (`dentroGeocerca`) en el cobro. Sanitizar con `clean*()`.
- **Multi-local:** toda lectura/escritura scopeada por `ubicacion_id` con el patrón `(? = 0 OR ubicacion_id = ?)` o filtro explícito por `mozoUbi()`.
- **Dinero:** `cuenta_pagos` es la única fuente de verdad de lo cobrado en mesas. Nunca sumar `cuentas.total` como venta. Las comandas (`origen='mesa'`) nunca entran al arqueo (guard `p.origen <> 'mesa'`).
- **Marca:** variables de `brandHead()` con fallback (amarillo `var(--c-brand,#FFDF00)`, rosa `var(--pink,#FFBBC8)`, negro `var(--black,#1E1E1E)`); nunca hex de marca hardcodeado. `mozo/index.php` ya incluye `brandHead()`.
- **Comprobantes:** `nubefactEmitir($pedidoId)` resuelve serie/correlativo por local + IGV de settings; nunca lanza excepción; precios incluyen IGV (el motor desagrega).
- **Verificación (no hay tests formales):** `php -l <archivo>` en cada PHP tocado; scripts PHP de aserción para lógica pura; `node --check` donde haya JS extraíble; checklist funcional. La BD de dev puede no existir: las migraciones se aplican en el servidor (phpMyAdmin); su verificación local es estructural.
- **Estilo:** español peruano; `formatMoney` = `S/ 1,234.50`; TZ America/Lima; utf8mb4.

---

### Task 1: Migración `58_cobro_mesas.sql`

**Files:**
- Create: `install/58_cobro_mesas.sql`
- Modify: `install/check_migraciones.sql` (añadir fila de detección)

**Interfaces:**
- Produces: tabla `cuenta_pagos` (cols: `id, cuenta_id, ubicacion_id, turno_id, parte_num, metodo_pago, tipo ENUM('efectivo','tarjeta','qr','otros'), monto, empleado_id, comprobante_pedido_id, created_at`); columnas nuevas en `cuentas`: `precuenta_at`, `descuento_tipo ENUM('porcentaje','monto')`, `descuento_valor`, `descuento_monto`, `cobrada_at`.

- [ ] **Step 1: Crear la migración**

Crear `install/58_cobro_mesas.sql` con exactamente este contenido (sigue el patrón de guards de `57_cuentas.sql`):

```sql
-- 58_cobro_mesas.sql — Mesas POS Sub-build C: pagos de cuentas (split + mixto), precuenta, descuento.
-- Idempotente. Aplicar en phpMyAdmin tras git pull.

CREATE TABLE IF NOT EXISTS `cuenta_pagos` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cuenta_id`             INT UNSIGNED NOT NULL,
  `ubicacion_id`          INT UNSIGNED NOT NULL,
  `turno_id`              INT UNSIGNED NULL,
  `parte_num`             SMALLINT NOT NULL DEFAULT 1,
  `metodo_pago`           VARCHAR(60) NOT NULL,
  `tipo`                  ENUM('efectivo','tarjeta','qr','otros') NOT NULL DEFAULT 'otros',
  `monto`                 DECIMAL(10,2) NOT NULL,
  `empleado_id`           INT UNSIGNED NULL,
  `comprobante_pedido_id` INT UNSIGNED NULL,
  `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cpago_cuenta` (`cuenta_id`),
  KEY `idx_cpago_ubi` (`ubicacion_id`),
  KEY `idx_cpago_turno` (`turno_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- cuentas: columnas de cobro (guard portable por columna)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='precuenta_at');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `precuenta_at` DATETIME NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='descuento_tipo');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `descuento_tipo` ENUM('porcentaje','monto') NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='descuento_valor');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `descuento_valor` DECIMAL(10,2) NOT NULL DEFAULT 0", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='descuento_monto');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `descuento_monto` DECIMAL(10,2) NOT NULL DEFAULT 0", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='cuentas' AND column_name='cobrada_at');
SET @s := IF(@c=0, "ALTER TABLE `cuentas` ADD COLUMN `cobrada_at` DATETIME NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
```

- [ ] **Step 2: Añadir fila a `check_migraciones.sql`**

Abrir `install/check_migraciones.sql` y añadir una fila `UNION ALL` siguiendo el formato exacto de las filas vecinas (mismo `SELECT '<etiqueta>', COUNT(*) ...`). Localizar la última fila de migración (la de mayor número, ej. `54_eventos_usa_pos`) y añadir DESPUÉS:

```sql
  UNION ALL SELECT '58_cobro_mesas.sql        (cuenta_pagos / cuentas.cobrada_at)', COUNT(*) FROM information_schema.tables  WHERE table_schema=DATABASE() AND table_name='cuenta_pagos'
```

(Ajustar el espaciado de la etiqueta para alinear con las filas vecinas; mantener el patrón `WHERE table_schema=DATABASE() AND table_name=...`.)

- [ ] **Step 3: Verificar estructura (no hay BD de dev garantizada)**

Run: `grep -c "PREPARE st FROM" install/58_cobro_mesas.sql`
Expected: `5` (cinco ALTERs guardados).

Run: `grep -c "cuenta_pagos" install/check_migraciones.sql`
Expected: `>= 1` (la fila nueva existe).

Si hay un MySQL local disponible, aplicar y re-aplicar para confirmar idempotencia:
Run (opcional): `mysql <db> < install/58_cobro_mesas.sql && mysql <db> < install/58_cobro_mesas.sql`
Expected: sin errores en la segunda ejecución (idempotente).

- [ ] **Step 4: Commit**

```bash
git add install/58_cobro_mesas.sql install/check_migraciones.sql
git commit -m "feat(mesas): migración 58 — cuenta_pagos + cuentas.cobro (precuenta/descuento)"
```

---

### Task 2: Helpers puros en `includes/cuentas.php` (reparto, línea de ítem, guard de tabla)

**Files:**
- Modify: `includes/cuentas.php` (añadir 3 funciones; refactor menor de `cuentaTotalRecalc`)
- Test: `/tmp/test_cobro_helpers.php` (script de aserción desechable)

**Interfaces:**
- Produces:
  - `cuentaPagosListo(): bool` — ¿existe la tabla `cuenta_pagos`?
  - `itemLineTotal(array $it): float` — total de una línea de ítem (0 si anulado); `(precio + suma modificadores) * qty`.
  - `repartoCentavos(float $total, int $n): array` — `$n` floats a 2 decimales que suman exactamente `$total`; el resto de centavos va a la última parte.

- [ ] **Step 1: Escribir el test de aserción**

Crear `/tmp/test_cobro_helpers.php`:

```php
<?php
require __DIR__ . '/../Documents/Proyectos/elgringo-cotizador/includes/cuentas_helpers_shim.php';
// (el shim no existe; ver Step 2 — en su lugar incluimos las funciones puras directamente)
```

En la práctica, como `includes/cuentas.php` depende de la clase `Database`, NO se puede incluir entero en un script aislado. Por eso el test copia SOLO las 3 funciones puras. Crear `/tmp/test_cobro_helpers.php` con:

```php
<?php
// Copia de las funciones puras a probar (deben quedar idénticas en includes/cuentas.php).
function itemLineTotal(array $it): float {
    if (!empty($it['anulado'])) return 0.0;
    $qty  = max(1, (int)($it['qty'] ?? 1));
    $base = (float)($it['precio'] ?? 0);
    $mods = 0.0;
    foreach ((array)($it['modificadores'] ?? []) as $m) $mods += (float)($m['precio'] ?? 0);
    return ($base + $mods) * $qty;
}
function repartoCentavos(float $total, int $n): array {
    $n = max(1, $n);
    $cent = (int) round($total * 100);
    $base = intdiv($cent, $n);
    $resto = $cent - $base * $n;
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $c = $base + ($i === $n - 1 ? $resto : 0);
        $out[] = round($c / 100, 2);
    }
    return $out;
}

function assertEq($got, $exp, $msg) {
    if (json_encode($got) !== json_encode($exp)) { echo "FAIL: $msg — got " . json_encode($got) . " exp " . json_encode($exp) . "\n"; exit(1); }
    echo "ok: $msg\n";
}

assertEq(repartoCentavos(120, 3), [40.0, 40.0, 40.0], 'reparto 120/3');
assertEq(repartoCentavos(100, 3), [33.33, 33.33, 33.34], 'reparto 100/3 resto a la ultima');
assertEq(repartoCentavos(10, 1), [10.0], 'reparto 10/1');
assertEq(repartoCentavos(0.10, 3), [0.03, 0.03, 0.04], 'reparto centavos chicos');
assertEq(array_sum(repartoCentavos(99.99, 7)), 99.99, 'reparto suma exacta 99.99/7');
assertEq(itemLineTotal(['qty'=>2,'precio'=>10,'modificadores'=>[['precio'=>1.5]]]), 23.0, 'linea con modificador');
assertEq(itemLineTotal(['qty'=>1,'precio'=>10,'anulado'=>true]), 0.0, 'linea anulada = 0');
echo "ALL PASS\n";
```

- [ ] **Step 2: Correr el test — debe FALLAR primero**

Run: `cd /tmp && php test_cobro_helpers.php`
Expected: si copiaste bien las funciones, este test PASA por sí mismo (es autocontenido). El objetivo real es que las MISMAS funciones queden en `includes/cuentas.php`. Primero confirmá que el test pasa aislado:
Expected: `ALL PASS`.

- [ ] **Step 3: Añadir las funciones a `includes/cuentas.php`**

En `includes/cuentas.php`, justo después de `function cuentasListo()` (línea ~13), añadir:

```php
/** ¿Existe la tabla de pagos de mesa? (Sub-build C) */
function cuentaPagosListo(): bool {
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cuenta_pagos'");
    } catch (\Throwable $e) { return false; }
}

/** Total de una línea de ítem (0 si anulado): (precio + suma de modificadores) * qty. */
function itemLineTotal(array $it): float {
    if (!empty($it['anulado'])) return 0.0;
    $qty  = max(1, (int)($it['qty'] ?? 1));
    $base = (float)($it['precio'] ?? 0);
    $mods = 0.0;
    foreach ((array)($it['modificadores'] ?? []) as $m) $mods += (float)($m['precio'] ?? 0);
    return ($base + $mods) * $qty;
}

/** Reparte $total en $n partes a 2 decimales; el resto de centavos va a la última. Suman exacto. */
function repartoCentavos(float $total, int $n): array {
    $n = max(1, $n);
    $cent  = (int) round($total * 100);
    $base  = intdiv($cent, $n);
    $resto = $cent - $base * $n;
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $c = $base + ($i === $n - 1 ? $resto : 0);
        $out[] = round($c / 100, 2);
    }
    return $out;
}
```

- [ ] **Step 4: Refactor DRY de `cuentaTotalRecalc` para usar `itemLineTotal`**

En `includes/cuentas.php`, reemplazar el cuerpo del `foreach` interno de `cuentaTotalRecalc` (líneas ~58-64, el bloque que calcula `$qty`/`$base`/`$mods` y suma a `$total`) por:

old_string (bloque actual):
```php
        foreach ($items as $it) {
            if (!empty($it['anulado'])) continue;
            $qty  = max(1, (int)($it['qty'] ?? 1));
            $base = (float)($it['precio'] ?? 0);
            $mods = 0.0;
            foreach ((array)($it['modificadores'] ?? []) as $m) $mods += (float)($m['precio'] ?? 0);
            $total += ($base + $mods) * $qty;
        }
```
new_string:
```php
        foreach ($items as $it) {
            $total += itemLineTotal($it);
        }
```

- [ ] **Step 5: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l includes/cuentas.php`
Expected: `No syntax errors detected in includes/cuentas.php`

- [ ] **Step 6: Commit**

```bash
git add includes/cuentas.php
git commit -m "feat(mesas): helpers puros de cobro — repartoCentavos, itemLineTotal, cuentaPagosListo"
```

---

### Task 3: Lectura de turnos y agregación de pagos para el arqueo

**Files:**
- Modify: `includes/cuentas.php` (añadir 2 funciones)

**Interfaces:**
- Consumes: esquema `pos_turnos` (`id, usuario_id, ubicacion_id, abierto_en, estado ENUM('abierto','cerrado')`), `users(name,email)`, `cuenta_pagos` (Task 1), `cuentaPagosListo()` (Task 2).
- Produces:
  - `turnoAbiertoLocal(int $ubicacionId): array` → `['turnos'=>[['id'=>int,'abierto_en'=>string,'usuario'=>string], ...], 'count'=>int]`.
  - `cuentaPagosArqueo(int $turnoId): array` → `['efectivo'=>float,'tarjeta'=>float,'qr'=>float,'otros'=>float,'total'=>float,'n'=>int]`.

- [ ] **Step 1: Añadir las funciones a `includes/cuentas.php`**

Al final de `includes/cuentas.php` (después de `mesaEstados`, antes del cierre del archivo), añadir:

```php
/** Turnos de caja ABIERTOS en un local (para asignar el cobro de mesa al arqueo). */
function turnoAbiertoLocal(int $ubicacionId): array {
    $rows = Database::fetchAll(
        "SELECT t.id, t.abierto_en, COALESCE(u.name, u.email, CONCAT('Caja ', t.usuario_id)) AS usuario
         FROM pos_turnos t LEFT JOIN users u ON u.id = t.usuario_id
         WHERE t.ubicacion_id = ? AND t.estado = 'abierto' ORDER BY t.id DESC", [$ubicacionId]);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
    unset($r);
    return ['turnos' => $rows, 'count' => count($rows)];
}

/** Suma de pagos de mesa de un turno, por bucket (para el arqueo). */
function cuentaPagosArqueo(int $turnoId): array {
    $z = ['efectivo'=>0.0,'tarjeta'=>0.0,'qr'=>0.0,'otros'=>0.0,'total'=>0.0,'n'=>0];
    if (!cuentaPagosListo()) return $z;
    $r = Database::fetch(
        "SELECT COALESCE(SUM(CASE WHEN tipo='efectivo' THEN monto ELSE 0 END),0) ef,
                COALESCE(SUM(CASE WHEN tipo='tarjeta'  THEN monto ELSE 0 END),0) ta,
                COALESCE(SUM(CASE WHEN tipo='qr'       THEN monto ELSE 0 END),0) qr,
                COALESCE(SUM(CASE WHEN tipo NOT IN ('efectivo','tarjeta','qr') THEN monto ELSE 0 END),0) ot,
                COALESCE(SUM(monto),0) tot, COUNT(*) n
         FROM cuenta_pagos WHERE turno_id = ?", [$turnoId]);
    return [
        'efectivo'=>(float)$r['ef'], 'tarjeta'=>(float)$r['ta'], 'qr'=>(float)$r['qr'],
        'otros'=>(float)$r['ot'], 'total'=>(float)$r['tot'], 'n'=>(int)$r['n'],
    ];
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l includes/cuentas.php`
Expected: `No syntax errors detected in includes/cuentas.php`

- [ ] **Step 3: Commit**

```bash
git add includes/cuentas.php
git commit -m "feat(mesas): turnoAbiertoLocal + cuentaPagosArqueo (cobro → arqueo)"
```

---

### Task 4: Estados de mesa (precuenta/por_cobrar) y detalle enriquecido

**Files:**
- Modify: `includes/cuentas.php` (reemplazar `mesaEstados`; extender el return de `cuentaDetalle`)

**Interfaces:**
- Consumes: `cuentaPagosListo()` (Task 2); columnas nuevas de `cuentas` (Task 1).
- Produces:
  - `mesaEstados` ahora devuelve `estado ∈ {libre(implícito),ocupada,precuenta,por_cobrar}` por mesa: `por_cobrar` si hay pagos parciales; `precuenta` si `precuenta_at` y sin pagos; si no `ocupada`.
  - `cuentaDetalle(...)` añade al array de retorno: `precuenta_at`, `descuento_tipo`, `descuento_valor`, `descuento_monto`, `pagado` (float), `monto_cobrar` (float = total − descuento_monto), `falta` (float = max(0, monto_cobrar − pagado)).

- [ ] **Step 1: Reemplazar `mesaEstados`**

old_string (la función actual completa, líneas ~161-170):
```php
/** Estados de mesa de un local: las que tienen cuenta abierta → 'ocupada' + monto. */
function mesaEstados(int $ubicacionId): array {
    $estados = []; $montos = []; $minutos = [];
    foreach (Database::fetchAll("SELECT mesa_id, total, TIMESTAMPDIFF(MINUTE, abierta_at, NOW()) AS mins FROM cuentas WHERE ubicacion_id = ? AND estado = 'abierta'", [$ubicacionId]) as $r) {
        $estados[(int)$r['mesa_id']] = 'ocupada';
        $montos[(int)$r['mesa_id']]  = (float)$r['total'];
        $minutos[(int)$r['mesa_id']] = max(0, (int)$r['mins']);
    }
    return ['estados' => $estados, 'montos' => $montos, 'minutos' => $minutos];
}
```
new_string:
```php
/** Estados de mesa de un local. ocupada · precuenta (rosa) · por_cobrar (parcial). */
function mesaEstados(int $ubicacionId): array {
    $estados = []; $montos = []; $minutos = [];
    $hasPagos = cuentaPagosListo();
    $sel = $hasPagos
        ? "SELECT cu.mesa_id, cu.total, cu.precuenta_at, TIMESTAMPDIFF(MINUTE, cu.abierta_at, NOW()) AS mins,
                  COALESCE((SELECT SUM(monto) FROM cuenta_pagos WHERE cuenta_id = cu.id),0) AS pagado
           FROM cuentas cu WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'"
        : "SELECT cu.mesa_id, cu.total, cu.precuenta_at, TIMESTAMPDIFF(MINUTE, cu.abierta_at, NOW()) AS mins,
                  0 AS pagado
           FROM cuentas cu WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'";
    foreach (Database::fetchAll($sel, [$ubicacionId]) as $r) {
        $mid = (int)$r['mesa_id'];
        $pagado = (float)$r['pagado'];
        if ($pagado > 0.001)               $estado = 'por_cobrar';
        elseif (!empty($r['precuenta_at'])) $estado = 'precuenta';
        else                                $estado = 'ocupada';
        $estados[$mid] = $estado;
        $montos[$mid]  = (float)$r['total'];
        $minutos[$mid] = max(0, (int)$r['mins']);
    }
    return ['estados' => $estados, 'montos' => $montos, 'minutos' => $minutos];
}
```

Nota: `cuentas.precuenta_at` puede no existir si la migración 58 no se aplicó. El `SELECT cu.precuenta_at` fallaría. Para tolerar instancias sin migrar, envolver la llamada en `mesaEstados` no es trivial aquí; en su lugar, `api/mozo.php` ya tolera fallos del menú con try/catch. **Decisión:** dejar el SELECT con `precuenta_at` (la migración 58 es requisito de Sub-build C, igual que la 57 lo fue de B). Documentar en el commit que requiere migración 58.

- [ ] **Step 2: Extender el return de `cuentaDetalle`**

En `cuentaDetalle`, antes del `return [...]` final (línea ~93), añadir el cálculo de pagado/descuento:

old_string:
```php
    return [
        'id' => (int)$c['id'], 'mesa_id' => (int)$c['mesa_id'], 'mesa_numero' => $c['mesa_numero'],
        'num_comensales' => (int)$c['num_comensales'], 'estado' => $c['estado'],
        'total' => (float)$c['total'], 'mozo_nombre' => $c['mozo_nombre'], 'abierta_at' => $c['abierta_at'],
        'comandas' => $comandas,
    ];
}
```
new_string:
```php
    $total  = (float)$c['total'];
    $descMonto = (float)($c['descuento_monto'] ?? 0);
    $montoCobrar = round(max(0, $total - $descMonto), 2);
    $pagado = 0.0;
    if (cuentaPagosListo()) {
        $pagado = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['s'] ?? 0);
    }
    return [
        'id' => (int)$c['id'], 'mesa_id' => (int)$c['mesa_id'], 'mesa_numero' => $c['mesa_numero'],
        'num_comensales' => (int)$c['num_comensales'], 'estado' => $c['estado'],
        'total' => $total, 'mozo_nombre' => $c['mozo_nombre'], 'abierta_at' => $c['abierta_at'],
        'precuenta_at' => $c['precuenta_at'] ?? null,
        'descuento_tipo' => $c['descuento_tipo'] ?? null,
        'descuento_valor' => (float)($c['descuento_valor'] ?? 0),
        'descuento_monto' => $descMonto,
        'monto_cobrar' => $montoCobrar,
        'pagado' => round($pagado, 2),
        'falta' => round(max(0, $montoCobrar - $pagado), 2),
        'comandas' => $comandas,
    ];
}
```

(El `SELECT cu.*` de `cuentaDetalle` ya trae las columnas nuevas de `cuentas`, así que `$c['precuenta_at']`/`descuento_*` están disponibles.)

- [ ] **Step 3: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l includes/cuentas.php`
Expected: `No syntax errors detected in includes/cuentas.php`

- [ ] **Step 4: Commit**

```bash
git add includes/cuentas.php
git commit -m "feat(mesas): mesaEstados precuenta/por_cobrar + cuentaDetalle con pagado/monto_cobrar (req. migración 58)"
```

---

### Task 5: Núcleo transaccional `cuentaCobrar`

**Files:**
- Modify: `includes/cuentas.php` (añadir `cuentaCobrar`)

**Interfaces:**
- Consumes: `turnoAbiertoLocal` (T3), `itemLineTotal`/`cuentaPagosListo` (T2), `cuentaTotalRecalc` (existente), `nubefactConfigurado`/`nubefactEmitir` (en `includes/nubefact.php`), `Database::getInstance()` (PDO para transacción).
- Produces:
  - `cuentaCobrar(int $cuentaId, int $ubicacionId, ?int $empleadoId, array $payload): array`.
    - `$payload`:
      ```
      [
        'descuento' => ['tipo'=>'porcentaje'|'monto'|null, 'valor'=>float],
        'modo'      => 'todo'|'iguales'|'items'|'montos',
        'turno_id'  => int (opcional, requerido si hay 2+ cajas abiertas),
        'partes'    => [
          [ 'monto'=>float,                       // modos todo/iguales/montos
            'item_keys'=>['pedidoId:itemIdx', ...], // SOLO modo items (server calcula el monto)
            'pagos'=>[ ['metodo'=>string,'monto'=>float], ... ],
            'comprobante'=>['tipo'=>'ticket'|'boleta'|'factura',
                            'cliente_tipo'=>'nombre'|'dni'|'ruc'|null,
                            'cliente_nombre'=>string,'cliente_documento'=>string,
                            'cliente_razon_social'=>string,'cliente_email'=>string] | null
          ], ...
        ]
      ]
      ```
    - Devuelve: `['ok'=>bool,'error'=>string?, 'sin_caja'=>bool?, 'multi_caja'=>bool?, 'turnos'=>array?, 'cerrada'=>bool, 'pagado'=>float, 'falta'=>float, 'comprobantes'=>[['parte'=>int,'tipo'=>string,'estado'=>string,'serie'=>string,'numero'=>int,'error'=>string,'pedido_id'=>int], ...]]`.

- [ ] **Step 1: Asegurar que `nubefact.php` esté disponible**

`cuentaCobrar` llama a `nubefactConfigurado()`/`nubefactEmitir()`. Esos viven en `includes/nubefact.php`, que NO está incluido por `includes/cuentas.php`. Para evitar acoplar el include, `cuentaCobrar` los llamará con guard `function_exists`. Confirmá los nombres:

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && grep -n "function nubefactConfigurado\|function nubefactEmitir" includes/nubefact.php`
Expected: ambas funciones existen.

- [ ] **Step 2: Añadir `cuentaCobrar` a `includes/cuentas.php`**

Añadir al final del archivo:

```php
/**
 * Cobra una o más PARTES de una cuenta (split + pago mixto). Transaccional.
 * El dinero va a cuenta_pagos (fuente de verdad); el comprobante (opcional) a un
 * pedido-comprobante por parte (reusa nubefactEmitir). Cierra la cuenta al pagar el total.
 */
function cuentaCobrar(int $cuentaId, int $ubicacionId, ?int $empleadoId, array $payload): array {
    $c = Database::fetch(
        "SELECT * FROM cuentas WHERE id = ? AND estado = 'abierta' AND (? = 0 OR ubicacion_id = ?)",
        [$cuentaId, $ubicacionId, $ubicacionId]);
    if (!$c) return ['ok' => false, 'error' => 'cuenta no abierta'];
    $ubi = (int)$c['ubicacion_id'];
    $mesaId = (int)$c['mesa_id'];

    // 1) Resolver el turno de caja del local.
    $tl = turnoAbiertoLocal($ubi);
    if ($tl['count'] === 0) return ['ok' => false, 'sin_caja' => true, 'error' => 'No hay caja abierta en el local'];
    $turnoId = 0;
    if ($tl['count'] === 1) {
        $turnoId = (int)$tl['turnos'][0]['id'];
    } else {
        $want = (int)($payload['turno_id'] ?? 0);
        $ids = array_map(fn($t) => (int)$t['id'], $tl['turnos']);
        if ($want && in_array($want, $ids, true)) $turnoId = $want;
        else return ['ok' => false, 'multi_caja' => true, 'turnos' => $tl['turnos'], 'error' => 'Elige la caja'];
    }

    // 2) Consumo autoritativo + descuento.
    $consumo = cuentaTotalRecalc($cuentaId);
    $yaPagado = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['s'] ?? 0);
    $modo = in_array($payload['modo'] ?? '', ['todo','iguales','items','montos'], true) ? $payload['modo'] : 'todo';

    // Descuento: solo si aún no hay pagos (primera tanda) y no en modo items.
    $descTipo = $c['descuento_tipo']; $descValor = (float)$c['descuento_valor']; $descMonto = (float)$c['descuento_monto'];
    if ($yaPagado <= 0.001 && $modo !== 'items') {
        $dIn = (array)($payload['descuento'] ?? []);
        $dt = in_array($dIn['tipo'] ?? '', ['porcentaje','monto'], true) ? $dIn['tipo'] : null;
        $dv = (float)($dIn['valor'] ?? 0);
        $dm = 0.0;
        if ($dt === 'porcentaje') $dm = $consumo * min(100, max(0, $dv)) / 100;
        elseif ($dt === 'monto')  $dm = min($consumo, max(0, $dv));
        $descTipo = $dt; $descValor = $dv; $descMonto = round($dm, 2);
    }
    $montoCobrar = round(max(0, $consumo - $descMonto), 2);

    // 3) Validar partes y construir su monto + items de comprobante.
    $partesIn = (array)($payload['partes'] ?? []);
    if (!$partesIn) return ['ok' => false, 'error' => 'sin partes a cobrar'];
    // Mapa de ítems de la cuenta (para modo items): "pedidoId:idx" => item.
    $itemsMap = [];
    if ($modo === 'items') {
        foreach (Database::fetchAll("SELECT id, items_json FROM pedidos WHERE cuenta_id = ? AND estado <> 'cancelado'", [$cuentaId]) as $pp) {
            $arr = json_decode($pp['items_json'] ?? '[]', true) ?: [];
            foreach ($arr as $idx => $it) { $itemsMap[$pp['id'] . ':' . $idx] = $it; }
        }
    }
    $partes = [];
    $sumPartes = 0.0;
    foreach ($partesIn as $i => $pi) {
        $compItems = null;
        if ($modo === 'items') {
            $monto = 0.0; $compItems = [];
            foreach ((array)($pi['item_keys'] ?? []) as $k) {
                if (!isset($itemsMap[$k])) return ['ok' => false, 'error' => 'ítem inválido en la división'];
                $it = $itemsMap[$k];
                if (!empty($it['anulado'])) continue;
                $monto += itemLineTotal($it);
                $compItems[] = ['nombre' => (string)($it['nombre'] ?? 'Ítem'), 'qty' => max(1,(int)($it['qty'] ?? 1)),
                                'precio' => (float)($it['precio'] ?? 0), 'modificadores' => (array)($it['modificadores'] ?? [])];
            }
            $monto = round($monto, 2);
        } else {
            $monto = round((float)($pi['monto'] ?? 0), 2);
        }
        if ($monto <= 0) return ['ok' => false, 'error' => 'parte con monto inválido'];
        // pagos de la parte (mixto): suman el monto de la parte.
        $pagos = [];
        $sumPagos = 0.0;
        foreach ((array)($pi['pagos'] ?? []) as $pg) {
            $met = trim((string)($pg['metodo'] ?? ''));
            $mn  = round((float)($pg['monto'] ?? 0), 2);
            if ($met === '' || $mn <= 0) continue;
            $pagos[] = ['metodo' => $met, 'monto' => $mn];
            $sumPagos += $mn;
        }
        if (!$pagos) return ['ok' => false, 'error' => 'parte sin pagos'];
        if (abs(round($sumPagos, 2) - $monto) > 0.01) return ['ok' => false, 'error' => 'los pagos no suman el monto de la parte'];
        $comp = null;
        $cIn = $pi['comprobante'] ?? null;
        if (is_array($cIn)) {
            $ct = in_array($cIn['tipo'] ?? '', ['ticket','boleta','factura'], true) ? $cIn['tipo'] : 'ticket';
            $comp = [
                'tipo' => $ct,
                'cliente_tipo' => in_array($cIn['cliente_tipo'] ?? '', ['nombre','dni','ruc'], true) ? $cIn['cliente_tipo'] : null,
                'cliente_nombre' => clean($cIn['cliente_nombre'] ?? ''),
                'cliente_documento' => preg_replace('/[^0-9A-Za-z]/', '', (string)($cIn['cliente_documento'] ?? '')),
                'cliente_razon_social' => clean($cIn['cliente_razon_social'] ?? ''),
                'cliente_email' => cleanEmail($cIn['cliente_email'] ?? ''),
                'items' => $compItems, // null salvo modo items
            ];
        }
        $partes[] = ['num' => $i + 1, 'monto' => $monto, 'pagos' => $pagos, 'comp' => $comp];
        $sumPartes += $monto;
    }
    $sumPartes = round($sumPartes, 2);

    // 4) No sobrepagar.
    if (round($yaPagado + $sumPartes, 2) > $montoCobrar + 0.01) {
        return ['ok' => false, 'error' => 'el cobro supera el saldo de la cuenta'];
    }

    // 5) Transacción: descuento + pagos + comprobantes (+ cierre si se completa).
    $pdo = Database::getInstance();
    $emitDespues = []; // [pedidoId, parteNum, tipo]
    $comprobantes = [];
    try {
        $pdo->beginTransaction();

        if ($yaPagado <= 0.001) {
            Database::execute("UPDATE cuentas SET descuento_tipo = ?, descuento_valor = ?, descuento_monto = ? WHERE id = ?",
                [$descTipo, $descValor, $descMonto, $cuentaId]);
        }

        $parteBase = (int)(Database::fetch("SELECT COALESCE(MAX(parte_num),0) m FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['m'] ?? 0);

        foreach ($partes as $k => $pt) {
            $parteNum = $parteBase + $k + 1;
            // Comprobante (opcional): pedido-comprobante por parte.
            $compPid = null;
            if ($pt['comp']) {
                $cm = $pt['comp'];
                $compItems = $cm['items'];
                if (!is_array($compItems) || !$compItems) {
                    $compItems = [['nombre' => 'Consumo en salón', 'qty' => 1, 'precio' => $pt['monto'], 'modificadores' => []]];
                }
                $nombrePed = 'Mesa ' . ($c['mesa_id'] ? (Database::fetch('SELECT numero FROM mesas WHERE id=?', [$mesaId])['numero'] ?? '') : '') . ' · parte ' . $parteNum;
                $compPid = (int) Database::insert(
                    "INSERT INTO pedidos (ubicacion_id, nombre, tipo_entrega, items_json, total, estado, metodo_pago, origen, cuenta_id, mesa_id,
                        comprobante_tipo, cliente_tipo, cliente_nombre, cliente_documento, cliente_razon_social, cliente_email, aceptado_at, completado_at, horario)
                     VALUES (?,?, 'recojo', ?, ?, 'entregado', 'mesa', 'mesa', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 'En salón')",
                    [$ubi, $nombrePed, json_encode($compItems, JSON_UNESCAPED_UNICODE), $pt['monto'], $cuentaId, $mesaId,
                     $cm['tipo'], $cm['cliente_tipo'], ($cm['cliente_nombre'] ?: null), ($cm['cliente_documento'] ?: null),
                     ($cm['cliente_razon_social'] ?: null), ($cm['cliente_email'] ?: null)]);
                if (in_array($cm['tipo'], ['boleta','factura'], true)) $emitDespues[] = ['pid' => $compPid, 'parte' => $parteNum, 'tipo' => $cm['tipo']];
            }
            // Pagos (mixto) → cuenta_pagos.
            foreach ($pt['pagos'] as $pg) {
                $tipoRow = Database::fetch("SELECT tipo FROM pos_metodos_pago WHERE nombre = ? LIMIT 1", [$pg['metodo']]);
                $tipo = $tipoRow['tipo'] ?? 'otros';
                Database::insert(
                    "INSERT INTO cuenta_pagos (cuenta_id, ubicacion_id, turno_id, parte_num, metodo_pago, tipo, monto, empleado_id, comprobante_pedido_id)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$cuentaId, $ubi, $turnoId, $parteNum, $pg['metodo'], $tipo, $pg['monto'], $empleadoId, $compPid]);
            }
        }

        // ¿Quedó pagada por completo?
        $pagadoTot = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['s'] ?? 0);
        $cerrada = round($pagadoTot, 2) >= $montoCobrar - 0.01;
        if ($cerrada) {
            Database::execute("UPDATE cuentas SET estado = 'cerrada', cobrada_at = NOW(), cerrada_at = NOW() WHERE id = ?", [$cuentaId]);
            // Trazabilidad: asignar turno a las comandas (NO entran al arqueo por el guard origen='mesa').
            Database::execute("UPDATE pedidos SET turno_id = ? WHERE cuenta_id = ? AND origen = 'mesa' AND estado <> 'cancelado' AND turno_id IS NULL", [$turnoId, $cuentaId]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'no se pudo registrar el cobro'];
    }

    // 6) Emitir comprobantes electrónicos FUERA de la transacción (red; nunca rompe el cobro).
    foreach ($emitDespues as $em) {
        $est = 'pendiente'; $serie = ''; $num = 0; $err = '';
        if (function_exists('nubefactConfigurado') && function_exists('nubefactEmitir') && nubefactConfigurado()) {
            $r = nubefactEmitir($em['pid']);
            $est = $r['estado'] ?? (!empty($r['ok']) ? 'emitido' : 'error');
            $serie = $r['serie'] ?? ''; $num = (int)($r['numero'] ?? 0); $err = $r['error'] ?? '';
        }
        $comprobantes[] = ['parte'=>$em['parte'],'tipo'=>$em['tipo'],'estado'=>$est,'serie'=>$serie,'numero'=>$num,'error'=>$err,'pedido_id'=>$em['pid']];
    }

    $pagadoTot = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['s'] ?? 0);
    return [
        'ok' => true,
        'cerrada' => round($pagadoTot, 2) >= $montoCobrar - 0.01,
        'pagado' => round($pagadoTot, 2),
        'falta' => round(max(0, $montoCobrar - $pagadoTot), 2),
        'comprobantes' => $comprobantes,
    ];
}
```

- [ ] **Step 3: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l includes/cuentas.php`
Expected: `No syntax errors detected in includes/cuentas.php`

- [ ] **Step 4: Revisión de lógica (checklist, no hay BD de dev)**

Confirmar leyendo el código:
- Sin caja abierta → `{sin_caja:true}`; 2+ cajas sin `turno_id` válido → `{multi_caja:true, turnos}`.
- Pagos de cada parte suman su monto (±0.01) o error.
- No se sobrepaga (`yaPagado + sumPartes <= montoCobrar + 0.01`).
- Descuento solo en primera tanda y no en modo items.
- Comprobante: items reales en modo items; línea "Consumo en salón" en los otros.
- Cierre solo cuando `pagado >= montoCobrar − 0.01`; al cerrar asigna `turno_id` a las comandas.
- `nubefactEmitir` corre FUERA de la transacción y nunca propaga excepción.

- [ ] **Step 5: Commit**

```bash
git add includes/cuentas.php
git commit -m "feat(mesas): cuentaCobrar — split (4 modos) + pago mixto + comprobante por parte + cierre"
```

---

### Task 6: Builder ESC/POS de precuenta (no fiscal), accesible sin login de cajero

**Files:**
- Create: `pos/escpos_build.php`
- Test: `/tmp/test_precuenta_bytes.php`

**Interfaces:**
- Consumes: `getSetting()` (helpers), salida de `cuentaDetalle()` (T4).
- Produces: `escposPrecuentaBytes(array $cuenta): string` — bytes ESC/POS (80mm) de la precuenta NO fiscal. Cabecera "PRE-CUENTA", ítems no anulados con su total de línea, total, pie "No válido como comprobante de pago". Sin QR, sin IGV.

- [ ] **Step 1: Crear `pos/escpos_build.php`**

Constantes y helpers con guards (`if (!defined)`, `if (!function_exists)`) para coexistir con `pos/escpos.php` si alguna vez se cargan juntos:

```php
<?php
/**
 * pos/escpos_build.php — builder ESC/POS reutilizable y SIN auth/echo.
 * Lo usa api/mozo.php (sesión de mozo) para imprimir la PRECUENTA no fiscal.
 * (El comprobante fiscal sigue saliendo por pos/escpos.php, gateado a pos_terminal.)
 */

if (!defined('EB_ESC_INIT')) {
    define('EB_ESC_INIT',     "\x1b\x40");
    define('EB_ESC_CENTER',   "\x1b\x61\x01");
    define('EB_ESC_LEFT',     "\x1b\x61\x00");
    define('EB_ESC_BOLD_ON',  "\x1b\x45\x01");
    define('EB_ESC_BOLD_OFF', "\x1b\x45\x00");
    define('EB_ESC_DBLHW',    "\x1d\x21\x11");
    define('EB_ESC_NORMAL',   "\x1d\x21\x00");
    define('EB_LINE_WIDTH',   48);
}

if (!function_exists('ebAsciiSafe')) {
    function ebAsciiSafe(string $s): string {
        $r = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        return ($r !== false && $r !== '') ? $r : $s;
    }
    function ebSeparator(): string { return str_repeat('-', EB_LINE_WIDTH) . "\n"; }
    function ebCenter(string $s): string {
        $s = ebAsciiSafe($s); $len = mb_strlen($s);
        if ($len >= EB_LINE_WIDTH) return $s . "\n";
        return str_repeat(' ', (int)floor((EB_LINE_WIDTH - $len) / 2)) . $s . "\n";
    }
    function ebTwoCol(string $left, string $right): string {
        $left = ebAsciiSafe($left); $right = ebAsciiSafe($right);
        $rLen = mb_strlen($right); $maxL = max(1, EB_LINE_WIDTH - $rLen - 1);
        if (mb_strlen($left) > $maxL) $left = mb_substr($left, 0, $maxL - 1) . '.';
        $pad = EB_LINE_WIDTH - mb_strlen($left) - $rLen;
        return $left . str_repeat(' ', max(1, $pad)) . $right . "\n";
    }
}

/** Bytes ESC/POS de la PRECUENTA (no fiscal) a partir del detalle de la cuenta. */
function escposPrecuentaBytes(array $cuenta): string {
    $empresa = getSetting('company_name', 'El Gringo');
    $out  = EB_ESC_INIT;
    $out .= EB_ESC_CENTER . EB_ESC_BOLD_ON . EB_ESC_DBLHW . ebCenter('PRE-CUENTA') . EB_ESC_NORMAL . EB_ESC_BOLD_OFF;
    $out .= EB_ESC_CENTER . ebCenter(ebAsciiSafe($empresa));
    $out .= EB_ESC_LEFT . ebSeparator();
    $out .= 'Mesa: ' . ebAsciiSafe((string)($cuenta['mesa_numero'] ?? '')) . "\n";
    if (!empty($cuenta['num_comensales'])) $out .= 'Comensales: ' . (int)$cuenta['num_comensales'] . "\n";
    if (!empty($cuenta['mozo_nombre']))     $out .= 'Mozo: ' . ebAsciiSafe((string)$cuenta['mozo_nombre']) . "\n";
    $out .= 'Fecha: ' . date('d/m/Y H:i') . "\n";
    $out .= ebSeparator();

    $total = 0.0;
    foreach ((array)($cuenta['comandas'] ?? []) as $cmd) {
        foreach ((array)($cmd['items'] ?? []) as $it) {
            if (!empty($it['anulado'])) continue;
            $qty  = max(1, (int)($it['qty'] ?? 1));
            $base = (float)($it['precio'] ?? 0);
            $mods = 0.0;
            foreach ((array)($it['modificadores'] ?? []) as $m) $mods += (float)($m['precio'] ?? 0);
            $line = ($base + $mods) * $qty;
            $total += $line;
            $out .= ebTwoCol($qty . 'x ' . (string)($it['nombre'] ?? 'Item'), 'S/ ' . number_format($line, 2));
            foreach ((array)($it['modificadores'] ?? []) as $m) {
                $mn = (string)($m['nombre'] ?? '');
                if ($mn !== '') $out .= '   + ' . ebAsciiSafe($mn) . "\n";
            }
        }
    }
    $out .= ebSeparator();
    $out .= EB_ESC_BOLD_ON . EB_ESC_DBLHW . ebTwoCol('TOTAL', 'S/ ' . number_format($total, 2)) . EB_ESC_NORMAL . EB_ESC_BOLD_OFF;
    $out .= ebSeparator();
    $out .= EB_ESC_CENTER . ebCenter('No valido como comprobante de pago') . EB_ESC_LEFT;
    $out .= "\n\n\n" . "\x1d\x56\x00"; // feed + corte total
    return $out;
}
```

- [ ] **Step 2: Test de aserción**

Crear `/tmp/test_precuenta_bytes.php`:

```php
<?php
define('UPLOAD_PATH', '/tmp'); define('APP_PATH', '/tmp');
function getSetting($k, $d = '') { return $d; } // stub
require '/Users/daniel/Documents/Proyectos/elgringo-cotizador/pos/escpos_build.php';

$cuenta = [
  'mesa_numero' => '5', 'num_comensales' => 2, 'mozo_nombre' => 'Ana',
  'comandas' => [
    ['items' => [
      ['nombre' => 'Smash Burger', 'qty' => 2, 'precio' => 18, 'modificadores' => [['nombre'=>'Extra queso','precio'=>3]]],
      ['nombre' => 'Anulada', 'qty' => 1, 'precio' => 99, 'anulado' => true],
    ]],
  ],
];
$bytes = escposPrecuentaBytes($cuenta);
$plain = preg_replace('/[\x00-\x1f]/', '', $bytes); // quitar control chars para asertar texto
if (strpos($plain, 'PRE-CUENTA') === false) { echo "FAIL: falta cabecera\n"; exit(1); }
if (strpos($plain, 'No valido como comprobante') === false) { echo "FAIL: falta pie\n"; exit(1); }
// 2x(18+3)=42.00 debe aparecer; la anulada (99) NO.
if (strpos($plain, '42.00') === false) { echo "FAIL: total de linea\n"; exit(1); }
if (strpos($plain, '99.00') !== false) { echo "FAIL: incluyo item anulado\n"; exit(1); }
if (strpos($plain, 'TOTAL') === false || strpos($plain, '42.00') === false) { echo "FAIL: total\n"; exit(1); }
echo "ALL PASS\n";
```

- [ ] **Step 3: Correr el test**

Run: `php /tmp/test_precuenta_bytes.php`
Expected: `ALL PASS`

- [ ] **Step 4: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l pos/escpos_build.php`
Expected: `No syntax errors detected in pos/escpos_build.php`

- [ ] **Step 5: Commit**

```bash
git add pos/escpos_build.php
git commit -m "feat(mesas): escposPrecuentaBytes — precuenta no fiscal reutilizable (sin gate de cajero)"
```

---

### Task 7: Acciones de cobro en `api/mozo.php`

**Files:**
- Modify: `api/mozo.php` (añadir `metodos`, `precuenta`, `turnos_local`, `cobrar`; incluir `escpos_build.php` y `nubefact.php`)

**Interfaces:**
- Consumes: `cuentaDetalle` (T4), `turnoAbiertoLocal` (T3), `cuentaCobrar` (T5), `escposPrecuentaBytes` (T6), `mozoEmp()`/`mozoUbi()`/`geoGate()` (existentes).
- Produces (respuestas JSON): `metodos` → `{ok, metodos:[{nombre,tipo}]}`; `turnos_local` → `{ok, turnos, count}`; `precuenta` → `{ok, b64}`; `cobrar` → passthrough de `cuentaCobrar`.

- [ ] **Step 1: Incluir builders al tope de `api/mozo.php`**

old_string (líneas 1-5):
```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cuentas.php';
```
new_string:
```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cuentas.php';
require_once __DIR__ . '/../includes/nubefact.php';
require_once __DIR__ . '/../pos/escpos_build.php';
```

- [ ] **Step 2: Registrar las escrituras nuevas (CSRF)**

old_string (línea ~15):
```php
$writes = ['login_pin', 'logout', 'abrir_cuenta', 'enviar_comanda', 'anular', 'cerrar_cuenta_vacia'];
```
new_string:
```php
$writes = ['login_pin', 'logout', 'abrir_cuenta', 'enviar_comanda', 'anular', 'cerrar_cuenta_vacia', 'precuenta', 'cobrar'];
```

- [ ] **Step 3: Añadir los casos al `switch ($action)`**

Insertar antes de `default:` (línea ~158):

```php
    case 'metodos':
        mout(['ok' => true, 'metodos' => Database::fetchAll(
            "SELECT nombre, tipo FROM pos_metodos_pago WHERE activo = 1 ORDER BY orden, id")]);

    case 'turnos_local': {
        $tl = turnoAbiertoLocal($ubi);
        mout(['ok' => true, 'turnos' => $tl['turnos'], 'count' => $tl['count']]);
    }

    case 'precuenta': {
        $cid = cleanInt($_POST['cuenta_id'] ?? 0);
        $d = cuentaDetalle($cid, $ubi);
        if (!$d || $d['estado'] !== 'abierta') mout(['ok' => false, 'error' => 'cuenta no abierta']);
        Database::execute("UPDATE cuentas SET precuenta_at = NOW() WHERE id = ? AND ubicacion_id = ?", [$cid, $ubi]);
        mout(['ok' => true, 'b64' => base64_encode(escposPrecuentaBytes($d))]);
    }

    case 'cobrar': {
        geoGate($ubi);
        $cid = cleanInt($_POST['cuenta_id'] ?? 0);
        $payload = json_decode($_POST['payload'] ?? '{}', true) ?: [];
        mout(cuentaCobrar($cid, $ubi, mozoEmp(), $payload));
    }
```

- [ ] **Step 4: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l api/mozo.php`
Expected: `No syntax errors detected in api/mozo.php`

- [ ] **Step 5: Verificar el contrato (lectura)**

Confirmar: `cobrar` exige sesión de mozo (el archivo ya corta con 401 si `!mozoEmp()` en línea ~55), CSRF (está en `$writes`), y geocerca (`geoGate`). `precuenta` es escritura con CSRF pero sin geocerca (solo marca estado/imprime). `metodos`/`turnos_local` son lecturas.

- [ ] **Step 6: Commit**

```bash
git add api/mozo.php
git commit -m "feat(mesas): api/mozo — metodos, turnos_local, precuenta (RawBT b64), cobrar"
```

---

### Task 8: Flujo de cobro y botón de precuenta en `mozo/index.php`

**Files:**
- Modify: `mozo/index.php` (botón Precuenta en la vista de cuenta; sheet de Cobro; CSS de marca; JS del flujo)

**Interfaces:**
- Consumes: acciones `metodos`, `turnos_local`, `precuenta`, `cobrar` (T7); `cuentaDetalle` ahora trae `monto_cobrar`/`pagado`/`falta`/`descuento_*`.
- Produces: UI de cobro. Reusa el patrón de "sheet" modal existente (`.modal`/`.sheet`, `openBorrador`) y el helper de fetch existente del archivo.

> **Contexto para el implementador:** `mozo/index.php` es un PWA de una sola página con paneles conmutados por JS y "sheets" modales animadas (de la pasada impeccable). Ya existen: un helper `api(action, opts)` o `fetch` con header `X-CSRF-Token` (CSRF en `window.CSRF`/`csrfToken`), la vista de cuenta (`renderCuenta`/`openCuenta`), y el patrón de sheet `#m-com`/`openBorrador`. **Antes de codear, leé el archivo** para reusar exactamente esos helpers y clases (no inventar nuevos nombres de CSS ni un segundo mecanismo de fetch). El cobro es una sheet nueva `#m-cobro`.

- [ ] **Step 1: Leer los puntos de integración**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && grep -n "function api\|X-CSRF-Token\|openBorrador\|renderCuenta\|function openCuenta\|id=\"m-com\"\|class=\"modal\|rawbt\|CSRF" mozo/index.php`
Expected: localizar el helper de fetch (cómo se mandan POST con CSRF), la función que pinta la cuenta, y el patrón de sheet. Anotar los nombres reales para usarlos en los pasos siguientes.

- [ ] **Step 2: Añadir el botón "Precuenta" y "Cobrar" en la vista de cuenta**

En la función que pinta el pie/acciones de la cuenta (junto al botón "+ Agregar a la cuenta"), añadir dos botones. Usar las clases de botón existentes del archivo (las que ya usa "Agregar"/"Enviar a cocina"). Marcado de referencia (ajustar clases a las reales del archivo):

```html
<button class="btn-2" id="btn-precuenta" type="button">Precuenta</button>
<button class="btn-1" id="btn-cobrar" type="button">Cobrar</button>
```

Y el handler de precuenta (imprime por RawBT igual que `pos/ticket.php`):

```javascript
document.getElementById('btn-precuenta').addEventListener('click', function () {
  var body = new URLSearchParams({ action: 'precuenta', cuenta_id: CUENTA_ID });
  fetch('../api/mozo.php', { method: 'POST', headers: { 'X-CSRF-Token': CSRF, 'Content-Type': 'application/x-www-form-urlencoded' }, credentials: 'same-origin', body: body })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) { alert(d.error || 'No se pudo generar la precuenta'); return; }
      window.location.href = 'rawbt:base64,' + d.b64;
      refreshEstados && refreshEstados(); // la mesa pasa a rosa
    })
    .catch(function () { alert('Error de red'); });
});
```

(Sustituir `CUENTA_ID`, `CSRF`, `refreshEstados` por los identificadores reales del archivo, hallados en Step 1.)

- [ ] **Step 3: Sheet de Cobro `#m-cobro` (HTML)**

Añadir junto a las otras sheets (`#m-com`, etc.) una sheet de cobro con: selector de **modo** (Todo · Iguales · Ítems · Montos), input de **descuento** (opcional, tipo+valor), zona de **partes** (dinámica), y por cada parte sus **líneas de pago** (método+monto) y un toggle de **comprobante** (tipo + datos de cliente con autocompletado DNI/RUC). Reusar la estructura `.modal`/`.sheet`/`.sheet-head`/`.sheet-body` existente. El marcado es extenso; estructura mínima:

```html
<div class="modal" id="m-cobro" aria-hidden="true">
  <div class="sheet" role="dialog" aria-modal="true">
    <div class="sheet-head"><b>Cobrar mesa</b><button class="x" data-close-cobro type="button">✕</button></div>
    <div class="sheet-body">
      <div class="cobro-resumen" id="cobro-resumen"></div>
      <div class="seg" id="cobro-modo">
        <button data-modo="todo" class="on" type="button">Todo junto</button>
        <button data-modo="iguales" type="button">Iguales</button>
        <button data-modo="items" type="button">Por ítems</button>
        <button data-modo="montos" type="button">Montos</button>
      </div>
      <div id="cobro-config"></div>   <!-- N (iguales), asignación (items), etc. -->
      <div id="cobro-partes"></div>   <!-- partes con sus pagos + comprobante -->
      <div class="cobro-foot">
        <div id="cobro-saldo"></div>
        <button class="btn-1" id="cobro-confirmar" type="button">Confirmar cobro</button>
      </div>
    </div>
  </div>
</div>
```

- [ ] **Step 4: JS del flujo de cobro**

Implementar (reusando el helper de fetch real):
- `openCobro()`: carga `cuentaDetalle` (ya en memoria desde `openCuenta`), carga `metodos` (cachear), pinta el resumen (`monto_cobrar`, `pagado`, `falta`) y arranca en modo "todo" con 1 parte = `falta`.
- `setModo(modo)`: 
  - `todo` → 1 parte con monto = `falta`.
  - `iguales` → pide N; al fijar N, crea N partes con `repartoCentavos(falta, N)` (replicar la función en JS, ver abajo).
  - `items` → lista los ítems no anulados de las comandas (key `pedidoId:idx`) con checkboxes/asignador a parte; el monto de cada parte se calcula sumando líneas.
  - `montos` → partes con monto editable libre; valida que sumen `falta`.
- Por cada parte: filas de **pago** (`select` de método + input monto; botón "+ línea"); el subtotal de pagos debe igualar el monto de la parte. Toggle **comprobante** (ticket/boleta/factura) + inputs de cliente; si DNI(8)/RUC(11), llamar al autocompletado existente (misma acción que usa el POS: `api/pos.php?action=consultar_doc` no aplica aquí porque es sesión de mozo — usar el endpoint de mozo si existe, o dejar los campos manuales con TODO controlado: ver nota).
- `confirmarCobro()`: arma el `payload` (modo, descuento, partes[{monto|item_keys, pagos[], comprobante}], turno_id?) y hace POST `action=cobrar`. Manejar respuestas:
  - `sin_caja` → alert "No hay caja abierta en el local".
  - `multi_caja` → mostrar selector con `d.turnos` y reintentar con `turno_id`.
  - `ok && cerrada` → cerrar sheet, volver al plano (`refreshEstados`), toast "Mesa cobrada".
  - `ok && !cerrada` → quedó saldo: refrescar resumen (`falta`), mesa en `por_cobrar`.
  - Si `comprobantes` trae alguno en `error`/`pendiente`, avisar "comprobante pendiente, reintentable" (no bloquea).

Replicar `repartoCentavos` en JS (idéntico a PHP):

```javascript
function repartoCentavos(total, n) {
  n = Math.max(1, n | 0);
  var cent = Math.round(total * 100), base = Math.floor(cent / n), resto = cent - base * n, out = [];
  for (var i = 0; i < n; i++) out.push(Math.round((base + (i === n - 1 ? resto : 0)) / 100 * 100) / 100);
  return out;
}
```

**Nota autocompletado DNI/RUC (controlada, no placeholder):** el POS usa `api/pos.php?action=consultar_doc`, gateado a `requireLogin`. La app del mozo no tiene login de usuario. Para no abrir un endpoint nuevo en este task, **el comprobante en el mozo pide los datos de cliente de forma manual** (nombre/documento/razón/email), sin autocompletado. El autocompletado RENIEC/SUNAT en el mozo queda como mejora futura (requiere exponer `consultar_doc` bajo sesión de mozo). Documentarlo así en el commit y en "Fuera de alcance".

- [ ] **Step 5: CSS (marca, táctil)**

Añadir al `<style>` los estilos `.seg`/`.cobro-*` reusando los tokens de marca ya definidos (`--am`, `--ng`, `--rosa`, `--line`, `--r-btn`), botones presionables (≥44px), `:active`. Nunca hex de marca hardcodeado (regla global; `brandHead()` ya está en el `<head>`).

- [ ] **Step 6: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l mozo/index.php`
Expected: `No syntax errors detected in mozo/index.php`

- [ ] **Step 7: Checklist funcional (servidor o local con BD)**

- Abrir cuenta con ítems → "Precuenta" imprime por RawBT y la mesa queda rosa.
- "Cobrar" → modo Todo junto → 1 línea Efectivo = total → Confirmar → mesa libre.
- Modo Iguales (3) → 3 partes iguales; pagar las 3 → mesa libre.
- Modo Montos → 2 partes que suman el total, una con boleta → comprobante emitido/pendiente; mesa libre.
- Modo Ítems → asignar ítems a 2 partes → cobrar → mesa libre.
- Pago mixto en una parte (efectivo + tarjeta) → suma valida.
- Sin caja abierta → bloquea con mensaje.

- [ ] **Step 8: Commit**

```bash
git add mozo/index.php
git commit -m "feat(mesas): app mozo — precuenta (RawBT) + cobro con split (4 modos), pago mixto y comprobante"
```

---

### Task 9: Integración con arqueo (`api/pos.php`) y dashboard (`admin/dashboard.php`)

**Files:**
- Modify: `api/pos.php` (`cerrar_turno`: sumar `cuenta_pagos`; guard `origen<>'mesa'`)
- Modify: `admin/dashboard.php` (sumar lo cobrado en mesas a los totales POS)

**Interfaces:**
- Consumes: `cuentaPagosArqueo` (T3), `cuentaPagosListo` (T2).

- [ ] **Step 1: Incluir `cuentas.php` en `api/pos.php`**

Confirmar si ya está incluido:
Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && grep -n "includes/cuentas.php\|require" api/pos.php | head`
Si NO aparece `includes/cuentas.php`, añadir tras los demás requires del tope del archivo:
```php
require_once __DIR__ . '/../includes/cuentas.php';
```

- [ ] **Step 2: `cerrar_turno` — guard de mesa + suma de `cuenta_pagos`**

En el `case 'cerrar_turno'`, el `$ag` (SUM de `pedidos`) debe excluir mesas. 

old_string:
```php
         FROM pedidos p LEFT JOIN pos_metodos_pago m ON m.nombre = p.metodo_pago
         WHERE p.turno_id = ? AND p.estado <> 'cancelado'", [$tid]);
    $cajaEsperada = (float)$t['monto_inicial'] + $ingreso + (float)$ag['ef'] - $gastosTot;
```
new_string:
```php
         FROM pedidos p LEFT JOIN pos_metodos_pago m ON m.nombre = p.metodo_pago
         WHERE p.turno_id = ? AND p.estado <> 'cancelado' AND p.origen <> 'mesa'", [$tid]);
    // Sumar lo cobrado en mesas (cuenta_pagos) a los buckets del arqueo.
    $cp = cuentaPagosArqueo($tid);
    $totEf = (float)$ag['ef'] + $cp['efectivo'];
    $totTa = (float)$ag['ta'] + $cp['tarjeta'];
    $totQr = (float)$ag['qr'] + $cp['qr'];
    $totOt = (float)$ag['ot'] + $cp['otros'];
    $totVentas = (float)$ag['tot'] + $cp['total'];
    $totPedidos = (int)$ag['n'] + $cp['n'];
    $cajaEsperada = (float)$t['monto_inicial'] + $ingreso + $totEf - $gastosTot;
```

Y en el `UPDATE pos_turnos` que persiste el arqueo, reemplazar los valores de los buckets por los combinados.

old_string:
```php
         (int)$ag['n'], $ag['tot'], $ag['ef'], $ag['ta'], $ag['qr'], $ag['ot'], $tid]);
```
new_string:
```php
         $totPedidos, $totVentas, $totEf, $totTa, $totQr, $totOt, $tid]);
```

- [ ] **Step 3: Verificar sintaxis de pos.php**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l api/pos.php`
Expected: `No syntax errors detected in api/pos.php`

- [ ] **Step 4: Dashboard — sumar lo cobrado en mesas a los totales POS**

`admin/dashboard.php` ya excluye `origen<>'mesa'` de `pedidos` (líneas ~108/115/122/151). Añadir el dinero de mesas vía `cuenta_pagos`. Tras el bloque que calcula `$canalPos`/`$ventasHoy`/`$ventasMes` (alrededor de la línea 130), añadir (guardado por tabla):

```php
// Mesas cobradas (cuenta_pagos) → se suman al canal POS.
$mesaHoy = 0.0; $mesaMes = 0.0;
if (function_exists('cuentaPagosListo') && cuentaPagosListo()) {
    $mesaHoy = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE DATE(created_at)=CURDATE()")['s'] ?? 0);
    $mesaMes = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE DATE_FORMAT(created_at,'%Y-%m')=?", [$mes])['s'] ?? 0);
    $ventasHoy = (float)$ventasHoy + $mesaHoy;
    $ventasMes = (float)$ventasMes + $mesaMes;
    $canalPos  = (float)$canalPos + $mesaMes;
}
```

(Verificar los nombres reales de las variables del dashboard con `grep -n "ventasHoy\|ventasMes\|canalPos\|\$mes " admin/dashboard.php` antes de editar; ajustar si difieren. Si `cuentas.php` no está incluido en dashboard, añadir `require_once __DIR__ . '/../../includes/cuentas.php';` cerca de los otros requires — el guard `function_exists` evita romper si no.)

Para `posPorTienda` (línea ~151), opcionalmente sumar por tienda:
```php
if (function_exists('cuentaPagosListo') && cuentaPagosListo()) {
    try {
        $mp = Database::fetchAll("SELECT ubicacion_id, COALESCE(SUM(monto),0) t FROM cuenta_pagos WHERE DATE_FORMAT(created_at,'%Y-%m')=? GROUP BY ubicacion_id", [$mes]);
        $mpById = [];
        foreach ($mp as $r) $mpById[(int)$r['ubicacion_id']] = (float)$r['t'];
        foreach ($posPorTienda as &$row) { $row['t'] = (float)$row['t'] + ($mpById[(int)$row['ubicacion_id']] ?? 0); }
        unset($row);
    } catch (\Throwable $e) {}
}
```

- [ ] **Step 5: Verificar sintaxis del dashboard**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l admin/dashboard.php`
Expected: `No syntax errors detected in admin/dashboard.php`

- [ ] **Step 6: Checklist (servidor con BD)**

- Cobrar una mesa con la caja abierta → cerrar el turno: el efectivo/tarjeta/qr de la mesa aparece en el arqueo y en `caja_esperada`.
- Las comandas de mesa NO se cuentan dos veces (guard `origen<>'mesa'`).
- Dashboard: el monto cobrado en mesas suma al bloque POS del mes/hoy.

- [ ] **Step 7: Commit**

```bash
git add api/pos.php admin/dashboard.php
git commit -m "feat(mesas): arqueo y dashboard suman cuenta_pagos (cobro de mesas) sin doble conteo"
```

---

## Self-Review

**1. Spec coverage:**
- C.1 modelo de datos → Task 1 (cuenta_pagos + cuentas ALTERs). ✅
- C.2 precuenta no fiscal → Task 6 (builder) + Task 7 (acción) + Task 8 (botón). ✅
- C.3 split 4 modos → Task 5 (`cuentaCobrar` modos) + Task 8 (UI). ✅
- C.4 cobro mixto + comprobante + turno → Task 5 (núcleo) + Task 7 (acción) + Task 8 (UI). ✅
- C.5 estados de plano (precuenta/por_cobrar) → Task 4 (`mesaEstados`). ✅
- C.6 arqueo/dashboard/monitor → Task 9 (arqueo + dashboard); monitor sin cambios (ya usa `cuentas.total` de abiertas, correcto). ✅
- C.7 archivos/API → Tasks 6/7/8/9. ✅
- Geocerca en cobro → Task 7 (`geoGate` en `cobrar`). ✅

**2. Placeholder scan:** El único "TODO controlado" es el autocompletado DNI/RUC en el mozo (Task 8 Step 4), explicitado como fuera de alcance con su razón (no abrir `consultar_doc` a sesión de mozo en este build); los campos de cliente quedan manuales y funcionales. No hay TBD ni "handle edge cases" vacíos.

**3. Type consistency:** Firmas consistventes entre tasks: `cuentaPagosListo()`/`itemLineTotal()`/`repartoCentavos()` (T2) usados por T3/T4/T5; `turnoAbiertoLocal()` (T3) por T5/T7; `cuentaCobrar()` (T5) por T7; `escposPrecuentaBytes()` (T6) por T7; `cuentaPagosArqueo()` (T3) por T9. `cuentaDetalle` extendido en T4 con `monto_cobrar`/`pagado`/`falta` consumidos por T7/T8. El payload de `cobrar` (T7) coincide con el de `cuentaCobrar` (T5).

**Riesgo conocido (para la revisión final):** la app del mozo (`mozo/index.php`) usa helpers de fetch/CSRF y nombres de función propios; Task 8 Step 1 obliga a leerlos antes de codear para no inventar identificadores. La integración de dashboard (Task 9 Step 4) depende de los nombres reales de variables (`$ventasHoy` etc.) — el step obliga a verificarlos con grep antes de editar.
