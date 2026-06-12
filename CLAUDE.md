# El Gringo — CLAUDE.md

Plataforma web integral para **El Gringo Burger Joint** (Lima, Perú). Empezó como cotizador de eventos y hoy es un **sistema completo de catering + restaurante**: cotizaciones/eventos B2B **y** operación diaria (carta online, POS, KDS, inventario, reservas, landing y analítica). PHP puro + MySQL, desplegado en hosting cPanel compartido.

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
- **Impresión térmica:** ESC/POS 80mm vía app **RawBT** (Android) — `pos/escpos.php`
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
├── includes/  (helpers.php, permissions.php, inventario.php)
├── auth/  (login.php, logout.php)
├── carta/                           ← Carta de venta pública por ubicación
│   ├── index.php (carta de venta + carrito → WhatsApp/Izipay)
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
│   ├── landing/   (botones del landing por tipo + editor de apariencia + qr)
│   ├── analytics/ · settings/ · users/
└── install/  (26 migraciones .sql — ver "Migraciones / despliegue")
```

---

## Base de datos (35 tablas)

**Core / cotizaciones:** `users`, `company_settings`, `categories`, `products`, `packages`, `package_products`, `clients`, `quotes`, `quote_items`, `quote_status_log`, `quote_requests`, `quote_templates`, `bank_accounts`, `reservas`.

**Carta / operación:** `ubicaciones`, `location_products`, `pedidos`, `grupos_modificadores`, `modificadores`, `product_modifier_groups`.

**POS:** `pos_turnos`, `pos_metodos_pago`, `pos_favoritos`.

**Generador de cartas PDF:** `cartas`, `carta_secciones`, `carta_items`.

**Inventario:** `insumos`, `insumo_stock`, `recetas`, `inventario_movimientos`, `proveedores`, `compras`, `compra_items`.

**Marketing/analítica:** `landing_links`, `analytics_events`, `product_likes`.

### Campos clave

**`quotes`** — `status ENUM('borrador','enviada','aceptada','rechazada')`, `origin ENUM('quote','event')`, `public_token`, `igv_type ENUM('none','10.5','18')`, `accepted_at`, `event_date`. Prefijos: **EG-** (quote) / **EV-** (evento directo, nace aceptada).

**`pedidos`** (carta + POS) — `ubicacion_id`, `nombre`, `telefono`, `tipo_entrega ENUM('delivery','recojo')`, `items_json`, `total`, `estado ENUM('pendiente','en_preparacion','listo','entregado','cancelado')`, `metodo_pago VARCHAR(60)`, `origen ENUM('carta','pos')`, `izipay_order_id`, `turno_id`, `aceptado_at`, `completado_at`, `stock_descontado`. **POS extra:** `descuento_tipo/valor/monto`, `cliente_tipo/nombre/documento/razon_social`, `comprobante_tipo ENUM('ticket','boleta','factura')`, `notas_pos`.

**`ubicaciones`** — `slug`, `sales_mode ENUM('menu','whatsapp','izipay')`, `whatsapp_number`, `hora_apertura/cierre`, `cerrado_manual`, `activa`, `es_principal`. `location_products`: precio/disponibilidad por local.

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

**Claves (28):** `dashboard, quotes, events, calendar, clients, requests, reservas, analytics` · `products, categories, packages, modifiers, locations` · `pedidos, kds, pos_terminal, pos_metodos, pos_caja, pos_monitor, pos_clientes` · `inv_insumos, inv_stock, inv_recetas, inv_movimientos, inv_compras, inv_evento` · `cartas_pdf, qr, landing`. (`settings`/`users` = solo `isAdmin()`.)

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

### Constantes
`APP_URL` (`https://elgringo.pe/cotizador`, sin slash) · `APP_PATH` · `UPLOAD_URL`/`UPLOAD_PATH` (con slash) · `MAX_FILE_SIZE` (2MB) · `DEBUG_MODE` (false en prod).

---

## Subsistemas

