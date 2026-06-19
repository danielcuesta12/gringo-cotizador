# Mesas POS — Sub-build C: Precuenta, Split & Cobro

**Fecha:** 2026-06-19
**Módulo:** POS de restaurante — mesas (parte 3 de 3)
**Estado:** diseño aprobado, pendiente de plan de implementación

## Contexto y decomposición

Cierra el POS de restaurante de mesas. Las 3 partes:
- **A — Mesas & Plano** (en producción): `mesa_pisos`/`mesas`/`mesa_elementos`, editor de plano, render reutilizable (`assets/js/plano-render.js`), tablero.
- **B — Cuentas & Mozo** (en producción): tabla `cuentas`, app del mozo (login por PIN), cada "enviar a cocina" crea una **comanda** = un `pedido` (`origen='mesa'`, `cuenta_id`, `mesa_id`), KDS con badge MESA N · Ronda, geocerca dura, frontera con el dinero (las comandas no son venta hasta cobrarse).
- **C — Precuenta, Split & Cobro** (este spec): imprimir precuenta no fiscal, dividir la cuenta en 4 modos, cobrar cada parte con pago **mixto** y comprobante opcional, cerrar la cuenta y que el dinero entre al **arqueo** y al **dashboard**.

## Decisiones tomadas (brainstorming, validadas)

1. **Quién cobra / arqueo:** el **mozo cobra desde su app**. El dinero entra al **turno de caja abierto del local** (el del cajero). Si no hay caja abierta → se **bloquea** ("No hay caja abierta en el local"). Si hay 2+ turnos abiertos en el local → el mozo **elige** de una lista corta.
2. **4 modos de split:** Todo junto · Partes iguales (entre N) · Por ítems · Por montos libres. Cada parte se cobra por separado; la cuenta se cierra cuando **todas** las partes están pagadas.
3. **Pago mixto:** cada parte (o la cuenta completa) admite **varias líneas de pago** (método + monto) que sumen el monto de la parte (ej. S/40 efectivo + S/30 tarjeta + S/20 Yape). Cada línea entra a su bucket del arqueo.
4. **Comprobante por parte:** cada parte puede pedir ticket / boleta / factura con su propio cliente (DNI/RUC con autocompletado RENIEC/SUNAT). Se emite por NubeFact reusando la maquinaria existente.
5. **Precuenta:** resumen no fiscal imprimible (RawBT ESC/POS), no bloquea la cuenta, marca la mesa en estado "precuenta" (rosa).
6. **Descuento:** opcional, global sobre la cuenta, aplicado **antes** de partir (como el POS).
7. **Marca editable:** toda superficie nueva usa variables de `brandHead()` (ya enganchada en el mozo), nunca hex hardcodeado.

## Separación de conceptos (invariante central)

| Concepto | Dónde vive | Cuenta como dinero | Notas |
|---|---|---|---|
| **Consumo** | comandas (`pedidos origen='mesa'`, de B) | NO | items reales, alimentan KDS + inventario |
| **Dinero** | `cuenta_pagos` (nuevo) | **SÍ** (fuente de verdad arqueo/dashboard) | soporta mixto y split |
| **Fiscal** | pedido-comprobante por parte (`pedidos origen='mesa'`) | NO | reusa `nubefactEmitir`, escpos QR, historial, reintentar |

- `cuentas.total` = **consumo** (suma de ítems no anulados). El monto **cobrado** (con descuento) vive en `cuenta_pagos`.
- El arqueo y el dashboard leen **`cuenta_pagos`** (lo realmente cobrado), no `cuentas.total`.
- El monitor "Mesas abiertas (sin cobrar)" sigue usando `cuentas.total` de cuentas `abierta` (consumo en curso).

## C.1 — Modelo de datos

