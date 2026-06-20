# App del mozo — Sub-build D: Servicio (aviso de Listo, estado en vivo, catálogo rápido, reintento)

**Fecha:** 2026-06-20
**Módulo:** POS de restaurante — app del mozo (mejoras de servicio)
**Estado:** diseño aprobado, pendiente de plan

## Contexto

La app del mozo (`mozo/index.php` + `api/mozo.php`, sesión por PIN, geocerca) ya hace: plano de mesas con estados en vivo (poll 5s), tomar pedido (catálogo → borrador → enviar a cocina = comanda), ver cuenta con badges de estado, precuenta, y cobro (split + mixto + comprobante). Esta tanda agrega 5 mejoras de servicio diario. **Sub-build E (transferir/juntar mesas) va en otra tanda.**

Sin tablas nuevas → **sin migración**. Todo en `mozo/index.php` + una consulta nueva en `api/mozo.php`.

## Decisiones tomadas (brainstorming)

1. **Aviso de "Listo" = notificación, no estado a cerrar.** Cuando cocina marca un pedido `listo` (el card desaparece del KDS), se le **notifica al mozo dueño de esa mesa** (banner + sonido). El mozo entrega; **no hay "marcar entregado"** (se evita el paso extra y la confusión). El aviso se desvanece solo.
2. **Cada mozo solo sus mesas:** el aviso se filtra por `cuentas.empleado_id = mozoEmp()`.
3. **Sonido sí, con toggle** (default on, persistido en localStorage). Ding sintetizado WebAudio (sin descargas, como el KDS).
4. **Estado en vivo en la cuenta:** poll mientras la vista está abierta.
5. **Búsqueda + agregar de un toque** en el catálogo.
6. **`inputmode="decimal"`** en los montos del cobro.
7. **Reintento simple** al enviar a cocina (solo ante caída de red; el cobro NO se auto-reintenta).
8. **Sin emojis** (color + texto/símbolos), tokens de marca (`brandHead()`).

## D.1 — Aviso de "Listo" por mozo

### Backend (`api/mozo.php`, acción `plano_estados`)
`plano_estados` ya devuelve `mesaEstados($ubi)` + umbrales. Se le agrega la clave **`listos`**: comandas en `listo` de las cuentas abiertas **de este mozo**:

```sql
SELECT p.id AS pedido_id, m.numero AS mesa, p.items_json
FROM pedidos p
JOIN cuentas c ON c.id = p.cuenta_id
LEFT JOIN mesas m ON m.id = p.mesa_id
WHERE c.ubicacion_id = ? AND c.empleado_id = ? AND c.estado = 'abierta'
  AND p.origen = 'mesa' AND p.estado = 'listo'
ORDER BY p.id
```
Por cada fila se arma `{pedido_id:int, mesa:string, resumen:string}` donde `resumen` = los nombres de los ítems no anulados (`qty× nombre`, unidos por `· `, máx ~3 + "…"). Scope: `mozoUbi()` + `mozoEmp()`. Si `mozoEmp()` es 0, `listos` = `[]` (defensivo; igual la acción ya exige sesión de mozo).

### Frontend (`mozo/index.php`)
- Estado: `var avisados = {}` (set de `pedido_id` ya notificados) y `var avisosSeed = false`.
- En el handler de `plano_estados` (dentro de `pollEstados`), procesar `d.listos`:
  - **Primera vez** (`!avisosSeed`): marcar todos los `pedido_id` actuales como avisados **sin** notificar; `avisosSeed = true`. (Evita spam al abrir/recargar la app.)
  - **Siguientes**: para cada `pedido_id` en `d.listos` que NO esté en `avisados` → es un Listo nuevo → **notificar** y agregar a `avisados`.
  - Podar `avisados`: quedarse solo con los `pedido_id` presentes en `d.listos` (los que ya se cobraron/entregaron salen; no re-notifican porque no reaparecen en `listo`).
- **Notificar** = mostrar banner + (si sonido on) ding:
  - Banner deslizante desde arriba: `Mesa <m> · <resumen> — Listo`. Auto-cierra ~6s; tap lo cierra antes. Si caen varios juntos, encolar/apilar (mostrar uno y reemplazar, o "Mesa 5 y 1 más"). Reusa estilo de la barra existente; **sin emojis** (un punto de color como indicador).
  - Ding: función `ding()` con WebAudio (oscilador, doble bip corto), creada bajo demanda; respeta `prefers-reduced-motion`? (el sonido no aplica; el parpadeo del banner sí).
- **Toggle de sonido**: control en la barra superior del plano (`#snd-toggle`), persistido en `localStorage['mozo_sonido']` (default `'1'`). Estado reflejado con texto/símbolo (no emoji): "Son. on/off".
- El poll y el render del plano no cambian su comportamiento actual; el aviso es un efecto lateral del mismo fetch.

