# El Gringo — CLAUDE.md

Plataforma web integral para **El Gringo Burger Joint** (Lima, Perú). Empezó como cotizador de eventos y hoy es un **sistema completo de catering + restaurante**: cotizaciones/eventos B2B **y** operación diaria (carta online multi-local, POS, KDS, inventario, reservas, landing, analítica, **facturación electrónica SUNAT** y **registro de gastos**). PHP puro + MySQL, desplegado en hosting cPanel compartido.

> **Nota de orientación:** este archivo describe un sistema grande con dos "mundos" que NO deben mezclarse: **Cotizaciones/eventos** (catering B2B, por mes/evento) y **Operación POS+Cartas** (venta diaria, por día/ticket). El dinero de uno nunca se suma con el del otro salvo en el panel consolidado del dashboard.

---

## Negocio

- **Empresa:** El Gringo Burger Joint — Lima, Perú
- **Operación:** dark kitchens + food truck + local (reservas de mesa)
- **Productos:** smash burgers, pollos crispy, salchipapas
- **Moneda:** Soles (S/) · **IGV** seleccionable por cotización (none / 10.5% / 18%)
- **Usuarios:** Admin (Daniel) + staff con permisos a la medida (cajero, cocina, asistente, inventario)

---

## Stack técnico

- **Backend:** PHP 8.0+ puro, sin frameworks · **BD:** MySQL 5.7+/MariaDB 10.3+ vía PDO
- **Frontend:** HTML + CSS propio + JS vanilla (sin Bootstrap/jQuery)
- **PWA:** `manifest.php` (por-app) + `sw.js` (network-only) → POS/KDS/Ventas instalables en el celular
- **Pagos online:** Izipay (Lyra/micuentaweb) — `api/izipay_*` — ACTIVO
- **Facturación electrónica:** NubeFact (OSE/SUNAT) — `includes/nubefact.php` + `admin/facturacion/` — boletas/facturas, **serie propia por local**, envío híbrido (NubeFact PDF+XML oficial + correo de marca). Credenciales `nubefact_*` en `company_settings`.
- **Consulta DNI/RUC:** RENIEC/SUNAT vía proveedor externo (apis.net.pe / apiperu.dev) — `includes/consulta_doc.php` (token en `company_settings`, endpoints configurables con `{n}`/`{token}`)
- **Impresión térmica:** ESC/POS 80mm vía app **RawBT** (Android) — `pos/escpos.php` (incluye comprobante con QR SUNAT nativo)
- **PDF/impresión:** HTML imprimible con `@media print`
- **Correo:** PHP `mail()` nativo. Remitentes por área: `cotizaciones@elgringo.pe`, `reservas@elgringo.pe`, `comprobantes@elgringo.pe` (deben existir como buzones en cPanel)
- **Hosting:** cPanel compartido. Docroot de `elgringo.pe` = carpeta `elgringo/`; el cotizador es subcarpeta `elgringo/cotizador/`. La **landing** se sirve en la raíz (`elgringo/index.php` carga `cotizador/landing.php`)
- **URLs:** Landing `https://elgringo.pe` · Panel/cotizador `https://elgringo.pe/cotizador` · Carta por ubicación `https://elgringo.pe/{slug}`
- **Repo:** github.com/danielcuesta12/gringo-cotizador

### Colores de marca (IMPORTANTE)
La marca es **amarillo `#FFDF00` + rosa `#FFBBC8` + negro `#1E1E1E`** (crema `#FFEFBC` secundario). El `--red #C8102E` es legado del cotizador original; en diseños nuevos (dashboard, emails, sidebar) **se usa la marca, no el rojo**.

---

## Flujo de trabajo

```
Editar en Claude Code (Mac) → git commit → git push origin main
  → SSH al servidor → cd /home/ebakxdhm/elgringo/cotizador && git pull
  → aplicar migraciones nuevas de install/*.sql en phpMyAdmin (cuando las haya)
```

---

## Los dos mundos

| | Cotizaciones / eventos (B2B) | Operación · POS y Cartas (diario) |
|---|---|---|
| Qué | Catering, eventos, food truck a tu evento | Venta diaria (carta online + caja POS) |
| Plata | "Facturado" = `quotes` **aceptadas** | "Ventas" = `pedidos` (origen carta+pos) |
| Tablas | `quotes`, `quote_items`, `quote_requests`, `reservas` | `pedidos`, `pos_*`, `ubicaciones`, `location_products` |
| Escala | por mes / por evento | por día / por ticket |

