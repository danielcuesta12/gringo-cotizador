# Mesas POS — Sub-build B: Cuentas & Mozo

**Fecha:** 2026-06-18
**Módulo:** POS de restaurante — mesas (parte 2 de 3)
**Estado:** diseño aprobado, pendiente de plan de implementación

## Contexto y decomposición

Continúa el POS de restaurante. **Sub-build A** (ya en producción) entregó: tablas `mesa_pisos`/`mesas`/`mesa_elementos`, el editor de plano interactivo multi-piso, el render reutilizable (`assets/js/plano-render.js`) y el tablero (`admin/mesas/tablero.php`) con el hook `onMesaTap`.

Sub-build B agrega la **operación de salón**: abrir una cuenta en una mesa, una **app del mozo** (login por PIN) para tomar pedidos, y que cada "enviar a cocina" cree una **comanda** (ticket KDS) ligada a la cuenta. Aquí se encienden los **estados de color** del plano.

Las 3 partes: **A — Mesas & Plano (hecho)** · **B — Cuentas & Mozo (este spec)** · **C — Precuenta, Split & Cobro**.

## Decisiones tomadas (brainstorming, validadas con mockups)

1. **Modelo:** cuenta abierta por mesa → cada "enviar a cocina" crea una **comanda = un `pedido`** (ticket KDS con su timer). La cuenta agrupa sus comandas y suma el total.
2. **App del mozo:** página móvil dedicada (PWA), **login por PIN** validado contra `empleados.pin_hash` (no usa el sistema de permisos de `users`; es PIN de empleado, como Asistencia).
3. **Operaciones del mozo en B:** abrir cuenta (con nº de comensales) · tomar pedido (catálogo + modificadores + nota + cantidad, como borrador) y enviar comanda al KDS · ver cuenta corriente con total · anular ítem/comanda con motivo. (Transferir/juntar mesas → futuro.)
4. **Toma de pedido:** el mozo arma un **borrador** y solo al "Enviar a cocina" se crea la comanda (la siguiente ronda). El pie del modal de producto va en **2 filas** (cantidad arriba, botón "Agregar" a fila completa); mismo criterio en el pie de la cuenta y del borrador.
5. **Anulación:** solo se puede anular mientras la comanda **no esté "Listo"** (estados `pendiente`/`en_preparacion`). Una vez que cocina la marca Listo, no se anula ("no se puede descocinar"). Como el inventario se descuenta al marcar Listo, anular-antes-de-Listo **nunca toca stock** (sin lógica de reversa).
6. **Frontera con el dinero:** las comandas de mesa (`origen='mesa'`) NO son venta hasta que se cobran (C). Dashboard y arqueo del POS **excluyen `origen='mesa'`** de los ingresos. La pestaña **EN VIVO** (monitor) muestra aparte **"Mesas abiertas (sin cobrar): S/ X"** = suma de cuentas abiertas.

## B.1 — Modelo de datos

### `cuentas` (la cuenta abierta de una mesa)
```sql
id            INT UNSIGNED PK AUTO_INCREMENT
mesa_id       INT UNSIGNED NOT NULL
ubicacion_id  INT UNSIGNED NOT NULL
empleado_id   INT UNSIGNED NULL          -- mozo que abrió (de empleados)
num_comensales INT NOT NULL DEFAULT 0
estado        ENUM('abierta','cerrada','cancelada') NOT NULL DEFAULT 'abierta'
total         DECIMAL(10,2) NOT NULL DEFAULT 0   -- cacheado (suma de ítems no anulados)
abierta_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
cerrada_at    DATETIME NULL
KEY (mesa_id), KEY (ubicacion_id), KEY (estado)
```
En B solo se usan `abierta` y `cancelada` (cuenta vacía cancelada). `cerrada` (pagada) llega en C. **Invariante:** una mesa tiene como mucho **una** cuenta `abierta` a la vez.

