# POS (punto de venta) — Diseño

**Fecha:** 2026-06-11
**Rama:** `pos`
**Estado:** Diseño aprobado (validado con mockups en companion visual)

---

## Contexto

Siguiente paso del programa de migración de marcona (D KDS ✅ → **E POS**). marcona tiene un POS completo (terminal `pos/index.html` ~2.800 líneas + APIs `pos_caja`/`pos_pedidos`/`pos_favoritos`/`pos_email` + tabs admin). Se **reimplementa en el stack de Lima** (PHP + clase `Database` + layout del panel), reusando lo que Lima ya tiene: `products`, `categories`, `ubicaciones`, `clients`, `pedidos` y el KDS. No es copy-paste; se adapta y se **mejora**.

**Aislamiento:** módulo nuevo; no toca `carta/*`, generador, ni lo existente, salvo añadir columnas a `pedidos` (compatibles) y un filtro de origen en `admin/pedidos`.

## Objetivos

1. **Terminal de venta** táctil (tablet) y usable en PC: vender rápido → genera un `pedido` (origen=pos) que entra al KDS.
2. **Caja por turnos**: apertura (monto inicial) y cierre con arqueo y totales por método.
3. **Control en tres niveles separados** (sin mezclar): **turno** (caja) → **día** (monitor) → **histórico de turnos** (historial de caja).
4. **Monitor de ventas en vivo** (solo dueño): hoy en tiempo real o cualquier día pasado completo.
5. Todo el **historial visible en el admin**, **diferenciado por origen** (carta vs pos).
6. **Preparado para SUNAT/RENIEC** (boleta/factura electrónica e identificación DNI/RUC) sin construir la integración aún.

## No-objetivos (por ahora)

- **No** se construye la integración SUNAT (emisión electrónica) ni RENIEC (consulta DNI/RUC) — se dejan los enganches; es una **Fase futura** con credenciales/OSE.
- **No** se diseña el control fino de permisos/roles aquí — se **consolida en la configuración del admin al final**. Por ahora: dueño/admin ve todo; cajero usa el terminal.
- No se toca el diseño del KDS ni de la carta.

---

## Piezas (6) y los tres niveles

| Pieza | Nivel | Quién | Qué |
|---|---|---|---|
| **Terminal de venta** | turno | cajero | Pantalla de venta. Barra inferior: Vender · Historial (del turno) · Caja · Clientes · Cerrar turno. |
| **Caja / turnos** | turno | cajero | Apertura (monto inicial) y cierre con arqueo; ventas y totales **del turno actual**. |
| **Historial de caja** | histórico de turnos | dueño (admin) | Registro de todos los turnos: cajero, ubicación, apertura/cierre, monto inicial/final, totales por método, **diferencia de arqueo**. |
| **Monitor de ventas en vivo** | día | dueño (página aparte) | Hoy en tiempo real, o un día pasado completo: total del día, ticket promedio, top productos. NO accesible a cajeros. |
| **Métodos de pago** | — | dueño (admin) | Config: Efectivo, Tarjeta, Yape/Plin… activar/ordenar/agregar. |
| **Clientes POS** | — | dueño (admin) | Ventas con DNI/RUC/razón social; historial por cliente. |

**Distinción crítica:** el control de caja es **por turno** (el cajero solo ve su turno); el monitor es **por día** (todos los turnos juntos); el historial de caja lista los **turnos** con su arqueo. Nunca se confunden ventas del día con ventas del turno.

---

## Arquitectura / componentes

**Stack:** Lima — PHP 8 + `Database` (PDO), layout del panel para las vistas de admin; el **terminal** es una página propia enfocada (full-screen, no usa el sidebar) consumida por JS vía un API JSON (`api/pos.php`), patrón igual al editor del generador.

**Archivos previstos (se detallan en los planes por fase):**
- `install/pos.sql` — esquema (tablas nuevas + columnas a `pedidos`).
- `api/pos.php` — endpoints JSON (CSRF + login): productos por ubicación, favoritos, abrir/cerrar turno, registrar venta, métodos, datos de turno actual, etc.
- `pos/terminal.php` (o `admin/pos/terminal.php`) — el terminal del cajero.
- `admin/pos/metodos.php` — métodos de pago.
- `admin/pos/caja.php` — historial de caja (turnos/arqueo).
- `admin/pos/clientes.php` — clientes POS.
- `admin/pos/monitor.php` — monitor en vivo (día), **solo admin**.
- `admin/pedidos` — gana filtro/badge de **origen** (carta/pos).
- Entradas de nav en `admin/layout-top.php`.

## Datos

**Tablas nuevas:**
```
pos_turnos        — id, usuario_id, ubicacion_id, monto_inicial, monto_final,
                    total_efectivo, total_tarjeta, total_qr, total_otros,
                    total_ventas, total_pedidos, abierto_en, cerrado_en,
                    estado ENUM('abierto','cerrado')
pos_metodos_pago  — id, nombre, icono, activo, orden
pos_favoritos     — id, ubicacion_id, producto_id, posicion (índice de celda en la cuadrícula)
                    -- 'posicion' permite un tablero editable con celdas vacías (gaps), no una lista compacta
```
**Extensiones a `pedidos`** (compatibles; no rompen carta/KDS):
```
origen ENUM('carta','pos') DEFAULT 'carta'   -- diferencia el origen de la venta
turno_id INT NULL                             -- enlaza la venta POS a su turno
metodo_pago VARCHAR(100) NULL
descuento_tipo ENUM('porcentaje','monto') NULL, descuento_valor DECIMAL, descuento_monto DECIMAL
cliente_tipo ENUM('nombre','dni','ruc') NULL, cliente_nombre, cliente_documento, cliente_razon_social
comprobante_tipo ENUM('ticket','boleta','factura') DEFAULT 'ticket'   -- intención de comprobante (SUNAT-ready)
notas_pos TEXT NULL
```
Las ventas POS son filas en `pedidos` con `origen='pos'` y `turno_id`; reusan `pedido_items` (o equivalente que ya use el KDS).