### KDS (verificación, sin cambios salvo que haga falta)
El flujo asume que al marcar `listo` el card sale del tablero activo del KDS (cocina terminó). **Verificar** en `admin/kds/index.php` que las comandas `listo` no queden en el tablero activo; si quedan, es un ajuste menor aparte (no bloquea D). El descuento de inventario al marcar Listo no cambia.

## D.2 — Estado en vivo en la cuenta

- `pollCuenta()`: mientras `v-cuenta` esté visible y `st.cuenta` exista, cada **5s** re-fetch `get('cuenta&cuenta_id='+st.cuenta.id)` y `renderCuenta()` (repinta los badges en preparación→listo). Se reprograma solo si la vista sigue abierta; al salir, se corta (chequear `$('v-cuenta').classList.contains('on')`).
- Arranca al entrar a la cuenta (`loadCuenta`/`renderCuenta`) y no interfiere con modales abiertos (anular/cobro viven en `.modal` aparte; re-renderizar el body de la cuenta por debajo es inocuo).
- No re-fetchear si hay una escritura en vuelo (simple: el poll es de solo lectura; si coincide con un `loadCuenta` manual, el último gana — aceptable).

## D.3 — Búsqueda en catálogo + agregar de un toque

- **Búsqueda:** input `#cat-search` arriba de `#cat-tabs` en `v-cat`. Normaliza (minúsculas, sin acentos) y filtra por `nombre`. Con texto: `drawCat` muestra los productos que matchean **en todas las categorías** (oculta las pestañas o las ignora); vacío: comportamiento actual por categoría. `inputmode` no aplica (texto).
- **Agregar de un toque:** extraer la lógica de "agregar al borrador" del modal a una función `addToBorrador(p, qty, sel, nota)` (misma forma de ítem que hoy: `{product_id, qty, nombre, precio, modificadores, nota}`). En `drawCat`, si el producto **no tiene grupos** (`!p.grupos || !p.grupos.length`), el tap (y el `+`) llaman `addToBorrador(p, 1, {}, '')` directo + feedback (toast corto / el botón "Ver borrador" actualiza su contador). Si **tiene grupos**, sigue abriendo `openProd(p)` (modal).
- El modal de producto sigue usando `addToBorrador` al confirmar (DRY).

## D.4 — `inputmode="decimal"` en cobro

Agregar `inputmode="decimal"` a: los inputs de **monto** de cada línea de pago (`.pago-row input`), el input de **descuento** (`.cobro-config input` de valor), y cualquier input numérico de monto del cobro. (El monto de partes en modo "montos" también.) Es atributo en el markup generado por `renderPartes`/`setModo`. No cambia validación.

## D.5 — Reintento simple al enviar a cocina

- Helper `postRetry(action, body, tries)`: hace `post(action, body)`; si la **promesa de red se rechaza** (sin respuesta), espera ~800ms y reintenta, hasta `tries` (default 3). Si el servidor responde con `{ok:false}` (error de negocio: geocerca, cuenta no abierta, etc.) **NO reintenta** (lo resuelve normal). Devuelve la misma promesa-resultado que `post`.
- `enviarComanda` usa `postRetry('enviar_comanda', ...)`. En fallo final de red: **no** limpia el borrador, deja la sheet del borrador abierta y muestra "Sin señal · toca Enviar para reintentar". En éxito: limpia borrador + toast "Enviado a cocina · Ronda N" (como hoy).
- El **cobro** (`cobrar`) sigue usando `post` directo (sin auto-reintento): un cobro a medias con red intermitente es riesgoso (turno/NubeFact); si falla, el mozo reintenta a mano.

## Archivos

- `api/mozo.php`: `plano_estados` suma `listos` (query nueva, scopeada por `mozoUbi()`+`mozoEmp()`).
- `mozo/index.php`:
  - Aviso de Listo: procesamiento de `d.listos` en `pollEstados`, banner, `ding()`, toggle de sonido.
  - `pollCuenta()` (estado en vivo).
  - Búsqueda + `addToBorrador` + quick-add en `drawCat`.
  - `inputmode="decimal"` en inputs de cobro.
  - `postRetry` + `enviarComanda` con reintento.

## Convenciones / seguridad

- Sesión de mozo (no `requireLogin`); las lecturas (`plano_estados`, `cuenta`, `menu`) ya están gateadas. `listos` no expone datos de otros mozos (filtra por `empleado_id`).
- SQL siempre con `?`. Scope multi-local por `ubicacion_id`.
- **Sin emojis**: estado por color + texto/símbolos. Tokens de marca (`brandHead()` ya en el `<head>`). Mobile-first, táctil ≥44px.
- Sin framework de tests: `php -l` + `node --check` (donde aplique) + captura a 390px + checklist funcional.

## Fuera de alcance (D)
Transferir/juntar mesas (Sub-build E), cola offline persistente (se hace reintento simple), marcar entregado (decidido: no va), notificaciones push del SO (es in-app mientras la app está abierta).