### `cuenta_pagos` (nuevo — libro de pagos de mesas)
```sql
id           INT UNSIGNED PK AUTO_INCREMENT
cuenta_id    INT UNSIGNED NOT NULL
ubicacion_id INT UNSIGNED NOT NULL
turno_id     INT UNSIGNED NULL          -- turno de caja del local al cobrar
parte_num    SMALLINT NOT NULL DEFAULT 1 -- nº de parte del split (1..N)
metodo_pago  VARCHAR(60) NOT NULL        -- nombre del método (de pos_metodos_pago)
tipo         ENUM('efectivo','tarjeta','qr','otros') NOT NULL DEFAULT 'otros'
monto        DECIMAL(10,2) NOT NULL
empleado_id  INT UNSIGNED NULL           -- mozo que cobró
comprobante_pedido_id INT UNSIGNED NULL  -- pedido-comprobante de esa parte (si pidió boleta/factura/ticket con datos)
created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
KEY (cuenta_id), KEY (ubicacion_id), KEY (turno_id), KEY (parte_num)
```
`tipo` se denormaliza desde `pos_metodos_pago.tipo` al momento del cobro (para que el arqueo no dependa de un JOIN a un método que pudo cambiar/desactivarse).

### `cuentas` (columnas nuevas, con guards de columna)
- `precuenta_at`   DATETIME NULL — cuándo se imprimió la precuenta (estado rosa).
- `descuento_tipo` ENUM('porcentaje','monto') NULL — descuento global al cobrar.
- `descuento_valor` DECIMAL(10,2) NOT NULL DEFAULT 0
- `descuento_monto` DECIMAL(10,2) NOT NULL DEFAULT 0 — monto efectivo del descuento aplicado.
- `cobrada_at`     DATETIME NULL — cuándo quedó totalmente pagada.

El `estado` ENUM ya incluye `'cerrada'` (de B). En C: `cerrada` = pagada. **Invariante:** una mesa tiene como mucho una cuenta `abierta` a la vez (de B).

### `pedidos` (pedido-comprobante por parte)
Reusa columnas existentes (de POS/comprobante): `origen='mesa'`, `cuenta_id`, `mesa_id`, `turno_id=NULL`, `items_json`, `total`, `comprobante_tipo`, `cliente_*`, `comprobante_serie/numero/estado/...`. Estado `'entregado'`. **No** se suma al arqueo (turno NULL + guard `origen<>'mesa'`).

### Migración
`install/58_cobro_mesas.sql`: crea `cuenta_pagos`; `ALTER cuentas ADD precuenta_at, descuento_tipo, descuento_valor, descuento_monto, cobrada_at` (con guards `IF NOT EXISTS` por columna, patrón del repo). Fila en `install/check_migraciones.sql`.

## C.2 — Precuenta (no fiscal)

- Acción `precuenta` (mozo): genera el resumen de la cuenta (mesa, comensales, comandas no anuladas con sus ítems, total) y lo imprime por RawBT ESC/POS reusando `pos/escpos.php` con una **rama no-fiscal a partir de la cuenta** (parámetro `cuenta_id` en vez de `id` de pedido). Cabecera **"PRE-CUENTA"** + pie **"No válido como comprobante de pago"**. Sin QR, sin IGV desglosado.
- Setea `cuentas.precuenta_at = NOW()` → la mesa pasa a estado **precuenta** (rosa) en `mesaEstados`.
- **No bloquea:** se pueden seguir agregando ítems / reimprimir la precuenta. Si se agregan ítems después, la precuenta se puede reimprimir con el nuevo total.

## C.3 — Split (4 modos) → partes

Al iniciar el cobro el mozo elige modo. Sobre el **monto a cobrar** = `cuentas.total − descuento_monto`:
- **Todo junto:** 1 parte = monto total.
- **Partes iguales (N):** `monto / N`, redondeado a 2 decimales; el **resto de centavos se ajusta en la última parte** para que las partes sumen exacto el total.
- **Por ítems:** el mozo asigna cada ítem (de las comandas no anuladas) a una parte. La suma de las partes = monto total. Un ítem no puede quedar sin asignar al confirmar.
- **Por montos libres:** el mozo escribe el monto de cada parte; se **valida server-side** que la suma == monto total (tolerancia de redondeo ±0.01).

