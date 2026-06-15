# Proyecto SaaS — "MiPOS Pro" (nombre tentativo)

> **Estado: PAUSADO** (junio 2026). Aparcado para retomar luego; mientras tanto se sigue trabajando el cotizador/El Gringo.
> Este documento consolida todo lo conversado para poder continuar sin perder contexto.

## Idea en una frase
Convertir la plataforma de El Gringo en un **SaaS multi-cliente**: un solo código en un servidor propio, bajo un dominio comercial, que sirve a muchos restaurantes a la vez — cada uno con su tienda (subdominio o dominio propio), su BD aislada y su plan. Una actualización se publica una vez y la reciben todos, según su plan.

---

## Decisiones ya tomadas

### Modelo comercial
- **Suscripción mensual recurrente** por restaurante (NO venta única del código).
- **Planes prearmados** (no à la carte): Básico / Pro / Multi-local.
- Precios de referencia: **Básico S/99 · Pro S/199 · Multi-local S/299+** (todos /mes). + **setup único S/250–600**. + **dominio propio** con cargo extra.
- Arrancar con **2–3 pilotos manuales** (cobro por transferencia/Yape, activación manual) antes de automatizar el cobro. Lean.

### Gating por plan (entitlements) — diseño acordado, falta escribir spec
- El plan vive en `company_settings` como `plan` (`basico|pro|multilocal`). Un solo valor por instancia.
- El **mapa plan → módulos vive en código** (nuevo `includes/plans.php`), no en BD.
- **Clave técnica:** `can('clave')` pasa a componer **plan + permiso de usuario** → con cambiarlo en un solo lugar queda gateado el sidebar Y las páginas. Aplica también al admin del cliente (si no compró POS, ni el dueño lo ve).
- **Compatibilidad:** si `plan` está vacío → se asume **"full"** (todo encendido). El Gringo y Marcona quedan intactos.
- **Módulos bloqueados = ocultos del todo** (no candado con "mejora tu plan").
- El plan se setea desde **Ajustes de cada instancia** (admin); no hay panel central todavía.

#### Mapa de módulos por plan (propuesto, validar antes de implementar)
| Módulo | Básico | Pro | Multi-local |
|---|:--:|:--:|:--:|
| Carta online + Pedidos WhatsApp + Landing/QR + Catálogo + Reservas | ✅ | ✅ | ✅ |
| POS (caja, KDS, métodos, clientes) | — | ✅ | ✅ |
| Facturación SUNAT + Izipay | — | ✅ | ✅ |
| Analítica + Gastos | — | ✅ | ✅ |
| Inventario (insumos, recetas, compras) | — | — | ✅ |
| Cotizaciones / Eventos (catering B2B) | — | — | ✅ |
| Cartas PDF | — | — | ✅ |
| Multi-local (>1 ubicación) | — | — | ✅ |

### Infraestructura
- **Salir del hosting compartido → VPS** (mismo stack LAMP/MySQL, no se reescribe nada). **NO migrar a Supabase/Postgres** (no encaja con PHP server-rendered; reescribir SQL sin beneficio).
- Proveedor sugerido: **DigitalOcean o Vultr (región Miami/US-East)** por latencia a Perú; ~US$12–25/mes para arrancar.
- Administrarlo con **panel** (Cloudways gestionado, o CloudPanel/cPanel) para no hacer sysadmin a mano.
- Si escala mucho: **MySQL administrado** (DO Managed MySQL / RDS / PlanetScale) — sigue siendo MySQL.

### Dominio y multi-tenant
- Dominio comercial (tentativo **mipospro.pe** — el nombre se debe afinar; ideas: Carteo, Tienda Lista, Gastora, Servida, Pideli…). Ahí vive la **web de oferta**.
- **DNS comodín `*.mipospro.pe`** → todo al VPS + **SSL comodín** (Let's Encrypt). Alta de subdominio = crear `.env` + BD.
- Resolución por dominio ya implementada: `config.php` lee `HTTP_HOST` → `.env.<host>` → BD propia. (Ya funciona con Marcona.)

### Las dos opciones del cliente
- **Subdominio** (`marcona.mipospro.pe`): barato, alta inmediata (DNS comodín ya apunta).
- **Dominio propio** (`marcona.pe`): cuesta más — el cliente apunta su DNS al VPS, se agrega su `.env.<dominio>` y se emite SSL específico. Justifica precio/fee mayor.

### "Actualizo una vez y vale para todos"
- **Código:** todos comparten la misma carpeta → un `git pull` en el VPS actualiza a todos al instante. (Ya es así.)
- **Pendiente importante:** las **migraciones de BD** hay que aplicarlas a CADA BD de cada cliente. Hoy es manual. **Hay que construir un "corredor de migraciones"** que recorra todas las tiendas y aplique las nuevas de un golpe.

### Pasarela / cobro
- La web de oferta tendrá **su propia pasarela** (Izipay o Culqi) para la suscripción. Idealmente **cobro recurrente**. Empezar manual.

### White-label — lo que falta para "100% configurable"
- Ya hecho: colores, logos, ícono de app, correo de marca, dominio, configuración en `company_settings`.
- **Falta:** tipografías **genéricas por defecto** + opción de personalizar (hoy la carta usa fuentes propias tipo Kimmy/DIN). Barrer cualquier marca fija restante. Panel donde el cliente sube logo, elige colores y (si pagó) conecta su dominio.

---

## Posicionamiento (de la comparativa)
- Competidores: **Wally** (S/49–199/mes, POS+facturación+inventario, maduro) y **Restaurant.pe** (POS+facturación, poco público). Ambos son **back-office**.
- **Nuestra cuña:** ninguno le da al restaurante su **tienda online de cara al cliente** (carta multi-local, delivery propio sin comisión, reservas, catering). NO competir como "POS más barato" (ahí pierde contra Wally) — vender la presencia digital + delivery propio + operación integrada.
- Mensaje: *"Tu carta online + delivery propio + reservas + caja + facturación, en una sola plataforma, sin comisiones de delivery."*
- Ver `comparativa-competencia.html` y `pitch-socio.html`.

---

## Orden de construcción recomendado
1. **Gating por plan** (cimiento; diseño ya acordado, falta spec + build).
2. **Terminar white-label** (tipografías genéricas + panel de marca del cliente).
3. **Mudanza a VPS** + dominio comodín + SSL.
4. **Corredor de migraciones** (aplicar migraciones a todas las BD de una).
5. **Web de oferta + alta de clientes** (manual primero).
6. **Cobro de suscripción recurrente** (al final).

## Riesgos / pendientes a cubrir antes de vender
- **NubeFact a PRODUCCIÓN** (hoy demo = sin valor legal).
- **Soporte / onboarding** (los competidores establecidos tienen ventaja ahí).
- **Entregabilidad de correo** (migrar de `mail()` a SMTP autenticado — ver pendientes del cotizador).

## Ideas adicionales del usuario (pendientes de detallar)
- _(Por completar — el usuario tiene ideas extra para el sistema que aportará luego.)_

## Artefactos en esta carpeta
- `comparativa-competencia.html` — comparativa El Gringo vs Wally vs Restaurant.pe.
- `pitch-socio.html` — infografía de pitch para socio comercial.