## Flujos

- **Abrir caja:** cajero elige ubicación + monto inicial → crea `pos_turnos` (estado abierto). Sin turno abierto, el terminal pide abrir caja.
- **Vender:** agrega productos (grilla/favoritos/búsqueda) → descuento opcional → cliente opcional (DNI/RUC + botón "Buscar" inerte por ahora) → método de pago → **Cobrar**. En efectivo pide monto recibido y muestra **vuelto**. Crea `pedido` (origen=pos, turno_id, método, comprobante_tipo) → entra al **KDS**.
- **Historial (terminal):** ventas **del turno actual** (reimprimir/anular según permita la fase).
- **Cerrar caja:** arqueo (conteo de efectivo) vs total del turno → guarda monto_final y totales → cierra turno.
- **Monitor (admin):** polling cada pocos segundos para el día de hoy (en vivo); selector de fecha para días pasados (agregados de ese día).

## Interacción táctil (terminal)

El terminal es **táctil-first** (tablet) y debe sentirse como una app nativa:
- **Carrito — deslizar para eliminar:** swipe a la izquierda sobre una línea del pedido la quita (con un botón rojo que aparece al deslizar); también hay un botón de quitar visible como respaldo. Confirmación rápida (deshacer breve) para evitar borrados accidentales.
- **Agregar:** un toque en el tile del producto lo suma al pedido (feedback visual/animación corta).
- **Cantidad:** botones **+/−** grandes por línea; tocar la cantidad permite teclear el número.
- **Targets grandes**, sin nada dependiente de hover; scroll suave en grilla y carrito.
- **Favoritos** y **categorías** como pestañas grandes para acceso de un toque.
- **Cuadrícula de favoritos editable:** un **tablero** donde colocas cada producto en la celda que quieras (arrastrar/asignar/quitar) y **se permiten celdas vacías** (los huecos se respetan, no se compactan). Se guarda por `posicion` en `pos_favoritos`. Pensado para el acceso rápido a lo más vendido, ordenado a tu gusto.
- En **PC** los mismos elementos funcionan con click; los gestos son una mejora táctil, no un requisito para operar.

## SUNAT / RENIEC — preparado, no construido

- **RENIEC/consulta:** el bloque de cliente captura tipo+doc+nombre+razón; un botón **"Buscar"** queda como enganche (manual hoy → consulta real luego). Sin cambios de esquema posteriores.
- **SUNAT:** la venta ya captura `comprobante_tipo`, cliente e IGV. Se deja un **seam "emitir comprobante"** (no-op/`no_emitido` hoy). La emisión real (XML/CDR, serie/correlativo, estado, PDF) se agrega en una **Fase futura** por migración, sin rearquitectura.

## Construcción por fases (rama `pos`, preview antes de merge)

- **F1 — Base:** `install/pos.sql` + Métodos de pago (admin) + Terminal básico (abrir caja → vender → genera `pedido` que entra al KDS) + cerrar caja con arqueo simple.
- **F2 — Cobro completo:** cobro/vuelto, descuento, cliente DNI/RUC (con botón "Buscar" inerte) + `comprobante_tipo`, favoritos, ticket (PDF/email).
- **F3 — Admin/control:** Historial de caja (turnos + arqueo) + Clientes POS + filtro de **origen** (carta/pos) en `admin/pedidos`.
- **F4 — Monitor en vivo:** página del dueño (hoy en tiempo real + días pasados).
- **F5 (futura):** integración SUNAT (emisión) + RENIEC (consulta DNI/RUC).

## Criterios de éxito

- Un cajero abre caja, vende varios pedidos (que aparecen en el KDS), cobra en efectivo con vuelto y en otros métodos, y cierra caja con arqueo correcto.
- El terminal solo muestra el turno actual; el admin muestra el histórico de turnos y las ventas por origen.
- El monitor (admin) muestra el día en vivo y permite ver días pasados.
- Las ventas POS y de carta se distinguen por `origen` en el admin.
- Nada de lo existente (carta, generador, KDS) se rompe.

## Pruebas / verificación

- `php -l` en cada archivo; revisión de seguridad del API (CSRF + login + prepared statements) como en `api/cartas.php`.
- Preview humano por fase (requiere aplicar `install/pos.sql`).
- Probar el flujo completo abrir→vender→cobrar→cerrar y el arqueo; confirmar que los pedidos POS llegan al KDS y que `admin/pedidos` los distingue por origen.

## Dependencias / riesgos

- Aplicar `install/pos.sql` (tablas + columnas a `pedidos`) en la BD — nota de despliegue por fase.
- Compatibilidad con la tabla `pedidos` existente de Lima: las columnas se agregan como nullable/con default para no romper la carta ni el KDS.
- El terminal es grande; se construye por fases y se valida en preview antes de merge.
- SUNAT/RENIEC: dependen de credenciales/OSE y certificado digital — explícitamente fuera de estas fases.
