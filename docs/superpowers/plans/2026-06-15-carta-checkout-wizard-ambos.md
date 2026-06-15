# Carta · Checkout Wizard + Venta por Ambos Métodos — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rediseñar el checkout de la carta pública como un wizard de 3 pasos, permitir venta simultánea por WhatsApp + Izipay (modo `ambos`), unificar la pantalla de pedido confirmado (según tema día/noche) y resetear todo el estado tras cada pedido.

**Architecture:** Cambio aislado a `carta/index.php` (UI pública) + `ubicaciones.sales_mode` (enum) + form/listado de Ubicaciones. KDS/POS/NubeFact/arqueo NO se tocan: el wizard produce el mismo `pedido` (mismos campos `items_json`, `total`, `metodo_pago`, `comprobante_*`, `origen='carta'`). El método por pedido (`metodo_pago='whatsapp'|'izipay'`) ya lo registra `api/pedido.php` y los badges existentes ya lo leen.

**Tech Stack:** PHP 8 plano, MySQL/PDO, HTML/CSS/JS vanilla. **Sin framework de tests** → verificación = `php -l` (sintaxis) + prueba manual en navegador (instancia de prueba o local). Convenciones del repo en `CLAUDE.md`.

**Spec:** `docs/superpowers/specs/2026-06-15-carta-checkout-wizard-ambos.md`
**Mockups aprobados (referencia visual exacta):**
- `docs/superpowers/specs/mockups/wizard-movil.html`
- `docs/superpowers/specs/mockups/wizard-escritorio.html`
- `docs/superpowers/specs/mockups/pedido-confirmado.html`

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `install/41_sales_mode_ambos.sql` | Añadir `'ambos'` al enum `ubicaciones.sales_mode` | Crear |
| `admin/locations/form.php` | Opción "Ambos" en el `<select>` + validación de POST | Modificar |
| `admin/locations/index.php` | Etiqueta `modeBadge` para `ambos` | Modificar |
| `carta/index.php` | Flags de modo, wizard (markup+CSS+JS), confirmación unificada, `resetPedido()`, comprobante en mensaje WA | Modificar (grueso) |

> `carta/index.php` es un archivo grande (~2000 líneas) con markup+CSS+JS mezclados. Cada tarea deja el archivo **sintácticamente válido y committeable**. La verificación de UI es manual contra los mockups.

---

## Task 1: Migración — `sales_mode` acepta `ambos`

**Files:**
- Create: `install/41_sales_mode_ambos.sql`

- [ ] **Step 1: Crear la migración**

Contenido exacto de `install/41_sales_mode_ambos.sql`:

```sql
-- Permite que una tienda venda por WhatsApp e Izipay a la vez.
ALTER TABLE ubicaciones
  MODIFY COLUMN sales_mode ENUM('menu','whatsapp','izipay','ambos') NOT NULL DEFAULT 'menu';
```

- [ ] **Step 2: Verificar sintaxis SQL (revisión visual)**

No hay BD local en este entorno. Confirmar que el enum lista los 4 valores y conserva `DEFAULT 'menu'`. La migración se aplica en phpMyAdmin al desplegar (ver `CLAUDE.md` → Migraciones).

- [ ] **Step 3: Commit**

```bash
git add install/41_sales_mode_ambos.sql
git commit -m "feat(carta): migración sales_mode acepta modo 'ambos'"
```

---

## Task 2: Admin Ubicaciones — opción "Ambos" + validación + badge

**Files:**
- Modify: `admin/locations/form.php` (línea ~42 validación POST; línea ~162-166 select; bloque de validación ~69)
- Modify: `admin/locations/index.php` (`$modeBadge` ~línea 24)

- [ ] **Step 1: Añadir `ambos` al whitelist del POST**

En `admin/locations/form.php` línea ~42, cambiar:

```php
'sales_mode'      => in_array($_POST['sales_mode'] ?? '', ['menu','whatsapp','izipay']) ? $_POST['sales_mode'] : 'menu',
```
por:
```php
'sales_mode'      => in_array($_POST['sales_mode'] ?? '', ['menu','whatsapp','izipay','ambos']) ? $_POST['sales_mode'] : 'menu',
```

- [ ] **Step 2: Añadir la opción al `<select>`**

En `admin/locations/form.php` (~línea 165, después de la opción `izipay`), añadir:

```php
            <option value="ambos"    <?= $data['sales_mode']==='ambos'?'selected':'' ?>>Ambos (WhatsApp + Izipay)</option>
```

- [ ] **Step 3: Validar requisitos del modo `ambos`**

En `admin/locations/form.php`, junto a la validación existente (~línea 69, `if ($data['sales_mode'] === 'whatsapp' && !$data['whatsapp_number'])`), añadir después:

```php
    if ($data['sales_mode'] === 'ambos') {
        if (!$data['whatsapp_number']) {
            $errors[] = 'El modo "Ambos" requiere un número de WhatsApp.';
        }
        require_once __DIR__ . '/../../includes/izipay.php';
        if (!izipayConfigured()) {
            $errors[] = 'El modo "Ambos" requiere que Izipay esté configurado (Facturación → Izipay).';
        }
    }
```

(Usa `$errors` igual que la validación de WhatsApp existente; revisar el nombre real de la variable de errores en el archivo y respetarlo.)

- [ ] **Step 4: Etiqueta de badge en el listado**

En `admin/locations/index.php` (~línea 24, array `$modeBadge`), añadir la clave `'ambos'`. Ejemplo (ajustar clases a las que ya usa el array):

```php
    'ambos'    => ['badge-info', 'WhatsApp + Izipay'],
```

- [ ] **Step 5: Verificar sintaxis**

Run: `php -l admin/locations/form.php && php -l admin/locations/index.php`
Expected: `No syntax errors detected` en ambos.

- [ ] **Step 6: Commit**

```bash
git add admin/locations/form.php admin/locations/index.php
git commit -m "feat(locations): opción de venta 'ambos' (WhatsApp + Izipay) con validación"
```

---

## Task 3: Carta — flags de modo y carga de Izipay también en `ambos`

Hoy la maquinaria de Izipay (config, SDK, modales) está gateada con `$salesMode === 'izipay'`. Para `ambos` debe cargar igual. Se introducen dos flags y se reemplazan los guards.

**Files:**
- Modify: `carta/index.php` (líneas 19, 30, 692, 725, 1071, 1460, 1853, 1962 — y donde aparezca `$salesMode === 'izipay'` / `SALES_MODE === 'izipay'`)

- [ ] **Step 1: Definir flags tras leer `$salesMode`**

En `carta/index.php` después de la línea 19 (`$salesMode = $ubi['sales_mode'];`), añadir:

```php
$showCard = in_array($salesMode, ['izipay', 'ambos'], true);  // botón/maquinaria Izipay
$showWa   = in_array($salesMode, ['whatsapp', 'ambos'], true); // botón WhatsApp
```

- [ ] **Step 2: Reemplazar los guards PHP de Izipay por `$showCard`**

Reemplazar TODAS las apariciones de `$salesMode === 'izipay'` que **cargan maquinaria de Izipay** por `$showCard`. Ubicaciones conocidas:
- L30: `if ($salesMode === 'izipay') {` → `if ($showCard) {`
- L1853: `<?php if ($salesMode === 'izipay'): ?>` (bloque de scripts Izipay) → `<?php if ($showCard): ?>`
- L1962: `<?php if ($salesMode === 'izipay'): ?>` (modales Izipay) → `<?php if ($showCard): ?>`

> Los guards de los **botones del carrito** (L692, L725) y el **botón del modal** (L886) se eliminan en las tareas del wizard (4–7); por ahora déjalos, pero cámbialos a `$showCard`/`$showWa` solo si quedan tras esas tareas. Verificar con: `grep -n "salesMode === 'izipay'" carta/index.php` al final → no debe quedar ninguno que bloquee `ambos`.