El **dashboard** (`admin/dashboard.php`) muestra ambos mundos separados por color (rosa cotizaciones / amarillo operación) + un **panel consolidado** (solo admin) que suma los dos, con export a Excel/CSV (`admin/export.php`).

---

## Estructura

```
gringo-cotizador/
├── index.php / landing.php          ← Landing link-in-bio (raíz elgringo.pe)
├── solicitud.php                    ← Form público de cotización (→ quote_requests)
├── reserva.php                      ← Form público de reserva de mesa (→ reservas)
├── manifest.php / sw.js             ← PWA (apps instalables)
├── config/  (config.php, database.php)
├── includes/  (helpers.php, permissions.php, inventario.php, nubefact.php, consulta_doc.php)
├── auth/  (login.php, logout.php)
├── carta/                           ← Carta de venta pública por ubicación
│   ├── selector.php (elige tienda si no hay slug; tema día/noche; recuerda elección)
│   ├── index.php (carta de venta + carrito → WhatsApp/Izipay; checkout pide comprobante)
│   ├── menu.php (menú solo-lectura) · banner.php · carta-print.php
├── pos/                             ← Terminal POS (standalone, full-screen, PWA)
│   ├── terminal.php · ticket.php (58mm) · escpos.php (ESC/POS 80mm → RawBT)
├── quotes/  (create, edit, list, pdf, view, send-email)
├── api/                             ← Endpoints JSON
│   ├── quotes.php · carta.php · carta_analytics.php · pedido.php
│   ├── pos.php · kds_pedidos.php · kds_update.php · kds_historial.php
│   ├── cartas.php · track.php
│   └── izipay_{config,create,verify,ipn,store_pending}.php
├── admin/
│   ├── layout-top.php / layout-bottom.php   ← Sidebar (grupos colapsables, gateado por permisos)
│   ├── dashboard.php                ← Dos mundos + consolidado + export
│   ├── export.php                   ← CSV/Excel (cotizaciones / operacion / consolidado)
│   ├── clients, categories, products, packages, modifiers, locations, events
│   ├── requests/  (buzón solicitudes cotización)
│   ├── reservas/  (buzón reservas: index + detail con confirmar/rechazar + email)
│   ├── pedidos/   (pedidos carta+POS, filtro origen, badge)
│   ├── kds/       (pantalla de cocina, standalone)
│   ├── pos/       (metodos, caja [arqueo], monitor [en vivo], clientes)
│   ├── cartas/    (generador de cartas PDF: index + editor)
│   ├── inventory/ (insumos, stock, recetas, movimientos, ajuste, compras, proveedores, salida_evento)
│   ├── gastos/    (registro de gastos: index lista+filtros+totales, form mobile-first)
│   ├── facturacion/ (config NubeFact + IGV + consulta DNI/RUC)
│   ├── landing/   (botones del landing por tipo + editor de apariencia + qr)
│   ├── analytics/ · settings/ · users/
└── install/  (~33 migraciones .sql — ver "Migraciones / despliegue")
```

---

## Base de datos (37 tablas)

**Core / cotizaciones:** `users`, `company_settings`, `categories`, `products`, `packages`, `package_products`, `clients`, `quotes`, `quote_items`, `quote_status_log`, `quote_requests`, `quote_templates`, `bank_accounts`, `reservas`.

**Carta / operación:** `ubicaciones`, `location_products`, `pedidos`, `grupos_modificadores`, `modificadores`, `product_modifier_groups`.

**POS:** `pos_turnos`, `pos_metodos_pago`, `pos_favoritos`.

**Generador de cartas PDF:** `cartas`, `carta_secciones`, `carta_items`.

**Inventario:** `insumos`, `insumo_stock`, `recetas`, `inventario_movimientos`, `proveedores`, `compras`, `compra_items`.

**Marketing/analítica:** `landing_links`, `analytics_events`, `product_likes`.

**Finanzas:** `gastos`, `gasto_categorias`.

### Campos clave

**`quotes`** — `status ENUM('borrador','enviada','aceptada','rechazada')`, `origin ENUM('quote','event')`, `public_token`, `igv_type ENUM('none','10.5','18')`, `accepted_at`, `event_date`. Prefijos: **EG-** (quote) / **EV-** (evento directo, nace aceptada).

