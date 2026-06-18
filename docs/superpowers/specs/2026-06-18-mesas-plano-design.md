# Mesas POS — Sub-build A: Mesas & Plano interactivo

**Fecha:** 2026-06-18
**Módulo:** POS de restaurante — mesas (parte 1 de 3)
**Estado:** diseño aprobado, pendiente de plan de implementación

## Contexto y decomposición

El POS actual (`pos/terminal.php`, `api/pos.php`, KDS) maneja un `pedido` como **ticket único**: se crea, va al KDS con su timer, se paga de una y entra al arqueo del turno. **No existe ningún concepto de mesa.** Existe `empleados` (módulo de asistencia) con `nombre`, `pin_hash`, `cargo`, `user_id`, `ubicacion_id` — reutilizable para los mozos.

Se quiere un **POS de restaurante completo**: mesas, cuentas abiertas, mozos tomando pedidos desde el celular, comandas por ronda al KDS, precuenta, división de cuenta (4 modos) y cobro por parte. Es demasiado para un solo spec, así que se decompuso en **3 sub-builds secuenciales**, cada uno desplegable:

- **A — Mesas & Plano (este spec):** modelo de datos de mesas/pisos + editor de plano interactivo multi-piso (admin) + render reutilizable del plano.
- **B — Cuentas & Mozo:** abrir cuenta en una mesa, app del mozo (login PIN), tomar pedidos → comandas al KDS (que muestra la mesa).
- **C — Precuenta, Split & Cobro:** precuenta imprimible, los 4 modos de split, cobro por parte con método y comprobante propios, integración al arqueo.

Cada sub-build tiene su propio ciclo spec → plan → implementación.

## Decisiones tomadas (brainstorming, validadas con mockups)

1. **Representación de mesa:** forma + tamaño según capacidad (cuadrada o redonda, redimensionable). Número + estado + monto encima.
2. **Editor:** lienzo libre donde el admin coloca mesas, las **redimensiona** con tiradores y les fija el **nº de comensales**, número/nombre y forma.
3. **Multi-piso:** un local tiene varios pisos/salones, cada uno con su propio plano (pestañas).
4. **Además de mesas, el plano admite:** etiquetas de texto, formas decorativas (barra/pared/puerta) e **imagen de fondo para calcar** (por piso).
5. **Estado de la mesa** (libre/ocupada/precuenta/por cobrar) NO se guarda en la mesa — se deriva de la cuenta abierta (Sub-build B). En A todas las mesas se ven "libres".

## A.1 — Modelo de datos

### `mesa_pisos` (pisos / salones)
```sql
id           INT UNSIGNED PK AUTO_INCREMENT
ubicacion_id INT UNSIGNED NOT NULL
nombre       VARCHAR(80) NOT NULL          -- "Piso 1", "Terraza"
orden        INT NOT NULL DEFAULT 0
fondo_img    VARCHAR(255) NULL             -- imagen de fondo (ruta relativa, como uploadImage)
ancho        INT NOT NULL DEFAULT 1000     -- dimensiones lógicas del lienzo (unidades)
alto         INT NOT NULL DEFAULT 700
activo       TINYINT(1) NOT NULL DEFAULT 1
created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
KEY (ubicacion_id)
```

### `mesas`
```sql
id           INT UNSIGNED PK AUTO_INCREMENT
piso_id      INT UNSIGNED NOT NULL
ubicacion_id INT UNSIGNED NOT NULL          -- denormalizado para consultas rápidas
numero       VARCHAR(20) NOT NULL           -- "1", "T-3" (número o nombre corto)
capacidad    INT NOT NULL DEFAULT 4         -- comensales
forma        ENUM('cuadrada','redonda') NOT NULL DEFAULT 'cuadrada'
pos_x        INT NOT NULL DEFAULT 0          -- coordenadas en el espacio lógico del piso
pos_y        INT NOT NULL DEFAULT 0
ancho        INT NOT NULL DEFAULT 60         -- redimensionable
alto         INT NOT NULL DEFAULT 60
activa       TINYINT(1) NOT NULL DEFAULT 1
created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
KEY (piso_id), KEY (ubicacion_id)
```

### `mesa_elementos` (decoración del plano)
```sql
id        INT UNSIGNED PK AUTO_INCREMENT
piso_id   INT UNSIGNED NOT NULL
tipo      ENUM('etiqueta','forma') NOT NULL
texto     VARCHAR(120) NULL               -- contenido cuando tipo='etiqueta'
pos_x     INT NOT NULL DEFAULT 0
pos_y     INT NOT NULL DEFAULT 0
ancho     INT NOT NULL DEFAULT 100
alto      INT NOT NULL DEFAULT 30
KEY (piso_id)
```