El **descuento global** (opcional, porcentaje o monto) se define antes de partir, igual que el POS. Se cachea en `cuentas.descuento_*`.

## C.4 — Cobro de una parte (pago mixto + comprobante)

Por cada parte:
- **Líneas de pago:** 1+ líneas (método de `pos_metodos_pago` + monto). Se valida que **sumen el monto de la parte** (±0.01). Cada línea → fila en `cuenta_pagos` (`tipo` copiado del método, `turno_id` resuelto, `parte_num`, `empleado_id`).
- **Comprobante (opcional):** ticket / boleta / factura. Si boleta/factura (o ticket con datos de cliente), se crea un **pedido-comprobante** (`origen='mesa'`, `cuenta_id`, `mesa_id`, `turno_id=NULL`, `comprobante_tipo`, `cliente_*`, `items_json`, `total`=monto de la parte, `estado='entregado'`) y se llama `nubefactEmitir($pedidoId)`. Su id se guarda en `cuenta_pagos.comprobante_pedido_id` de esa parte. Cliente con autocompletado RENIEC/SUNAT (`api/...consultar_doc`, ya existe).
  - **Ítems del comprobante:** split **por ítems** → los ítems reales de la parte. Modos **iguales / montos libres / todo junto con descuento** → **una línea sintetizada "Consumo en salón"** por el monto de la parte (única forma fiscalmente limpia de partir un consumo sin ítems unívocos).
- **Turno:** al confirmar el cobro se resuelve `turnoAbiertoLocal($ubi)`:
  - 0 turnos abiertos → error `{ok:false, sin_caja:true}` → la app muestra "No hay caja abierta en el local".
  - 1 turno → se usa.
  - 2+ → la API devuelve la lista (`turnos_local`) y la app pide al mozo elegir; el cobro reintenta con `turno_id` explícito.

El cobro es **transaccional** (`cuentaCobrar`): inserta las `cuenta_pagos`, crea y emite los pedidos-comprobante, y si con esta operación **todas las partes quedan pagadas** marca `cuentas.estado='cerrada'`, `cobrada_at=NOW()` y asigna `turno_id` a las comandas de la cuenta (trazabilidad; no entran al arqueo). La emisión NubeFact nunca lanza excepción (patrón existente): un comprobante en error queda reintentable sin romper el cobro.

**Cobro parcial (split en varias tandas):** se permite cobrar partes en momentos distintos. Mientras haya pagos pero falte monto, la mesa queda en estado **por_cobrar** (naranja). La geocerca dura aplica también al cobro.

## C.5 — Estados del plano en vivo

`mesaEstados($ubicacionId)` (en `includes/cuentas.php`) calcula por mesa con cuenta `abierta`:
- **libre:** sin cuenta abierta.
- **ocupada:** cuenta abierta, sin `precuenta_at` y sin pagos → color por tiempo (verde/naranja/rojo, de B).
- **precuenta:** `precuenta_at` seteado y sin pagos aún → **rosa**.
- **por_cobrar:** existe ≥1 fila en `cuenta_pagos` pero la suma de pagos < monto a cobrar → **naranja** (split parcial).

Cuenta `cerrada` → la mesa ya no aparece en `estados` → render como **libre**. `plano-render.js` ya dibuja `libre/ocupada/precuenta/por_cobrar`; no requiere cambios de render.

## C.6 — Arqueo, dashboard, monitor