**`pedidos`** (carta + POS) — `ubicacion_id`, `nombre`, `telefono`, `tipo_entrega ENUM('delivery','recojo')`, `items_json`, `total`, `estado ENUM('pendiente','en_preparacion','listo','entregado','cancelado')`, `metodo_pago VARCHAR(60)`, `origen ENUM('carta','pos')`, `izipay_order_id`, `turno_id`, `aceptado_at`, `completado_at`, `stock_descontado`. **POS/comprobante:** `descuento_tipo/valor/monto`, `cliente_tipo/nombre/documento/razon_social/email`, `comprobante_tipo ENUM('ticket','boleta','factura')`, `notas_pos`. **Comprobante electrónico (NubeFact):** `comprobante_serie/numero/estado('pendiente'|'emitido'|'error')/pdf/xml/cdr/hash/qr/error/emitido_at`. Un pedido de carta atendido en el POS recibe `turno_id` → entra al Historial del turno.

**`ubicaciones`** — `slug`, `sales_mode ENUM('menu','whatsapp','izipay')`, `whatsapp_number`, `hora_apertura/cierre`, `cerrado_manual`, `activa`, `es_principal`, **`referencia`** (zona que ve el cliente en el selector), **serie propia por local:** `serie_boleta/serie_factura/num_boleta/num_factura`. `location_products`: precio/disponibilidad por local.

**`gastos`** — `tipo ENUM('empresa','prestamo')`, `concepto`, `monto`, `categoria_id`→`gasto_categorias`, `ubicacion_id`, `usuario_id`, `fecha`, `tags` (slugs por coma, FIND_IN_SET), `foto` (se borra a los 2 meses), `nota`, `estado ENUM('pendiente','pagado')` (solo préstamos), `pagado_at/pagado_por`. Admin crea ambos tipos y marca pagado; no-admin solo crea/ve **sus** préstamos.

**`pos_turnos`** (arqueo) — `monto_inicial`, `ingreso_efectivo`, `gastos_total`, `gastos_json`, `total_efectivo/tarjeta/qr/otros`, `monto_final`, `caja_esperada`, `caja_real`, `diferencia`, `estado ENUM('abierto','cerrado')`. Arqueo: `caja_esperada = inicial + ingresos + ventas_efectivo − gastos`; `diferencia = esperada − real`.

**`reservas`** — `nombre`, `telefono`, `email`, `fecha`, `hora`, `num_personas`, `ubicacion_id`, `comentarios`, `estado ENUM('pendiente','confirmada','rechazada')`.

**`inventario_movimientos`** — ledger con `tipo (ingreso/ajuste/merma/venta/evento/compra/transferencia)`, `cantidad` (con signo), `costo_unitario`, `pedido_id`. **`users.permissions`** = JSON de claves de permiso (null = admin/acceso total).

---

## Permisos (control de acceso)

Sistema en `includes/permissions.php` + helpers en `helpers.php`. **Admin (role='admin') = superusuario** (ve todo, gestiona usuarios). Otros usuarios: `users.permissions` = arreglo JSON de claves.

```php
can('clave')                  // bool — admin siempre true; else revisa $_SESSION['user_permissions']
requirePermission('clave')    // gatea la página; redirige al primer acceso disponible si no tiene
userPermissions()             // array de claves del usuario actual
firstAllowedPath()            // primera ruta accesible (post-login / acceso denegado)
```

**Claves (29):** `dashboard, quotes, events, calendar, clients, requests, reservas, analytics` · `products, categories, packages, modifiers, locations` · `pedidos, kds, pos_terminal, pos_metodos, pos_caja, pos_monitor, pos_clientes` · `inv_insumos, inv_stock, inv_recetas, inv_movimientos, inv_compras, inv_evento` · `cartas_pdf, qr, landing` · `gastos` (grupo Finanzas). (`settings`/`users`/`facturacion` = solo `isAdmin()`.)

**Plantillas rápidas:** cajero (`pos_terminal,pos_caja`), cocina (`kds`), ventas (dashboard+quotes+events+calendar+clients+requests+reservas), inventario (los `inv_*`), admin (acceso total). El form de usuario (`admin/users/form.php`) tiene checkboxes por área + botones de plantilla. El menú (`layout-top.php`) y cada página se gatean con `can()`/`requirePermission()`.