Sistema de coordenadas: cada piso define un lienzo lógico (`ancho`×`alto`, default 1000×700). Mesas y elementos guardan `pos_x/pos_y/ancho/alto` en esas unidades. El render escala el lienzo para caber en pantalla (responsive), conservando proporción.

## A.2 — Editor de plano (`admin/mesas/index.php`)

Página admin gateada por el permiso **`mesas`**. Estructura:
- **Pestañas de piso** (arriba): seleccionar piso, agregar piso, renombrar, eliminar (con confirmación; eliminar piso borra sus mesas/elementos).
- **Barra de herramientas:** agregar mesa redonda, agregar mesa cuadrada, agregar etiqueta de texto, agregar forma decorativa; subir/cambiar **imagen de fondo** del piso; botón Guardar.
- **Lienzo** con cuadrícula y **snap a grilla**. Mesas/elementos se **arrastran** y se **redimensionan** con tiradores de esquina.
- **Panel de propiedades** del elemento seleccionado: para mesa → número/nombre, comensales (−/+), forma (cuadrada/redonda), eliminar; para etiqueta → texto; para forma → tamaño/eliminar.
- Toda la lógica del canvas vive en `assets/js/plano-editor.js`. El guardado serializa el piso completo (mesas + elementos + fondo + dims) y lo manda a `api/mesas.php`.

## A.3 — Render del plano (componente reutilizable)

`assets/js/plano-render.js` — dibuja un piso en modo **read-only** a partir del JSON del plano: posiciona mesas (con número, forma, color de estado, y monto si lo hubiera), etiquetas, formas y fondo, escalando el lienzo lógico al contenedor. Recibe un callback `onMesaTap(mesaId)` para uso operativo (lo usará la app del mozo en Sub-build B).

**Mapa de estados → color** (definido aquí; los 3 últimos se activan en B):
- `libre` → blanco/borde verde
- `ocupada` → amarillo (`#FFDF00`)
- `precuenta` → rosa (`#FFBBC8`)
- `por_cobrar` → naranja

`admin/mesas/tablero.php` — vista operativa que usa `plano-render.js` para mostrar las mesas de un local por piso. En Sub-build A todas salen "libre" (sin cuentas todavía); valida que el render funcione y deja el tablero listo para que B lo encienda.

## A.4 — API (`api/mesas.php`)

`requireLogin()` + `can('mesas')`; `verifyCsrf()` en escrituras (header `X-CSRF-Token`). Acciones:
- `plano` (GET, param `ubicacion_id`) → JSON con pisos + mesas + elementos del local.
- `guardar_piso` (POST) → upsert de un piso y su contenido (mesas + elementos): crea/actualiza/borra según el JSON enviado, en una transacción.
- `crear_piso` / `renombrar_piso` / `eliminar_piso` (POST).
- `subir_fondo` (POST, multipart) → `uploadImage($_FILES, 'planos')` → guarda en `mesa_pisos.fondo_img`.

## A.5 — Permisos, navegación, multi-local

- **Permiso nuevo `mesas`** añadido al catálogo de `includes/permissions.php` y a los checkboxes del form de usuarios; admin siempre `true`.
- Link en el sidebar (`admin/layout-top.php`), grupo **Operación · POS y Cartas**, gateado por `can('mesas')`.
- Todo por `ubicacion_id` (multi-local), consistente con el resto.

## Convenciones / seguridad
- PHP puro + PDO, prepared statements (`?`), nunca concatenar variables en SQL.
- `verifyCsrf()` en POST del admin y escrituras de la API; sanitizar con `clean*()`; `requirePermission('mesas')` / `can('mesas')`.
- `uploadImage` retorna ruta relativa (ej. `planos/x.png`); el consumidor antepone `UPLOAD_URL`.
- Marca: negro `#1E1E1E`, amarillo `#FFDF00`, rosa `#FFBBC8`, crema `#FFEFBC`. Editor pensado para escritorio/tablet; el tablero (render) debe verse bien también en celular.
- No hay framework de tests: verificación = `php -l` + `node --check` del JS + checklist funcional.

## Migración / despliegue
- `install/56_mesas.sql` (crea `mesa_pisos`, `mesas`, `mesa_elementos`, con `CREATE TABLE IF NOT EXISTS`); añadir fila a `install/check_migraciones.sql`.

## Fuera de alcance de A (va en B/C)
Cuentas abiertas, comandas al KDS, app del mozo, login PIN, estados reales de mesa, precuenta, split, cobro, arqueo. A entrega solo: **definir el plano (editor) y renderizarlo (tablero)**.