- **Arqueo (`api/pos.php` `cerrar_turno`):** además del SUM de `pedidos` por `metodo_pago.tipo WHERE turno_id=?`, sumar `cuenta_pagos` por `tipo WHERE turno_id=?` y agregarlo a `total_efectivo/total_tarjeta/total_qr/total_otros`. El efectivo de mesas entra a `caja_esperada`. Añadir guard `AND p.origen <> 'mesa'` al SUM de `pedidos` (defensivo contra doble conteo; las comandas no tienen método de pago de venta).
- **Dashboard (`admin/dashboard.php`, bucket 3 POS):** sumar lo cobrado en mesas = `SUM(cuenta_pagos.monto)` del periodo/tienda, junto a carta+pos. (Las comandas `origen='mesa'` siguen excluidas del SUM de `pedidos`.)
- **Monitor (`admin/pos/monitor.php`):** "Mesas abiertas (sin cobrar)" sin cambios (sigue = `SUM(cuentas.total WHERE estado='abierta')`).

## C.7 — Archivos, API, integración

- **Migración:** `install/58_cobro_mesas.sql` (+ `check_migraciones.sql`).
- **`includes/cuentas.php`** (nuevas funciones):
  - `precuentaData(int $cuentaId, int $ubicacionId): ?array` — datos para imprimir la precuenta.
  - `turnoAbiertoLocal(int $ubicacionId): array` — `['turnos'=>[{id,usuario,abierto_en}], 'count'=>n]` de los `pos_turnos estado='abierto'` del local.
  - `cuentaCobrar(int $cuentaId, int $ubicacionId, ?int $empleadoId, array $payload): array` — transaccional; `$payload` = `{descuento, modo, partes:[{parte_num, monto, pagos:[{metodo,monto}], comprobante:{tipo,cliente...}}], turno_id?}`. Devuelve `['ok','error','sin_caja'?,'cerrada','comprobantes'=>[...]]`.
  - `cuentaPagosArqueo(int $turnoId): array` — `['efectivo'=>..,'tarjeta'=>..,'qr'=>..,'otros'=>..]` para el arqueo.
  - Helper de redondeo para repartir centavos en partes iguales.
- **`api/mozo.php`** (nuevas acciones, todas con sesión de mozo + CSRF en escrituras + geocerca en `cobrar`):
  - `precuenta` (GET/escritura ligera) → marca `precuenta_at` y devuelve datos/printURL.
  - `turnos_local` (GET) → lista de turnos abiertos del local.
  - `cobrar` (POST) → `cuentaCobrar(...)` con `lat/lng` (geocerca).
- **`pos/escpos.php`:** rama no-fiscal por `cuenta_id` (precuenta). El comprobante por parte ya imprime con `id` de pedido (existente).
- **`mozo/index.php`:** botón **Precuenta** en la vista de cuenta; flujo de **Cobro** (sheet: descuento → elegir modo de split → armar partes → por parte: líneas de pago + comprobante con cliente → confirmar). Estados por_cobrar/precuenta reflejados al volver al plano. Marca vía `brandHead()`.
- **`api/pos.php` / `admin/dashboard.php`:** cambios de C.6.

## Convenciones / seguridad

- PHP puro + PDO, prepared statements (`?`); `verifyCsrf()` en escrituras (header en API); sanitizar con `clean*()`.
- App del mozo: gate por **sesión de mozo** (PIN), no por permiso de `users`. Geocerca dura en `cobrar`.
- Multi-local por `ubicacion_id`; toda lectura/escritura scopeada (patrón de B: `(? = 0 OR ubicacion_id = ?)`).
- Comprobantes: serie/correlativo **por local** (fallback global), IGV de settings — `nubefactEmitir` ya lo resuelve. Precios incluyen IGV (el motor desagrega).
- Dinero: `cuenta_pagos` es la única fuente de verdad de lo cobrado en mesas. Nunca sumar `cuentas.total` como venta.
- Marca: variables de `brandHead()` con fallback (negro `#1E1E1E`, amarillo `#FFDF00`, rosa `#FFBBC8`); nunca hex hardcodeado.
- Sin framework de tests: verificación = `php -l` + `node --check` + checklist funcional + SQL.

## Fuera de alcance de C

Transferir/juntar mesas, propinas (tip), reabrir cuenta cerrada, descuento por-parte (solo global en C), y enrolamiento de dispositivos. Quedan como endurecimiento futuro.