### Carta de venta + pedidos (público)
`carta/index.php` por **slug de ubicación**. Tema **día/noche** (toggle + memoria). El cliente arma carrito → según `ubicaciones.sales_mode`: **whatsapp** (guarda pedido + abre WhatsApp) o **izipay** (paga con tarjeta y luego se crea el pedido). `carta/menu.php` = menú solo-lectura. `api/carta.php` sirve el menú (secciones+productos+modificadores).

### POS (operación en caja) — construido completo
`pos/terminal.php` standalone, full-screen, PWA, **opera en celular**. Caja por **turnos** con **arqueo** (caja inicial, ingresos, gastos, ventas efectivo, esperada vs real → diferencia). Modal de ítem con modificadores/nota/descuento, descuento global, cliente DNI/RUC + comprobante. Cobro por método. Genera `pedidos` con `origen='pos'`. Ticket 58mm (`ticket.php`) o **impresión térmica 80mm** ESC/POS vía RawBT (`escpos.php` → botón "Imprimir" abre `rawbt:base64,...`); también recibo por correo y ticket embebido en modal. Admin: `pos/metodos`, `pos/caja` (historial+arqueo), `pos/monitor` (ventas en vivo, solo dueño, standalone+PWA, comparativo semanal+export), `pos/clientes`.

### KDS (cocina) — integrado con carta Y POS
`admin/kds/index.php` standalone. Lee `pedidos` (carta y `origen='pos'` → badge "SALÓN"). Estados pendiente→en_preparacion→listo/entregado/cancelado. Al **marcar Listo** descuenta inventario (`descontarStockPedido`). `api/kds_{pedidos,update,historial}.php`. Sonido + fullscreen + historial del día.

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
El sidebar tiene **grupos colapsables** (persistidos en localStorage), gateados por `can()`, con acentos de marca (rosa Cotizaciones / amarillo Operación) y pastilla activa amarilla. Orden: Dashboard · Cotizaciones · Operación · Catálogo · Inventario · Marketing · Sistema.

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

---

## Objetivo multi-empresa
Cada instancia: su propio `.env` (DB_NAME, APP_URL/PATH, UPLOAD_URL/PATH). El código es idéntico; la config visual (colores, logos, cuentas, tipos de evento) vive en `company_settings`. Esta plataforma porta los módulos de restaurante de **Marcona** como instancia independiente (datos separados; marcona no se toca).

---

## Migraciones / despliegue

26 archivos en `install/`. Al desplegar hay que aplicar las **nuevas** en phpMyAdmin (no hay tracking automático — conviene un checklist). Las más recientes: `permisos.sql`, `reservas.sql`, `landing_tipo.sql`, `pos_arqueo.sql`, `cartas_badges.sql`. Muchas tablas tienen guards (`inventarioListo()`, `$tableReady`) para no romper si falta aplicar una.

---

## Pendiente / notas honestas

- **Lealtad/fidelización** — único módulo de Marcona aún no portado (hay referencias en frontend; falta backend + admin + tabla). Es el siguiente gran build.
- **Entregabilidad de correo:** `mail()` nativo; en prod conviene migrar a **SMTP autenticado** por reputación. Los buzones `cotizaciones@`/`reservas@`/`comprobantes@elgringo.pe` deben existir en cPanel. Cuidado con el **límite horario de correos** del hosting (puede hacer que "no lleguen").
- **APIs aún en `requireAdmin()`** (ej. `api/cartas.php`): un usuario personalizado no-admin con ese permiso podría no poder guardar. Afinar a `can()` por permiso si se da ese acceso.
- Migración de marca incompleta: el panel aún usa `--red #C8102E` en varios lados; lo nuevo va en marca.
- Sistema en **español peruano**; moneda `S/ 1,234.50`; fechas dd/mm/yyyy (BD yyyy-mm-dd); TZ America/Lima (UTC-5); utf8mb4.
- Mucho de lo nuevo (POS, reservas, permisos, dashboard) es **reciente** — necesita uso real para pulir.
