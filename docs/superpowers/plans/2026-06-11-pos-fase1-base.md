# POS · Fase 1 — Base (esquema + métodos + terminal + caja)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** POS funcional mínimo: abrir caja → vender (genera un `pedido` origen=pos que entra al KDS) → cerrar caja con arqueo; + admin de métodos de pago.

**Architecture:** Módulo nuevo en el stack de Lima. Un **terminal** (página propia `pos/terminal.php`) consume `api/pos.php` (JSON, CSRF por header, `requireLogin`). Las ventas son filas en la tabla `pedidos` existente (`origen='pos'`, `items_json`), que el KDS ya muestra como "SALÓN". Tablas nuevas para turnos/métodos.

**Tech Stack:** PHP 8 (`Database` PDO), JS vanilla, MySQL/InnoDB. Reusa `products`/`categories`/`ubicaciones`/`pedidos`/KDS.

**Rama:** `pos`. **Aislamiento:** archivos nuevos + ampliar `pedidos` (columnas compatibles, nullable/con default) + entradas de nav. No toca carta, generador ni KDS. **Verificación:** `php -l` + revisión de seguridad del API + QA humano (requiere aplicar `install/pos.sql`).

## Datos de referencia (ya verificados)
- `pedidos` ya tiene: `ubicacion_id, nombre, telefono, tipo_entrega, direccion, horario, comentarios, items_json, total, estado ENUM('pendiente','en_preparacion','listo','entregado','cancelado'), metodo_pago ENUM('whatsapp','izipay'), izipay_order_id, origen ENUM('carta','pos'), aceptado_at, completado_at, created_at`.
- **items_json**: array de `{ qty, nombre, precio, modificadores:[{nombre}] }` (lo que el KDS renderiza).
- KDS muestra `origen='pos'` como badge "SALÓN"; un pedido en `estado='en_preparacion'` con `aceptado_at` corre el timer y muestra "Listo".

## Estructura de archivos
- Create: `install/pos.sql` — tablas nuevas + ALTERs a `pedidos`.
- Create: `api/pos.php` — endpoints del POS.
- Create: `admin/pos/metodos.php` — admin de métodos de pago.
- Create: `pos/terminal.php` — el terminal del cajero.
- Modify: `admin/layout-top.php` — entradas de nav (POS terminal + POS métodos).

---

## Tarea 1: Esquema `install/pos.sql`

**Files:** Create `install/pos.sql`.

- [ ] **Step 1: Crear el archivo**

