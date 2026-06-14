# Facturación de pedidos de la carta + multi-local — Diseño

> Estado: **IMPLEMENTADO** (6 fases). Fecha: 2026-06-14.
> Resume toda la conversación de diseño. Es la fuente de verdad para construir.

## Objetivo

Cerrar el círculo fiscal de la **operación diaria**: que **todo** pedido (POS directo + carta online vía Izipay/WhatsApp) pueda emitir su comprobante electrónico (ticket / boleta / factura) de forma ordenada, con el **cajero como único punto de control fiscal**, sin duplicación de registros ni de dinero, y funcionando con **varios locales** (cada uno con su propia serie de comprobantes).

---

## Decisiones tomadas (resumen)

1. **El cajero centraliza la facturación.** Los pedidos de la carta caen, además del KDS, en una **bandeja dentro del POS** del local correspondiente, **pre-cargados** con los datos del cliente. El cajero decide qué emitir (ticket/boleta/factura) y completa datos si faltan.
2. **No frenar la cocina por el trámite fiscal.**
   - **Izipay (ya pagado):** entra **directo al KDS** y aparece en la bandeja para emitir en paralelo.
   - **WhatsApp (no pagado):** entra a la bandeja como **"por aceptar"**; el cajero lo acepta → recién pasa al KDS. El comprobante se emite al confirmar el pago (al entregar) o manualmente.
3. **Una venta = una sola fila** en `pedidos`. Las pantallas (KDS, bandeja POS, admin/pedidos, Historial) son **vistas filtradas** de la misma fila. El comprobante es un **atributo** de la fila (`comprobante_estado`), nunca un registro nuevo. → **Cero duplicación.**
4. **El dinero se cuenta una sola vez.** La venta de carta cuenta como carta (mundo "operación"); emitir su comprobante desde el POS **no la suma al arqueo de efectivo** del cajero (el arqueo mide solo el cajón).
5. **Historial unificado.** Al resolver un pedido de carta, pasa al Historial junto a las ventas POS, con **badge de origen** (carta + método Izipay/WhatsApp). El arqueo sigue siendo solo POS-efectivo.
6. **Ruteo por elección de tienda.** Al entrar a la carta sin slug, el cliente **elige el local**. El local elegido cocina (KDS) y factura (bandeja POS). Los QR por local siguen llevando su slug directo (se saltan el selector).
7. **Serie de comprobantes por local.** Cada `ubicacion` maneja su propia `serie_boleta`, `serie_factura` y correlativos independientes, con incremento atómico. Si un local no define serie, cae al global de Facturación (compatibilidad).

---

## Flujo completo (end-to-end)

```
Cliente entra a la carta
   ├─ con slug (QR de local)  → carta de ese local directo
   └─ sin slug (link genérico)→ SELECTOR DE TIENDA → carta del local elegido
        (elección recordada en localStorage; botón "cambiar tienda")

Cliente arma pedido → checkout:
   - datos de entrega (ya existen)
   - NUEVO: comprobante deseado (boleta/factura), documento, correo,
            RUC+razón social si factura (autocompletado RENIEC/SUNAT)
   - paga:
        Izipay  → pedido estado='en_preparacion' (pagado)
        WhatsApp→ pedido estado='pendiente'        (no pagado)

Pedido (1 fila en `pedidos`, con su ubicacion_id) aparece en:
   - KDS:
        Izipay   → directo (a cocinar)
        WhatsApp → solo tras "aceptar" del cajero
   - Bandeja POS del local (ubicacion_id = local del cajero):
        lista pre-cargada de pedidos de carta del local

Cajero en la bandeja:
   - (WhatsApp) Aceptar → manda a KDS
   - Elegir ticket/boleta/factura, completar/corregir datos
   - Emitir (NubeFact, serie del local) + imprimir
   - El pedido pasa a "atendido" → entra al Historial con badge

Envío al cliente (híbrido, ya existe): NubeFact manda PDF+XML oficial +
   correo de marca con enlace al PDF.
Reintento de fallidos (pendiente/error): botón Reintentar (ya existe).
```

---

## Cambios por módulo / archivo

### 1. Carta pública — selector de tienda
- **`carta/index.php`** (o un nuevo `carta/selector.php`): si la URL no trae slug de local, mostrar el **selector de tienda** (ver mockup). Lista `ubicaciones` activas con: nombre, **referencia/zona** (campo nuevo), estado **Abierto/Cerrado** (`ubicacionAbierta()`), y enlace a su carta.
  - Recordar elección en `localStorage` (con opción "cambiar tienda").
  - QR con slug → salta el selector (comportamiento actual).
- **`admin/locations`**: agregar campo **referencia/zona** por ubicación (texto corto que ve el cliente, ej. "Lince / San Isidro").

