# Liquidación de evento (food truck) — Diseño maestro

**Fecha:** 2026-06-16
**Estado:** aprobado en brainstorming. Módulo grande → **decompuesto en 4 sub-proyectos** (cada uno con su propio plan).
**Mockups:** `docs/superpowers/specs/mockups/` → `receta-insumo-vivo.html`, `foodtruck-control-diario.html`, `liquidacion-evento-pipeline.html`.

## Objetivo
Poder **liquidar cada evento del food truck**: saber cuánto se vendió, cuánto costó la mercadería, cuánto los descartables y otros gastos, y al final la **utilidad real** y el **rendimiento (margen %)** — para comparar facturado vs utilidad y saber qué eventos rinden.

## Contexto actual
- `insumos` (nombre, unidad, costo_unitario, activo). `recetas` (product_id → insumo_id, cantidad). `insumo_stock` por ubicación. `inventario_movimientos` (ledger).
- `salida_evento.php` ya arma por productos, explota a ingredientes y ajusta, pero solo **descuenta stock con una referencia de texto libre** (no queda ligado a un evento ni se controla después).
- Modificadores: `grupos_modificadores`, `modificadores`, `product_modifier_groups`. **No tienen insumos asignados.**
- `gastos` + `gasto_categorias` (con creación rápida). `pedidos` (POS/carta) guardan `items_json` con modificadores y `ubicacion_id`. Eventos = `quotes` con `origin='event'`.

---

## El pipeline (7 etapas, sobre cimientos de catálogo)

**Cimientos (catálogo):** insumos con **tipo** (ingrediente/descartable) · receta de producto · receta de modificador — todo con **editor de insumo al vuelo**.

1. **Planificar por productos** ("100 gringos, 50 con doble carne, 100 salchipapas…").
2. **Explotar recetas** (producto + modificadores) + **ajuste por salida** (quitar un ingrediente, ej. "sin tocino", sin tocar la receta maestra).
3. **Requerimiento consolidado** (ingredientes sumados + **descartables como líneas sueltas**); revisar/corregir/confirmar.
4. **Confirmar = inventario inicial del evento** + **asignar a evento** (cotización del calendario **o** evento suelto del truck).
5. **Control diario**: consumo **teórico** (ventas POS del truck, explotando receta de producto + modificadores) → **corregido** (manual) → **conteo físico** → diferencia; saldo arrastra al día siguiente; **agregar días**; **cerrar** (sobrante vuelve al stock).
6. **Otros gastos**: personal, gas, transporte… cada uno con **categoría** (de `gasto_categorias`, creable al vuelo).
7. **Liquidación**: `Ingresos − Mercadería − Papelería/descartables − Otros gastos = Utilidad`; KPIs Facturado / Utilidad / Rendimiento %.

### Reglas clave acordadas
- **Modificadores también consumen insumos** → reciben su propia mini-receta, editable **en la ventana de modificadores**.
- **Insumo tiene tipo:** `ingrediente` (va en recetas → cuenta como **mercadería del producto**) o `descartable` (NO va en receta → se controla por **conteo** en el evento y en la liquidación sale como **Papelería**, no como mercadería). Evita ensuciar el costo del producto con cajas/vasos.
- **"Sin tocino"** = override puntual de la salida (etapa 2), no edita la receta maestra. (A futuro, un modificador "quita" puede restar un insumo del teórico del POS.)
- **Inventario del evento = libro aparte** (saldo inicial = la salida). NO re-descuenta el stock del local por las ventas del evento → sin doble conteo.
- **Ingresos** del evento = total de la **cotización** vinculada, **o** venta **asignada** (pedidos POS del evento) **o** **monto manual** (revive el "evento libre con venta asignada").

---

## Modelo de datos (nivel diseño; el plan afina DDL)
- `insumos`: **+ `tipo ENUM('ingrediente','descartable') DEFAULT 'ingrediente'`**.
- **`receta_modificadores`** (nueva): `modificador_id`, `insumo_id`, `cantidad`. (paralela a `recetas`)
- **`eventos`** (nueva): `id`, `nombre`, `quote_id` (NULL = evento suelto), `ubicacion_id` (truck), `fecha_inicio`, `fecha_fin` (NULL=1 día), `venta_manual` (NULL), `estado ENUM('planificado','abierto','cerrado')`, `created_at`.
- **`evento_insumos`** (nueva): `evento_id`, `insumo_id`, `cantidad_inicial`, `costo_unitario` (snapshot al confirmar). El inventario inicial del evento.
- **`evento_dias`** (nueva): `evento_id`, `fecha`, `dia_num`.
- **`evento_dia_conteo`** (nueva): `dia_id`, `insumo_id`, `teorico`, `corregido`, `conteo`. (inicial y saldo se derivan; teórico se calcula de los pedidos POS del truck en esa fecha explotando recetas).
- **`evento_gastos`** (nueva): `evento_id`, `categoria_id` (→`gasto_categorias`), `monto`, `descripcion`.
- Asignar ventas POS: `pedidos.evento_id` (NULL) **o** sumar pedidos del truck en el rango de fechas (a decidir en el plan de SP3/SP4).

---

## Decomposición en sub-proyectos (orden de construcción)

### SP1 — Catálogo: tipos de insumo + recetas al vuelo + recetas de modificador
Insumo gana `tipo`. Editor de receta de producto con **buscar/crear insumo al vuelo** (mini-modal: unidad + costo opcional). Sección "insumos que consume" en la **ventana de modificadores** con el mismo editor. *Útil por sí solo* (facilita recetas hoy, sin eventos). Mockup: `receta-insumo-vivo.html`.

### SP2 — Planificación por productos → requerimiento consolidado
Mejora de `salida_evento`: planificar por **productos + modificadores**, override "sin X" por producto, **consolidar** (ingredientes + descartables sueltos), revisar/corregir/confirmar.

### SP3 — Evento + inventario inicial + control diario
Entidad `eventos` (vínculo a cotización o suelto). Confirmar SP2 = inventario inicial. Control diario (teórico POS + corregido + conteo, multi-día, cerrar). Mockup: `foodtruck-control-diario.html`.

### SP4 — Liquidación
Ingresos (cotización / POS / manual) + mercadería (de receta) + papelería (descartables por conteo) + otros gastos (categorizados) → utilidad + rendimiento; comparativa facturado vs utilidad. Mockup: `liquidacion-evento-pipeline.html`.

**Orden:** SP1 → SP2 → SP3 → SP4. Cada uno es entregable y testeable por sí mismo.

---

## Qué NO incluye (YAGNI / futuro)
Reconocimiento de "quita" en el teórico del POS (modificador que resta insumo); prorrateo de gastos fijos entre eventos; planilla; integración contable. El modelo queda preparado pero no se construye en v1.

## Pruebas (manual; no hay framework de tests)
Por sub-proyecto: editor de receta crea/reusa insumos y modificadores; explosión por productos+modificadores con override; requerimiento consolidado correcto; confirmar crea inventario de evento y lo liga; control diario cuadra (teórico/corregido/conteo, multi-día, cierre devuelve sobrante); liquidación calcula utilidad y rendimiento con las 3 fuentes de ingreso y los 3 tipos de costo.
