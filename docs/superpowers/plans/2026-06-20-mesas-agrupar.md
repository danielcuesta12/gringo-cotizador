# Mesas POS Sub-build E1 — Agrupar mesas · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que una cuenta ocupe varias mesas: juntar una mesa libre a una cuenta abierta (grupo grande) y separar una mesa del grupo, antes de cobrar.

**Architecture:** La mesa principal sigue en `cuentas.mesa_id`; una tabla nueva `cuenta_mesas` guarda solo las mesas secundarias. El núcleo que pinta/rutea (`mesaEstados`, `cuentaAbiertaDeMesa`, `cuentaDetalle`) se hace consciente de las secundarias, con guard `cuentaMesasListo()` para tolerar la migración pendiente. La UI vive en la ficha de mesa del mozo.

**Tech Stack:** PHP 8 + PDO (`Database`), JS vanilla inline, MySQL. Sin frameworks.

## Global Constraints

- **Modelo:** principal en `cuentas.mesa_id`; `cuenta_mesas` = solo secundarias. Cuenta de una mesa = sin filas → idéntica a hoy, sin backfill.
- **Antes de cobrar:** juntar/separar solo si la cuenta NO tiene pagos (`cuenta_pagos`). Guard `cuentaTieneCobro()`.
- **Solo mesa libre** en E1 (juntar una mesa con cuenta abierta = fusión = E2). Libre = activa, del local, no principal ni secundaria de ninguna cuenta abierta.
- SQL siempre con `?`. Scope multi-local por `ubicacion_id`. Sesión de mozo; `verifyCsrf()` + `geoGate($ubi)` en escrituras.
- **Guard `cuentaMesasListo()`** en todo acceso a `cuenta_mesas`: si la migración 59 no está, el sistema se comporta como hoy (una mesa por cuenta) sin romperse.
- **Sin emojis** (regla del proyecto): texto/símbolos; al tocar `openMesaInfo` quitar el `⏱` existente. Tokens de marca; táctil ≥44px.
- **Verificación (sin framework de tests):** `php -l <archivo>` en cada PHP tocado; checklist funcional/lógico; grep estructural para la migración. No hay BD de dev garantizada.

---

### Task 1: Migración `59_cuenta_mesas.sql` + guard `cuentaMesasListo()`

**Files:**
- Create: `install/59_cuenta_mesas.sql`
- Modify: `install/check_migraciones.sql`
- Modify: `includes/cuentas.php` (añadir `cuentaMesasListo()`)

**Interfaces:**
- Produces: tabla `cuenta_mesas (id, cuenta_id, mesa_id, created_at)` con `UNIQUE(cuenta_id, mesa_id)`; `cuentaMesasListo(): bool`.

- [ ] **Step 1: Crear la migración**

Crear `install/59_cuenta_mesas.sql`:
```sql
-- 59_cuenta_mesas.sql — Mesas POS Sub-build E1: mesas secundarias (juntadas) de una cuenta.
-- La principal sigue en cuentas.mesa_id; esta tabla solo guarda las secundarias. Idempotente.

CREATE TABLE IF NOT EXISTS `cuenta_mesas` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cuenta_id`  INT UNSIGNED NOT NULL,
  `mesa_id`    INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cmesa` (`cuenta_id`, `mesa_id`),
  KEY `idx_cmesa_mesa` (`mesa_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Fila en `check_migraciones.sql`**

Añadir, después de la fila de `58_cobro_mesas.sql`, con el formato exacto de las vecinas:
```sql
  UNION ALL SELECT '59_cuenta_mesas.sql       (tabla cuenta_mesas)', COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cuenta_mesas'
```

- [ ] **Step 3: Añadir `cuentaMesasListo()` a `includes/cuentas.php`**

Justo después de `cuentaPagosListo()`, añadir:
```php
/** ¿Existe la tabla de mesas secundarias? (Sub-build E1) */
function cuentaMesasListo(): bool {
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cuenta_mesas'");
    } catch (\Throwable $e) { return false; }
}
```

