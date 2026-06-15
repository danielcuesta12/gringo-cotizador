# Carta · Checkout wizard + venta por ambos métodos — Diseño

**Fecha:** 2026-06-15
**Estado:** aprobado en brainstorming, pendiente de plan de implementación

## Objetivo

Rediseñar el checkout de la carta pública (`carta/index.php`) como un **wizard de 3 pasos** unificado para todas las tiendas, permitir que una tienda venda por **WhatsApp e Izipay a la vez** (nuevo modo `ambos`), rediseñar la pantalla de **pedido confirmado** (unificada, según tema día/noche) y garantizar el **reseteo total del estado** después de cada pedido.

## Contexto actual

- `ubicaciones.sales_mode ENUM('menu','whatsapp','izipay')` — un solo método por tienda.
- El checkout de hoy es el modal `#modal-pedido` ("Completa tu pedido") con todo el formulario (nombre, teléfono, entrega, dirección, comentarios, comprobante) y **un** botón final cuyo texto/color dependen de `$salesMode`. La lógica está ramificada con `if ($salesMode === 'izipay')` repartida por todo el archivo (botones del carrito desktop/mobile, modal, JS `confirmarPedido`).
- La captura de **comprobante** (boleta/factura + DNI/RUC + nombre/razón + correo) ya vive en ese formulario y NO está gateada por modo. Los datos ya se guardan en el pedido vía `compPayload` (se envían a `api/pedido.php` en ambos flujos).
- Pantalla de confirmación: solo existe para Izipay (`#modal-confirmado`, full-screen negra). El flujo WhatsApp no tiene cierre visual: abre `wa.me` y nada más.

### Bugs confirmados a resolver (reseteo de estado)

1. **WhatsApp no vacía el carrito:** `confirmarPedido()` en su rama WhatsApp guarda el pedido, cierra el modal y abre WhatsApp, pero **nunca llama a `vaciarCarrito()`** → carrito y productos seleccionados quedan pegados. (Izipay sí limpia vía `vaciarYVolver()`.)
2. **El formulario no se resetea (ambos métodos):** los campos (nombre, teléfono, dirección, comentarios, comprobante + documento + razón + correo) nunca se limpian → se repiten los datos del pedido anterior.

`vaciarCarrito()` (líneas ~1233) ya limpia bien carrito + badges + contadores de productos; el problema es que WhatsApp no lo llama y nadie limpia el formulario.

## Decisiones de diseño

### 1. Modo de venta `ambos`

- `ubicaciones.sales_mode` pasa a `ENUM('menu','whatsapp','izipay','ambos')`. Migración en `install/`. Lo existente intacto.
- `admin/locations/form.php`: 4ª opción **"Ambos (WhatsApp + Izipay)"** en el `<select name="sales_mode">` (validación del POST acepta el nuevo valor). Validación: requiere `whatsapp_number` **y** que Izipay esté configurado (`izipayConfigured()`); si falta uno, aviso y se guarda con el que aplique.

### 2. Checkout wizard (mismo flujo para todas las tiendas)

Reemplaza el modal `#modal-pedido` por un wizard de 3 pasos:

- **Paso 1 · Tus datos:** nombre*, teléfono*, entrega (delivery/recojo en tarjetas segmentadas), dirección* (solo delivery), comentarios.
- **Paso 2 · Comprobante (opcional):** selector Ninguno/Boleta/Factura + DNI/RUC + nombre/razón + correo. Con botón **"Omitir"**.
- **Paso 3 · Pago:** mini-resumen (ítems + total) y los botones de pago **según `sales_mode`**:
  - `whatsapp` → un botón verde "Pedir por WhatsApp".
  - `izipay` → un botón negro "Pagar con tarjeta".
  - `ambos` → los dos botones (tarjeta arriba, "O", WhatsApp abajo).
  - `menu` → no aplica (sigue redirigiendo a `/menu`).

Barra superior fija: botón atrás, título del paso, **total siempre visible**, barra de progreso de 3 tramos, "Paso N de 3".

**Layouts:**
- **Móvil:** wizard a pantalla completa / bottom-sheet (donde hoy está `#modal-pedido`).
- **Escritorio:** el wizard vive en el **panel derecho** (`carrito-desktop`), con la carta a la izquierda sin cambios. El panel va cambiando de paso.

La lógica de pago deja de leer `SALES_MODE` global; el paso 3 decide qué botón(es) pintar a partir de `sales_mode`, y cada botón dispara su flujo (Izipay: `iniciarPago()`; WhatsApp: arma mensaje + `wa.me` + guarda pedido).

