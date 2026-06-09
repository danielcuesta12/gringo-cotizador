# Checklist C1 (carta + venta WhatsApp) y C2 (Izipay) — pasos para activar

Todo el código está en `main` y desplegado, pero **inerte** hasta que hagas estos
pasos (necesitan tu BD / .htaccess / credenciales — por eso no los hice yo).
Nada de lo nuevo afecta lo que ya funciona: las cartas de venta viven en URLs
nuevas (`/{slug}`) que se activan solo cuando agregues la regla al `.htaccess`.

## 1. Traer el código
```
cd /home/ebakxdhm/elgringo/cotizador && git pull origin main
```

## 2. Crear la tabla de pedidos (phpMyAdmin → SQL)
Pega el contenido de `cotizador/install/pedidos.sql`.

## 3. Activar las URLs de carta de venta — `.htaccess` de la raíz `elgringo/`
Reemplázalo por esto (añade la última regla; mantiene landing y menú):
```apache
RewriteEngine On
<IfModule LiteSpeed>
  RewriteRule .* - [E=Cache-Control:no-cache]
</IfModule>
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Landing
RewriteRule ^$ cotizador/landing.php [L]

# Menú de solo lectura  ->  /{slug}/menu
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([a-zA-Z0-9_-]+)/menu/?$ cotizador/carta/menu.php?slug=$1 [L,QSA]

# Carta de venta  ->  /{slug}
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([a-zA-Z0-9_-]+)/?$ cotizador/carta/index.php?slug=$1 [L,QSA]
```

## 4. Configurar las ubicaciones (Admin → Sitio → Ubicaciones)
Por cada ubicación, elige su **modalidad de venta**:
- **Solo menú** → `/{slug}` redirige a `/{slug}/menu` (sin venta).
- **WhatsApp** → carta con carrito; el pedido se envía al **WhatsApp de la ubicación** (pon el número en la ubicación) y se guarda en `pedidos`. ✅ **Listo y funcional.**
- **Izipay** → ver paso 5 (falta tu activación). Por ahora una ubicación Izipay usa el checkout de WhatsApp como respaldo.

## 5. Izipay (C2) — requiere TUS credenciales + prueba en TEST
Backend ya portado: `api/izipay_config.php`, `izipay_create.php`, `izipay_verify.php`,
`izipay_ipn.php`, `izipay_store_pending.php` (firma HMAC + idempotencia, igual que marcona).
Para activarlo:
1. Agrega al `cotizador/.env` tus llaves:
   ```
   IZIPAY_MODE=TEST
   IZIPAY_SHOP_ID=...
   IZIPAY_REST_PASS_TEST=...      IZIPAY_REST_PASS_PROD=...
   IZIPAY_HMAC_TEST=...           IZIPAY_HMAC_PROD=...
   IZIPAY_PUBLIC_KEY_TEST=...     IZIPAY_PUBLIC_KEY_PROD=...
   IZIPAY_REST_SERVER=https://api.micuentaweb.pe
   IZIPAY_JS_URL=https://static.micuentaweb.pe/static/js/krypton-client/V4.0/stable/kr-payment-form.min.js
   ```
2. En el Back Office de Izipay, configura la **URL de IPN**:
   `https://elgringo.pe/cotizador/api/izipay_ipn.php`
3. **Falta wirear el formulario de pago embebido en la carta** (el UI). Lo dejé sin
   hacer a propósito: activar un flujo de pago sin poder probarlo en TEST es
   riesgoso. Cuando vuelvas y tengas las llaves, lo conectamos y probamos juntos
   en modo TEST antes de pasar a PROD.

## Probar C1 ahora (tras pasos 1-4)
- Crea/edita una ubicación con modalidad **WhatsApp** y su número.
- Asígnale ítems con precio (Ubicaciones → Ítems).
- Abre `elgringo.pe/{slug}` → arma un pedido → "enviar por WhatsApp".
- El pedido aparece en la tabla `pedidos` (la vista de Pedidos/KDS llega en fases D/E).
