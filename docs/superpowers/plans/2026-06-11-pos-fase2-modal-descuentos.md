# POS · Fase 2 — Modal de ítem, descuentos, cliente, favoritos, ticket

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enriquecer el terminal POS: modal único de ítem (modificadores + nota + descuento), descuento global, nota general del pedido, cliente DNI/RUC + comprobante, favoritos editables y ticket imprimible.

**Architecture:** Extiende `api/pos.php` (modificadores por producto, registrar_venta con descuentos/cliente/comprobante, favoritos) y `pos/terminal.php` (modal de ítem, descuentos, cliente, favoritos). Reusa `grupos_modificadores`/`product_modifier_groups`/`modificadores` (ya existen, la carta los usa). **Sin migración nueva**: las columnas (`descuento_*`, `cliente_*`, `comprobante_tipo`, `notas_pos`) ya las agregó F1; lo por-ítem va en `items_json`.

**Tech Stack:** PHP 8 (`Database`), JS vanilla. **Rama:** `pos-f2`. **Aislamiento:** solo `api/pos.php` y `pos/terminal.php` (+ posible `pos/ticket.php` nuevo). No toca carta/generador/KDS. **Verificación:** `php -l` + JS syntax check + QA humano.

## Datos de referencia
- Modificadores de un producto: `grupos_modificadores g JOIN product_modifier_groups pmg ON pmg.grupo_id=g.id WHERE pmg.product_id=? AND g.activo=1`; cada grupo `{id,nombre,tipo,max_opciones,requerido}` con `modificadores:[{id,nombre,precio_adicional}]` (`SELECT id,nombre,precio_adicional FROM modificadores WHERE grupo_id=? AND activo=1`).
- **Forma del ítem del carrito (nueva):** `{ id, qty, nombre, precio, modificadores:[{nombre,precio}], nota, desc_tipo:('porcentaje'|'monto'|null), desc_valor }`. El KDS ya renderiza `modificadores[].nombre`; la **nota** se agrega como un modificador extra `{nombre: 'Nota: ...'}` para que se vea en cocina.
- `pedidos`: `descuento_tipo/descuento_valor/descuento_monto` (global), `cliente_tipo/cliente_nombre/cliente_documento/cliente_razon_social`, `comprobante_tipo`, `notas_pos`.

---

## Tarea 1: API — modificadores, registrar_venta completo, favoritos

**Files:** Modify `api/pos.php`.

- [ ] **Step 1: Endpoint `producto_mods`** (GET) — devolver los grupos de modificadores de un producto. Insertar como nuevo `case` (lectura, sin CSRF):

```php
case 'producto_mods':
    $pid = cleanInt($_GET['producto_id'] ?? 0);
    $grupos = [];
    try {
        $grupos = Database::fetchAll(
            "SELECT g.id, g.nombre, g.tipo, g.max_opciones, g.requerido
             FROM grupos_modificadores g
             JOIN product_modifier_groups pmg ON pmg.grupo_id = g.id
             WHERE pmg.product_id = ? AND g.activo = 1
             ORDER BY pmg.orden, g.orden, g.id", [$pid]);
        foreach ($grupos as &$g) {
            $g['modificadores'] = Database::fetchAll(
                "SELECT id, nombre, precio_adicional FROM modificadores WHERE grupo_id = ? AND activo = 1 ORDER BY orden, id", [(int)$g['id']]);
        }
        unset($g);
    } catch (Exception $e) { $grupos = []; }
    pout(['ok'=>true,'grupos'=>$grupos]);
```

- [ ] **Step 2: `registrar_venta` completo** — reemplazar la normalización de ítems y el insert para soportar modificadores/nota/descuento por ítem, descuento global, cliente, comprobante y nota general. El total se recalcula en el servidor.

Reemplazar desde `$clean = [];` hasta el `pout(['ok'=>true,'id'=>$pid]);` del case `registrar_venta` por:

```php
    $clean = [];
    $subtotal = 0.0;
    foreach ($items as $it) {
        $qty   = max(1, (int)($it['qty'] ?? 1));
        $base  = (float)($it['precio'] ?? 0);
        $mods  = [];
        $modsSum = 0.0;
        foreach ((array)($it['modificadores'] ?? []) as $m) {
            $mp = (float)($m['precio'] ?? 0);
            $mods[] = ['nombre' => clean($m['nombre'] ?? ''), 'precio' => $mp];
            $modsSum += $mp;
        }
        $nota = clean($it['nota'] ?? '');
        if ($nota !== '') $mods[] = ['nombre' => 'Nota: ' . $nota, 'precio' => 0];   // visible en el KDS
        $lineUnit = $base + $modsSum;
        $lineTot  = $lineUnit * $qty;
        // descuento por ítem
        $dt = in_array($it['desc_tipo'] ?? '', ['porcentaje','monto'], true) ? $it['desc_tipo'] : null;
        $dv = (float)($it['desc_valor'] ?? 0);
        if ($dt === 'porcentaje') $lineTot -= $lineTot * min(100, max(0, $dv)) / 100;
        elseif ($dt === 'monto')  $lineTot -= min($lineTot, max(0, $dv));
        $subtotal += $lineTot;
        $clean[] = ['qty'=>$qty, 'nombre'=>clean($it['nombre'] ?? ''), 'precio'=>$base,
                    'modificadores'=>$mods, 'nota'=>$nota, 'desc_tipo'=>$dt, 'desc_valor'=>$dv];
    }
    // descuento global
    $gdt = in_array($_POST['descuento_tipo'] ?? '', ['porcentaje','monto'], true) ? $_POST['descuento_tipo'] : null;
    $gdv = cleanFloat($_POST['descuento_valor'] ?? 0);
    $gMonto = 0.0;
    if ($gdt === 'porcentaje') $gMonto = $subtotal * min(100, max(0, $gdv)) / 100;
    elseif ($gdt === 'monto')  $gMonto = min($subtotal, max(0, $gdv));
    $total = max(0, $subtotal - $gMonto);
    // cliente + comprobante + nota general
    $cTipo = in_array($_POST['cliente_tipo'] ?? '', ['nombre','dni','ruc'], true) ? $_POST['cliente_tipo'] : null;
    $cNom  = clean($_POST['cliente_nombre'] ?? '');
    $cDoc  = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['cliente_documento'] ?? ''));
    $cRaz  = clean($_POST['cliente_razon_social'] ?? '');
    $compro = clean($_POST['comprobante_tipo'] ?? 'ticket');
    if (!in_array($compro, ['ticket','boleta','factura'], true)) $compro = 'ticket';
    $notas = clean($_POST['notas_pos'] ?? '');
    $nombre = $cNom ?: 'Mostrador';
    $tipoRow = Database::fetch("SELECT tipo FROM pos_metodos_pago WHERE nombre = ? LIMIT 1", [$metodo]);
    $tipo    = $tipoRow['tipo'] ?? 'otros';
    $bucket  = ['efectivo'=>'total_efectivo','tarjeta'=>'total_tarjeta','qr'=>'total_qr'][$tipo] ?? 'total_otros';
    $pid = Database::insert(
        "INSERT INTO pedidos (ubicacion_id, nombre, tipo_entrega, items_json, total, estado, metodo_pago, origen, turno_id,
            comprobante_tipo, descuento_tipo, descuento_valor, descuento_monto,
            cliente_tipo, cliente_nombre, cliente_documento, cliente_razon_social, notas_pos, aceptado_at, horario)
         VALUES (?,?, 'recojo', ?, ?, 'en_preparacion', ?, 'pos', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'En salón')",
        [$ubi, $nombre, json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $metodo, $tid,
         $compro, $gdt, $gdv, $gMonto, $cTipo, ($cNom ?: null), ($cDoc ?: null), ($cRaz ?: null), ($notas ?: null)]);
    Database::execute("UPDATE pos_turnos SET total_ventas=total_ventas+?, total_pedidos=total_pedidos+1, $bucket=$bucket+? WHERE id=?", [$total, $total, $tid]);
    pout(['ok'=>true,'id'=>$pid,'total'=>$total]);
```

- [ ] **Step 3: Favoritos** — endpoints para el tablero editable. Añadir cases:

```php
case 'favoritos':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    pout(['ok'=>true,'data'=>Database::fetchAll(
        "SELECT f.id, f.producto_id, f.posicion, p.name AS nombre, p.image AS foto
         FROM pos_favoritos f JOIN products p ON p.id = f.producto_id AND p.active = 1
         WHERE f.ubicacion_id = ? ORDER BY f.posicion, f.id", [$ubi])]);

case 'fav_set':   // colocar/mover un producto en una celda (write)
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $prod = cleanInt($_POST['producto_id'] ?? 0);
    $pos = cleanInt($_POST['posicion'] ?? 0);
    if (!$ubi || !$prod) pout(['ok'=>false,'error'=>'Datos']);
    Database::execute("DELETE FROM pos_favoritos WHERE ubicacion_id=? AND posicion=?", [$ubi,$pos]); // una celda = un producto
    $id = Database::insert("INSERT INTO pos_favoritos (ubicacion_id,producto_id,posicion) VALUES (?,?,?)", [$ubi,$prod,$pos]);
    pout(['ok'=>true,'id'=>$id]);

case 'fav_clear': // vaciar una celda (write)
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $pos = cleanInt($_POST['posicion'] ?? 0);
    Database::execute("DELETE FROM pos_favoritos WHERE ubicacion_id=? AND posicion=?", [$ubi,$pos]);
    pout(['ok'=>true]);
```

Y agregar `'fav_set','fav_clear'` al array `$writes` (las de escritura, con CSRF).

- [ ] **Step 4:** `php -l api/pos.php` → sin errores. Commit: `git add api/pos.php && git commit -m "feat(pos f2): modificadores por producto, registrar_venta con descuentos/cliente/comprobante, favoritos"`

**Revisión:** este case maneja dinero → tras implementarlo, revisar (CSRF en escrituras, prepared statements, total recalculado en servidor, `$bucket` de whitelist).

---

## Tarea 2: Terminal — modal único de ítem + descuentos + cliente/comprobante + nota general

**Files:** Modify `pos/terminal.php`.

**Cambio de flujo:** tocar un tile de producto **ya no agrega directo**; abre el **modal de ítem**. Tocar una línea del carrito **reabre el mismo modal** precargado.

Implementar en `pos/terminal.php` (JS vanilla, escapar con `esc`, CSRF por header):
- **Modal de ítem** (`abrirItemModal(producto, lineIdx?)`): sin foto. Muestra **nombre**; carga los **modificadores** con `apiGet('producto_mods',{producto_id})` y los renderiza por grupo (botones seleccionables que suman `precio_adicional`; respeta `requerido`/`max_opciones` de forma simple: tipo single = radio, multiple = toggle). **Cantidad** (±). **Nota** (textarea). **Descuento del ítem** (toggle %/S/ + valor). Calcula el precio de la línea en vivo. Botón **Agregar** (si viene de la grilla) o **Guardar** (si viene del carrito, con `lineIdx`) + **Eliminar** (al editar). Al confirmar, arma el objeto de línea `{ id, qty, nombre, precio, modificadores:[{nombre,precio}], nota, desc_tipo, desc_valor }` y lo agrega/actualiza en `state.cart`, luego `renderCart()`.
- **Tile de producto:** cambiar el handler para que llame `abrirItemModal(prod)` en vez de `addToCart`.
- **Línea del carrito:** al **tocar** la línea (no los botones ±/✕ ni el swipe) → `abrirItemModal(state.cart[idx], idx)`.
- **renderCart / total de línea:** el precio de línea = `qty × (precio + Σ modificadores)` menos el descuento del ítem; mostrarlo. La nota y los modificadores se muestran como sub-línea (ej. "+ Tocino · Nota: sin cebolla").
- **Descuento global** (pie): botón **"Descuento"** junto al total → mini-modal toggle %/S/ + valor → guarda `state.descuento = {tipo,valor}`; el total mostrado y el COBRAR lo restan. `cartTotal()` pasa a: subtotal (Σ líneas) − descuento global.
- **Nota general del pedido** (pie): una cajita/textarea `state.notas`.
- **Cobro:** antes de confirmar, una sección de **comprobante** (Ticket / Boleta / Factura) y, si Boleta/Factura, campos de **cliente**: tipo (DNI/RUC), número, nombre/razón social, con un botón **"Buscar"** **inerte** (deshabilitado o con `title="Próximamente (RENIEC/SUNAT)"`). `registrar_venta` recibe: `items` (JSON con modificadores/nota/descuento), `descuento_tipo`,`descuento_valor`, `cliente_*`, `comprobante_tipo`, `notas_pos`, `metodo_pago`, `ubicacion_id`, `turno_id`.