---

## Clase Database (config/database.php)

```php
Database::fetch($sql, $params)       // array|null
Database::fetchAll($sql, $params)    // array
Database::insert($sql, $params)      // int (último ID)
Database::execute($sql, $params)     // int (filas afectadas)
Database::getInstance()              // PDO (para transacciones)
```
Nunca concatenar variables en SQL — siempre `?` con prepared statements.

---

## Helpers (includes/helpers.php)

```php
// Auth/permisos: isLoggedIn, requireLogin, requireAdmin, isAdmin, currentUser,
//                can, requirePermission, userPermissions, firstAllowedPath
// Seguridad:     verifyCsrf, csrfToken, csrfField
// Sanitización:  clean, cleanInt, cleanFloat, cleanEmail
// Formato:       formatMoney ("S/ 1,234.50"), formatDate (dd/mm/yyyy), formatDatetime
// Utilidades:    redirect (sin .php), flashMessage, getFlashMessages, generateQuoteNumber,
//                generateToken, getSetting, setSetting, uploadImage (→ 'logos/x.png', sin prefijo uploads/),
//                igvRate, quoteStatusBadge/Label, paginate, ubicacionAbierta
```
Inventario en `includes/inventario.php`: `invMovimiento`, `descontarStockPedido` (al marcar Listo en KDS, idempotente vía `stock_descontado`), `invEntradaCompra` (costo promedio ponderado), `recetaCosto`, `inventarioListo`/`comprasListo` (tolerancia a tablas faltantes).

Facturación en `includes/nubefact.php`: `nubefactConfigurado()`, `nubefactEmitir($pedidoId)` → emite boleta/factura (lee serie/correlativo del **local** del pedido, fallback al global; idempotente; nunca lanza excepción; auto-avanza correlativo si "número ya usado"; falla de red = `pendiente` reintentable). Consulta en `includes/consulta_doc.php`: `consultaDocConfigurado()`, `consultarDocumento($tipo,$numero)` (DNI 8 / RUC 11, proveedor configurable).

### Constantes
`APP_URL` (`https://elgringo.pe/cotizador`, sin slash) · `APP_PATH` · `UPLOAD_URL`/`UPLOAD_PATH` (con slash) · `MAX_FILE_SIZE` (2MB) · `DEBUG_MODE` (false en prod).

---

## Subsistemas

### Carta de venta + pedidos (público) — multi-local
`carta/index.php` por **slug de ubicación**. **Sin slug → `carta/selector.php`** (elige tienda: referencia/zona, abierto/cerrado, recuerda elección en localStorage; QR con slug se salta el selector). El header de la carta tiene pill "cambiar tienda". Tema **día/noche** (toggle + memoria, variables compartidas). El cliente arma carrito → según `ubicaciones.sales_mode`: **whatsapp** (guarda pedido `estado='pendiente'` + abre WhatsApp) o **izipay** (paga, pedido `estado='en_preparacion'`). En el **checkout** puede pedir **comprobante** (boleta/factura + documento + nombre/razón + correo) → se guarda en el pedido para que el cajero lo emita en su bandeja. `carta/menu.php` = menú solo-lectura. `api/carta.php` sirve el menú; `api/pedido.php` graba el pedido.

### POS (operación en caja) — construido completo
`pos/terminal.php` standalone, full-screen, PWA, **opera en celular**. Barra inferior con 5 paneles (única fuente de verdad `showPanel(name)`): **Vender · Pedidos · Caja · Historial · Clientes**. Caja por **turnos** con **arqueo** (caja inicial, ingresos, gastos, ventas efectivo, esperada vs real → diferencia). **Modal de ítem** con modificadores/nota/descuento **y precio personalizado por ítem** (editable, "Normal" para restaurar; reemplaza el precio, excluyente con el descuento; marca "Precio especial" en el carrito). Descuento global, cliente DNI/RUC + comprobante con **autocompletado RENIEC/SUNAT** (`action=consultar_doc`). Cobro por método → emite comprobante automáticamente si boleta/factura. **Historial** del turno (ventas POS + carta atendidos, badge "Carta", reimprimir, botón Reintentar comprobante). **Clientes** (búsqueda sobre pedidos POS → precarga datos en el cobro). **Pedidos** (bandeja, ver más abajo). Genera `pedidos` con `origen='pos'`. Ticket 58mm (`ticket.php`) o **impresión térmica 80mm** ESC/POS vía RawBT (`escpos.php`; imprime ticket o **comprobante con QR SUNAT**). Admin: `pos/metodos`, `pos/caja`, `pos/monitor`, `pos/clientes`.