### `pedidos` (la comanda — tabla existente, columnas nuevas)
- `cuenta_id` INT UNSIGNED NULL — la cuenta a la que pertenece la comanda (NULL si no es de mesa).
- `mesa_id` INT UNSIGNED NULL — denormalizado para el KDS.
- `origen` ENUM suma el valor **`'mesa'`** (antes `'carta','pos'`).
- Cada comanda nace `estado='en_preparacion'` (el mozo es quien la manda, entra directo al KDS). `items_json` como hoy; cada ítem puede llevar `anulado:true` + `anul_motivo`.

### `cuenta_anulaciones` (registro de voids)
```sql
id          INT UNSIGNED PK AUTO_INCREMENT
cuenta_id   INT UNSIGNED NOT NULL
pedido_id   INT UNSIGNED NOT NULL
item_idx    INT NULL                 -- índice del ítem en items_json; NULL = comanda completa
motivo      VARCHAR(160) NOT NULL
empleado_id INT UNSIGNED NULL
created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
KEY (cuenta_id), KEY (pedido_id)
```

**Total de la cuenta** = suma de los ítems no-anulados de todas sus comandas no-canceladas. Se recalcula y cachea en `cuentas.total` tras cada cambio.

## B.2 — App del mozo (`mozo/`) + auth

PWA móvil (manifest + sw, instalable como POS/KDS). Pantallas: **PIN → Plano (estados en vivo) → Cuenta → Catálogo/Borrador**.

**Login por PIN:** el mozo escribe su PIN (4 dígitos) → `api/mozo.php?action=login_pin` valida server-side contra `empleados.pin_hash` (empleado `activo=1`; opcionalmente filtrado por `ubicacion_id` del dispositivo). Al validar, se crea una **sesión de mozo** en el dispositivo (sesión PHP con `mozo_empleado_id` + `mozo_ubicacion_id`), con re-PIN al expirar. Toda acción de `api/mozo.php` (salvo `login_pin`) exige esa sesión.

**Selección de local:** el dispositivo elige el local una vez (o viene en la URL); la app opera sobre ese `ubicacion_id`.

## B.3 — Tomar pedido → comanda → KDS

Vista de cuenta: header (mesa, comensales, total corriente) + comandas agrupadas por ronda (ítems, con anulados tachados) + **"+ Agregar a la cuenta"**. El catálogo (categorías + búsqueda + modificadores + nota + cantidad) reusa productos/`location_products`/grupos de modificadores (igual que carta y POS). Los ítems se acumulan en un **borrador**; al **"Enviar a cocina"** se crea un `pedido` (`origen='mesa'`, `cuenta_id`, `mesa_id`, `estado='en_preparacion'`, `items_json`) → aparece en el KDS con badge **MESA N · Ronda R** (R = nº de comandas de la cuenta). El KDS reusa timer/gestos/inventario/alertas; solo agrega el badge de mesa y la etiqueta de ronda.

**Abrir cuenta:** tocar una mesa libre → modal de nº de comensales (opcional) → `abrir_cuenta` crea la cuenta `abierta` y la mesa pasa a "ocupada". Tocar una mesa ocupada → abre su cuenta. Una cuenta abierta sin comandas puede **cancelarse** (`cerrar_cuenta_vacia`) y la mesa vuelve a libre.

## B.4 — Anulaciones (void)

El mozo anula **un ítem** o **una comanda completa**, con **motivo** (chips: "cliente lo rechazó", "error del mozo", "otro…"). Reglas:
- **Solo permitido si la comanda NO está `listo`/`entregado`/`cancelado`** (es decir, `estado IN ('pendiente','en_preparacion')`). Si ya está Listo, el botón anular no aparece / la API lo rechaza.
- Ítem anulado → se marca en `items_json` (`anulado:true`, `anul_motivo`); el total de la cuenta lo excluye; el KDS lo muestra tachado.
- Comanda completa → `pedidos.estado='cancelado'` (sale del KDS).
- Cada anulación se registra en `cuenta_anulaciones` (cuenta, pedido, item_idx|NULL, motivo, empleado, hora).
- Como el stock se descuenta recién al marcar Listo, anular-antes-de-Listo no requiere revertir inventario.
- Gate server-side: solo el mozo dueño de la cuenta (o admin) puede anular.