- [ ] **Step 3: Exponer flags al JS**

En `carta/index.php` ~L1071 (donde está `const SALES_MODE = ...`), añadir debajo:

```js
  const SHOW_CARD = <?= $showCard ? 'true' : 'false' ?>;
  const SHOW_WA   = <?= $showWa ? 'true' : 'false' ?>;
```

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l carta/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Verificación manual**

En instancia de prueba: una tienda en `izipay` y otra en `whatsapp` siguen cargando igual que antes (el SDK de Izipay aparece solo donde `$showCard`). Una tienda en `ambos` carga el SDK de Izipay sin romper.

- [ ] **Step 6: Commit**

```bash
git add carta/index.php
git commit -m "feat(carta): flags showCard/showWa; Izipay carga también en modo ambos"
```

---

## Task 4: Carta — markup del wizard (reemplaza el formulario del modal)

Reemplazar el cuerpo del modal `#modal-pedido` (L784-896) por un wizard de 3 pasos. **Conservar todos los IDs de campos existentes** (`campo-nombre`, `campo-telefono`, `campo-direccion`, `campo-comentarios`, `campo-comprobante`, `comp-fields`, `campo-doc`, `campo-razon`, `campo-email-comp`, `opt-delivery`, `opt-recojo`, `radio-delivery`, `radio-recojo`, `campo-dir-wrap`, errores `err-*`) para no romper el JS existente.

**Files:**
- Modify: `carta/index.php` (L784-896, contenido de `#modal-pedido`)

- [ ] **Step 1: Reestructurar el modal como wizard**

El contenedor `#modal-pedido` pasa a tener 3 paneles + barra superior con progreso + pie. Estructura (replicar el layout visual del mockup `wizard-movil.html`):

```html
<div id="modal-pedido" class="wz-modal" style="display:none">
  <div class="wz-card">
    <!-- barra superior -->
    <div class="wz-top">
      <div class="wz-top-row">
        <button type="button" class="wz-back" id="wz-back" onclick="wizardGo(wizardStep-1)">‹</button>
        <span class="wz-title" id="wz-title">Tus datos</span>
        <span class="wz-total" id="wz-total">S/0</span>
      </div>
      <div class="wz-steps">
        <div class="wz-bar" id="wz-b0"><i></i></div>
        <div class="wz-bar" id="wz-b1"><i></i></div>
        <div class="wz-bar" id="wz-b2"><i></i></div>
      </div>
      <div class="wz-cap" id="wz-cap">Paso 1 de 3</div>
    </div>

    <div class="wz-body">
      <!-- PASO 1: datos (mover aquí los campos nombre/teléfono/entrega/dirección/comentarios EXISTENTES, con sus IDs) -->
      <div class="wz-panel wz-on" id="wz-p0"> ... campos existentes ... </div>
      <!-- PASO 2: comprobante (mover aquí el bloque comprobante EXISTENTE con sus IDs) -->
      <div class="wz-panel" id="wz-p1"> ... bloque comprobante existente ... </div>
      <!-- PASO 3: pago -->
      <div class="wz-panel" id="wz-p2">
        <div id="wz-resumen"></div>
        <div id="wz-pagos"></div>  <!-- botones inyectados por JS según modo (Task 7) -->
      </div>
    </div>

    <div class="wz-foot" id="wz-foot">
      <button type="button" class="wz-cta" id="wz-cta" onclick="wizardNext()">Continuar</button>
      <button type="button" class="wz-skip" id="wz-skip" style="display:none" onclick="wizardGo(2)">Omitir, no necesito comprobante</button>
      <button type="button" class="wz-cancel" onclick="cerrarModal()">Cancelar</button>
    </div>
  </div>
</div>
```