### Bandeja de pedidos en el POS (cajero = control fiscal)
Pestaña **Pedidos** del terminal: lista los pedidos de **carta** del local sin atender (`origen='carta' AND turno_id IS NULL`), pre-cargados, con badge de pendientes que se refresca cada 60s. Acciones: **Aceptar → cocina** (WhatsApp `pendiente`→`en_preparacion`, recién ahí entra al KDS) y **Emitir comprobante** (modal pre-cargado ticket/boleta/factura, completa datos con RENIEC/SUNAT → `nubefactEmitir`, marca atendido con `turno_id`, imprime). API: `pedidos_carta`, `aceptar_pedido`, `atender_pedido`. El dinero de carta NO entra al arqueo de efectivo; el comprobante es un evento aparte del pago.

### KDS (cocina) — integrado con carta Y POS
`admin/kds/index.php` standalone. Lee `pedidos` (carta y `origen='pos'` → badge "SALÓN"). Estados pendiente→en_preparacion→listo/entregado/cancelado. **Gating WhatsApp:** los pedidos de WhatsApp en `pendiente` NO aparecen en el KDS hasta que el cajero los acepta (Izipay y POS nacen `en_preparacion` → entran directo). Al **marcar Listo** descuenta inventario (`descontarStockPedido`). `api/kds_{pedidos,update,historial}.php`. Sonido + fullscreen + historial del día.

### Inventario
Insumos con `costo_unitario`; stock por ubicación (`insumo_stock`, con `stock_min`). Recetas (producto→insumos = ficha técnica/costeo). Movimientos (ledger auditable). Compras a proveedores → actualizan costo por **promedio ponderado** (`invEntradaCompra`). Salida a evento. Descuento automático de stock al marcar Listo.

### Reservas (mesa)
`reserva.php` público (form embebible) → tabla `reservas` + aviso a `reservas@elgringo.pe`. Buzón admin `admin/reservas/` (pendiente/confirmada/rechazada) con botones **Confirmar/Rechazar** que pre-redactan un email editable y lo envían desde `reservas@elgringo.pe`. Admin puede **eliminar** reservas.

### Landing link-in-bio (raíz elgringo.pe)
`landing.php` con botones administrables (`landing_links`) por **tipo**: `link` (enlace), `cotizacion` (embebe `solicitud.php`), `reserva` (embebe `reserva.php`) — los embebidos son **colapsables**. Editor de apariencia (foto fondo, colores, transparencia) + QR con `?src=` rastreable. Las URLs de los forms embebidos se resuelven desde `APP_URL` (no del campo url).

### Analítica
`analytics_events` (page_view, link_click, product_view, add_to_cart, checkout_open, order_placed, search) vía `api/track.php` y `api/carta_analytics.php`. Rastrea fuente con `?src=`. `product_likes` (acumulado). Dashboard `admin/analytics/` (KPIs, embudo, por página/dispositivo/fuente, top productos/clics/búsquedas).

### Izipay (pagos online) — ACTIVO
`api/izipay_{config,create,verify,ipn,store_pending}.php`. Flujo: create (formToken) → form Krypton embebido en la carta → verify (HMAC) → crea `pedido` (`metodo_pago` izipay, `estado='en_preparacion'`). IPN server-to-server como respaldo. Credenciales `IZIPAY_*` en `.env`.

### Facturación electrónica (NubeFact / SUNAT) — ACTIVO (probado en DEMO)
`includes/nubefact.php` + `admin/facturacion/index.php` (config: url/token NubeFact, modo demo/producción, IGV %, IGV incluido, series globales de respaldo, token de consulta DNI/RUC). Flujo: el comprobante se emite al **confirmar el cobro** (POS) o al **atender el pedido de carta** (bandeja). **Serie propia por local** (fallback al global). Precios **incluyen IGV** → el motor desagrega. **Envío híbrido al cliente:** NubeFact manda el PDF+XML+CDR oficial (`cliente_email`) **y** `comprobantes@elgringo.pe` envía un correo de marca con botón al PDF. Pendientes/errores → botón **Reintentar** (Historial / `admin/pedidos/detail.php`). Impresión térmica del comprobante con QR SUNAT en `escpos.php`. Migraciones: `nubefact.sql`, `nubefact_email.sql`, `multilocal_facturacion.sql`.