### 3. Pantalla de pedido confirmado (unificada)

Reemplaza `#modal-confirmado`. Una sola pantalla para los dos métodos:

- **Hero** + titular según método: WhatsApp → "¡Pedido enviado!"; tarjeta → "¡Pago confirmado!".
- **Código de pedido** prominente (#NNN).
- **"Qué sigue":**
  - WhatsApp: mensaje "envíaselo a la tienda para confirmar" + botón **Abrir WhatsApp** (fallback si el navegador bloqueó la pestaña) + nota de que la tienda confirma por el chat.
  - Tarjeta: "¡Pago recibido! Estamos preparando tu pedido" + tarjeta terminada en ••NNNN.
- **Mini-resumen:** entrega + dirección, ítems, total. Chip de comprobante si se pidió.
- Botón **"Hacer otro pedido"** → dispara el reseteo total y vuelve a la carta.
- **Fondo según tema día/noche:** usa las variables de tema existentes de la carta (noche = oscuro/negro, día = claro).
- **Layout:** móvil = pantalla completa; escritorio = tarjeta centrada sobre fondo oscurecido.

### 4. Reseteo total de estado (`resetPedido()`)

Una sola función, llamada al **completar** un pedido (al cerrar/"Hacer otro pedido" de la confirmación) en **ambos** flujos:

1. **Carrito:** `carrito = {}` + contadores y badges de productos a 0 (reusar `vaciarCarrito()`).
2. **Carta:** quitar estado `in-cart`/`selected` de tarjetas y modificadores.
3. **Formulario/wizard:** vaciar nombre, teléfono, dirección, comentarios y bloque de comprobante; reiniciar entrega a su default y el wizard al **paso 1**.
4. **Interno:** `_pedidoData = null` y cerrar cualquier modal/overlay abierto.

### 5. Comprobante en el flujo WhatsApp

- Los datos de comprobante ya se persisten en el pedido (vía `compPayload`) → el cajero los ve en la **bandeja del POS** y emite desde ahí (sin cambios en backend más allá de verificar `api/pedido.php`).
- **Agregar** los datos de comprobante al **texto del mensaje de WhatsApp** (tipo + documento + nombre/razón) para que la tienda los reciba en el chat.

## Qué NO cambia

Display de la carta (productos, categorías, fotos, búsqueda, tema día/noche, modal de detalle/modificadores), KDS, NubeFact, arqueo del POS, modos `menu/whatsapp/izipay` existentes, gating de WhatsApp en KDS (pedido `pendiente` hasta que el cajero acepta).

## Archivos afectados (estimado)

- `install/NN_sales_mode_ambos.sql` — ALTER del enum (nuevo).
- `admin/locations/form.php` — 4ª opción + validación.
- `carta/index.php` — wizard (HTML + CSS + JS), botones de pago por modo, pantalla de confirmación unificada, `resetPedido()`, comprobante en mensaje WhatsApp. (Es el grueso del trabajo.)
- `api/pedido.php` — verificar que acepta los campos de comprobante en pedidos de carta (probablemente ya).

## Manejo de errores / bordes

- Tienda `ambos` con Izipay no configurado en runtime (`IZ_ENABLED` falso): el paso 3 oculta el botón de tarjeta y deja solo WhatsApp (no romper).
- WhatsApp con pestaña bloqueada: la pantalla de confirmación ofrece "Abrir WhatsApp" (ya hay fallback a `window.location.href`).
- Izipay: pago rechazado mantiene el modal de error actual; el reseteo solo ocurre tras confirmación exitosa.
- Validación de campos requeridos por paso antes de permitir "Continuar" (nombre/teléfono/entrega/dirección en paso 1).

## Pruebas

Como ninguna tienda está vendiendo aún, se valida en una instancia/rama de prueba:
- Cada `sales_mode` (`whatsapp`, `izipay`, `ambos`, `menu`) muestra el paso de pago correcto.
- Wizard navega adelante/atrás, valida requeridos, total siempre visible, móvil y escritorio.
- Pedido por WhatsApp e Izipay: se guarda en BD, llega a KDS/POS según corresponde, comprobante persiste y aparece en bandeja.
- Mensaje de WhatsApp incluye comprobante cuando se pidió.
- Tras completar (ambos métodos): carrito vacío, productos deseleccionados, formulario limpio, wizard en paso 1.
- Confirmación respeta tema día/noche.