Mover los campos existentes (no recrearlos) dentro de `#wz-p0` y `#wz-p1` conservando IDs/onclick (`setTipoEntrega`, `onCompChange`). Eliminar el botón final único `confirmarPedido()` (se reemplaza por los botones del paso 3 en Task 7) y el bloque de lealtad oculto puede quedarse oculto dentro del paso 1.

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l carta/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verificación manual**

El modal abre y muestra el Paso 1 con los campos. (La navegación aún no funciona hasta Task 6 — es esperado.)

- [ ] **Step 4: Commit**

```bash
git add carta/index.php
git commit -m "feat(carta): markup del wizard de checkout (3 pasos), conserva IDs de campos"
```

---

## Task 5: Carta — CSS del wizard (móvil overlay + escritorio panel)

**Files:**
- Modify: `carta/index.php` (bloque `<style>` de la carta)

- [ ] **Step 1: Añadir CSS del wizard**

Añadir al `<style>` de la carta. Móvil = overlay a pantalla completa (bottom-sheet); escritorio (≥900px) = tarjeta anclada a la derecha (~380px) que visualmente ocupa el lugar del panel del carrito, como en `wizard-escritorio.html`. Variables de tema día/noche ya existen en la carta; usarlas para fondos/texto.

```css
.wz-modal{position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.55);display:flex;align-items:flex-end;justify-content:center}
.wz-card{background:var(--bg,#fff);width:100%;max-width:480px;max-height:92vh;overflow-y:auto;border-radius:16px 16px 0 0;display:flex;flex-direction:column}
.wz-top{background:#1B1F4B;color:#fff;padding:14px 18px}
.wz-top-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:11px}
.wz-back{background:rgba(255,255,255,.14);border:none;color:#fff;width:30px;height:30px;border-radius:50%;font-size:17px;cursor:pointer}
.wz-back[disabled]{opacity:.25;cursor:default}
.wz-title{font-size:14px;font-weight:800}
.wz-total{font-size:15px;font-weight:900;color:#F5C200}
.wz-steps{display:flex;gap:6px}
.wz-bar{flex:1;height:4px;border-radius:3px;background:rgba(255,255,255,.22);overflow:hidden}
.wz-bar i{display:block;height:100%;width:0;background:#F5C200;transition:width .3s}
.wz-bar.done i,.wz-bar.cur i{width:100%}
.wz-cap{font-size:11px;color:rgba(255,255,255,.7);margin-top:8px;font-weight:600}
.wz-body{padding:18px;flex:1}
.wz-panel{display:none}
.wz-panel.wz-on{display:block;animation:wzSlide .25s ease}
@keyframes wzSlide{from{opacity:0;transform:translateX(12px)}to{opacity:1;transform:none}}
.wz-foot{padding:0 18px 20px}
.wz-cta{width:100%;padding:15px;border:none;border-radius:12px;background:#F5C200;color:#1B1F4B;font-size:15px;font-weight:900;text-transform:uppercase;letter-spacing:.6px;cursor:pointer}
.wz-skip{display:block;width:100%;text-align:center;background:none;border:none;color:#aaa;font-size:12.5px;font-weight:700;margin-top:11px;cursor:pointer}
.wz-cancel{display:block;width:100%;padding:11px;background:none;border:1px solid #e0e0e0;border-radius:10px;color:#888;font-size:13px;margin-top:8px;cursor:pointer}
@media(min-width:900px){
  .wz-modal{align-items:flex-start;justify-content:flex-end;background:rgba(0,0,0,.35);padding:20px 26px}
  .wz-card{max-width:380px;border-radius:16px;position:sticky;top:20px}
}
```

(Ajustar colores a las variables de marca de la carta; `#1B1F4B`/`#F5C200` son los del mockup. Respetar tema día/noche con las variables existentes.)

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l carta/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verificación manual**

Móvil: el wizard se ve como bottom-sheet a pantalla completa. Escritorio (ventana ≥900px): el wizard aparece anclado a la derecha, con la carta visible a la izquierda. Comparar contra los mockups.

