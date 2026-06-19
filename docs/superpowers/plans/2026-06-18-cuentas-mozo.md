# Mesas POS — Sub-build B (Cuentas & Mozo) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Operar el salón: abrir cuentas en las mesas, una app del mozo (login por PIN, atada al local por geocerca GPS) que toma pedidos y envía comandas al KDS (mostrando la mesa), con anulaciones y estados de mesa en vivo — sin contar esas comandas como venta hasta que se cobren (C).

**Architecture:** Una librería compartida `includes/cuentas.php` concentra la lógica (abrir cuenta, enviar comanda, anular, total, estados de mesa, geocerca). `api/mozo.php` expone esa lógica a la app del mozo (`mozo/index.php`, PWA) tras un login por PIN (sesión de mozo) y validación de geocerca en las escrituras. Las comandas son `pedidos` con `origen='mesa'` + `cuenta_id`/`mesa_id`; el KDS las muestra con badge de mesa. Dashboard/monitor excluyen `origen='mesa'` de la venta realizada y el monitor suma aparte las cuentas abiertas.

**Tech Stack:** PHP 8 puro + PDO (clase `Database`), HTML + CSS propio + JS vanilla. PWA (manifest + `sw.js`). Deploy: git push → git pull en cPanel → aplicar SQL en phpMyAdmin.

## Global Constraints

- Nunca concatenar variables en SQL — siempre `?` con prepared statements.
- `verifyCsrf()` en escrituras de la API (header `X-CSRF-Token`). La app del mozo NO usa `requireLogin`/permisos de `users`: se gatea por **sesión de mozo** (PIN de `empleados`).
- Sanitizar con `clean()`/`cleanInt()`/`cleanFloat()`. Nunca exponer `pin_hash`.
- Comandas de mesa: `pedidos.origen='mesa'`, nacen `estado='en_preparacion'` con `aceptado_at=NOW()` (arranca el timer del KDS). `turno_id` queda NULL hasta el cobro (C).
- **Anular solo si la comanda NO está `listo`/`entregado`/`cancelado`** (estado IN `pendiente`,`en_preparacion`). Como el stock se descuenta al marcar Listo, anular-antes-de-Listo no toca inventario.
- **Frontera de venta:** dashboard y monitor excluyen `origen='mesa'` de la venta realizada. El arqueo (`cerrar_turno`) ya las excluye porque `turno_id` es NULL. El monitor muestra aparte `SUM(cuentas.total WHERE estado='abierta')`.
- **Geocerca dura:** las escrituras de `api/mozo.php` exigen `dentroGeocerca($ubi,$lat,$lng)`. Kill-switch global `getSetting('mozo_geocerca_activa','1')`. Reusa `ubicaciones.lat/lng/geocerca_radio`. Sin coords o setting en `'0'` → no restringe.
- Una mesa tiene como mucho **una** cuenta `abierta` a la vez.
- Multi-local por `ubicacion_id`. Marca: negro `#1E1E1E`, amarillo `#FFDF00`, rosa `#FFBBC8`, crema `#FFEFBC`. Mobile-first.
- NO hay framework de tests. Verificación = `php -l` + `node --check` (JS) + checklist funcional + SQL en phpMyAdmin.

### Contrato de la comanda (items_json)
Cada ítem: `{product_id, qty, nombre, precio, modificadores:[{nombre,precio}], nota, anulado?:true, anul_motivo?}`. (Igual que el POS, + flags de anulación.)

---

### Task 1: Migración de esquema

**Files:**
- Create: `install/57_cuentas.sql`
- Modify: `install/check_migraciones.sql`

**Interfaces:**
- Produces: tablas `cuentas`, `cuenta_anulaciones`; columnas `pedidos.cuenta_id`, `pedidos.mesa_id`; `pedidos.origen` con valor `'mesa'`.

- [ ] **Step 1: Escribir la migración**

Create `install/57_cuentas.sql`:

```sql
-- 57_cuentas.sql — Mesas POS Sub-build B: cuentas, anulaciones, enlace de comandas.
-- Idempotente. Aplicar en phpMyAdmin tras git pull.

CREATE TABLE IF NOT EXISTS `cuentas` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mesa_id`       INT UNSIGNED NOT NULL,
  `ubicacion_id`  INT UNSIGNED NOT NULL,
  `empleado_id`   INT UNSIGNED NULL,
  `num_comensales` INT NOT NULL DEFAULT 0,
  `estado`        ENUM('abierta','cerrada','cancelada') NOT NULL DEFAULT 'abierta',
  `total`         DECIMAL(10,2) NOT NULL DEFAULT 0,
  `abierta_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cerrada_at`    DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cuentas_mesa` (`mesa_id`),
  KEY `idx_cuentas_ubi` (`ubicacion_id`),
  KEY `idx_cuentas_estado` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cuenta_anulaciones` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cuenta_id`   INT UNSIGNED NOT NULL,
  `pedido_id`   INT UNSIGNED NOT NULL,
  `item_idx`    INT NULL,
  `motivo`      VARCHAR(160) NOT NULL,
  `empleado_id` INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_canul_cuenta` (`cuenta_id`),
  KEY `idx_canul_pedido` (`pedido_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- pedidos: columnas de enlace (guard portable)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='cuenta_id');
SET @s := IF(@c=0, "ALTER TABLE `pedidos` ADD COLUMN `cuenta_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pedidos' AND column_name='mesa_id');
SET @s := IF(@c=0, "ALTER TABLE `pedidos` ADD COLUMN `mesa_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- pedidos.origen: agregar 'mesa' al ENUM (re-ejecutable: MODIFY al set completo)
ALTER TABLE `pedidos` MODIFY COLUMN `origen` ENUM('carta','pos','mesa') NOT NULL DEFAULT 'carta';
```

- [ ] **Step 2: Añadir el chequeo en check_migraciones.sql**

READ `install/check_migraciones.sql` y agrega una fila (formato existente, SELECT + UNION ALL) que verifique la tabla `cuentas`:

```sql
SELECT '57_cuentas.sql' AS migracion,
       IF(COUNT(*)>0,'✅','❌') AS aplicada
FROM information_schema.tables
WHERE table_schema=DATABASE() AND table_name='cuentas'
UNION ALL
```
(Respeta dónde van los `UNION ALL`.)

- [ ] **Step 3: Verificar estructura SQL**

Sin MySQL local. Revisa: `CREATE TABLE` terminan en `;`, sin comas finales; los 2 bloques `PREPARE/EXECUTE/DEALLOCATE` balanceados; el `MODIFY` del ENUM incluye los 3 valores.

- [ ] **Step 4: Commit**

```bash
git add install/57_cuentas.sql install/check_migraciones.sql
git commit -m "feat(cuentas): migración — cuentas, anulaciones, enlace de comandas en pedidos"
```

- [ ] **Step 5: (Deploy) aplicar y verificar**

```sql
SHOW TABLES LIKE 'cuenta%';                 -- cuentas, cuenta_anulaciones
SHOW COLUMNS FROM pedidos LIKE 'cuenta_id'; -- existe
SHOW COLUMNS FROM pedidos LIKE 'origen';    -- ENUM('carta','pos','mesa')
```

---

### Task 2: Librería compartida `includes/cuentas.php`

**Files:**
- Create: `includes/cuentas.php`

**Interfaces:**
- Consumes: `Database::*`, `helpers.php` (`getSetting`), `inventario.php` (no se usa directo aquí).
- Produces:
  - `cuentasListo(): bool`
  - `haversineM(float $lat1,float $lng1,float $lat2,float $lng2): float` (metros)
  - `dentroGeocerca(int $ubicacionId, ?float $lat, ?float $lng): bool`
  - `cuentaAbrir(int $mesaId, int $ubicacionId, ?int $empleadoId, int $numComensales): int` (id de cuenta; reusa la abierta si existe)
  - `cuentaTotalRecalc(int $cuentaId): float`
  - `cuentaDetalle(int $cuentaId): ?array` (`{id,mesa_id,mesa_numero,num_comensales,estado,total,comandas:[{pedido_id,ronda,estado,creada_at,items:[...] }]}`)
  - `comandaEnviar(int $cuentaId, array $items, ?int $empleadoId): array` (`{ok, pedido_id, ronda}`)
  - `cuentaAnular(int $cuentaId, int $pedidoId, ?int $itemIdx, string $motivo, ?int $empleadoId): array` (`{ok, error?}`)
  - `mesaEstados(int $ubicacionId): array` (`{estados:{mesaId:'ocupada'}, montos:{mesaId:float}}`)

- [ ] **Step 1: Escribir la librería**

Create `includes/cuentas.php`:

```php
<?php
/**
 * Cuentas de mesa (Sub-build B) — lógica compartida: abrir cuenta, enviar comanda,
 * anular, total, estados de mesa y geocerca. Consumido por api/mozo.php.
 */

/** ¿Existen las tablas de cuentas? */
function cuentasListo(): bool {
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cuentas'");
    } catch (\Throwable $e) { return false; }
}

/** Distancia en metros entre dos coordenadas (haversine). */
function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** ¿El mozo está dentro de la geocerca del local? Kill-switch global + sin-coords = permite. */
function dentroGeocerca(int $ubicacionId, ?float $lat, ?float $lng): bool {
    if (getSetting('mozo_geocerca_activa', '1') !== '1') return true;
    $u = Database::fetch("SELECT lat, lng, geocerca_radio FROM ubicaciones WHERE id = ?", [$ubicacionId]);
    if (!$u || $u['lat'] === null || $u['lng'] === null) return true; // local sin coordenadas configuradas
    if ($lat === null || $lng === null) return false;                 // la app no entregó GPS
    $radio = (int)($u['geocerca_radio'] ?? 100) ?: 100;
    return haversineM((float)$u['lat'], (float)$u['lng'], $lat, $lng) <= $radio;
}

/** La cuenta abierta de una mesa, o null. */
function cuentaAbiertaDeMesa(int $mesaId): ?array {
    return Database::fetch("SELECT * FROM cuentas WHERE mesa_id = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1", [$mesaId]);
}

/** Abre (o reusa) la cuenta abierta de una mesa. Devuelve el id. */
function cuentaAbrir(int $mesaId, int $ubicacionId, ?int $empleadoId, int $numComensales): int {
    $ex = cuentaAbiertaDeMesa($mesaId);
    if ($ex) {
        if ($numComensales > 0 && (int)$ex['num_comensales'] === 0) {
            Database::execute("UPDATE cuentas SET num_comensales = ? WHERE id = ?", [$numComensales, (int)$ex['id']]);
        }
        return (int)$ex['id'];
    }
    return (int) Database::insert(
        "INSERT INTO cuentas (mesa_id, ubicacion_id, empleado_id, num_comensales) VALUES (?,?,?,?)",
        [$mesaId, $ubicacionId, $empleadoId, max(0, $numComensales)]);
}

/** Suma de ítems no-anulados de las comandas no-canceladas; cachea en cuentas.total. */
function cuentaTotalRecalc(int $cuentaId): float {
    $total = 0.0;
    foreach (Database::fetchAll("SELECT items_json FROM pedidos WHERE cuenta_id = ? AND estado <> 'cancelado'", [$cuentaId]) as $row) {
        $items = json_decode($row['items_json'] ?? '[]', true) ?: [];
        foreach ($items as $it) {
            if (!empty($it['anulado'])) continue;
            $qty  = max(1, (int)($it['qty'] ?? 1));
            $base = (float)($it['precio'] ?? 0);
            $mods = 0.0;
            foreach ((array)($it['modificadores'] ?? []) as $m) $mods += (float)($m['precio'] ?? 0);
            $total += ($base + $mods) * $qty;
        }
    }
    $total = round($total, 2);
    Database::execute("UPDATE cuentas SET total = ? WHERE id = ?", [$total, $cuentaId]);
    return $total;
}

/** Detalle de la cuenta con sus comandas (rondas) e ítems. */
function cuentaDetalle(int $cuentaId): ?array {
    $c = Database::fetch(
        "SELECT cu.*, m.numero AS mesa_numero FROM cuentas cu LEFT JOIN mesas m ON m.id = cu.mesa_id WHERE cu.id = ?", [$cuentaId]);
    if (!$c) return null;
    $comandas = [];
    $ronda = 0;
    foreach (Database::fetchAll("SELECT id, estado, items_json, created_at FROM pedidos WHERE cuenta_id = ? ORDER BY id", [$cuentaId]) as $p) {
        $ronda++;
        if ($p['estado'] === 'cancelado') continue;
        $comandas[] = [
            'pedido_id' => (int)$p['id'],
            'ronda'     => $ronda,
            'estado'    => $p['estado'],
            'creada_at' => $p['created_at'],
            'items'     => json_decode($p['items_json'] ?? '[]', true) ?: [],
        ];
    }
    return [
        'id' => (int)$c['id'], 'mesa_id' => (int)$c['mesa_id'], 'mesa_numero' => $c['mesa_numero'],
        'num_comensales' => (int)$c['num_comensales'], 'estado' => $c['estado'],
        'total' => (float)$c['total'], 'comandas' => $comandas,
    ];
}

/** Crea una comanda (pedido origen='mesa') desde un borrador de ítems. */
function comandaEnviar(int $cuentaId, array $items, ?int $empleadoId): array {
    $c = Database::fetch("SELECT * FROM cuentas WHERE id = ? AND estado = 'abierta'", [$cuentaId]);
    if (!$c) return ['ok' => false, 'error' => 'cuenta no abierta'];
    // Normalizar ítems (mismo formato que el POS)
    $clean = [];
    foreach ($items as $it) {
        $qty  = max(1, (int)($it['qty'] ?? 1));
        $base = (float)($it['precio'] ?? 0);
        $mods = [];
        foreach ((array)($it['modificadores'] ?? []) as $m) {
            $mods[] = ['nombre' => clean($m['nombre'] ?? ''), 'precio' => (float)($m['precio'] ?? 0)];
        }
        $nota = clean($it['nota'] ?? '');
        $clean[] = ['product_id' => (int)($it['product_id'] ?? $it['id'] ?? 0), 'qty' => $qty,
                    'nombre' => clean($it['nombre'] ?? ''), 'precio' => $base, 'modificadores' => $mods, 'nota' => $nota];
    }
    if (!$clean) return ['ok' => false, 'error' => 'borrador vacío'];
    // Total de la comanda
    $tot = 0.0;
    foreach ($clean as $it) {
        $msum = 0.0; foreach ($it['modificadores'] as $m) $msum += (float)$m['precio'];
        $tot += ($it['precio'] + $msum) * $it['qty'];
    }
    $pedidoId = (int) Database::insert(
        "INSERT INTO pedidos (ubicacion_id, nombre, tipo_entrega, items_json, total, estado, metodo_pago, origen, cuenta_id, mesa_id, aceptado_at)
         VALUES (?, ?, 'recojo', ?, ?, 'en_preparacion', 'whatsapp', 'mesa', ?, ?, NOW())",
        [(int)$c['ubicacion_id'], 'Mesa ' . (Database::fetch('SELECT numero FROM mesas WHERE id=?', [(int)$c['mesa_id']])['numero'] ?? ''),
         json_encode($clean, JSON_UNESCAPED_UNICODE), round($tot, 2), $cuentaId, (int)$c['mesa_id']]);
    $ronda = (int)(Database::fetch("SELECT COUNT(*) n FROM pedidos WHERE cuenta_id = ?", [$cuentaId])['n'] ?? 1);
    cuentaTotalRecalc($cuentaId);
    return ['ok' => true, 'pedido_id' => $pedidoId, 'ronda' => $ronda];
}

/** Anula un ítem (itemIdx) o una comanda completa (itemIdx=null). Solo antes de 'listo'. */
function cuentaAnular(int $cuentaId, int $pedidoId, ?int $itemIdx, string $motivo, ?int $empleadoId): array {
    $p = Database::fetch("SELECT * FROM pedidos WHERE id = ? AND cuenta_id = ?", [$pedidoId, $cuentaId]);
    if (!$p) return ['ok' => false, 'error' => 'comanda no encontrada'];
    if (!in_array($p['estado'], ['pendiente', 'en_preparacion'], true)) {
        return ['ok' => false, 'error' => 'no se puede anular: la cocina ya la marcó lista'];
    }
    $motivo = substr(trim($motivo), 0, 160) ?: 'Sin motivo';
    if ($itemIdx === null) {
        Database::execute("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?", [$pedidoId]);
    } else {
        $items = json_decode($p['items_json'] ?? '[]', true) ?: [];
        if (!isset($items[$itemIdx])) return ['ok' => false, 'error' => 'ítem inexistente'];
        $items[$itemIdx]['anulado'] = true;
        $items[$itemIdx]['anul_motivo'] = $motivo;
        Database::execute("UPDATE pedidos SET items_json = ? WHERE id = ?", [json_encode($items, JSON_UNESCAPED_UNICODE), $pedidoId]);
    }
    Database::execute(
        "INSERT INTO cuenta_anulaciones (cuenta_id, pedido_id, item_idx, motivo, empleado_id) VALUES (?,?,?,?,?)",
        [$cuentaId, $pedidoId, $itemIdx, $motivo, $empleadoId]);
    cuentaTotalRecalc($cuentaId);
    return ['ok' => true];
}

/** Estados de mesa de un local: las que tienen cuenta abierta → 'ocupada' + monto. */
function mesaEstados(int $ubicacionId): array {
    $estados = []; $montos = [];
    foreach (Database::fetchAll("SELECT mesa_id, total FROM cuentas WHERE ubicacion_id = ? AND estado = 'abierta'", [$ubicacionId]) as $r) {
        $estados[(int)$r['mesa_id']] = 'ocupada';
        $montos[(int)$r['mesa_id']]  = (float)$r['total'];
    }
    return ['estados' => $estados, 'montos' => $montos];
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l includes/cuentas.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Self-review**

Confirma: todas las queries con `?`; `dentroGeocerca` respeta el kill-switch y "sin coords"; `cuentaAnular` rechaza si estado no es pendiente/en_preparacion; `comandaEnviar` crea `origen='mesa'`, `en_preparacion`, `aceptado_at=NOW()`, `turno_id` queda NULL (no se setea); el total excluye ítems `anulado` y comandas `cancelado`.

- [ ] **Step 4: Commit**

```bash
git add includes/cuentas.php
git commit -m "feat(cuentas): librería compartida (abrir/comanda/anular/total/estados/geocerca)"
```

---

### Task 3: API del mozo (`api/mozo.php`)

**Files:**
- Create: `api/mozo.php`

**Interfaces:**
- Consumes: `includes/cuentas.php` (Task 2), `empleados.pin_hash`, `ubicaciones`, `products`/`location_products`/modificadores.
- Produces (JSON). Sesión de mozo en `$_SESSION` (`mozo_emp`, `mozo_ubi`, `mozo_nombre`). Acciones:
  - GET `?action=mozos&ubicacion_id=N` → `{ok, mozos:[{id,nombre}]}` (empleados activos del local, para elegir antes del PIN).
  - POST `?action=login_pin` (`ubicacion_id`,`empleado_id`,`pin`) → valida PIN → set sesión → `{ok, nombre}`.
  - GET `?action=sesion` → `{ok, mozo:{emp,nombre,ubi}|null}`.
  - POST `?action=logout` → limpia sesión.
  - GET `?action=plano` → pisos+mesas+elementos (como `api/mesas.php?action=plano`, del local de la sesión).
  - GET `?action=plano_estados` → `mesaEstados(ubi)`.
  - GET `?action=menu` → `{ok, categorias:[...], productos:[{id,nombre,precio,categoria,grupos:[...]}]}`.
  - POST `?action=abrir_cuenta` (`mesa_id`,`num_comensales`,`lat`,`lng`) → `{ok, cuenta_id}` (geocerca).
  - GET `?action=cuenta&cuenta_id=N` → `cuentaDetalle`.
  - POST `?action=enviar_comanda` (`cuenta_id`,`items` JSON,`lat`,`lng`) → `comandaEnviar` (geocerca).
  - POST `?action=anular` (`cuenta_id`,`pedido_id`,`item_idx`?,`motivo`,`lat`,`lng`) → `cuentaAnular` (geocerca).
  - POST `?action=cerrar_cuenta_vacia` (`cuenta_id`,`lat`,`lng`) → cancela una cuenta sin comandas.

- [ ] **Step 1: Escribir el endpoint**

Create `api/mozo.php`:

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cuentas.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

function mout($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function mozoEmp(): int { return (int)($_SESSION['mozo_emp'] ?? 0); }
function mozoUbi(): int { return (int)($_SESSION['mozo_ubi'] ?? 0); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$writes = ['login_pin', 'logout', 'abrir_cuenta', 'enviar_comanda', 'anular', 'cerrar_cuenta_vacia'];
if (in_array($action, $writes, true) && $action !== 'login_pin') verifyCsrf();

// --- acciones públicas (sin sesión de mozo) ---
if ($action === 'mozos') {
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    mout(['ok' => true, 'mozos' => Database::fetchAll(
        "SELECT id, nombre FROM empleados WHERE ubicacion_id = ? AND activo = 1 ORDER BY nombre", [$ubi])]);
}

if ($action === 'login_pin') {
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $eid = cleanInt($_POST['empleado_id'] ?? 0);
    $pin = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    $emp = Database::fetch("SELECT * FROM empleados WHERE id = ? AND ubicacion_id = ? AND activo = 1", [$eid, $ubi]);
    if (!$emp || empty($emp['pin_hash'])) mout(['ok' => false, 'error' => 'Mozo no válido']);
    if (!empty($emp['pin_bloqueado_hasta']) && strtotime($emp['pin_bloqueado_hasta']) > time()) {
        mout(['ok' => false, 'error' => 'Bloqueado por intentos. Espera unos minutos.']);
    }
    if (!password_verify($pin, $emp['pin_hash'])) {
        $intentos = (int)($emp['pin_intentos'] ?? 0) + 1;
        try {
            if ($intentos >= 5) Database::execute("UPDATE empleados SET pin_intentos=0, pin_bloqueado_hasta=(NOW()+INTERVAL 5 MINUTE) WHERE id=?", [$eid]);
            else Database::execute("UPDATE empleados SET pin_intentos=? WHERE id=?", [$intentos, $eid]);
        } catch (\Throwable $e) {}
        mout(['ok' => false, 'error' => 'PIN incorrecto']);
    }
    try { Database::execute("UPDATE empleados SET pin_intentos=0, pin_bloqueado_hasta=NULL WHERE id=?", [$eid]); } catch (\Throwable $e) {}
    $_SESSION['mozo_emp'] = (int)$emp['id'];
    $_SESSION['mozo_ubi'] = $ubi;
    $_SESSION['mozo_nombre'] = $emp['nombre'];
    mout(['ok' => true, 'nombre' => $emp['nombre']]);
}

if ($action === 'sesion') {
    mout(['ok' => true, 'mozo' => mozoEmp() ? ['emp' => mozoEmp(), 'nombre' => $_SESSION['mozo_nombre'] ?? '', 'ubi' => mozoUbi()] : null]);
}
if ($action === 'logout') { unset($_SESSION['mozo_emp'], $_SESSION['mozo_ubi'], $_SESSION['mozo_nombre']); mout(['ok' => true]); }

// --- de aquí en adelante requiere sesión de mozo ---
if (!mozoEmp()) { http_response_code(401); mout(['ok' => false, 'error' => 'sesión requerida']); }
$ubi = mozoUbi();

// Geocerca: las escrituras mandan lat/lng
function geoGate(int $ubi): void {
    $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null;
    if (!dentroGeocerca($ubi, $lat, $lng)) {
        mout(['ok' => false, 'error' => 'Debes estar en el local · activa la ubicación', 'geo' => true]);
    }
}

switch ($action) {

    case 'plano': {
        $pisos = [];
        foreach (Database::fetchAll("SELECT id FROM mesa_pisos WHERE ubicacion_id = ? AND activo = 1 ORDER BY orden, id", [$ubi]) as $row) {
            $pid = (int)$row['id'];
            $p = Database::fetch("SELECT id, nombre, orden, fondo_img, ancho, alto FROM mesa_pisos WHERE id = ?", [$pid]);
            $p['mesas'] = Database::fetchAll("SELECT id, numero, capacidad, forma, pos_x, pos_y, ancho, alto FROM mesas WHERE piso_id = ? AND activa = 1 ORDER BY id", [$pid]);
            $p['elementos'] = Database::fetchAll("SELECT id, tipo, texto, pos_x, pos_y, ancho, alto FROM mesa_elementos WHERE piso_id = ? ORDER BY id", [$pid]);
            $pisos[] = $p;
        }
        mout(['ok' => true, 'pisos' => $pisos]);
    }

    case 'plano_estados':
        mout(array_merge(['ok' => true], mesaEstados($ubi)));

    case 'menu': {
        $prods = Database::fetchAll(
            "SELECT p.id, p.name AS nombre, COALESCE(c.name,'Sin categoría') AS categoria, c.sort_order AS cat_orden, lp.price AS precio
             FROM location_products lp JOIN products p ON p.id = lp.product_id AND p.active = 1
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE lp.location_id = ? AND lp.available = 1
             ORDER BY c.sort_order, c.name, lp.sort_order, p.name", [$ubi]);
        // grupos de modificadores por producto
        foreach ($prods as &$pr) {
            $pr['grupos'] = Database::fetchAll(
                "SELECT g.id, g.nombre, g.min_select, g.max_select FROM grupos_modificadores g
                 JOIN product_modifier_groups pmg ON pmg.grupo_id = g.id
                 WHERE pmg.product_id = ? ORDER BY g.id", [(int)$pr['id']]);
            foreach ($pr['grupos'] as &$g) {
                $g['opciones'] = Database::fetchAll("SELECT id, nombre, precio FROM modificadores WHERE grupo_id = ? AND activo = 1 ORDER BY id", [(int)$g['id']]);
            }
            unset($g);
        }
        unset($pr);
        $cats = [];
        foreach ($prods as $p) { $cats[$p['categoria']] = true; }
        mout(['ok' => true, 'categorias' => array_keys($cats), 'productos' => $prods]);
    }

    case 'abrir_cuenta':
        geoGate($ubi);
        $mesaId = cleanInt($_POST['mesa_id'] ?? 0);
        $m = Database::fetch("SELECT id FROM mesas WHERE id = ? AND ubicacion_id = ? AND activa = 1", [$mesaId, $ubi]);
        if (!$m) mout(['ok' => false, 'error' => 'mesa inválida']);
        mout(['ok' => true, 'cuenta_id' => cuentaAbrir($mesaId, $ubi, mozoEmp(), cleanInt($_POST['num_comensales'] ?? 0))]);

    case 'cuenta': {
        $cid = cleanInt($_GET['cuenta_id'] ?? 0);
        $d = cuentaDetalle($cid);
        if (!$d) mout(['ok' => false, 'error' => 'cuenta no encontrada']);
        mout(['ok' => true, 'cuenta' => $d]);
    }

    case 'enviar_comanda':
        geoGate($ubi);
        $cid = cleanInt($_POST['cuenta_id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true) ?: [];
        mout(comandaEnviar($cid, $items, mozoEmp()));

    case 'anular':
        geoGate($ubi);
        $cid = cleanInt($_POST['cuenta_id'] ?? 0);
        $pid = cleanInt($_POST['pedido_id'] ?? 0);
        $idx = ($_POST['item_idx'] ?? '') === '' ? null : cleanInt($_POST['item_idx']);
        mout(cuentaAnular($cid, $pid, $idx, clean($_POST['motivo'] ?? ''), mozoEmp()));

    case 'cerrar_cuenta_vacia':
        geoGate($ubi);
        $cid = cleanInt($_POST['cuenta_id'] ?? 0);
        $n = (int)(Database::fetch("SELECT COUNT(*) n FROM pedidos WHERE cuenta_id = ? AND estado <> 'cancelado'", [$cid])['n'] ?? 0);
        if ($n > 0) mout(['ok' => false, 'error' => 'la cuenta tiene comandas']);
        Database::execute("UPDATE cuentas SET estado = 'cancelada', cerrada_at = NOW() WHERE id = ? AND ubicacion_id = ? AND estado = 'abierta'", [$cid, $ubi]);
        mout(['ok' => true]);

    default:
        http_response_code(400);
        mout(['ok' => false, 'error' => 'acción inválida']);
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l api/mozo.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Self-review**

Confirma: `login_pin` valida PIN con `password_verify` + anti-bruteforce (tolerante si faltan columnas); tras login crea sesión de mozo; las acciones de datos exigen `mozoEmp()` (401 si no); `geoGate` corre en abrir_cuenta/enviar_comanda/anular/cerrar_cuenta_vacia; `verifyCsrf()` en escrituras (menos login_pin); nunca se devuelve `pin_hash`; todas las queries con `?`.

- [ ] **Step 4: Commit**

```bash
git add api/mozo.php
git commit -m "feat(mozo): api/mozo.php — login PIN, plano, menú, comandas, anular (con geocerca)"
```

---

### Task 4: App del mozo (`mozo/index.php` + manifest)

**Files:**
- Create: `mozo/index.php`
- Create: `mozo/manifest.php`

**Interfaces:**
- Consumes: `api/mozo.php` (Task 3), `assets/js/plano-render.js` (Sub-build A), `csrfToken()`, `UPLOAD_URL`.
- Produces: la PWA del mozo (pantallas PIN → plano → cuenta → catálogo). Una sola página con JS vanilla que cambia de vista.

- [ ] **Step 1: Crear el manifest PWA**

Create `mozo/manifest.php`:

```php
<?php
require_once __DIR__ . '/../config/config.php';
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
    'name' => 'El Gringo · Mozo',
    'short_name' => 'Mozo',
    'start_url' => APP_URL . '/mozo/index.php',
    'display' => 'standalone',
    'background_color' => '#1E1E1E',
    'theme_color' => '#1E1E1E',
    'icons' => [],
], JSON_UNESCAPED_SLASHES);
```

- [ ] **Step 2: Crear la app**

Create `mozo/index.php`:

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$ready = (bool) Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cuentas'");
// Selección de local: por querystring o el principal
$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, sort_order, nombre");
$ubiSel = cleanInt($_GET['ubicacion_id'] ?? 0) ?: (int)($_SESSION['mozo_ubi'] ?? ($ubis[0]['id'] ?? 0));
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="theme-color" content="#1E1E1E">
<link rel="manifest" href="<?= APP_URL ?>/mozo/manifest.php">
<title>El Gringo · Mozo</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f5f2ec;color:#1E1E1E;height:100dvh;overflow:hidden}
.view{display:none;flex-direction:column;height:100dvh}
.view.on{display:flex}
.top{background:#1E1E1E;color:#fff;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;font-weight:800}
.top .y{color:#FFDF00}
.top button{background:rgba(255,255,255,.14);border:none;color:#fff;border-radius:8px;padding:7px 10px;font-weight:800;font-size:13px}
.body{flex:1;overflow:auto;-webkit-overflow-scrolling:touch}
.foot{background:#fff;border-top:1px solid #e7e3da;padding:11px 13px}
.btn{display:block;width:100%;text-align:center;background:#FFDF00;color:#1E1E1E;font-weight:900;border:none;border-radius:12px;padding:14px;font-size:15px}
.btn.dark{background:#1E1E1E;color:#FFDF00}
.btn.red{background:#dc2626;color:#fff}
.key{background:#fff;border:none;border-radius:12px;padding:16px 0;font-size:22px;font-weight:800}
.pindots{display:flex;gap:11px;justify-content:center;margin:14px 0}
.pindots span{width:14px;height:14px;border-radius:50%;border:2px solid #ccc}
.pindots span.on{background:#1E1E1E;border-color:#1E1E1E}
.row{display:flex;justify-content:space-between;align-items:center;padding:11px 13px;border-bottom:1px solid #e7e3da;background:#fff}
.tag{font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px;background:#efece4;color:#777}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:flex-end;z-index:50}
.modal.on{display:flex}
.sheet{background:#fff;border-radius:18px 18px 0 0;width:100%;max-height:92dvh;overflow:auto;padding-bottom:env(safe-area-inset-bottom)}
.opt{display:flex;align-items:center;gap:10px;padding:10px 14px;font-size:14px}
.mark{width:20px;height:20px;border-radius:6px;border:2px solid #ccc;flex-shrink:0}
.mark.on{background:#1E1E1E;border-color:#1E1E1E}
.mark.rad{border-radius:50%}.mark.rad.on{background:#FFDF00;border-color:#FFDF00;box-shadow:inset 0 0 0 4px #fff}
.chip{display:inline-block;font-size:12px;font-weight:800;padding:7px 12px;border-radius:9px;background:#eee;color:#555;white-space:nowrap}
.chip.on{background:#1E1E1E;color:#FFDF00}
.anul{text-decoration:line-through;color:#bbb}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#1E1E1E;color:#fff;padding:10px 16px;border-radius:10px;font-weight:700;font-size:13px;z-index:80;display:none}
</style>
</head>
<body>
<?php if (!$ready): ?>
<div class="view on"><div class="body" style="display:flex;align-items:center;justify-content:center;padding:24px;text-align:center">
  <p>La app del mozo necesita su migración. Aplica <code>install/57_cuentas.sql</code> en phpMyAdmin.</p>
</div></div>
<?php else: ?>

<!-- PIN -->
<div class="view on" id="v-pin">
  <div class="top"><span>EL GRINGO · <span class="y">Mozo</span></span><span id="pin-ubi"></span></div>
  <div class="body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:18px">
    <div id="pin-step" style="font-weight:800">Elige tu nombre</div>
    <div id="pin-mozos" style="display:flex;flex-direction:column;gap:8px;width:100%;max-width:320px"></div>
    <div id="pin-pad" style="display:none;width:100%;max-width:320px">
      <div class="pindots"><span></span><span></span><span></span><span></span></div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:9px">
        <button class="key" data-k="1">1</button><button class="key" data-k="2">2</button><button class="key" data-k="3">3</button>
        <button class="key" data-k="4">4</button><button class="key" data-k="5">5</button><button class="key" data-k="6">6</button>
        <button class="key" data-k="7">7</button><button class="key" data-k="8">8</button><button class="key" data-k="9">9</button>
        <button class="key" data-k="x" style="background:none">⌫</button><button class="key" data-k="0">0</button><button class="key" data-k="c" style="background:none;color:#888">‹</button>
      </div>
      <div id="pin-err" style="color:#dc2626;font-weight:700;text-align:center;margin-top:10px;min-height:18px"></div>
    </div>
  </div>
</div>

<!-- PLANO -->
<div class="view" id="v-plano">
  <div class="top"><span>Mesas · <span class="y" id="plano-piso">Piso 1</span></span><span id="plano-mozo"></span></div>
  <div id="plano-tabs" style="display:flex;gap:6px;padding:8px 10px;overflow:auto;background:#efece4"></div>
  <div class="body"><div id="plano-board" style="padding:8px"></div></div>
</div>

<!-- CUENTA -->
<div class="view" id="v-cuenta">
  <div class="top"><button onclick="goPlano()">‹</button><span>Mesa <span class="y" id="cta-mesa"></span> · <span id="cta-com"></span> pers</span><span id="cta-total">S/ 0</span></div>
  <div class="body" id="cta-body"></div>
  <div class="foot">
    <button class="btn" onclick="openCatalogo()">+ Agregar a la cuenta</button>
    <div style="text-align:center;font-size:10px;color:#999;margin-top:8px;font-weight:700">Precuenta y cobro → Sub-build C</div>
  </div>
</div>

<!-- CATÁLOGO (modal de pantalla completa) -->
<div class="view" id="v-cat">
  <div class="top"><button onclick="showView('v-cuenta')">‹</button><span>Agregar a Mesa <span class="y" id="cat-mesa"></span></span><span></span></div>
  <div id="cat-tabs" style="display:flex;gap:6px;padding:8px 10px;overflow:auto;background:#efece4"></div>
  <div class="body" id="cat-list"></div>
  <div class="foot" id="cat-foot" style="background:#FFEFBC;border-top-color:#e7d99a;display:none">
    <div style="font-size:11px;font-weight:800;color:#8a6d00;margin-bottom:7px" id="cat-borr"></div>
    <button class="btn dark" onclick="enviarComanda()">🍳 Enviar a cocina</button>
  </div>
</div>

<!-- modal producto -->
<div class="modal" id="m-prod"><div class="sheet" id="m-prod-in"></div></div>
<!-- modal comensales -->
<div class="modal" id="m-com"><div class="sheet" style="padding:18px">
  <div style="font-weight:900;font-size:16px;margin-bottom:4px">Abrir Mesa <span id="com-mesa"></span></div>
  <div style="font-size:12px;color:#888;margin-bottom:12px">¿Cuántos comensales? (opcional)</div>
  <div style="display:flex;align-items:center;gap:14px;justify-content:center;margin-bottom:14px">
    <button class="key" style="width:46px" onclick="comStep(-1)">−</button><b id="com-n" style="font-size:22px">2</b><button class="key" style="width:46px" onclick="comStep(1)">+</button>
  </div>
  <button class="btn" onclick="confirmAbrir()">Abrir cuenta</button>
</div></div>
<!-- modal anular -->
<div class="modal" id="m-anul"><div class="sheet" style="padding:18px">
  <div style="font-weight:900;font-size:15px;margin-bottom:10px" id="anul-tit">Anular</div>
  <div id="anul-motivos" style="display:flex;flex-direction:column;gap:7px;margin-bottom:12px"></div>
  <button class="btn" style="background:#eee;color:#555" onclick="closeModal('m-anul')">Cancelar</button>
</div></div>

<div class="toast" id="toast"></div>

<script src="<?= APP_URL ?>/assets/js/plano-render.js?v=<?= @filemtime(__DIR__ . '/../assets/js/plano-render.js') ?: time() ?>"></script>
<script>
var API = '<?= APP_URL ?>/api/mozo.php';
var CSRF = <?= json_encode($csrf) ?>;
var UPLOAD = '<?= UPLOAD_URL ?>';
var UBI = <?= (int)$ubiSel ?>;
var st = { pin:'', emp:0, pisos:[], pi:0, cuenta:null, borrador:[], catProd:[], catCat:null, prodSel:null, comN:2, comMesa:0, anul:null, lastGeo:null };

function $(id){ return document.getElementById(id); }
function showView(v){ document.querySelectorAll('.view').forEach(function(x){x.classList.remove('on');}); $(v).classList.add('on'); }
function openModal(id){ $(id).classList.add('on'); } function closeModal(id){ $(id).classList.remove('on'); }
function toast(t){ var n=$('toast'); n.textContent=t; n.style.display='block'; setTimeout(function(){n.style.display='none';},2200); }
function get(a){ return fetch(API+'?action='+a).then(function(r){return r.json();}); }
function post(a, body){ var fd=new FormData(); fd.append('action',a); Object.keys(body||{}).forEach(function(k){fd.append(k,body[k]);}); return fetch(API+'?action='+a,{method:'POST',headers:{'X-CSRF-Token':CSRF},body:fd}).then(function(r){return r.json();}); }

// geo: cachear última posición; refrescar antes de escribir
function geo(){ return new Promise(function(res){ if(!navigator.geolocation){res(null);return;} navigator.geolocation.getCurrentPosition(function(p){ st.lastGeo={lat:p.coords.latitude,lng:p.coords.longitude}; res(st.lastGeo); }, function(){ res(st.lastGeo); }, {enableHighAccuracy:true,timeout:6000,maximumAge:30000}); }); }
function withGeo(body){ body=body||{}; if(st.lastGeo){ body.lat=st.lastGeo.lat; body.lng=st.lastGeo.lng; } return body; }

// ---- PIN ----
function loadMozos(){
  get('mozos&ubicacion_id='+UBI).then(function(d){
    var box=$('pin-mozos'); box.innerHTML='';
    (d.mozos||[]).forEach(function(m){
      var b=document.createElement('button'); b.className='btn'; b.style.background='#fff'; b.textContent=m.nombre;
      b.onclick=function(){ st.emp=m.id; $('pin-step').textContent='Hola '+m.nombre+', tu PIN'; $('pin-mozos').style.display='none'; $('pin-pad').style.display='block'; renderDots(); };
      box.appendChild(b);
    });
  });
}
function renderDots(){ var dots=document.querySelectorAll('#pin-pad .pindots span'); dots.forEach(function(s,i){ s.classList.toggle('on', i<st.pin.length); }); }
document.addEventListener('click', function(e){ var k=e.target.getAttribute && e.target.getAttribute('data-k'); if(k===null||k===undefined)return;
  if(k==='x'){ st.pin=st.pin.slice(0,-1); } else if(k==='c'){ st.pin=''; st.emp=0; $('pin-mozos').style.display='flex'; $('pin-pad').style.display='none'; $('pin-step').textContent='Elige tu nombre'; return; }
  else if(st.pin.length<4){ st.pin+=k; }
  renderDots();
  if(st.pin.length===4){ doLogin(); }
});
function doLogin(){
  post('login_pin', {ubicacion_id:UBI, empleado_id:st.emp, pin:st.pin}).then(function(d){
    if(d.ok){ $('plano-mozo').textContent=d.nombre+' 👤'; geo(); enterApp(); }
    else { $('pin-err').textContent=d.error||'PIN incorrecto'; st.pin=''; renderDots(); }
  });
}

// ---- App ----
function enterApp(){ loadPlano(); showView('v-plano'); pollEstados(); }
function loadPlano(){ get('plano').then(function(d){ st.pisos=d.pisos||[]; st.pi=0; drawPlano(); }); }
function drawPlano(){
  var tabs=$('plano-tabs'); tabs.innerHTML='';
  st.pisos.forEach(function(p,i){ var t=document.createElement('span'); t.className='chip'+(i===st.pi?' on':''); t.textContent=p.nombre; t.onclick=function(){ st.pi=i; drawPlano(); }; tabs.appendChild(t); });
  var piso=st.pisos[st.pi]; if(!piso){ $('plano-board').innerHTML='<p style="padding:24px;text-align:center;color:#888">Este local no tiene plano. Pídele al admin que lo arme.</p>'; return; }
  $('plano-piso').textContent=piso.nombre;
  refreshEstados();
}
var EST={estados:{},montos:{}};
function refreshEstados(){
  var piso=st.pisos[st.pi]; if(!piso)return;
  PlanoRender.draw($('plano-board'), piso, {uploadUrl:UPLOAD, estados:EST.estados, montos:EST.montos, onMesaTap:onMesaTap});
}
function pollEstados(){ get('plano_estados').then(function(d){ if(d.ok){ EST={estados:d.estados||{},montos:d.montos||{}}; if($('v-plano').classList.contains('on')) refreshEstados(); } }); setTimeout(pollEstados, 5000); }

function onMesaTap(mesaId){
  // ¿ocupada? abre su cuenta : pide comensales
  if(EST.estados[mesaId]==='ocupada'){ abrirYver(mesaId, 0); }
  else { st.comMesa=mesaId; st.comN=2; $('com-mesa').textContent=mesaNum(mesaId); $('com-n').textContent='2'; openModal('m-com'); }
}
function mesaNum(id){ var n='?'; st.pisos.forEach(function(p){ (p.mesas||[]).forEach(function(m){ if(m.id==id) n=m.numero; }); }); return n; }
function comStep(d){ st.comN=Math.max(0, st.comN+d); $('com-n').textContent=st.comN; }
function confirmAbrir(){ closeModal('m-com'); abrirYver(st.comMesa, st.comN); }
function abrirYver(mesaId, n){
  geo().then(function(){ post('abrir_cuenta', withGeo({mesa_id:mesaId, num_comensales:n})).then(function(d){
    if(!d.ok){ toast(d.error||'No se pudo'); return; }
    loadCuenta(d.cuenta_id);
  }); });
}

// ---- Cuenta ----
function loadCuenta(cid){ get('cuenta&cuenta_id='+cid).then(function(d){ if(!d.ok){toast('Error');return;} st.cuenta=d.cuenta; renderCuenta(); showView('v-cuenta'); }); }
function renderCuenta(){
  var c=st.cuenta; $('cta-mesa').textContent=c.mesa_numero||''; $('cta-com').textContent=c.num_comensales; $('cta-total').textContent='S/ '+c.total.toFixed(0); $('cat-mesa').textContent=c.mesa_numero||'';
  var b=$('cta-body'); b.innerHTML='';
  if(!c.comandas.length){ b.innerHTML='<p style="padding:24px;text-align:center;color:#888">Cuenta vacía. Agrega el primer pedido.</p>'; return; }
  c.comandas.forEach(function(co){
    var h=document.createElement('div');
    h.innerHTML='<div style="padding:7px 13px;font-size:9px;font-weight:800;color:#999;text-transform:uppercase;background:#efece4">Ronda '+co.ronda+' · '+co.estado+'</div>';
    b.appendChild(h);
    co.items.forEach(function(it, idx){
      var anul=!!it.anulado;
      var mods=(it.modificadores||[]).map(function(m){return m.nombre;}).join(' · ');
      var unit=(it.precio||0)+((it.modificadores||[]).reduce(function(s,m){return s+(m.precio||0);},0));
      var r=document.createElement('div'); r.className='row';
      r.innerHTML='<div class="'+(anul?'anul':'')+'">'+it.qty+'× '+esc(it.nombre)+(mods?'<br><small style="color:#999">'+esc(mods)+'</small>':'')+(it.nota?'<br><small style="color:#c98a00">Nota: '+esc(it.nota)+'</small>':'')+'</div>'+
        '<div style="text-align:right"><b class="'+(anul?'anul':'')+'">S/ '+(unit*it.qty).toFixed(0)+'</b>'+(anul?'':(co.estado==='pendiente'||co.estado==='en_preparacion'?'<br><span style="color:#dc2626;font-size:11px;font-weight:700">anular</span>':''))+'</div>';
      if(!anul && (co.estado==='pendiente'||co.estado==='en_preparacion')){ r.querySelector('span').onclick=function(){ openAnular(co.pedido_id, idx, it.qty+'× '+it.nombre); }; }
      b.appendChild(r);
    });
  });
}
function esc(s){ return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

// ---- Anular ----
function openAnular(pedidoId, idx, label){ st.anul={pedido_id:pedidoId, item_idx:idx}; $('anul-tit').textContent='Anular «'+label+'»';
  var box=$('anul-motivos'); box.innerHTML=''; ['El cliente lo rechazó','Error del mozo','Otro'].forEach(function(mo){ var b=document.createElement('button'); b.className='btn'; b.style.background='#FFBBC8'; b.style.color='#1E1E1E'; b.textContent=mo; b.onclick=function(){ doAnular(mo); }; box.appendChild(b); }); openModal('m-anul'); }
function doAnular(motivo){ closeModal('m-anul'); geo().then(function(){ post('anular', withGeo({cuenta_id:st.cuenta.id, pedido_id:st.anul.pedido_id, item_idx:st.anul.item_idx, motivo:motivo})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} loadCuenta(st.cuenta.id); }); }); }

// ---- Catálogo + borrador ----
function openCatalogo(){ st.borrador=[]; updBorr(); if(!st.catProd.length){ get('menu').then(function(d){ st.catProd=d.productos||[]; st.catCat=(d.categorias||[])[0]||null; drawCat(); showView('v-cat'); }); } else { drawCat(); showView('v-cat'); } }
function drawCat(){
  var cats=[]; st.catProd.forEach(function(p){ if(cats.indexOf(p.categoria)<0) cats.push(p.categoria); });
  var tabs=$('cat-tabs'); tabs.innerHTML='';
  cats.forEach(function(c){ var t=document.createElement('span'); t.className='chip'+(c===st.catCat?' on':''); t.textContent=c; t.onclick=function(){ st.catCat=c; drawCat(); }; tabs.appendChild(t); });
  var list=$('cat-list'); list.innerHTML='';
  st.catProd.filter(function(p){return p.categoria===st.catCat;}).forEach(function(p){
    var r=document.createElement('div'); r.className='row'; r.innerHTML='<div>'+esc(p.nombre)+(p.grupos&&p.grupos.length?'<br><small style="color:#999">toca para modificar</small>':'')+'</div><div style="display:flex;align-items:center;gap:9px"><b>S/ '+Number(p.precio).toFixed(0)+'</b><span style="width:26px;height:26px;border-radius:50%;background:#FFDF00;color:#1E1E1E;font-weight:900;display:flex;align-items:center;justify-content:center">+</span></div>';
    r.onclick=function(){ openProd(p); };
    list.appendChild(r);
  });
}
function openProd(p){ st.prodSel={p:p, qty:1, sel:{}, nota:''}; renderProd(); openModal('m-prod'); }
function renderProd(){
  var s=st.prodSel, p=s.p;
  var html='<div style="padding:15px 16px 6px"><div style="font-weight:900;font-size:17px">'+esc(p.nombre)+'</div><div style="color:#888;font-size:12px">S/ '+Number(p.precio).toFixed(2)+'</div></div><div style="padding:6px 16px">';
  (p.grupos||[]).forEach(function(g){
    var multi=(parseInt(g.max_select||1)!==1);
    html+='<div style="font-size:10px;font-weight:800;color:#888;text-transform:uppercase;margin:8px 0 2px">'+esc(g.nombre)+'</div>';
    (g.opciones||[]).forEach(function(o){
      var on=(s.sel[g.id]&&s.sel[g.id][o.id]);
      html+='<div class="opt" data-g="'+g.id+'" data-o="'+o.id+'" data-multi="'+(multi?1:0)+'" data-precio="'+o.precio+'" data-nombre="'+esc(o.nombre)+'"><span class="mark '+(multi?'':'rad')+(on?' on':'')+'"></span> '+esc(o.nombre)+(parseFloat(o.precio)>0?'<span style="margin-left:auto;color:#888">+S/ '+Number(o.precio).toFixed(0)+'</span>':'')+'</div>';
    });
  });
  html+='<div style="font-size:10px;font-weight:800;color:#888;text-transform:uppercase;margin:8px 0 4px">Nota para cocina</div><input id="prod-nota" placeholder="Sin cebolla…" value="'+esc(s.nota)+'" style="width:100%;padding:9px 11px;border:1.5px solid #ddd;border-radius:8px;font-size:13px"></div>';
  // pie en 2 filas
  html+='<div style="border-top:1px solid #eee;padding:11px 16px 16px"><div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-bottom:11px"><span style="font-size:11px;font-weight:800;color:#888;text-transform:uppercase">Cantidad</span><div style="display:flex;align-items:center;gap:14px"><button class="key" style="width:40px" onclick="prodQty(-1)">−</button><b id="prod-qty" style="font-size:18px">'+s.qty+'</b><button class="key" style="width:40px" onclick="prodQty(1)">+</button></div></div><button class="btn dark" onclick="addBorr()">Agregar · S/ <span id="prod-tot">'+prodTotal().toFixed(0)+'</span></button></div>';
  $('m-prod-in').innerHTML=html;
  $('m-prod-in').querySelectorAll('.opt').forEach(function(el){ el.onclick=function(){ toggleOpt(el); }; });
}
function toggleOpt(el){ var g=el.getAttribute('data-g'), o=el.getAttribute('data-o'), multi=el.getAttribute('data-multi')==='1'; var s=st.prodSel; s.sel[g]=s.sel[g]||{};
  if(multi){ if(s.sel[g][o]) delete s.sel[g][o]; else s.sel[g][o]={precio:parseFloat(el.getAttribute('data-precio')),nombre:el.getAttribute('data-nombre')}; }
  else { s.sel[g]={}; s.sel[g][o]={precio:parseFloat(el.getAttribute('data-precio')),nombre:el.getAttribute('data-nombre')}; }
  renderProd();
}
function prodQty(d){ st.prodSel.qty=Math.max(1, st.prodSel.qty+d); renderProd(); }
function prodTotal(){ var s=st.prodSel; var base=parseFloat(s.p.precio); var mods=0; Object.keys(s.sel).forEach(function(g){ Object.keys(s.sel[g]).forEach(function(o){ mods+=s.sel[g][o].precio; }); }); return (base+mods)*s.qty; }
function addBorr(){ var s=st.prodSel; var mods=[]; Object.keys(s.sel).forEach(function(g){ Object.keys(s.sel[g]).forEach(function(o){ mods.push({nombre:s.sel[g][o].nombre, precio:s.sel[g][o].precio}); }); });
  var nota=($('prod-nota')||{}).value||'';
  st.borrador.push({product_id:s.p.id, nombre:s.p.nombre, precio:parseFloat(s.p.precio), qty:s.qty, modificadores:mods, nota:nota});
  closeModal('m-prod'); updBorr();
}
function updBorr(){ var n=st.borrador.length; var tot=st.borrador.reduce(function(s,it){ var m=it.modificadores.reduce(function(a,x){return a+x.precio;},0); return s+(it.precio+m)*it.qty; },0);
  $('cat-foot').style.display=n?'block':'none'; $('cat-borr').textContent='Borrador · '+n+' ítems · S/ '+tot.toFixed(0); }
function enviarComanda(){ if(!st.borrador.length)return; geo().then(function(){ post('enviar_comanda', withGeo({cuenta_id:st.cuenta.id, items:JSON.stringify(st.borrador)})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} st.borrador=[]; toast('Enviado a cocina · Ronda '+d.ronda); loadCuenta(st.cuenta.id); }); }); }

function goPlano(){ showView('v-plano'); refreshEstados(); }

// arranque: ¿ya hay sesión?
get('sesion').then(function(d){ if(d.ok&&d.mozo){ st.emp=d.mozo.emp; $('plano-mozo').textContent=d.mozo.nombre+' 👤'; geo(); enterApp(); } else { $('pin-ubi').textContent=''; loadMozos(); } });
</script>
<?php endif; ?>
</body>
</html>
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l mozo/index.php && php -l mozo/manifest.php`
Expected: `No syntax errors detected` (ambos)
Si hay node: extrae mentalmente que el `<script>` cierra bien; si no, revisa a ojo llaves/paréntesis.

- [ ] **Step 4: Verificación funcional (post-deploy, HTTPS)**

Acceptance: en el celular, `/mozo/index.php` pide elegir mozo → PIN → entra al plano; tocar mesa libre pide comensales y abre cuenta; "+ Agregar" muestra el catálogo, el modal de producto con modificadores/cantidad/nota (pie en 2 filas), "Enviar a cocina" crea la comanda y aparece en el KDS; anular un ítem antes de Listo lo tacha; con la geocerca activa, fuera del local las escrituras se bloquean.

- [ ] **Step 5: Commit**

```bash
git add mozo/index.php mozo/manifest.php
git commit -m "feat(mozo): app del mozo (PIN, plano en vivo, cuenta, catálogo, comandas, anular)"
```

---

### Task 5: KDS muestra la mesa (badge MESA N · Ronda)

**Files:**
- Modify: `api/kds_pedidos.php`
- Modify: `admin/kds/index.php`

**Interfaces:**
- Consumes: `pedidos.mesa_id`, `pedidos.cuenta_id`, `pedidos.origen='mesa'`, tabla `mesas`.

- [ ] **Step 1: Enriquecer cada pedido con mesa y ronda en `api/kds_pedidos.php`**

READ `api/kds_pedidos.php`. En el bucle que arma cada `$p` (después de `$p['origen'] = $p['origen'] ?? 'carta';`), añade el enriquecimiento de mesa:

```php
        // Mesa (Sub-build B): numero de mesa + nº de ronda dentro de la cuenta
        $p['mesa_numero'] = null;
        $p['ronda'] = null;
        if (($p['origen'] ?? '') === 'mesa' && !empty($p['mesa_id'])) {
            try {
                $mn = Database::fetch("SELECT numero FROM mesas WHERE id = ?", [(int)$p['mesa_id']]);
                $p['mesa_numero'] = $mn['numero'] ?? null;
                if (!empty($p['cuenta_id'])) {
                    $p['ronda'] = (int)(Database::fetch(
                        "SELECT COUNT(*) n FROM pedidos WHERE cuenta_id = ? AND id <= ?",
                        [(int)$p['cuenta_id'], (int)$p['id']])['n'] ?? 1);
                }
            } catch (\Throwable $e) { /* tablas de mesas no migradas */ }
        }
```
(Como la query base es `SELECT p.*`, `mesa_id`/`cuenta_id`/`origen` ya vienen en `$p`.)

- [ ] **Step 2: Dibujar el badge en `admin/kds/index.php`**

READ `admin/kds/index.php` y localiza el constructor de tarjeta `cardHTML(p,opts)` donde arma las etiquetas de la fila 3 (SALÓN/DELIVERY). Añade, en la lógica de etiquetas, el badge de mesa cuando aplique. Busca dónde se decide la etiqueta de origen (p.ej. el texto "SALÓN"/"DELIVERY") y antepone:

```javascript
      // Mesa (Sub-build B)
      var mesaBadge = (p.origen === 'mesa' && p.mesa_numero)
        ? '<span class="kcat" style="background:#1E1E1E;color:#FFDF00;font-weight:900">MESA ' + p.mesa_numero + (p.ronda ? ' · R' + p.ronda : '') + '</span>'
        : '';
```
e incluye `mesaBadge` al inicio del HTML de etiquetas de esa tarjeta (junto a las demás). Reusa la clase de etiqueta que ya use el card (si es `.kcat` u otra, úsala; el estilo inline asegura el color de marca).

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l api/kds_pedidos.php && php -l admin/kds/index.php`
Expected: `No syntax errors detected` (ambos)

- [ ] **Step 4: Verificación funcional**

Acceptance: una comanda `origen='mesa'` aparece en el KDS con "MESA N · R{ronda}"; las de carta/POS no cambian.

- [ ] **Step 5: Commit**

```bash
git add api/kds_pedidos.php admin/kds/index.php
git commit -m "feat(kds): badge MESA N · Ronda en comandas de mesa"
```

---

### Task 6: Frontera de venta (dashboard + monitor)

**Files:**
- Modify: `admin/dashboard.php`
- Modify: `api/pos.php` (acción `monitor`)

**Interfaces:**
- Consumes: `pedidos.origen`, `cuentas.total`.

- [ ] **Step 1: Excluir `origen='mesa'` de la venta POS en el dashboard**

READ `admin/dashboard.php`. En las queries que suman `pedidos` como ingreso POS del mes (las de `FROM pedidos ... DATE_FORMAT(created_at...)` — el total POS, el desglose por origen y `posPorTienda`), añade `AND p.origen <> 'mesa'` (o `AND origen <> 'mesa'` según el alias) a cada WHERE. Ejemplo, la query de total POS:

```php
"SELECT COALESCE(SUM(total),0) t, COUNT(*) n FROM pedidos
 WHERE estado <> 'cancelado' AND origen <> 'mesa' AND DATE_FORMAT(created_at,'%Y-%m') = ?"
```
Aplica el mismo `AND origen <> 'mesa'` a las otras dos (desglose por origen y `posPorTienda`, esta última con alias `p.`). No toques los buckets de cotizaciones/eventos.

- [ ] **Step 2: Sumar las mesas abiertas en el monitor**

READ `api/pos.php`, acción `case 'monitor':`. Antes de su `pout(...)`, calcula la suma de cuentas abiertas del local y agrégala a la respuesta:

```php
    $mesasAbiertas = 0.0;
    try {
        $mesasAbiertas = (float)(Database::fetch(
            "SELECT COALESCE(SUM(total),0) t FROM cuentas WHERE ubicacion_id = ? AND estado = 'abierta'", [$ubi])['t'] ?? 0);
    } catch (\Throwable $e) { /* cuentas no migradas */ }
```
y añade `'mesas_abiertas' => $mesasAbiertas` al array que pasa a `pout(...)`. (Confirma el nombre de la variable de ubicación en esa acción — probablemente `$ubi`.)

- [ ] **Step 3: Mostrar el dato en el monitor (front)**

READ `admin/pos/monitor.php`. Donde se pintan los KPIs en vivo, añade una tarjeta/figura "Mesas abiertas (sin cobrar)" que lea `mesas_abiertas` de la respuesta del poll. Usa el mismo patrón de render de los KPIs existentes (formatear como `S/`). Mantenlo separado de las ventas realizadas.

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/dashboard.php && php -l api/pos.php && php -l admin/pos/monitor.php`
Expected: `No syntax errors detected` (los tres)

- [ ] **Step 5: Verificación funcional**

Acceptance: una cuenta de mesa abierta NO aparece en el ingreso POS del dashboard; el monitor EN VIVO muestra "Mesas abiertas: S/ X" con la suma de cuentas abiertas; al cobrarse (C) recién entrará a ventas.

- [ ] **Step 6: Commit**

```bash
git add admin/dashboard.php api/pos.php admin/pos/monitor.php
git commit -m "feat(mesas): dashboard excluye comandas de mesa; monitor suma cuentas abiertas"
```

---

### Task 7: Interruptor de la geocerca del mozo (Ajustes)

**Files:**
- Modify: `admin/settings/index.php`

**Interfaces:**
- Consumes: `getSetting`/`setSetting`. Produces: setting `mozo_geocerca_activa` ('1'/'0').

- [ ] **Step 1: Guardar el setting en el POST**

READ `admin/settings/index.php`. Junto a la línea `setSetting('pos_nombre_obligatorio', ...)`, añade:

```php
    setSetting('mozo_geocerca_activa', isset($_POST['mozo_geocerca_activa']) ? '1' : '0');
```

- [ ] **Step 2: Añadir el checkbox en la tarjeta POS**

READ la tarjeta "Punto de venta (POS)" del form. Junto al checkbox de `pos_nombre_obligatorio`, añade:

```php
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-top:10px">
        <input type="checkbox" name="mozo_geocerca_activa" value="1" <?= getSetting('mozo_geocerca_activa','1')==='1'?'checked':'' ?> style="width:18px;height:18px">
        <span>Geocerca del mozo (solo puede tomar pedidos dentro del local) — apágalo si el GPS falla</span>
      </label>
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/settings/index.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Verificación funcional**

Acceptance: en Ajustes aparece el toggle (default ON); al apagarlo, `getSetting('mozo_geocerca_activa')` = '0' y la app del mozo deja de exigir GPS (vuelve a solo-PIN).

- [ ] **Step 5: Commit**

```bash
git add admin/settings/index.php
git commit -m "feat(mozo): toggle Ajustes para la geocerca del mozo (kill-switch)"
```

---

## Self-Review

**Spec coverage:**
- B.1 modelo (cuentas, cuenta_anulaciones, pedidos.cuenta_id/mesa_id/origen='mesa') → Task 1. ✅
- B.2 app + auth PIN + sesión → Task 4 (app) + Task 3 (login_pin/sesión). ✅
- B.3 comanda → KDS → Task 2 (`comandaEnviar`) + Task 4 (catálogo/enviar) + Task 5 (badge). ✅
- B.4 anular (solo antes de Listo, ítem/comanda, motivo) → Task 2 (`cuentaAnular`) + Task 3/4. ✅
- B.5 estados en vivo → Task 2 (`mesaEstados`) + Task 3 (`plano_estados`) + Task 4 (poll). ✅
- B.6 frontera de venta (excluir origen='mesa'; monitor suma cuentas) → Task 6; arqueo intacto (turno_id NULL). ✅
- B.7 geocerca dura + kill-switch → Task 2 (`dentroGeocerca`) + Task 3 (`geoGate`) + Task 4 (geo) + Task 7 (toggle). ✅
- B.8 archivos/permisos/integración → repartido en Tasks 1–7. ✅

**Type/contract consistency:** `items_json` (`{product_id,qty,nombre,precio,modificadores,nota,anulado?,anul_motivo?}`) idéntico en cuentas.php, api/mozo.php y la app. `cuentaDetalle` devuelve `comandas:[{pedido_id,ronda,estado,items}]` consumido por la app. `mesaEstados` → `{estados,montos}` consumido por `plano_estados` y `PlanoRender.draw`. Sesión de mozo: `$_SESSION['mozo_emp'/'mozo_ubi'/'mozo_nombre']` set en login y leída por las acciones.

**Placeholder scan:** sin TBD/TODO; código completo. Los `try/catch` que silencian (columnas no migradas) siguen la convención del proyecto, no son placeholders.

**Notas de integración a verificar en ejecución:**
- `api/kds_pedidos.php` usa `SELECT p.*` → `mesa_id`/`cuenta_id`/`origen` ya vienen en `$p` (Task 5).
- En `admin/kds/index.php`, el nombre exacto de la clase de etiqueta del card (Task 5 Step 2) y dónde se ensamblan las etiquetas — el implementer lo ubica al leer `cardHTML`.
- En `admin/dashboard.php` (Task 6), el alias de `pedidos` por query (con/sin `p.`) — aplicar `origen <> 'mesa'` con el alias correcto.
- En `api/pos.php` acción `monitor` (Task 6) y `admin/pos/monitor.php`, el nombre de la variable de ubicación y el patrón de KPIs — confirmar al leer.
- `empleados` tiene `pin_intentos`/`pin_bloqueado_hasta` (de `43_asistencia_pin_lockout.sql`); el login es tolerante si faltan.