- [ ] **Step 1:** Implementar lo anterior en `pos/terminal.php`.
- [ ] **Step 2:** `php -l pos/terminal.php` + JS syntax check (extraer `<script>`, neutralizar `<?= ?>`, `node --check`).
- [ ] **Step 3:** Commit — `git add pos/terminal.php && git commit -m "feat(pos f2): modal de ítem (modificadores/nota/descuento), descuento global, nota general, cliente+comprobante"`

---

## Tarea 3: Favoritos editables (tablero) en el terminal

**Files:** Modify `pos/terminal.php`.

- [ ] **Step 1:** Agregar una vista/pestaña **"Favoritos"** en el panel de productos (toggle con "Todos"/categorías). Renderiza una **cuadrícula de celdas** (ej. 4×N). Carga con `apiGet('favoritos',{ubicacion_id})`. Cada celda con producto muestra el producto (tap → abre el modal de ítem como cualquier producto). Celdas **vacías** muestran un "+" para **asignar** (abre un buscador/selección de producto → `fav_set(ubicacion_id, producto_id, posicion)`). Un modo "editar" permite **quitar** (`fav_clear`) y reasignar. Se respetan los **huecos** (cada celda es una `posicion`; las vacías no se compactan).
- [ ] **Step 2:** `php -l` + JS check.
- [ ] **Step 3:** Commit — `git add pos/terminal.php && git commit -m "feat(pos f2): tablero de favoritos editable (celdas con huecos) en el terminal"`

---

## Tarea 4: Ticket imprimible

**Files:** Create `pos/ticket.php`.

- [ ] **Step 1:** Crear `pos/ticket.php?id=PEDIDO` — `requireLogin()`; lee el `pedido` (origen=pos) + su `items_json`; renderiza un **ticket angosto (~58/80mm)** con: marca, nº de pedido, fecha/hora, cajero/ubicación, ítems (qty, nombre, modificadores, nota, precio de línea), descuentos (ítem y global), total, método de pago, y datos de cliente/comprobante si los hay. CSS `@media print` para 58mm (como el patrón "HTML imprimible" del proyecto). Botón "Imprimir" (oculto en print). Tras una venta, el terminal puede abrir `pos/ticket.php?id=` en una ventana para imprimir (opcional, vía botón "Imprimir ticket" en la confirmación).
- [ ] **Step 2:** `php -l pos/ticket.php`.
- [ ] **Step 3:** En el terminal, en la confirmación de venta, agregar botón **"Imprimir ticket"** que abre `pos/ticket.php?id=<id>`. Commit — `git add pos/ticket.php pos/terminal.php && git commit -m "feat(pos f2): ticket imprimible 58mm + botón imprimir tras la venta"`

---

## Verificación final de la fase
- [ ] `php -l` en `api/pos.php`, `pos/terminal.php`, `pos/ticket.php`; JS check del terminal.
- [ ] Revisión de seguridad de `api/pos.php` (CSRF, prepared statements, total server-side).
- [ ] (Humano) Vender: tocar producto → modal con modificadores/nota/descuento → agregar; tocar línea → reabre y edita; descuento global; nota general; cobrar con Boleta + cliente DNI; el pedido llega al KDS con sus modificadores y nota; imprimir ticket; arqueo cuadra con descuentos.
- [ ] Favoritos: asignar productos a celdas, dejar huecos, usarlos para vender.
- [ ] Aislamiento: solo `api/pos.php`, `pos/terminal.php`, `pos/ticket.php` modificados/creados.

## Pendiente (sus propios planes)
- **F3:** Historial de caja (admin) + Clientes POS + filtro de **origen** en `admin/pedidos`.
- **F4:** Monitor de ventas en vivo (día). **F5 (futura):** SUNAT/RENIEC reales.