- [ ] **Step 4: Commit**

```bash
git add carta/index.php
git commit -m "style(carta): CSS del wizard (móvil overlay + escritorio panel derecho)"
```

---

## Task 6: Carta — navegación del wizard (JS)

**Files:**
- Modify: `carta/index.php` (JS; añadir junto a `enviarPedido`/`confirmarPedido`)

- [ ] **Step 1: Añadir estado y funciones de navegación**

Añadir en el JS de la carta:

```js
  let wizardStep = 0;
  const WZ_TITLES = ['Tus datos', 'Comprobante', 'Pago'];

  function wizardGo(n) {
    if (n < 0 || n > 2) return;
    wizardStep = n;
    for (let i = 0; i < 3; i++) {
      document.getElementById('wz-p' + i).classList.toggle('wz-on', i === wizardStep);
      const b = document.getElementById('wz-b' + i);
      b.className = 'wz-bar' + (i < wizardStep ? ' done' : (i === wizardStep ? ' cur' : ''));
    }
    document.getElementById('wz-title').textContent = WZ_TITLES[wizardStep];
    document.getElementById('wz-cap').textContent = 'Paso ' + (wizardStep + 1) + ' de 3';
    document.getElementById('wz-back').disabled = wizardStep === 0;
    document.getElementById('wz-foot').style.display = wizardStep === 2 ? 'none' : 'block';
    document.getElementById('wz-skip').style.display = wizardStep === 1 ? 'block' : 'none';
    if (wizardStep === 2) renderPasoPago();   // definido en Task 7
  }

  // Valida el paso actual antes de avanzar
  function wizardNext() {
    if (wizardStep === 0 && !validarDatos()) return;
    wizardGo(wizardStep + 1);
  }

  // Valida nombre/teléfono/entrega/dirección (reusa la lógica de confirmarPedido)
  function validarDatos() {
    let valid = true;
    const nombre = document.getElementById('campo-nombre').value.trim();
    const telefono = document.getElementById('campo-telefono').value.trim();
    const direccion = tipoEntrega === 'delivery' ? document.getElementById('campo-direccion').value.trim() : '';
    const setErr = (id, errId, bad) => {
      document.getElementById(errId).style.display = bad ? 'block' : 'none';
      document.getElementById(id).style.borderColor = bad ? '#dc2626' : '#ddd';
      if (bad) valid = false;
    };
    setErr('campo-nombre', 'err-nombre', !nombre);
    setErr('campo-telefono', 'err-telefono', !telefono);
    if (tipoEntrega === 'delivery') setErr('campo-direccion', 'err-direccion', !direccion);
    return valid;
  }
```

- [ ] **Step 2: Inicializar el wizard al abrir el modal**

En `enviarPedido()` (L1305-1314), antes de mostrar el modal, resetear el total y posicionar el wizard en el paso 0. Añadir tras `document.getElementById('modal-pedido').style.display = 'flex';`:

```js
    document.getElementById('wz-total').textContent = document.getElementById('mobile-total').textContent || 'S/0';
    wizardGo(0);
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l carta/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verificación manual**

Abrir el carrito → "Continuar" avanza paso 1→2→3, la flecha atrás funciona, la barra de progreso avanza, el paso 1 valida campos requeridos, el paso 2 muestra "Omitir", el paso 3 oculta el pie.

- [ ] **Step 5: Commit**

```bash
git add carta/index.php
git commit -m "feat(carta): navegación del wizard (pasos, validación, progreso)"
```

---

## Task 7: Carta — paso de pago según modo + dispatch

`confirmarPedido()` (L1371) deja de leer `SALES_MODE` global y pasa a recibir el método. El paso 3 inyecta los botones según el modo.

**Files:**
- Modify: `carta/index.php` (JS: `renderPasoPago`, `confirmarPedido(metodo)`, ramas Izipay/WhatsApp L1459-1520)

- [ ] **Step 1: Render del paso de pago**

Añadir:

```js
  function renderPasoPago() {
    // Resumen
    let total = 0;
    const filas = Object.values(carrito).map(i => {
      const sub = Math.round(i.precio * i.qty); total += sub;
      return `<div class="wz-it"><span>${i.qty}x ${i.nombre}</span><span>S/${sub}</span></div>`;
    }).join('');
    document.getElementById('wz-resumen').innerHTML =
      filas + `<div class="wz-it wz-ittot"><span>Total</span><span>S/${Math.round(total)}</span></div>`;

    // Botones según modo
    const card = `<button type="button" class="wz-pay wz-pay-card" onclick="confirmarPedido('izipay')">💳 Pagar con tarjeta</button>`;
    const wa   = `<button type="button" class="wz-pay wz-pay-wa" onclick="confirmarPedido('whatsapp')">🟢 Pedir por WhatsApp</button>`;
    let html = '';
    if (SHOW_CARD && IZ_ENABLED) html += card;
    if (SHOW_CARD && IZ_ENABLED && SHOW_WA) html += '<div class="wz-or">O</div>';
    if (SHOW_WA) html += wa;
    document.getElementById('wz-pagos').innerHTML = html;
  }