- [ ] **Step 4: Verificar**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l includes/cuentas.php && grep -c "cuenta_mesas" install/check_migraciones.sql`
Expected: `No syntax errors detected in includes/cuentas.php` y el grep `>= 1`.

- [ ] **Step 5: Commit**
```bash
git add install/59_cuenta_mesas.sql install/check_migraciones.sql includes/cuentas.php
git commit -m "feat(mesas): migración 59 cuenta_mesas + guard cuentaMesasListo()"
```

---

### Task 2: Núcleo consciente de mesas secundarias

**Files:**
- Modify: `includes/cuentas.php` (`cuentaAbiertaDeMesa`, `mesaEstados`, añadir `cuentaMesasLista`, `cuentaDetalle` devuelve `mesas`)

**Interfaces:**
- Consumes: `cuentaMesasListo()` (Task 1).
- Produces:
  - `cuentaMesasLista(int $cuentaId): array` → `[['id'=>int,'numero'=>string,'principal'=>bool], ...]` (principal primero).
  - `cuentaAbiertaDeMesa` ahora encuentra la cuenta abierta por mesa principal **o** secundaria.
  - `mesaEstados` pinta también las mesas secundarias (mismo estado/total/mins de su cuenta).
  - `cuentaDetalle(...)` añade `'mesas' => cuentaMesasLista($cuentaId)`.

- [ ] **Step 1: `cuentaAbiertaDeMesa` busca principal o secundaria**

old_string:
```php
function cuentaAbiertaDeMesa(int $mesaId): ?array {
    return Database::fetch("SELECT * FROM cuentas WHERE mesa_id = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1", [$mesaId]);
}
```
new_string:
```php
function cuentaAbiertaDeMesa(int $mesaId): ?array {
    $c = Database::fetch("SELECT * FROM cuentas WHERE mesa_id = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1", [$mesaId]);
    if ($c) return $c;
    if (cuentaMesasListo()) {
        return Database::fetch(
            "SELECT cu.* FROM cuentas cu JOIN cuenta_mesas cm ON cm.cuenta_id = cu.id
             WHERE cm.mesa_id = ? AND cu.estado = 'abierta' ORDER BY cu.id DESC LIMIT 1", [$mesaId]);
    }
    return null;
}
```

- [ ] **Step 2: Añadir `cuentaMesasLista()`**

Añadir (cerca de `cuentaDetalle`):
```php
/** Mesas de una cuenta: principal (de cuentas.mesa_id) + secundarias (cuenta_mesas). Principal primero. */
function cuentaMesasLista(int $cuentaId): array {
    $out = [];
    $c = Database::fetch("SELECT cu.mesa_id, m.numero FROM cuentas cu LEFT JOIN mesas m ON m.id = cu.mesa_id WHERE cu.id = ?", [$cuentaId]);
    if ($c && $c['mesa_id'] !== null) $out[] = ['id' => (int)$c['mesa_id'], 'numero' => (string)($c['numero'] ?? ''), 'principal' => true];
    if (cuentaMesasListo()) {
        foreach (Database::fetchAll("SELECT m.id, m.numero FROM cuenta_mesas cm JOIN mesas m ON m.id = cm.mesa_id WHERE cm.cuenta_id = ? ORDER BY cm.id", [$cuentaId]) as $r) {
            $out[] = ['id' => (int)$r['id'], 'numero' => (string)$r['numero'], 'principal' => false];
        }
    }
    return $out;
}
```

- [ ] **Step 3: `cuentaDetalle` devuelve `mesas`**

En el `return [...]` de `cuentaDetalle`, añadir la clave `mesas` (después de `'mesa_numero' => $c['mesa_numero'],`):

old_string:
```php
        'id' => (int)$c['id'], 'mesa_id' => (int)$c['mesa_id'], 'mesa_numero' => $c['mesa_numero'],
        'num_comensales' => (int)$c['num_comensales'], 'estado' => $c['estado'],
```
new_string:
```php
        'id' => (int)$c['id'], 'mesa_id' => (int)$c['mesa_id'], 'mesa_numero' => $c['mesa_numero'],
        'mesas' => cuentaMesasLista($cuentaId),
        'num_comensales' => (int)$c['num_comensales'], 'estado' => $c['estado'],
```

- [ ] **Step 4: `mesaEstados` pinta las secundarias**

Reemplazar la función `mesaEstados` completa por esta versión (añade `cu.id` al SELECT, acumula `$porCuenta`, y un segundo pase para las secundarias):

old_string:
```php
function mesaEstados(int $ubicacionId): array {
    $estados = []; $montos = []; $minutos = [];
    $hasCobro = cuentaPagosListo(); // migración 58 = cuenta_pagos + cuentas.precuenta_at (se crean juntas)
    // ncom = comandas no canceladas: una cuenta abierta SIN contenido no pinta la mesa (se ve libre).
    $ncomSub = "(SELECT COUNT(*) FROM pedidos WHERE cuenta_id = cu.id AND estado <> 'cancelado')";
    $sel = $hasCobro
        ? "SELECT cu.mesa_id, cu.total, cu.precuenta_at, TIMESTAMPDIFF(MINUTE, cu.abierta_at, NOW()) AS mins,
                  COALESCE((SELECT SUM(monto) FROM cuenta_pagos WHERE cuenta_id = cu.id),0) AS pagado, $ncomSub AS ncom
           FROM cuentas cu WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'"
        : "SELECT cu.mesa_id, cu.total, NULL AS precuenta_at, TIMESTAMPDIFF(MINUTE, cu.abierta_at, NOW()) AS mins,
                  0 AS pagado, $ncomSub AS ncom
           FROM cuentas cu WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'";
    foreach (Database::fetchAll($sel, [$ubicacionId]) as $r) {
        $mid = (int)$r['mesa_id'];
        $pagado = (float)$r['pagado'];
        $ncom   = (int)$r['ncom'];
        if ($ncom === 0 && $pagado <= 0.001) continue; // cuenta abierta vacía → no pintar la mesa
        if ($pagado > 0.001)                $estado = 'por_cobrar';
        elseif (!empty($r['precuenta_at'])) $estado = 'precuenta';
        else                                $estado = 'ocupada';
        $estados[$mid] = $estado;
        $montos[$mid]  = (float)$r['total'];
        $minutos[$mid] = max(0, (int)$r['mins']);
    }
    return ['estados' => $estados, 'montos' => $montos, 'minutos' => $minutos];
}
```
new_string:
```php
function mesaEstados(int $ubicacionId): array {
    $estados = []; $montos = []; $minutos = [];
    $hasCobro = cuentaPagosListo(); // migración 58 = cuenta_pagos + cuentas.precuenta_at (se crean juntas)
    // ncom = comandas no canceladas: una cuenta abierta SIN contenido no pinta la mesa (se ve libre).
    $ncomSub = "(SELECT COUNT(*) FROM pedidos WHERE cuenta_id = cu.id AND estado <> 'cancelado')";
    $sel = $hasCobro
        ? "SELECT cu.id, cu.mesa_id, cu.total, cu.precuenta_at, TIMESTAMPDIFF(MINUTE, cu.abierta_at, NOW()) AS mins,
                  COALESCE((SELECT SUM(monto) FROM cuenta_pagos WHERE cuenta_id = cu.id),0) AS pagado, $ncomSub AS ncom
           FROM cuentas cu WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'"
        : "SELECT cu.id, cu.mesa_id, cu.total, NULL AS precuenta_at, TIMESTAMPDIFF(MINUTE, cu.abierta_at, NOW()) AS mins,
                  0 AS pagado, $ncomSub AS ncom
           FROM cuentas cu WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'";
    $porCuenta = []; // cuentas pintadas (con contenido) → su estado/total/mins, para pintar sus secundarias
    foreach (Database::fetchAll($sel, [$ubicacionId]) as $r) {
        $pagado = (float)$r['pagado'];
        $ncom   = (int)$r['ncom'];
        if ($ncom === 0 && $pagado <= 0.001) continue; // cuenta abierta vacía → no pintar la mesa
        if ($pagado > 0.001)                $estado = 'por_cobrar';
        elseif (!empty($r['precuenta_at'])) $estado = 'precuenta';
        else                                $estado = 'ocupada';
        $mid = (int)$r['mesa_id']; $tot = (float)$r['total']; $min = max(0, (int)$r['mins']);
        $estados[$mid] = $estado; $montos[$mid] = $tot; $minutos[$mid] = $min;
        $porCuenta[(int)$r['id']] = ['estado' => $estado, 'total' => $tot, 'mins' => $min];
    }
    // Mesas secundarias (juntadas) → mismo estado que su cuenta (solo cuentas con contenido).
    if ($porCuenta && cuentaMesasListo()) {
        foreach (Database::fetchAll(
            "SELECT cm.cuenta_id, cm.mesa_id FROM cuenta_mesas cm
             JOIN cuentas cu ON cu.id = cm.cuenta_id
             WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'", [$ubicacionId]) as $cm) {
            $cid = (int)$cm['cuenta_id'];
            if (!isset($porCuenta[$cid])) continue;
            $mid = (int)$cm['mesa_id'];
            $estados[$mid] = $porCuenta[$cid]['estado'];
            $montos[$mid]  = $porCuenta[$cid]['total'];
            $minutos[$mid] = $porCuenta[$cid]['mins'];
        }
    }
    return ['estados' => $estados, 'montos' => $montos, 'minutos' => $minutos];
}
```

- [ ] **Step 5: Verificar**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l includes/cuentas.php`
Expected: `No syntax errors detected in includes/cuentas.php`

- [ ] **Step 6: Revisión de lógica (checklist)**

Confirmar leyendo: `cuentaAbiertaDeMesa` cae a `cuenta_mesas` solo si la principal no matchea y la tabla existe; `mesaEstados` solo pinta secundarias de cuentas que quedaron en `$porCuenta` (con contenido) y respeta la regla de no-pintar-vacías; `cuentaMesasLista` pone la principal primero; el guard `cuentaMesasListo()` está en cada acceso a `cuenta_mesas`.

- [ ] **Step 7: Commit**
```bash
git add includes/cuentas.php
git commit -m "feat(mesas): núcleo consciente de mesas secundarias (estados/ruteo/detalle)"
```

---

### Task 3: Lógica de juntar / separar / mesas libres

**Files:**
- Modify: `includes/cuentas.php` (añadir `cuentaTieneCobro`, `mesasLibres`, `cuentaJuntarMesaLibre`, `cuentaSepararMesa`)

**Interfaces:**
- Consumes: `cuentaMesasListo`, `cuentaMesasLista`, `cuentaAbiertaDeMesa`, `cuentaPagosListo` (Tasks 1-2).
- Produces:
  - `cuentaTieneCobro(int $cuentaId): bool` — ¿la cuenta tiene pagos?
  - `mesasLibres(int $ubicacionId): array` → `[['id'=>int,'numero'=>string], ...]` mesas activas del local no usadas por ninguna cuenta abierta.
  - `cuentaJuntarMesaLibre(int $cuentaId, int $mesaId, int $ubicacionId): array` → `['ok'=>bool,'error'?=>string,'mesas'?=>cuentaMesasLista]`.
  - `cuentaSepararMesa(int $cuentaId, int $mesaId, int $ubicacionId): array` → idem.

- [ ] **Step 1: Añadir las cuatro funciones**

Al final de `includes/cuentas.php`:
```php
/** ¿La cuenta ya tiene pagos registrados? */
function cuentaTieneCobro(int $cuentaId): bool {
    if (!cuentaPagosListo()) return false;
    return (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['s'] ?? 0) > 0.001;
}

/** Mesas libres del local: activas y no usadas (principal ni secundaria) por ninguna cuenta abierta. */
function mesasLibres(int $ubicacionId): array {
    $ocup = [];
    foreach (Database::fetchAll("SELECT mesa_id FROM cuentas WHERE ubicacion_id = ? AND estado = 'abierta'", [$ubicacionId]) as $r) $ocup[(int)$r['mesa_id']] = 1;
    if (cuentaMesasListo()) {
        foreach (Database::fetchAll(
            "SELECT cm.mesa_id FROM cuenta_mesas cm JOIN cuentas cu ON cu.id = cm.cuenta_id
             WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'", [$ubicacionId]) as $r) $ocup[(int)$r['mesa_id']] = 1;
    }
    $out = [];
    foreach (Database::fetchAll("SELECT id, numero FROM mesas WHERE ubicacion_id = ? AND activa = 1 ORDER BY numero+0, numero", [$ubicacionId]) as $m) {
        if (!isset($ocup[(int)$m['id']])) $out[] = ['id' => (int)$m['id'], 'numero' => (string)$m['numero']];
    }
    return $out;
}

/** Junta una mesa LIBRE a una cuenta abierta (grupo grande). Antes de cobrar. */
function cuentaJuntarMesaLibre(int $cuentaId, int $mesaId, int $ubicacionId): array {
    if (!cuentaMesasListo()) return ['ok' => false, 'error' => 'función no disponible'];
    $c = Database::fetch("SELECT * FROM cuentas WHERE id = ? AND estado = 'abierta' AND (? = 0 OR ubicacion_id = ?)", [$cuentaId, $ubicacionId, $ubicacionId]);
    if (!$c) return ['ok' => false, 'error' => 'cuenta no abierta'];
    if (cuentaTieneCobro($cuentaId)) return ['ok' => false, 'error' => 'la cuenta ya tiene pagos'];
    if ($mesaId === (int)$c['mesa_id']) return ['ok' => false, 'error' => 'ya es la mesa principal'];
    $m = Database::fetch("SELECT id FROM mesas WHERE id = ? AND ubicacion_id = ? AND activa = 1", [$mesaId, (int)$c['ubicacion_id']]);
    if (!$m) return ['ok' => false, 'error' => 'mesa inválida'];
    if (cuentaAbiertaDeMesa($mesaId)) return ['ok' => false, 'error' => 'la mesa no está libre'];
    Database::execute("INSERT IGNORE INTO cuenta_mesas (cuenta_id, mesa_id) VALUES (?, ?)", [$cuentaId, $mesaId]);
    return ['ok' => true, 'mesas' => cuentaMesasLista($cuentaId)];
}

/** Separa una mesa SECUNDARIA del grupo → vuelve a libre. La principal no se separa. */
function cuentaSepararMesa(int $cuentaId, int $mesaId, int $ubicacionId): array {
    if (!cuentaMesasListo()) return ['ok' => false, 'error' => 'función no disponible'];
    $c = Database::fetch("SELECT * FROM cuentas WHERE id = ? AND estado = 'abierta' AND (? = 0 OR ubicacion_id = ?)", [$cuentaId, $ubicacionId, $ubicacionId]);
    if (!$c) return ['ok' => false, 'error' => 'cuenta no abierta'];
    if (cuentaTieneCobro($cuentaId)) return ['ok' => false, 'error' => 'la cuenta ya tiene pagos'];
    if ($mesaId === (int)$c['mesa_id']) return ['ok' => false, 'error' => 'no se puede separar la mesa principal'];
    $n = Database::execute("DELETE FROM cuenta_mesas WHERE cuenta_id = ? AND mesa_id = ?", [$cuentaId, $mesaId]);
    if ($n <= 0) return ['ok' => false, 'error' => 'la mesa no está en este grupo'];
    return ['ok' => true, 'mesas' => cuentaMesasLista($cuentaId)];
}
```

- [ ] **Step 2: Verificar**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l includes/cuentas.php`
Expected: `No syntax errors detected in includes/cuentas.php`

- [ ] **Step 3: Revisión de lógica (checklist)**

Confirmar: ambas escrituras validan cuenta abierta + del local + **sin pagos**; `juntar` exige mesa **libre** (vía `cuentaAbiertaDeMesa`) del mismo local y no la principal; `separar` solo borra secundarias y nunca la principal; `mesasLibres` excluye principales y secundarias de cuentas abiertas; todo con `?`.

- [ ] **Step 4: Commit**
```bash
git add includes/cuentas.php
git commit -m "feat(mesas): juntar mesa libre + separar + mesasLibres (antes de cobrar)"
```

---

### Task 4: Acciones de API (`api/mozo.php`)

**Files:**
- Modify: `api/mozo.php` (writes array + casos `mesas_libres`, `juntar_mesa`, `separar_mesa`)

**Interfaces:**
- Consumes: `mesasLibres`, `cuentaJuntarMesaLibre`, `cuentaSepararMesa` (Task 3); `mozoUbi()` (`$ubi`), `geoGate()`.
- Produces (JSON): `mesas_libres` → `{ok, mesas:[{id,numero}]}`; `juntar_mesa`/`separar_mesa` → passthrough.

- [ ] **Step 1: Registrar escrituras**

old_string:
```php
$writes = ['login_pin', 'logout', 'abrir_cuenta', 'enviar_comanda', 'anular', 'cerrar_cuenta_vacia', 'precuenta', 'cobrar'];
```
new_string:
```php
$writes = ['login_pin', 'logout', 'abrir_cuenta', 'enviar_comanda', 'anular', 'cerrar_cuenta_vacia', 'precuenta', 'cobrar', 'juntar_mesa', 'separar_mesa'];
```

- [ ] **Step 2: Añadir los casos**

Insertar antes de `default:`:
```php
    case 'mesas_libres':
        mout(['ok' => true, 'mesas' => mesasLibres($ubi)]);

    case 'juntar_mesa':
        geoGate($ubi);
        mout(cuentaJuntarMesaLibre(cleanInt($_POST['cuenta_id'] ?? 0), cleanInt($_POST['mesa_id'] ?? 0), $ubi));

    case 'separar_mesa':
        geoGate($ubi);
        mout(cuentaSepararMesa(cleanInt($_POST['cuenta_id'] ?? 0), cleanInt($_POST['mesa_id'] ?? 0), $ubi));
```

- [ ] **Step 3: Verificar**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l api/mozo.php`
Expected: `No syntax errors detected in api/mozo.php`

Confirmar (lectura): `juntar_mesa`/`separar_mesa` están en `$writes` (CSRF) y llaman `geoGate($ubi)`; `mesas_libres` es lectura; las tres scopean por `$ubi`.

- [ ] **Step 4: Commit**
```bash
git add api/mozo.php
git commit -m "feat(mesas): api/mozo — mesas_libres, juntar_mesa, separar_mesa"
```

---

### Task 5: UI de agrupar en la ficha de mesa (`mozo/index.php`)

**Files:**
- Modify: `mozo/index.php` (reescribir `openMesaInfo` con grupo + botones; añadir modal `#m-pick` y `openJuntar`/`doJuntar`/`openSeparar`/`doSeparar`)

**Interfaces:**
- Consumes: acciones `mesas_libres`/`juntar_mesa`/`separar_mesa` (Task 4); `cuenta.mesas` (objetos `{id,numero,principal}`) y `cuenta.pagado` de `cuentaDetalle`; helpers `$()`,`get()`,`post()`,`geo()`,`withGeo()`,`toast()`,`openModal`/`closeModal`,`esc`.

- [ ] **Step 1: Leer los anclajes reales**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && grep -n "function openMesaInfo\|m-mesa-in\|id=\"m-mesa\"\|function geo\|withGeo\|sheet-head\|class=\"modal\"" mozo/index.php | head`
Anotar la estructura real de modal/sheet para que `#m-pick` use las mismas clases.

- [ ] **Step 2: Reescribir `openMesaInfo` (grupo + botones, sin el emoji ⏱)**

old_string (la función actual, desde `$('m-mesa-in').innerHTML=` hasta `openModal('m-mesa');`):
```javascript
    $('m-mesa-in').innerHTML=
      '<div style="padding:15px 16px 4px;display:flex;justify-content:space-between;align-items:flex-start">'+
        '<div><div style="font-weight:900;font-size:19px">Mesa '+esc(c.mesa_numero||'')+'</div>'+
          '<div style="font-size:12px;color:#888;margin-top:2px">'+c.num_comensales+' comensales'+(c.mozo_nombre?(' · '+esc(c.mozo_nombre)):'')+'</div></div>'+
        '<div style="font-weight:900;font-size:21px">S/ '+Number(c.total).toFixed(0)+'</div></div>'+
      '<div style="padding:0 16px;font-size:11px;color:#888">⏱ Abierta '+mins+' min · '+rondas+' ronda'+(rondas===1?'':'s')+'</div>'+
      (resumen?('<div style="margin:9px 16px 0;padding-top:8px;border-top:1px solid #eee;font-size:12px;color:#555;line-height:1.5">'+esc(resumen)+'</div>'):'')+
      '<div style="padding:13px 16px">'+
        '<button class="btn" onclick="verCuentaDesdeInfo()">Ver / agregar</button>'+
        '<button class="btn dark" style="margin-top:8px" onclick="verCuentaDesdeInfo(); setTimeout(function(){ document.getElementById(\'btn-cobrar\').click(); }, 600)">Cobrar</button>'+
        '<button class="btn" style="background:#eee;color:#555;margin-top:8px" onclick="closeModal(\'m-mesa\')">Cerrar</button>'+
      '</div>';
    openModal('m-mesa');
```
new_string:
```javascript
    var grupo = (c.mesas && c.mesas.length) ? c.mesas.map(function(m){return esc(m.numero);}).join(' + ') : esc(c.mesa_numero||'');
    var sinPagos = !(c.pagado > 0);
    var nSec = (c.mesas||[]).filter(function(m){return !m.principal;}).length;
    var acciones =
      '<button class="btn" onclick="verCuentaDesdeInfo()">Ver / agregar</button>'+
      '<button class="btn dark" style="margin-top:8px" onclick="verCuentaDesdeInfo(); setTimeout(function(){ document.getElementById(\'btn-cobrar\').click(); }, 600)">Cobrar</button>';
    if (sinPagos) acciones += '<button class="btn" style="margin-top:8px" onclick="openJuntar()">Juntar mesa</button>';
    if (sinPagos && nSec > 0) acciones += '<button class="btn" style="margin-top:8px" onclick="openSeparar()">Separar mesa</button>';
    acciones += '<button class="btn" style="background:#eee;color:#555;margin-top:8px" onclick="closeModal(\'m-mesa\')">Cerrar</button>';
    $('m-mesa-in').innerHTML=
      '<div style="padding:15px 16px 4px;display:flex;justify-content:space-between;align-items:flex-start">'+
        '<div><div style="font-weight:900;font-size:19px">Mesa '+grupo+'</div>'+
          '<div style="font-size:12px;color:#888;margin-top:2px">'+c.num_comensales+' comensales'+(c.mozo_nombre?(' · '+esc(c.mozo_nombre)):'')+'</div></div>'+
        '<div style="font-weight:900;font-size:21px">S/ '+Number(c.total).toFixed(0)+'</div></div>'+
      '<div style="padding:0 16px;font-size:11px;color:#888">Abierta '+mins+' min · '+rondas+' ronda'+(rondas===1?'':'s')+'</div>'+
      (resumen?('<div style="margin:9px 16px 0;padding-top:8px;border-top:1px solid #eee;font-size:12px;color:#555;line-height:1.5">'+esc(resumen)+'</div>'):'')+
      '<div style="padding:13px 16px">'+acciones+'</div>';
    openModal('m-mesa');
```

- [ ] **Step 3: Añadir el modal `#m-pick`**

Junto a los otros modales (ej. después de `#m-mesa`), añadir (ajustar clases a las reales del archivo halladas en Step 1):
```html
<div class="modal" id="m-pick"><div class="sheet"><div class="sheet-head" style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid var(--line)"><b id="pick-tit"></b><button type="button" style="background:none;border:none;font-size:20px;color:var(--muted)" onclick="closeModal('m-pick')">✕</button></div><div id="pick-body" style="padding:14px 16px"></div></div></div>
```

- [ ] **Step 4: JS de juntar / separar**

Añadir (cerca de `openMesaInfo`):
```javascript
function openJuntar(){
  get('mesas_libres').then(function(d){
    if(!d.ok){ toast('Error'); return; }
    if(!d.mesas.length){ toast('No hay mesas libres'); return; }
    $('pick-tit').textContent='Juntar a esta cuenta';
    var box=$('pick-body'); box.innerHTML='';
    d.mesas.forEach(function(m){ var b=document.createElement('button'); b.className='btn'; b.style.marginBottom='8px'; b.textContent='Mesa '+m.numero; b.onclick=function(){ doJuntar(m.id); }; box.appendChild(b); });
    openModal('m-pick');
  });
}
function doJuntar(mesaId){ geo().then(function(){ post('juntar_mesa', withGeo({cuenta_id:st.cuenta.id, mesa_id:mesaId})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} closeModal('m-pick'); toast('Mesa juntada'); openMesaInfo(st.cuenta.mesa_id); }); }); }
function openSeparar(){
  var sec=(st.cuenta.mesas||[]).filter(function(m){return !m.principal;});
  if(!sec.length){ toast('No hay mesas para separar'); return; }
  $('pick-tit').textContent='Separar del grupo';
  var box=$('pick-body'); box.innerHTML='';
  sec.forEach(function(m){ var b=document.createElement('button'); b.className='btn'; b.style.marginBottom='8px'; b.textContent='Mesa '+m.numero; b.onclick=function(){ doSeparar(m.id); }; box.appendChild(b); });
  openModal('m-pick');
}
function doSeparar(mesaId){ geo().then(function(){ post('separar_mesa', withGeo({cuenta_id:st.cuenta.id, mesa_id:mesaId})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} closeModal('m-pick'); toast('Mesa separada'); openMesaInfo(st.cuenta.mesa_id); }); }); }
```

- [ ] **Step 5: Verificar**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l mozo/index.php`
Expected: `No syntax errors detected in mozo/index.php`

Y confirmar que ya no queda el emoji: `grep -c "⏱" mozo/index.php` → `0`.

- [ ] **Step 6: Checklist funcional**

- Tocar una mesa ocupada muestra "Mesa 5" (o "Mesa 5 + 6" si está agrupada) y, si no tiene pagos, los botones "Juntar mesa" / "Separar mesa".
- "Juntar mesa" lista las mesas libres; al elegir, la mesa se suma al grupo (la ficha pasa a "Mesa 5 + 6"); la mesa juntada se pinta ocupada en el plano (en ≤5s por el poll).
- "Separar mesa" lista las secundarias; al elegir, esa mesa vuelve a libre.
- Una cuenta con pagos no muestra juntar/separar.

- [ ] **Step 7: Commit**
```bash
git add mozo/index.php
git commit -m "feat(mozo): ficha de mesa con grupo + juntar/separar mesa (quita emoji ⏱)"
```

---

## Self-Review

**1. Spec coverage:**
- E1.1 modelo `cuenta_mesas` → Task 1. ✅
- E1.2 conciencia multi-mesa (`cuentaAbiertaDeMesa`/`mesaEstados`/`cuentaDetalle.mesas`) → Task 2. ✅
- E1.3 juntar mesa libre → Task 3 (`cuentaJuntarMesaLibre`) + Task 4 (API) + Task 5 (UI). ✅
- E1.4 separar → Task 3 (`cuentaSepararMesa`) + Task 4 + Task 5. ✅
- E1.5 API (mesas_libres/juntar/separar) → Task 4. ✅
- E1.6 UI ficha con grupo + botones → Task 5. ✅
- Guard `cuentaMesasListo()` en todo acceso a `cuenta_mesas` → Tasks 1-3. ✅
- Sin emojis (quita ⏱) → Task 5 Step 5 lo verifica. ✅

**2. Placeholder scan:** sin TBD/TODO; cada paso trae el código completo. Las verificaciones son comandos concretos.

**3. Type consistency:** `cuentaMesasLista` (Task 2) devuelve `[{id,numero,principal}]`, consumido por `cuentaDetalle.mesas` (Task 2) y la UI (Task 5, usa `m.numero`/`m.principal`/`m.id`). `mesasLibres` (Task 3) devuelve `[{id,numero}]`, consumido por `mesas_libres` (Task 4) y `openJuntar` (Task 5). `cuentaJuntarMesaLibre`/`cuentaSepararMesa` (Task 3) devuelven `{ok,error?,mesas?}`, passthrough en Task 4. `geoGate`/`mozoUbi` ya existen.

**Riesgo conocido (para la revisión):** Tasks 2-3 agregan/modifican varias funciones en `includes/cuentas.php`; si una ancla `old_string` cambió, reubicar por contexto. Task 5 reescribe `openMesaInfo` (anclas exactas dadas) y añade un modal — verificar que las clases `.modal`/`.sheet` coincidan con las del archivo (Step 1).
