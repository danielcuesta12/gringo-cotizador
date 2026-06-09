# Migración marcona → El Gringo (programa multi-sesión)

Objetivo: consolidar la plataforma de restaurante de `elgringo-marcona`
dentro de El Gringo, sobre **una sola base de datos (la del cotizador)**, con
un sistema unificado. Origen local: `~/Documents/Proyectos/elgringo-marcona`.

## Principios
- **Una BD, una fuente de verdad.** El catálogo (`products`/`categories`) del
  cotizador es la base; editar un producto se refleja en todo.
- **Multi-ubicación.** Cada ubicación define qué ítems ofrece, con
  **precio y disponibilidad propios** (tabla `location_products`).
- **Modalidad de venta por ubicación** (`ubicaciones.sales_mode`): `menu`
  (solo lectura) · `whatsapp` · `izipay`.
- Reusar los frontends sólidos de marcona (carta, KDS, POS, Izipay) apuntados
  al catálogo compartido; rehacer las piezas de admin en el panel PHP del
  cotizador para que todo se vea coherente.
- Cada fase: reconciliar esquema → admin → frontend → probar (TEST) → desplegar.
  Trabajo en rama, preview, merge, `git pull`. Ver [[redesign-workflow]].

## Roadmap
- [ ] **A — Base catálogo multi-ubicación**
  - tabla `ubicaciones` (nombre, slug, color, sales_mode, whatsapp, activa, principal, orden)
  - tabla `location_products` (location_id, product_id, price, available, sort_order)
  - admin de ubicaciones en el panel del cotizador
- [ ] **B — Menú de solo lectura** por ubicación (`/menu`) + conectar a la landing link-in-bio
- [ ] **C1 — Carta con venta + WhatsApp** (carrito, enviar pedido, guardar en `pedidos`) + selector sales_mode
- [ ] **C2 — Modalidad Izipay** (portar izipay_create/ipn/verify; credenciales en .env; IPN HTTPS; probar en TEST)
- [ ] **D — KDS** por ubicación (pantalla cocina, beep, estados; polling)
- [ ] **E — POS** (caja, favoritos, pedidos presenciales)
- [ ] **F — Reservas**
- [ ] **G — Lealtad** + validador
- [ ] **Transversal — Analítica** (visitas/clics landing + pedidos) y captura de leads

## Landing link-in-bio (proyecto puerta de entrada)
Ver [[landing-linkbio-project]]. La landing en la raíz `elgringo.pe` lleva a
las cartas/menús de cada ubicación.

## Credenciales necesarias (pedir al usuario en su fase)
- Izipay: IZIPAY_SHOP_ID, IZIPAY_MODE, IZIPAY_REST_PASS_TEST/PROD,
  IZIPAY_HMAC_TEST/PROD, IZIPAY_PUBLIC_KEY_TEST/PROD, IZIPAY_REST_SERVER, IZIPAY_JS_URL
- SMTP (PHPMailer) para correos de pedidos/reservas

## Reconciliaciones pendientes (decidir al llegar)
- `clientes` (marcona) ↔ `clients` (cotizador): unificar en una tabla.
- `productos`+`variantes`+`modificadores` (marcona) ↔ `products` (cotizador):
  el cotizador no tiene variantes/modificadores; decidir si se agregan.
- `usuarios` (marcona) ↔ `users` (cotizador): un solo login/roles.
- `pedidos`: tabla nueva en la BD del cotizador (no existe hoy).