### Consulta DNI / RUC (RENIEC · SUNAT)
`includes/consulta_doc.php` vía proveedor externo (apis.net.pe v2 por defecto; configurable a apiperu.dev u otro con endpoints `{n}`/`{token}`). Lo usa el cobro del POS y la bandeja (`api/pos.php?action=consultar_doc`, autocompleta al escribir 8/11 dígitos). El token se guarda en `company_settings` (no se llama desde el navegador). El DNI tiene costo por consulta según el plan del proveedor.

### Registro de gastos (control) — `admin/gastos/`
Libro de control **separado del dinero de ventas**. Dos tipos: **empresa** (gasto de la cuenta, solo admin) y **préstamo** (cualquier usuario con permiso `gastos`, con estado pendiente→pagado que **solo el admin** marca). Form mobile-first: monto, concepto, **categoría con creación rápida** (`gasto_categorias`), **tags tipo chips** con sugerencias, tienda, fecha, **foto del comprobante** (cámara en móvil, se borra a los 2 meses al entrar al módulo), nota. Lista con totales (préstamos pendientes / gastos del mes) y filtros por tipo/estado/**tag**/búsqueda. No-admin solo ve **sus** préstamos (gating en servidor). Migración: `gastos.sql`.

### Generador de cartas PDF
`admin/cartas/` (lista + editor de dos paneles). Cartas a medida con secciones/ítems/fotos/**badges**/colores/QR → banner imprimible 42cm (`carta-print.php`). Tablas `cartas`/`carta_secciones`/`carta_items`. Independiente del catálogo de productos.

---

## Layout admin

```php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
requirePermission('clave');   // o requireAdmin() para settings/users
$pageTitle = '...'; $activePage = '...';
include __DIR__ . '/../../admin/layout-top.php';
// contenido
include __DIR__ . '/../../admin/layout-bottom.php';
```
El sidebar tiene **grupos colapsables** (persistidos en localStorage), gateados por `can()`, con acentos de marca (rosa Cotizaciones / amarillo Operación) y pastilla activa amarilla. Orden: Dashboard · Cotizaciones · Operación · Catálogo · Inventario · Marketing · **Finanzas** · Sistema.

---

## Convenciones de código

```php
// Página protegida: requirePermission('clave');  (admin = requireAdmin())
// Todo POST del admin: verifyCsrf(); luego clean()/cleanInt()/cleanFloat()
// Forms admin: <form method="post"><?= csrfField() ?>...
// Redirecciones sin .php: redirect('/admin/dashboard');
```

**Forms públicos embebidos (solicitud, reserva): NO usan verifyCsrf()** — van en iframe (el token de sesión no sobrevive). Protegidos por **honeypot** (campo `website`). NO agregar CSRF a esos forms.

**APIs:** `requireLogin()` + JSON + switch `$action` + `verifyCsrf()` en escrituras (token por header `X-CSRF-Token`). El monitor (`pos.php?action=monitor`) es admin-only.

---

## Seguridad — no negociable
1. Nunca concatenar variables en SQL — siempre `?`. 2. `verifyCsrf()` en POST del admin. 3. Sanitizar con `clean*()`. 4. `requirePermission()`/`requireAdmin()` en cada página protegida. 5. Nunca mostrar errores PDO en prod.

---

## Bugs resueltos — NO repetir

