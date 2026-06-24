# Integración PedidosYa → POS · Diseño

**Fecha:** 2026-06-23
**Módulo:** Pedidos / delivery — integración con PedidosYa (Delivery Hero)
**Estado:** diseño aprobado; **construcción BLOQUEADA** hasta tener credenciales + documentación de la API de PedidosYa.

## Contexto y por qué aún no se construye

El usuario quiere que los pedidos de **PedidosYa** (delivery) entren **automáticamente** al sistema, en vez de tener que copiarlos a mano desde la tablet de PedidosYa. La conexión recetas↔pedidos↔stock ya existe (un pedido marcado "Listo" en el KDS descuenta insumos/subrecetas vía `descontarStockPedido`/`recetaConsumo`); falta solo la **puerta de entrada** del delivery.

**No se construye todavía** porque la integración real depende de acceso que solo da PedidosYa: NO hay API self-service. Se necesita el **programa de integración de POS de Delivery Hero** (partnership + certificación + OAuth + webhooks), o un **agregador** (Deliverect/Otter/Cheftools, de pago). Construir el webhook/parser/firma/mapeo **sin su documentación = retrabajo seguro**. Este spec congela las decisiones de diseño para arrancar plan+build apenas lleguen las credenciales.

**Ya hecho (prerrequisito):** se generó y compartió con PedidosYa la **llave PGP pública** (`daniel@elgringo.pe`, RSA4096) que pedían.

## Decisiones tomadas (brainstorming)

1. **Entra a la bandeja como los pedidos de carta/WhatsApp**, para ser aceptados — NO directo al KDS.
2. **Aceptar/Rechazar hablan con PedidosYa** (no es solo un cambio de estado interno): aceptar confirma la orden a su API; rechazar le avisa con motivo.
3. **Ya está pagado** (PedidosYa cobra) → en la bandeja **no hay paso de cobro**.
4. **Boleta simple por defecto:** si el pedido viene sin DNI/RUC, igual sale **boleta simple** (sin documento → "CLIENTE VARIOS", ya soportado por `nubefactEmitir`). Factura solo si el payload trae **RUC de 11 dígitos**.
5. **Comisión de PedidosYa = gasto**, no algo que el restaurante emite; va al módulo de Gastos.
6. **Setting `pedidosya_auto_aceptar`** (off por defecto): si está on, el pedido entra directo a cocina y se confirma solo (para no perder el tiempo de respuesta); si off, manual en la bandeja.

## Modelo de datos

- **`pedidos.origen`**: agregar valor **`'pedidosya'`** al ENUM (hoy `carta|pos|mesa`). Migración.
- Reusar campos existentes del pedido: `comprobante_tipo`, `cliente_*`, `comprobante_serie/numero/estado/...` (NubeFact), `total`, `items_json`, `estado`, `nombre`, `tipo_entrega='delivery'`.
- Nuevos campos sugeridos en `pedidos` (gateados por guard): `pedidosya_order_id` (id de la orden en su sistema, para idempotencia + callbacks), `pedidosya_estado` (estado remoto), opcional `pedidosya_raw` (JSON del payload para auditoría).
- Credenciales/config en `company_settings`: `pedidosya_token`/`pedidosya_secret`/`pedidosya_webhook_secret`/`pedidosya_auto_aceptar` (patrón NubeFact/Izipay).

## Flujo

1. **Entrada (webhook `api/pedidosya_webhook.php`):** recibe el evento de orden nueva, **valida la firma/secret**, mapea ítems de PedidosYa → productos nuestros (`product_id`, para que la receta descuente stock), inserta `pedido` con `origen='pedidosya'`, `estado='pendiente'`, `pedidosya_order_id`. Idempotente por `pedidosya_order_id`. Si `pedidosya_auto_aceptar` → pasa directo a `en_preparacion` (KDS) + confirma a la API.
2. **Bandeja** (POS `Pedidos` / `admin/pedidos`): lista también `origen='pedidosya'` con **badge "PedidosYa"** distinto del de carta, badge que late + ding, y **ETA/tiempo** visible (el repartidor está en camino). Botones:
   - **Aceptar** → `pendiente→en_preparacion` (entra al KDS) **y** POST de aceptación a PedidosYa.
   - **Rechazar** (motivo) → cancela + POST de rechazo a PedidosYa.
3. **Comprobante:** al aceptar, auto-emisión vía `nubefactEmitir` → **boleta simple** (sin documento) por defecto; **factura** si el payload trae RUC. Serie del local del pedido; reintento con el botón existente si NubeFact falla. (Comprobante = evento aparte del pago, como en la bandeja de carta.)
4. **KDS → Listo:** `descontarStockPedido` descuenta insumos + subrecetas-con-stock (ya construido). Push de estado a PedidosYa (preparando/listo/despachado) según su API.

## Facturación — regla de la boleta simple

`nubefactEmitir` ya emite boleta sin documento como "CLIENTE VARIOS" (`cliente_tipo_de_documento='-'`, `numero='0'`). Para PedidosYa: documento vacío → boleta simple automática.

**⚠️ Umbral SUNAT:** las boletas **sin identificar al cliente** solo corresponden **bajo cierto monto por operación** (viene siendo **S/ 700**); por encima, SUNAT exige el **DNI** del comprador. En el build: si `total > umbral` y no hay documento → marcar para pedir DNI en vez de boleta simple a ciegas. **Confirmar el umbral vigente con el contador.** (Para tickets típicos de delivery se está muy por debajo.)

**⚠️ Modelo fiscal a confirmar** con PedidosYa + contador antes de prender el auto-emit: ¿el restaurante factura al cliente final, o PedidosYa ya le emite? Si ambos emiten → **doble facturación** (problema SUNAT). El diseño soporta ambas variantes; la decisión es comercial/tributaria.

## Alternativa: agregador

Si la integración directa con Delivery Hero es lenta, usar un **agregador** (Deliverect/Otter/etc.): exponen **una sola API** (y suelen cubrir Rappi/Uber Eats también), a cambio de un costo mensual. El lado nuestro (webhook → `origen='pedidosya'` o `'delivery'` → bandeja) es casi idéntico; solo cambia el origen del payload.

## Fuera de alcance / a definir cuando llegue la doc
- Formato exacto del payload, método de firma/auth, endpoints de aceptar/rechazar/estado, **sincronización de menú** (publicar/mapear la carta a PedidosYa) — todo depende de su documentación.
- Manejo de la **comisión** en Gastos (registro automático vs manual).
- Cancelaciones iniciadas por el cliente/PedidosYa (webhook de cancelación → cancelar el pedido + revertir stock si ya se descontó).

## Convenciones / seguridad
- PHP+PDO, `?` siempre; el webhook valida firma/secret (no `verifyCsrf`, es server-to-server, como `izipay_ipn`). Credenciales en `company_settings`, nunca en el navegador. Sin emojis. Multi-empresa. Guards para degradar si falta la migración/columnas.
