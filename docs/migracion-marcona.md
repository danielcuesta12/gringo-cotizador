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

## ⚠️ Constraints de diseño de la carta (NO olvidar)
- La **carta (menú solo-lectura y carta de venta) se trae TAL CUAL de marcona**
  (`carta_template.html` / `burgerjoint/menu`), con su diseño propio. NO aplicar
  el tema amarillo del panel del cotizador. El rediseño de la carta se hace
  DESPUÉS, aparte.
- Traer las **fuentes de marcona** a El Gringo y arreglar las rutas de @font-face:
  Gilroy-Bold/Medium (.ttf), Arial_Narrow_Bold.ttf, DINMed, Kimmy.woff2.
  OJO: algunas vienen con ruta absoluta `/elgringo/marcona/fonts/...` → cambiarlas
  para que apunten a los fonts copiados en El Gringo.
- El **admin** (ubicaciones, ítems, etc.) sí va en el estilo del panel del cotizador.

## Roadmap
- [x] **A — Base catálogo multi-ubicación**
  - tabla `ubicaciones` (nombre, slug, color, sales_mode, whatsapp, activa, principal, orden)
  - tabla `location_products` (location_id, product_id, price, available, sort_order)
  - admin de ubicaciones en el panel del cotizador
- [x] **B — Menú de solo lectura** por ubicación (`/menu`) + conectar a la landing link-in-bio
- [x] **C1 — Carta con venta + WhatsApp** (carrito, enviar pedido, guardar en `pedidos`) + selector sales_mode ✅ (falta aplicar SQL + .htaccess, ver docs/checklist-c1-c2.md)
- [~] **C2 — Modalidad Izipay** (portar izipay_create/ipn/verify; credenciales en .env; IPN HTTPS; probar en TEST) — backend portado (inerte); falta credenciales .env + wirear UI + probar TEST
- [x] **D — KDS** por ubicación (pantalla cocina, beep WebAudio, estados, timers/colores, drag, historial; polling) — `admin/kds/index.php` + `api/kds_pedidos|update|historial.php`. Además `admin/pedidos/` (bandeja en el admin). Requiere `install/pedidos.sql` (incluye `origen`/`completado_at`) o `install/pedidos_kds.sql` si ya existía la tabla.
- [ ] **E — POS** (caja, favoritos, pedidos presenciales)
- [ ] **F — Reservas**
- [ ] **G — Lealtad** + validador
- [ ] **Transversal — Analítica** (visitas/clics landing + pedidos) y captura de leads

## Landing link-in-bio ✅ EN VIVO (elgringo.pe)
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

## Mapa de URLs (oficial)
Admin base en `/cotizador` (alias `/admin` opcional vía .htaccess, sin mover nada).

### Público (sin login) — raíz elgringo.pe
- `/`                              → Landing link-in-bio
- `/{slug}`                        → Carta de venta por ubicación (ej. /burgerjoint)
- `/{slug}/menu`                   → Menú solo lectura por ubicación
- `/cotizador/solicitud`           → Formulario público de cotización
- `/cotizador/quotes/view?token=`  → Cotización pública (cliente)
- `/cotizador/quotes/pdf?id=`      → PDF

### Admin (login en /cotizador/auth/login)
- Principal:   /cotizador/admin/dashboard
- Ventas:      /cotizador/admin/cartas | /pedidos | /pos | /kds
- Cotizaciones:/cotizador/quotes/create | /quotes/list | /admin/events/create | /admin/calendar | /admin/requests
- CRM:         /cotizador/admin/clients | /reservas | /lealtad
- Catálogo:    /cotizador/admin/products | /categories | /packages
- Sitio:       /cotizador/admin/landing | /ubicaciones | /analytics
- Admin:       /cotizador/admin/users | /settings

### APIs internas
- /cotizador/api/quotes | /api/carta?slug= | /api/pedido | /api/pedidos | /api/izipay/{create,ipn,verify} | /api/track