| Bug | Fix |
|-----|-----|
| Doble /cotizador/ en redirect post-login | requireLogin extrae el path de APP_URL del REQUEST_URI |
| Logo con doble prefijo uploads/ | uploadImage retorna 'logos/x.png'; el consumidor agrega UPLOAD_URL |
| PDF en blanco al imprimir en Mac | padding en .doc como margin; @media print cancela body padding |
| Inputs con flechitas | type="text" inputmode="decimal" |
| Emojis corruptos en WA | mb_convert_encoding('&#CODEPOINT;','UTF-8','HTML-ENTITIES') |
| Facturado sumaba no-aceptadas | filtra status='aceptada' |
| POS: cerrar_turno NULL comprobante (500) | default 'ticket' si falta la clave |
| POS: carrito mostraba 1 línea | cachear nodo #cart-empty (no re-buscar tras innerHTML) |
| Monitor "Error al actualizar" | renderChart en rama sin-ventas llamaba con args corridos |
| Landing botón Reserva 404 | resolver URL del form embebido desde APP_URL, no del campo url |
| Reserva "Token de seguridad inválido" | quitar verifyCsrf del form público embebido (honeypot, como solicitud) |
| api/pos.php acción no leída en POST | $action = $_GET['action'] ?? $_POST['action'] |
| POS: paneles flotaban 64px arriba (se veía COBRAR detrás) | paneles `position:fixed` de topbar-h a btmbar-h (no `absolute` dentro de #app) |
| POS: nav-vender no cerraba el panel Historial (toggle desincronizado) | `showPanel(name)` única fuente de verdad para Vender/Pedidos/Caja/Historial/Clientes |
| Selector/pill de tienda invisible según tema | usar `var(--header-text)` (como el link de IG); logo sobre barra con color de marca |
| NubeFact IGV/serie hardcodeados | IGV de `getSetting`; serie/correlativo del local del pedido |
| escpos/ticket/emitir solo aceptaban origen='pos' | quitar el filtro para poder imprimir/emitir comprobantes de carta |

---

## Objetivo multi-empresa
Cada instancia: su propio `.env` (DB_NAME, APP_URL/PATH, UPLOAD_URL/PATH). El código es idéntico; la config visual (colores, logos, cuentas, tipos de evento) vive en `company_settings`. Esta plataforma porta los módulos de restaurante de **Marcona** como instancia independiente (datos separados; marcona no se toca).

---

## Migraciones / despliegue

~33 archivos en `install/`. Al desplegar hay que aplicar las **nuevas** en phpMyAdmin (no hay tracking automático). **Para saber cuáles faltan: pega `install/check_migraciones.sql` en phpMyAdmin** → te lista cada migración con ✅/❌ (mirando si su columna/tabla existe). Las más recientes: `nubefact.sql`, `nubefact_email.sql`, `multilocal_facturacion.sql`, `gastos.sql`. Muchas tablas tienen guards (`inventarioListo()`, `$ready`, try/catch) para no romper si falta aplicar una.

---

## Pendiente / notas honestas

- **Lealtad/fidelización** — único módulo de Marcona aún no portado (hay referencias en frontend; falta backend + admin + tabla). Es el siguiente gran build.
- **NubeFact en PRODUCCIÓN** — está probado en **DEMO** (sin valor legal). Antes de producción: en SUNAT (Clave SOL) revisar qué series/correlativos ya existen bajo el RUC (POS anterior / portal), elegir series nuevas por local, arrancar en Nº 1, y cambiar el modo a producción. DEMO y producción son entornos separados.
- **SaaS / multi-local real** — la arquitectura ya soporta varias tiendas (serie por local, selector, bandeja). Falta capa comercial para venderlo a otras marcas (alta de clientes, planes, cobro de suscripción).
- **Entregabilidad de correo:** `mail()` nativo; en prod conviene migrar a **SMTP autenticado** por reputación. Los buzones `cotizaciones@`/`reservas@`/`comprobantes@elgringo.pe` deben existir en cPanel. Cuidado con el **límite horario de correos** del hosting (puede hacer que "no lleguen").
- **APIs / permisos:** `api/cartas.php` ya usa `can('cartas_pdf')` (antes `isAdmin()`). El resto de APIs usan `requireLogin()` + chequeo interno. Si se da acceso a otra API a no-admins, afinar a `can()` por permiso.
- Migración de marca: en `assets/css/style.css` `--red` ya está aliasado a `var(--brand)`, así que el panel muestra marca. Los `#C8102E` que quedan son **semánticos** (rojo = "falta" en arqueo, errores/destructivo en POS `--red`, gráficos del monitor) o **configurables** (color del PDF de cotización, `pdf_primary_color`) — NO son legado a quitar. Los emails de marca usan `brandPrimaryHex()`.
- Sistema en **español peruano**; moneda `S/ 1,234.50`; fechas dd/mm/yyyy (BD yyyy-mm-dd); TZ America/Lima (UTC-5); utf8mb4.
- Mucho de lo nuevo (POS, reservas, permisos, dashboard) es **reciente** — necesita uso real para pulir.