### 2. Carta pública — datos de comprobante en el checkout
- **`carta/index.php`** (checkout) + **`api/pedido.php`**: capturar y guardar
  `comprobante_tipo` (boleta/factura/ninguno), `cliente_documento`, `cliente_nombre`,
  `cliente_razon_social`, `cliente_email`. Reusar la **consulta RENIEC/SUNAT**
  (`includes/consulta_doc.php`) para autocompletar nombre/razón social.
- `api/pedido.php` hoy NO guarda estos campos → ampliar el INSERT.

### 3. Bandeja de pedidos en el POS (nueva pestaña)
- **`pos/terminal.php`**: nueva pestaña **"Pedidos"** en la barra inferior (al lado de Vender/Caja/Historial/Clientes). Reusa el patrón `showPanel('pedidos')`.
  - Lista pedidos `origen='carta'` del `ubicacion_id` del cajero, **pendientes de atender**.
  - Cada fila: nombre, ítems, total, método (Izipay/WhatsApp), datos de comprobante si vinieron, estado (por aceptar / aceptado).
  - Acciones: **Aceptar** (WhatsApp → KDS), **Emitir comprobante** (abre el modal de cobro/comprobante **pre-cargado**), **Imprimir/Ver**.
- **`api/pos.php`**: nuevas acciones
  - `pedidos_carta` (read): pedidos de carta del local pendientes de atender.
  - `aceptar_pedido` (write): WhatsApp pendiente → en_preparacion (entra al KDS).
  - `atender_pedido` / reusar `emitir` + marcar atendido (write): asociar `turno_id`, emitir comprobante.
- El **modal de comprobante** del POS se reutiliza, pre-rellenando desde el pedido.

### 4. Motor NubeFact — serie por local
- **`includes/nubefact.php`** (`nubefactEmitir`): leer `serie_boleta`/`serie_factura`
  y correlativo del `ubicacion_id` del pedido. **Incremento atómico** (transacción)
  del correlativo por local. Fallback al global si el local no tiene serie.
- **`admin/locations`** (o `admin/facturacion`): configurar por local
  `serie_boleta`, `serie_factura`, `num_boleta`, `num_factura`.

### 5. KDS — gating de WhatsApp
- **`admin/kds`** / **`api/kds_*`**: los WhatsApp solo aparecen tras "aceptar".
  (Izipay sigue entrando directo.) Verificar el filtro de estados.

### 6. Historial unificado
- **`pos/terminal.php`** (`historial_turno` + render): incluir pedidos de carta
  **atendidos** en este turno (los que recibieron `turno_id`), con badge de origen
  y método. El arqueo (`pos_turnos` buckets) **no** cambia.
- **`api/pos.php`** (`historial_turno`): ampliar query para incluir
  `origen='carta' AND turno_id=?` además de `origen='pos'`.

---

## Migraciones (SQL nuevas)

**`install/multilocal_facturacion.sql`**
```sql
-- Serie y correlativo de comprobantes por local
ALTER TABLE `ubicaciones`
  ADD COLUMN `serie_boleta`  VARCHAR(10) NULL,
  ADD COLUMN `serie_factura` VARCHAR(10) NULL,
  ADD COLUMN `num_boleta`    INT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN `num_factura`   INT UNSIGNED NOT NULL DEFAULT 1,
  -- Referencia/zona que ve el cliente en el selector de tienda
  ADD COLUMN `referencia`    VARCHAR(120) NULL;
```

(Los campos de comprobante/cliente en `pedidos` ya existen; `cliente_email`
viene de `install/nubefact_email.sql`. No se requiere tabla nueva.)

---

## Orden de implementación (fases)

1. **Serie por local** (migración + motor + admin). Base para todo lo fiscal multi-local. Testeable con el POS actual.
2. **Selector de tienda** en la carta (+ campo referencia). Independiente, visible rápido.
3. **Datos de comprobante en el checkout** de la carta (`api/pedido.php` + form).
4. **Bandeja de pedidos en el POS** (pestaña + acciones API + modal pre-cargado).
5. **Gating de WhatsApp en el KDS** (aceptar → cocina).
6. **Historial unificado** (carta atendida + badges).

Cada fase deja el sistema funcionando; se despliega y se prueba antes de la siguiente.

---

## Casos borde considerados

- **Local sin cajero abierto:** la bandeja muestra el backlog del local cuando alguien abra el POS; respaldo siempre disponible con "Emitir" en `admin/pedidos`. (Opcional futuro: modo "auto-emisión" por local para dark kitchens.)
- **Cliente elige tienda lejana:** mitigado con la referencia/zona en el selector; si crece el volumen, se puede añadir ruteo por distrito encima sin rehacer nada.
- **Pedido cancelado antes de pagar (WhatsApp):** nunca se emitió (se emite al confirmar pago) → sin nota de crédito.
- **Concurrencia de correlativo:** series distintas por local + incremento atómico + reintento "número ya usado → avanza" (ya existe).
- **Menús/precios por local:** el selector lleva al cliente a la carta del local elegido desde el inicio, así ve los precios correctos de ese local.
