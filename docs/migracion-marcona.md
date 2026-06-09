# Migración marcona → El Gringo (programa multi-sesión)

Objetivo: **DUPLICAR** la plataforma de restaurante de marcona como una
**instancia independiente de El Gringo** (otra ciudad), corriendo sobre la
**BD del cotizador** y compartiendo su catálogo.

> ⚠️ **`/marcona` NO se toca.** Sigue operando solo, con su propia BD, en su
> ciudad. Son dos inquilinos del mismo software con **datos separados**.
> Marcona es la **fuente del CÓDIGO** que copiamos, NO de los datos —
> no se fusiona ninguna base de datos en vivo.

Origen del código (solo lectura): `~/Documents/Proyectos/elgringo-marcona`.

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

## Diseño del esquema propio de El Gringo (NO es merge con marcona)
El Gringo usa SU propia BD (la del cotizador). No se importan datos de marcona;
solo se porta el código y se decide el esquema propio de El Gringo:
- Clientes: reusar `clients` del cotizador (no traer `clientes` de marcona).
- Productos: reusar `products`/`categories` del cotizador como catálogo. El
  cotizador no tiene variantes/modificadores; decidir si se agregan al portar
  la carta de venta (Fase C).
- Usuarios/login: reusar `users` del cotizador (un solo login para todo).
- `pedidos`: tabla nueva en la BD del cotizador (se crea en Fase C).