## B.5 — Estados del plano en vivo

`api/mozo.php?action=plano_estados&ubicacion_id=N` → `{estados:{mesaId:'ocupada'|'libre'}, montos:{mesaId:total}}`. El plano (reusa `plano-render.js`) se refresca por poll (~5s). En B: **libre** (sin cuenta abierta) · **ocupada** (cuenta abierta, con o sin comandas). `precuenta`/`por_cobrar` se activan en C.

## B.6 — Frontera con el dinero / arqueo

- Las comandas `origen='mesa'` **no cuentan como venta** hasta que C las cobra (ahí se les asigna `turno_id`, método de pago, comprobante y entran al arqueo).
- **Dashboard** (`admin/dashboard.php`) y **arqueo del POS** (`api/pos.php` `cerrar_turno`, y `pos_monitor`): agregar filtro `origen <> 'mesa'` donde hoy suman `pedidos` como ingreso POS, para no inflar ventas con cuentas abiertas.
- **EN VIVO** (`admin/pos/monitor.php`): mostrar aparte **"Mesas abiertas (sin cobrar): S/ X"** = `SUM(cuentas.total WHERE estado='abierta' AND ubicacion_id=?)`, separado de las ventas realizadas.
- **Inventario:** se descuenta al marcar Listo en el KDS, igual que cualquier pedido (consumo real, independiente del cobro).

## B.7 — Archivos, permisos, integración

- Migración `install/57_cuentas.sql`: crea `cuentas`, `cuenta_anulaciones`; `ALTER pedidos ADD cuenta_id, mesa_id`; amplía `pedidos.origen` con `'mesa'` (guards de columna; `MODIFY` del ENUM). Fila en `check_migraciones.sql`.
- `includes/cuentas.php`: lógica compartida — `cuentaListo()`, `cuentaAbrir`, `cuentaDetalle`, `cuentaTotalRecalc`, `cuentaAnular`, `mesaEstados($ubicacionId)`, `comandaEnviar`.
- `mozo/index.php` (app) + `mozo/manifest.php` + reusar `sw.js` (PWA instalable).
- `api/mozo.php`: `login_pin`, `plano_estados`, `abrir_cuenta`, `cuenta`, `menu`, `enviar_comanda`, `anular`, `cerrar_cuenta_vacia`. Sesión de mozo (no `requireLogin`); `verifyCsrf()` en escrituras vía header.
- KDS: `api/kds_pedidos.php` enriquece la comanda con `mesa` (numero) y ronda; `admin/kds/index.php` dibuja el badge MESA N · Ronda. Las comandas `origen='mesa'` nacen `en_preparacion` (entran directo, sin gating de WhatsApp).
- Dashboard/arqueo/monitor: cambios de B.6.

## Convenciones / seguridad
- PHP puro + PDO, prepared statements (`?`); `verifyCsrf()` en escrituras; sanitizar con `clean*()`.
- App del mozo: gate por **sesión de mozo** (PIN), no por permiso de `users`. PIN validado server-side; nunca exponer `pin_hash`.
- Multi-local por `ubicacion_id`. Marca: negro `#1E1E1E`, amarillo `#FFDF00`, rosa `#FFBBC8`, crema `#FFEFBC`. Mobile-first (la app vive en el celular del mozo).
- Sin framework de tests: verificación = `php -l` + `node --check` + checklist funcional + SQL.

## Fuera de alcance de B (va en C)
Precuenta imprimible, los 4 modos de split, cobro por parte con método/comprobante, cierre de cuenta (`estado='cerrada'`), asignación de `turno_id`/método/comprobante a la cuenta, y su entrada al arqueo.