```sql
-- ============================================================
-- POS (Fase E) — turnos de caja, métodos de pago, favoritos
-- Aplicar una vez. Requiere que exista la tabla `pedidos`.
-- ============================================================
CREATE TABLE IF NOT EXISTS `pos_turnos` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`     INT UNSIGNED NOT NULL,
  `ubicacion_id`   INT UNSIGNED NOT NULL,
  `monto_inicial`  DECIMAL(10,2) NOT NULL DEFAULT 0,
  `monto_final`    DECIMAL(10,2) NULL,
  `total_efectivo` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_tarjeta`  DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_qr`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_otros`    DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_ventas`   DECIMAL(10,2) NOT NULL DEFAULT 0,
  `total_pedidos`  INT NOT NULL DEFAULT 0,
  `abierto_en`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cerrado_en`     DATETIME NULL,
  `estado`         ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
  PRIMARY KEY (`id`),
  INDEX `idx_turno_estado` (`estado`),
  INDEX `idx_turno_ubi` (`ubicacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_metodos_pago` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(60) NOT NULL,
  `tipo`   ENUM('efectivo','tarjeta','qr','otros') NOT NULL DEFAULT 'otros',
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `orden`  SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_favoritos` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ubicacion_id` INT UNSIGNED NOT NULL,
  `producto_id`  INT UNSIGNED NOT NULL,
  `posicion`     SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_fav_ubi` (`ubicacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Métodos por defecto
INSERT IGNORE INTO `pos_metodos_pago` (`id`,`nombre`,`tipo`,`orden`) VALUES
  (1,'Efectivo','efectivo',1),(2,'Tarjeta','tarjeta',2),(3,'Yape / Plin','qr',3);

-- Ampliar `pedidos` para el POS (compatibles; no rompen carta/KDS).
-- metodo_pago pasa de ENUM a VARCHAR para admitir efectivo/tarjeta/qr.
ALTER TABLE `pedidos` MODIFY COLUMN `metodo_pago` VARCHAR(60) NOT NULL DEFAULT 'whatsapp';
ALTER TABLE `pedidos`
  ADD COLUMN `turno_id` INT UNSIGNED NULL,
  ADD COLUMN `descuento_tipo` ENUM('porcentaje','monto') NULL,
  ADD COLUMN `descuento_valor` DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN `descuento_monto` DECIMAL(10,2) NOT NULL DEFAULT 0,
  ADD COLUMN `cliente_tipo` ENUM('nombre','dni','ruc') NULL,
  ADD COLUMN `cliente_nombre` VARCHAR(255) NULL,
  ADD COLUMN `cliente_documento` VARCHAR(20) NULL,
  ADD COLUMN `cliente_razon_social` VARCHAR(255) NULL,
  ADD COLUMN `comprobante_tipo` ENUM('ticket','boleta','factura') NOT NULL DEFAULT 'ticket',
  ADD COLUMN `notas_pos` TEXT NULL;
```

- [ ] **Step 2: Commit** — `git add install/pos.sql && git commit -m "feat(pos): esquema — turnos, métodos, favoritos + columnas POS en pedidos"`

**Nota de despliegue (no es un step):** aplicar `install/pos.sql` en la BD una vez. Si algún ALTER falla porque la columna ya existe (instalación previa), ignorarlo.

---

## Tarea 2: API `api/pos.php`

**Files:** Create `api/pos.php`. **Patrón:** igual a `api/cartas.php` (`requireLogin()` + JSON + `$action` switch + `verifyCsrf()` en escritura por header `X-CSRF-Token` + `clean/cleanInt/cleanFloat` + prepared statements).

- [ ] **Step 1: Crear el archivo con estos endpoints**

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');
function pout($d){ echo json_encode($d); exit; }

$action = clean($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$writes = ['abrir_turno','cerrar_turno','registrar_venta'];
if (in_array($action, $writes, true)) { if (!$isPost) pout(['ok'=>false,'error'=>'Método']); verifyCsrf(); }
$uid = (int)(currentUser()['id'] ?? 0);

switch ($action) {

// Productos disponibles de una ubicación (para la grilla), agrupados por categoría
case 'productos':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    $rows = Database::fetchAll(
        "SELECT p.id, p.name AS nombre, p.image AS foto, c.name AS categoria, lp.price AS precio
         FROM location_products lp
         JOIN products p ON p.id = lp.product_id AND p.active = 1
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE lp.location_id = ? AND lp.available = 1
         ORDER BY c.sort_order, c.name, lp.sort_order, p.sort_order, p.name", [$ubi]);
    pout(['ok'=>true,'data'=>$rows]);

// Métodos de pago activos
case 'metodos':
    pout(['ok'=>true,'data'=>Database::fetchAll("SELECT id,nombre,tipo FROM pos_metodos_pago WHERE activo=1 ORDER BY orden,id")]);

// Turno abierto del cajero en esa ubicación (o null)
case 'turno_actual':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    $t = Database::fetch("SELECT * FROM pos_turnos WHERE usuario_id=? AND ubicacion_id=? AND estado='abierto' ORDER BY id DESC LIMIT 1", [$uid,$ubi]);
    pout(['ok'=>true,'turno'=>$t]);

case 'abrir_turno':
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $monto = cleanFloat($_POST['monto_inicial'] ?? 0);
    if (!$ubi) pout(['ok'=>false,'error'=>'Ubicación']);
    // un solo turno abierto por cajero+ubicación
    $ya = Database::fetch("SELECT id FROM pos_turnos WHERE usuario_id=? AND ubicacion_id=? AND estado='abierto'", [$uid,$ubi]);
    if ($ya) pout(['ok'=>true,'id'=>(int)$ya['id']]);
    $id = Database::insert("INSERT INTO pos_turnos (usuario_id,ubicacion_id,monto_inicial) VALUES (?,?,?)", [$uid,$ubi,$monto]);
    pout(['ok'=>true,'id'=>$id]);

case 'cerrar_turno':
    $tid = cleanInt($_POST['turno_id'] ?? 0);
    $montoFinal = cleanFloat($_POST['monto_final'] ?? 0);
    $t = Database::fetch("SELECT * FROM pos_turnos WHERE id=? AND usuario_id=? AND estado='abierto'", [$tid,$uid]);
    if (!$t) pout(['ok'=>false,'error'=>'Turno no encontrado']);
    // Totales del turno desde pedidos (no del día): suma por bucket de método
    $ag = Database::fetch(
        "SELECT COUNT(*) n, COALESCE(SUM(total),0) tot,
                COALESCE(SUM(CASE WHEN m.tipo='efectivo' THEN p.total ELSE 0 END),0) ef,
                COALESCE(SUM(CASE WHEN m.tipo='tarjeta'  THEN p.total ELSE 0 END),0) ta,
                COALESCE(SUM(CASE WHEN m.tipo='qr'       THEN p.total ELSE 0 END),0) qr,
                COALESCE(SUM(CASE WHEN m.tipo NOT IN ('efectivo','tarjeta','qr') OR m.tipo IS NULL THEN p.total ELSE 0 END),0) ot
         FROM pedidos p LEFT JOIN pos_metodos_pago m ON m.nombre = p.metodo_pago
         WHERE p.turno_id = ? AND p.estado <> 'cancelado'", [$tid]);
    Database::execute(
        "UPDATE pos_turnos SET estado='cerrado', cerrado_en=NOW(), monto_final=?,
            total_pedidos=?, total_ventas=?, total_efectivo=?, total_tarjeta=?, total_qr=?, total_otros=? WHERE id=?",
        [$montoFinal, (int)$ag['n'], $ag['tot'], $ag['ef'], $ag['ta'], $ag['qr'], $ag['ot'], $tid]);
    pout(['ok'=>true]);

case 'registrar_venta':
    $ubi   = cleanInt($_POST['ubicacion_id'] ?? 0);
    $tid   = cleanInt($_POST['turno_id'] ?? 0);
    $metodo= clean($_POST['metodo_pago'] ?? 'Efectivo');
    $total = cleanFloat($_POST['total'] ?? 0);
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!$ubi || !$tid || !is_array($items) || !count($items)) pout(['ok'=>false,'error'=>'Datos incompletos']);
    // validar turno abierto
    $t = Database::fetch("SELECT id FROM pos_turnos WHERE id=? AND usuario_id=? AND estado='abierto'", [$tid,$uid]);
    if (!$t) pout(['ok'=>false,'error'=>'Caja cerrada']);
    // normalizar items a la forma del KDS {qty,nombre,precio,modificadores}
    $clean = [];
    foreach ($items as $it) {
        $clean[] = [
            'qty'    => max(1,(int)($it['qty'] ?? 1)),
            'nombre' => clean($it['nombre'] ?? ''),
            'precio' => (float)($it['precio'] ?? 0),
            'modificadores' => array_values(array_map(fn($m)=>['nombre'=>clean($m['nombre'] ?? '')], (array)($it['modificadores'] ?? []))),
        ];
    }
    $nombre = clean($_POST['cliente_nombre'] ?? '') ?: 'Mostrador';
    $compro = in_array($_POST['comprobante_tipo'] ?? 'ticket', ['ticket','boleta','factura'], true) ? $_POST['comprobante_tipo'] : 'ticket';
    $pid = Database::insert(
        "INSERT INTO pedidos (ubicacion_id, nombre, tipo_entrega, items_json, total, estado, metodo_pago, origen, turno_id, comprobante_tipo, aceptado_at, horario)
         VALUES (?,?, 'recojo', ?, ?, 'en_preparacion', ?, 'pos', ?, ?, NOW(), 'En salón')",
        [$ubi, $nombre, json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $metodo, $tid, $compro]);
    // acumular en el turno
    Database::execute("UPDATE pos_turnos SET total_ventas=total_ventas+?, total_pedidos=total_pedidos+1 WHERE id=?", [$total, $tid]);
    pout(['ok'=>true,'id'=>$pid]);

default:
    pout(['ok'=>false,'error'=>'Acción no válida']);
}
```

- [ ] **Step 2:** `php -l api/pos.php` → sin errores.
- [ ] **Step 3:** Commit — `git add api/pos.php && git commit -m "feat(pos): api/pos.php — productos, turno (abrir/cerrar), métodos, registrar venta"`

*(Nota: `currentUser()` existe en helpers; `verifyCsrf()` acepta header `X-CSRF-Token`. La venta crea el `pedido` con `estado='en_preparacion'` y `aceptado_at=NOW()` → entra al KDS como "SALÓN".)*

---

## Tarea 3: Admin de métodos de pago `admin/pos/metodos.php` + nav

**Files:** Create `admin/pos/metodos.php`; Modify `admin/layout-top.php`.

- [ ] **Step 1:** Crear `admin/pos/metodos.php` (patrón de `admin/landing/index.php`): `requireLogin()`+`requireAdmin()`; POST con `verifyCsrf()` para crear/togglear/borrar; lista de `pos_metodos_pago` con nombre, tipo (select efectivo/tarjeta/qr/otros), activo (toggle), y un form "nuevo método" (nombre + tipo). `$pageTitle='POS · Métodos de pago'; $activePage='pos-metodos';` con layout-top/bottom. (CRUD simple sobre `pos_metodos_pago`: INSERT, UPDATE activo = NOT activo, DELETE.)

- [ ] **Step 2:** En `admin/layout-top.php`, tras la entrada "Generador de QR", añadir dos entradas de nav:
```html
    <a href="<?php echo APP_URL; ?>/pos/terminal.php" target="_blank"
       class="nav-link">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></span> POS · Terminal
    </a>
    <a href="<?php echo APP_URL; ?>/admin/pos/metodos.php"
       class="nav-link <?php echo ($activePage??'')==='pos-metodos'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span> POS · Métodos
    </a>
```

- [ ] **Step 3:** `php -l admin/pos/metodos.php && php -l admin/layout-top.php` → sin errores.
- [ ] **Step 4:** Commit — `git add admin/pos/metodos.php admin/layout-top.php && git commit -m "feat(pos): admin de métodos de pago + nav"`

---

## Tarea 4: Terminal `pos/terminal.php`

**Files:** Create `pos/terminal.php`. Página propia full-screen (no usa el sidebar del panel). `requireLogin()`. Expone a JS: `CSRF=csrfToken()`, `API=APP_URL/api/pos.php`, `UPLOAD_URL`, y la lista de ubicaciones activas para elegir dónde se abre la caja.

**Contrato del API** (Tarea 2): `productos`(ubicacion_id)→`[{id,nombre,foto,categoria,precio}]` · `metodos`→`[{id,nombre,tipo}]` · `turno_actual`(ubicacion_id)→`{turno|null}` · `abrir_turno`(ubicacion_id,monto_inicial)→`{id}` · `cerrar_turno`(turno_id,monto_final) · `registrar_venta`(ubicacion_id,turno_id,metodo_pago,total,items[json],cliente_nombre?,comprobante_tipo?)→`{id}`.

**Comportamiento a implementar (JS vanilla + helpers `apiGet`/`apiPost` como en el editor del generador, CSRF por header):**
- **Selección de ubicación + caja:** al cargar, elegir ubicación → `turno_actual`. Si no hay turno abierto, mostrar pantalla "Abrir caja" (input monto inicial → `abrir_turno`). Con turno abierto, mostrar el terminal.
- **Grilla de productos:** `productos(ubicacion_id)` → tiles por categoría (pestañas de categoría + buscador que filtra por nombre). Tap en un tile lo agrega al carrito (si ya está, suma qty).
- **Carrito (derecha):** líneas `{qty,nombre,precio,modificadores:[]}`; botones +/− por línea; **swipe a la izquierda para eliminar** (touchstart/touchmove/touchend; si desplaza > ~60px, quita la línea; en desktop, botón ✕ visible). Total = suma qty*precio.
- **Cobro:** botones de método (de `metodos`); botón **COBRAR**. En método tipo efectivo, pedir "monto recibido" y mostrar **vuelto** (recibido − total) antes de confirmar. Al confirmar → `registrar_venta` (manda `items` como JSON.stringify del carrito, total, metodo_pago=nombre del método, ubicacion_id, turno_id) → limpia el carrito y muestra confirmación breve ("Venta #id registrada"). El pedido aparece en el KDS.
- **Barra inferior:** Vender (activo) · Historial (placeholder F2/F3) · **Caja** (muestra monto inicial + ventas del turno + botón **Cerrar turno** → pide monto_final → `cerrar_turno` → vuelve a "Abrir caja") · Clientes (placeholder) · Cerrar turno (atajo). Íconos SVG, sin emojis.
- **Táctil-first:** targets grandes, sin hover; el layout responde (productos izquierda / carrito derecha en horizontal; apilado en vertical angosto).

- [ ] **Step 1:** Implementar `pos/terminal.php` según lo anterior (PHP cabecera + HTML del terminal + `<script>` con la lógica). Escapar texto en `innerHTML` con una función `esc()`. CSRF por header en POST. `items` se envía como `JSON.stringify(cart)`.
- [ ] **Step 2:** `php -l pos/terminal.php` → sin errores.
- [ ] **Step 3:** Commit — `git add pos/terminal.php && git commit -m "feat(pos): terminal del cajero (abrir caja, vender, cobrar con vuelto, cerrar caja)"`

---

## Verificación final de la fase
- [ ] `php -l` sin errores en los 4 archivos nuevos.
- [ ] Revisión de seguridad de `api/pos.php` (CSRF en escrituras, login, prepared statements) — como se hizo con `api/cartas.php`.
- [ ] (Humano, tras `install/pos.sql`) Abrir `pos/terminal.php`: elegir ubicación → abrir caja con monto inicial → agregar productos (tap, +/−, swipe para eliminar) → cobrar (efectivo con vuelto y otro método) → la venta aparece en el **KDS** como "SALÓN" → Caja muestra el total del turno → cerrar turno con arqueo.
- [ ] `admin/pos/metodos.php`: crear/activar/desactivar métodos; el terminal los toma.
- [ ] Aislamiento: el diff no rompe carta/generador/KDS; `pedidos` sigue funcionando para la carta.

## Pendiente (sus propios planes)
- **F2:** cobro/vuelto avanzado, descuento, cliente DNI/RUC (+ botón "Buscar" inerte) + comprobante_tipo, **favoritos editables**, ticket (PDF/email), Historial del turno.
- **F3:** Historial de caja (admin) + Clientes POS + filtro de **origen** en `admin/pedidos`.
- **F4:** Monitor de ventas en vivo (día). **F5 (futura):** SUNAT/RENIEC.