```

(Añadir CSS mínimo `.wz-pay`, `.wz-pay-card{background:#1A1A1A;color:#fff}`, `.wz-pay-wa{background:#25D366;color:#fff}`, `.wz-or`, `.wz-it`, `.wz-ittot` — reusar estilos del mockup.)

- [ ] **Step 2: `confirmarPedido(metodo)` recibe el método**

Cambiar la firma `function confirmarPedido() {` (L1371) → `function confirmarPedido(metodo) {`. La validación de datos del paso 1 ya se hizo en `wizardNext`, pero mantener la de comprobante. Reemplazar la rama de decisión:
- Eliminar `if (SALES_MODE === 'izipay') { ... }` (L1460) como condición de modo.
- Usar `if (metodo === 'izipay') { ... iniciarPago() ... return; }`.
- El resto (guardar + abrir WhatsApp) corre cuando `metodo === 'whatsapp'`.

- [ ] **Step 3: Mostrar confirmación tras WhatsApp**

Al final de la rama WhatsApp (tras abrir `wa.me`, L1511-1519), en vez de no hacer nada, mostrar la pantalla de confirmación (Task 9). Reemplazar el bloque final por:

```js
    cerrarModal();
    if (window.track) track('order_placed', 'carta', { ubicacion_id: CARTA_ID, meta: { metodo: 'whatsapp', total: Math.round(saveTotal) } });
    const _waUrl = 'https://wa.me/<?= $waNum ?>?text=' + msg;
    handleLoyalty(loyaltyEmailVal, nombre);
    mostrarConfirmacion({ metodo: 'whatsapp', pedidoId: null, total: Math.round(saveTotal), waUrl: _waUrl });
    const _w = window.open(_waUrl, '_blank');  // intento inmediato; si se bloquea, el botón "Abrir WhatsApp" de la confirmación lo reabre
```

(Definir `loyaltyEmailVal` antes si no existe en ese scope.)

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l carta/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Verificación manual**

Tienda `whatsapp`: paso 3 muestra 1 botón verde. Tienda `izipay`: 1 botón negro. Tienda `ambos`: ambos botones con "O". Cada botón dispara su flujo.

- [ ] **Step 6: Commit**

```bash
git add carta/index.php
git commit -m "feat(carta): paso de pago con botones por modo y dispatch confirmarPedido(metodo)"
```

---

## Task 8: Carta — comprobante en el mensaje de WhatsApp

**Files:**
- Modify: `carta/index.php` (construcción del mensaje WA, L1427-1445)

- [ ] **Step 1: Añadir líneas de comprobante al mensaje**

En el array `lines` del mensaje WA (después de `*Comentarios:*`, antes de `lines.push('', '*Pedido:*')`), añadir:

```js
    if (compTipo) {
      const etq = compTipo === 'factura' ? 'Factura' : 'Boleta';
      lines.push(`*Comprobante:* ${etq}`);
      if (compDoc) lines.push(`*Documento:* ${compDoc}`);
      if (compNom) lines.push(`*Nombre/Razón:* ${compNom}`);
    }
```

(`compTipo`/`compDoc`/`compNom` ya existen en `confirmarPedido`, L1410-1412.)

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l carta/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Verificación manual**

Pedido WhatsApp con boleta/factura: el texto de WhatsApp incluye las líneas de comprobante. (Los datos ya se guardan en el pedido vía `compPayload` → visibles en bandeja del POS.)

- [ ] **Step 4: Commit**

```bash
git add carta/index.php
git commit -m "feat(carta): incluir datos de comprobante en el mensaje de WhatsApp"
```

---

## Task 9: Carta — pantalla de pedido confirmado unificada

Reemplazar `#modal-confirmado` (L1995-2002, hoy solo Izipay y fondo negro fijo) por una pantalla unificada que sirve a ambos métodos y respeta el tema día/noche. Sacarla del guard `$showCard` para que exista siempre.

**Files:**
- Modify: `carta/index.php` (mover/rehacer `#modal-confirmado` fuera del bloque `$showCard`; reescribir `mostrarConfirmacion`)

- [ ] **Step 1: Markup unificado (fuera del guard de Izipay)**

Crear el markup según `pedido-confirmado.html`, con secciones para WhatsApp y tarjeta controladas por JS. Usar variables de tema para fondo/texto. IDs: `conf-hero`, `conf-icon`, `conf-titulo`, `conf-codigo`, `conf-msg`, `conf-wa-btn`, `conf-prep`, `conf-resumen`, `conf-card-row`, `conf-chip`. Botón "Hacer otro pedido" → `onclick="resetPedido()"` (Task 10). Botón "Abrir WhatsApp" → `onclick="abrirWhatsApp()"`.

- [ ] **Step 2: Reescribir `mostrarConfirmacion`**

Reemplazar la función actual (L1929-1940) por una que cubra ambos métodos:

```js
  let _waUrlActual = '';
  function mostrarConfirmacion(data) {
    cerrarModal();
    const wa = data.metodo === 'whatsapp';
    _waUrlActual = data.waUrl || '';
    document.getElementById('conf-titulo').textContent = wa ? '¡Pedido enviado!' : '¡Pago confirmado!';
    document.getElementById('conf-codigo').textContent = data.pedidoId ? ('#' + String(data.pedidoId).padStart(3,'0')) : '';
    document.getElementById('conf-msg').textContent = wa
      ? 'Envíale tu pedido a la tienda por WhatsApp para confirmarlo. Ahí coordinas pago y entrega.'
      : 'Recibimos tu pago. La tienda ya está preparando tu pedido.';
    document.getElementById('conf-msg').style.display = wa ? 'block' : 'none';
    document.getElementById('conf-wa-btn').style.display = wa ? 'flex' : 'none';
    document.getElementById('conf-prep').style.display = wa ? 'none' : 'flex';
    document.getElementById('conf-hero').className = 'conf-hero ' + (wa ? 'wa' : 'ok');
    document.getElementById('modal-confirmado').style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
  function abrirWhatsApp() {
    if (!_waUrlActual) return;
    const w = window.open(_waUrlActual, '_blank');
    if (!w) window.location.href = _waUrlActual;
  }
```

La rama Izipay (`mostrarConfirmacion` ya se llamaba desde el flujo Izipay, L1900/1918/1919) debe pasar `metodo:'izipay'`. Actualizar esas llamadas para incluir `metodo:'izipay'`.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l carta/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verificación manual**

Pedido WhatsApp → pantalla "¡Pedido enviado!" con botón "Abrir WhatsApp". Pago Izipay → "¡Pago confirmado!". Cambiar tema día/noche y reconfirmar que la pantalla cambia de fondo acorde.

- [ ] **Step 5: Commit**

```bash
git add carta/index.php
git commit -m "feat(carta): pantalla de pedido confirmado unificada (WhatsApp+tarjeta, tema día/noche)"
```

---

## Task 10: Carta — `resetPedido()` y reseteo tras completar

**Files:**
- Modify: `carta/index.php` (JS: nueva `resetPedido`; reemplazar `vaciarYVolver`)

- [ ] **Step 1: Crear `resetPedido()`**

```js
  function resetPedido() {
    // 1. Carrito + productos seleccionados en la carta
    vaciarCarrito();
    // 2. Formulario
    ['campo-nombre','campo-telefono','campo-direccion','campo-comentarios','campo-doc','campo-razon','campo-email-comp']
      .forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
    const comp = document.getElementById('campo-comprobante');
    if (comp) { comp.value = ''; onCompChange(); }
    ['err-nombre','err-telefono','err-direccion','err-comp'].forEach(id => {
      const el = document.getElementById(id); if (el) el.style.display = 'none';
    });
    // 3. Entrega y wizard
    setTipoEntrega('delivery');
    wizardStep = 0;
    // 4. Estado interno + cerrar pantallas
    _pedidoData = null;
    document.getElementById('modal-confirmado').style.display = 'none';
    const mp = document.getElementById('modal-pedido'); if (mp) mp.style.display = 'none';
    document.body.style.overflow = '';
  }
```

- [ ] **Step 2: Apuntar el botón de la confirmación a `resetPedido`**

El botón "Hacer otro pedido" / "Volver a la carta" de `#modal-confirmado` debe llamar `resetPedido()`. Reemplazar el uso de `vaciarYVolver()` (L1942-1948) por `resetPedido()` (mantener `handleLoyalty` si aplica: llamarlo antes del reset dentro de la rama que lo necesite, o conservar `vaciarYVolver` como wrapper que llama a `handleLoyalty` y luego `resetPedido`).

```js
  function vaciarYVolver() {
    if (_pedidoData && typeof handleLoyalty === 'function') handleLoyalty(_pedidoData.loyaltyEmail, _pedidoData.nombre);
    resetPedido();
  }
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l carta/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verificación manual (el bug original)**

Hacer un pedido por **WhatsApp**, volver a la carta desde la confirmación → carrito vacío, productos sin badge/seleccción, formulario limpio, wizard en paso 1. Repetir con **Izipay**. Confirmar que NADA del pedido anterior queda pegado.

- [ ] **Step 5: Commit**

```bash
git add carta/index.php
git commit -m "fix(carta): resetPedido() limpia carrito, productos y formulario tras cada pedido"
```

---

## Verificación final (manual, instancia de prueba)

- [ ] Cada `sales_mode` (`whatsapp`, `izipay`, `ambos`, `menu`) muestra el paso de pago correcto.
- [ ] Wizard: navegación adelante/atrás, validación de requeridos, total visible, móvil y escritorio (comparar mockups).
- [ ] Pedido WhatsApp e Izipay: se guarda en BD, llega a KDS/POS, comprobante persiste y aparece en bandeja del POS con el badge correcto (WhatsApp / Izipay).
- [ ] Mensaje de WhatsApp incluye comprobante cuando se pidió.
- [ ] Tras completar (ambos métodos): carrito vacío, productos deseleccionados, formulario limpio, wizard en paso 1.
- [ ] Confirmación respeta tema día/noche.
- [ ] `grep -n "salesMode === 'izipay'\|SALES_MODE === 'izipay'" carta/index.php` → no quedan guards que bloqueen `ambos`.
- [ ] Aplicar `install/41_sales_mode_ambos.sql` en phpMyAdmin al desplegar.
