# Almacén central + 3 modos de inventario — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar un almacén central que nunca vende (solo guarda y despacha) y una pantalla de 3 modos de inventario (Ingresos/Salidas/Conteo) operable sobre almacén central y restaurantes, con despachos central→restaurante como transferencias enlazadas.

**Architecture:** El almacén central es una `ubicaciones` con flag `es_almacen=1` y `activa=0` (queda oculto de todos los contextos de venta que ya filtran `activa=1`, sin tocarlos). Los 3 modos son una capa de UI sobre el motor existente `invMovimiento`. Un helper nuevo `invTransferir` hace la transferencia atómica con `ref` común. Los selectores de inventario usan un helper compartido `ubicacionesConInventario` (`activa=1 OR es_almacen=1`, tolerante si falta la columna).

**Tech Stack:** PHP 8 vanilla + MySQL/PDO. Sin framework de tests: cada tarea se valida con `php -l` + checklist manual en navegador/phpMyAdmin (igual que el resto del proyecto).

**Spec:** `docs/superpowers/specs/2026-06-16-almacen-central-modos-inventario-design.md`
**Mockup:** `docs/superpowers/specs/mockups/inventario-modos-almacen.html`

---

## File Structure

- **Create:** `install/49_almacen_central.sql` — migración: `ubicaciones.es_almacen`.
- **Create:** `admin/inventory/operar.php` — pantalla de 3 modos (GET render + POST handler).
- **Modify:** `includes/inventario.php` — helpers `invTransferir` + `ubicacionesConInventario`.
- **Modify:** `install/check_migraciones.sql` — detectar la migración 49.
- **Modify:** `admin/locations/form.php` — checkbox `es_almacen` + guardado tolerante.
- **Modify:** `admin/locations/index.php` — badge "Almacén".
- **Modify:** `admin/inventory/stock.php`, `movimientos.php`, `salida_evento.php`, `compra_form.php` — usar `ubicacionesConInventario()`.
- **Modify:** `admin/layout-top.php` — item de menú "Operar" en el grupo Inventario.
- **Modify (docs):** `CLAUDE.md` — documentar el módulo.

---

## Task 1: Migración `es_almacen`

**Files:**
- Create: `install/49_almacen_central.sql`
- Modify: `install/check_migraciones.sql:29` (agregar una línea UNION ALL antes de `) m;`)

- [ ] **Step 1: Crear la migración**

Crear `install/49_almacen_central.sql` con exactamente:

```sql
-- 49 · Almacén central: ubicación que guarda y despacha pero NUNCA vende.
-- Se crea con activa=0 + es_almacen=1 → queda oculto de carta/POS/KDS (que filtran activa=1)
-- pero visible en los selectores de inventario (activa=1 OR es_almacen=1).
ALTER TABLE `ubicaciones`
  ADD COLUMN `es_almacen` TINYINT(1) NOT NULL DEFAULT 0 AFTER `es_principal`;
```

- [ ] **Step 2: Registrar la migración en el diagnóstico**

En `install/check_migraciones.sql`, justo ANTES de la línea `) m;` (línea 29 actual, después de la línea de `48 evento_gastos`), insertar:

```sql
  UNION ALL SELECT '49 almacen_central         (ubicaciones.es_almacen)', COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ubicaciones' AND column_name='es_almacen'
```

- [ ] **Step 3: Verificar sintaxis SQL (revisión visual)**

No hay linter SQL local. Revisar visualmente que el `ALTER TABLE` termina en `;` y que la línea UNION ALL quedó dentro del `SELECT ... FROM ( ... ) m;`. Confirmar que `49_almacen_central.sql` es el número siguiente (último era `48_evento_gastos.sql`).

Run: `ls install/ | grep -E '^(48|49)_'`
Expected: muestra `48_evento_gastos.sql` y `49_almacen_central.sql`.

- [ ] **Step 4: Commit**

```bash
git add install/49_almacen_central.sql install/check_migraciones.sql
git commit -m "feat(inventario): migración es_almacen (almacén central) + check_migraciones"
```

---

## Task 2: Helpers `invTransferir` y `ubicacionesConInventario`

**Files:**
- Modify: `includes/inventario.php` (agregar dos funciones; el archivo termina en la función `eventoEliminar`, ~línea 216)

- [ ] **Step 1: Agregar `ubicacionesConInventario` al final de `includes/inventario.php`**

Pegar al final del archivo (después del bloque `if (!function_exists('eventoEliminar'))`):

```php

if (!function_exists('ubicacionesConInventario')) {
    /**
     * Ubicaciones que manejan stock: locales activos (venta) + almacenes (no venden).
     * Devuelve filas con id, nombre, es_almacen. Tolerante: si la columna es_almacen
     * aún no existe (migración 49 sin aplicar), cae a activa=1 y marca es_almacen=0.
     */
    function ubicacionesConInventario(): array
    {
        try {
            $hasCol = Database::fetch(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema=DATABASE() AND table_name='ubicaciones' AND column_name='es_almacen'"
            );
            if ($hasCol) {
                return Database::fetchAll(
                    "SELECT id, nombre, es_almacen FROM ubicaciones
                     WHERE activa=1 OR es_almacen=1
                     ORDER BY es_almacen DESC, es_principal DESC, sort_order, nombre"
                );
            }
        } catch (\Throwable $e) { /* sin information_schema: cae al fallback */ }
        $rows = Database::fetchAll(
            "SELECT id, nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, sort_order, nombre"
        );
        foreach ($rows as &$r) { $r['es_almacen'] = 0; }
        return $rows;
    }
}
```

- [ ] **Step 2: Agregar `invTransferir` al final de `includes/inventario.php`**

Pegar después del bloque anterior:

```php

if (!function_exists('invTransferir')) {
    /**
     * Transferencia de insumos entre ubicaciones: baja en origen y sube en destino,
     * en una transacción, con una referencia común que enlaza ambas patas.
     * $items = [insumo_id => cantidad(positiva)]. Devuelve la ref usada (o '' si nada/falló).
     * NO valida stock (el llamador valida antes); NO revierte ventas históricas.
     */
    function invTransferir(int $origen, int $destino, array $items, string $motivo = 'Despacho'): string
    {
        if ($origen <= 0 || $destino <= 0 || $origen === $destino) return '';
        $ref = 'TRF-' . date('YmdHis') . '-' . $origen . '-' . $destino;
        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();
            $hubo = false;
            foreach ($items as $insumoId => $cant) {
                $insumoId = (int)$insumoId; $cant = (float)$cant;
                if ($insumoId <= 0 || $cant <= 0) continue;
                $idOut = invMovimiento($origen,  $insumoId, 'transferencia', -$cant, ['motivo'=>$motivo,                 'ref'=>$ref]);
                $idIn  = invMovimiento($destino, $insumoId, 'transferencia',  $cant, ['motivo'=>$motivo . ' (recibido)', 'ref'=>$ref]);
                // invMovimiento traga sus excepciones y devuelve 0: forzamos rollback si falló una pata
                if (!$idOut || !$idIn) throw new \RuntimeException('Falló una pata de la transferencia');
                $hubo = true;
            }
            if (!$hubo) { $pdo->rollBack(); return ''; }
            $pdo->commit();
            return $ref;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return '';
        }
    }
}
```

- [ ] **Step 3: Lint**

Run: `php -l includes/inventario.php`
Expected: `No syntax errors detected in includes/inventario.php`

- [ ] **Step 4: Commit**

```bash
git add includes/inventario.php
git commit -m "feat(inventario): helpers invTransferir (transferencia atómica) y ubicacionesConInventario"
```

---

## Task 3: `es_almacen` en el form y la lista de ubicaciones

**Files:**
- Modify: `admin/locations/form.php` (default `$data` ~línea 23-26; POST parse ~línea 51-53; guardado tolerante ~después de línea 128; checkbox UI ~línea 350-353)
- Modify: `admin/locations/index.php` (badge junto a "Principal", ~línea 78)

- [ ] **Step 1: Default de `$data` en `form.php`**

En `admin/locations/form.php`, en el array por defecto de `$data` (el que empieza en línea ~19), agregar `'es_almacen' => 0,` junto a `'es_principal' => 0,`. Cambiar la línea:

```php
    'activa' => 1, 'cerrado_manual' => 0, 'es_principal' => 0, 'sort_order' => 0,
```

por:

```php
    'activa' => 1, 'cerrado_manual' => 0, 'es_principal' => 0, 'es_almacen' => 0, 'sort_order' => 0,
```

- [ ] **Step 2: Parsear el POST en `form.php`**

En el bloque `$data = [...]` del POST, después de la línea `'es_principal'    => isset($_POST['es_principal']) ? 1 : 0,` agregar:

```php
        'es_almacen'      => isset($_POST['es_almacen']) ? 1 : 0,
```

- [ ] **Step 3: Guardado tolerante de `es_almacen` en `form.php`**

`es_almacen` NO se mete en el INSERT/UPDATE posicional (frágil). Se guarda aparte y tolerante, igual que los campos multilocal. Justo DESPUÉS del bloque `// Campos multilocal ... } catch (\Throwable $e) { /* columnas aún no creadas */ }` (termina ~línea 128) y ANTES del bloque `// Campos de asistencia`, insertar:

```php
        // Flag almacén central — UPDATE aparte y tolerante (migración 49)
        if (!empty($savedId)) {
            try {
                Database::execute("UPDATE ubicaciones SET es_almacen=? WHERE id=?", [$data['es_almacen'], $savedId]);
            } catch (\Throwable $e) { /* columna es_almacen aún no creada */ }
        }
```

- [ ] **Step 4: Checkbox en la UI de `form.php`**

En el grupo "Opciones" (el `<div class="form-group">` con los toggles Activa/Principal, ~línea 344-355), agregar un tercer toggle después del de "Principal". Reemplazar:

```php
            <label class="toggle-wrap" style="cursor:pointer">
              <input type="checkbox" name="es_principal" value="1" <?= $data['es_principal']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)">
              <span class="toggle-label">Principal</span>
            </label>
          </div>
```

por:

```php
            <label class="toggle-wrap" style="cursor:pointer">
              <input type="checkbox" name="es_principal" value="1" <?= $data['es_principal']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)">
              <span class="toggle-label">Principal</span>
            </label>
            <label class="toggle-wrap" style="cursor:pointer" title="Solo guarda y despacha; no vende">
              <input type="checkbox" name="es_almacen" value="1" <?= !empty($data['es_almacen'])?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)">
              <span class="toggle-label">Almacén central</span>
            </label>
          </div>
          <div class="form-hint" style="margin-top:6px">Si marcas <strong>Almacén central</strong>, desmarca <strong>Activa</strong>: el almacén no vende, solo guarda y despacha insumos.</div>
```

- [ ] **Step 5: Badge en `index.php`**

En `admin/locations/index.php`, en la celda del nombre (~línea 77-78), después de la línea del badge "Principal":

```php
              <?php if ($u['es_principal']): ?><span class="badge badge-warning" style="font-size:10px">Principal</span><?php endif; ?>
```

agregar:

```php
              <?php if (!empty($u['es_almacen'])): ?><span class="badge" style="font-size:10px;background:var(--brand);color:#1e1e1e">Almacén</span><?php endif; ?>
```

- [ ] **Step 6: Lint**

Run: `php -l admin/locations/form.php && php -l admin/locations/index.php`
Expected: `No syntax errors detected` en ambos.

- [ ] **Step 7: Commit**

```bash
git add admin/locations/form.php admin/locations/index.php
git commit -m "feat(ubicaciones): marcar/mostrar Almacén central (es_almacen)"
```

---

## Task 4: Selectores de inventario usan `ubicacionesConInventario()`

**Files:**
- Modify: `admin/inventory/stock.php:10`
- Modify: `admin/inventory/movimientos.php:10`
- Modify: `admin/inventory/salida_evento.php:10`
- Modify: `admin/inventory/compra_form.php:11`

Los cuatro ya hacen `require_once __DIR__ . '/../../includes/inventario.php';` (usan `invMovimiento`/`inventarioListo`). El helper está disponible.

- [ ] **Step 1: `stock.php`**

Reemplazar la línea 10:

```php
$ubicaciones = $ready ? Database::fetchAll("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, nombre") : [];
```

por:

```php
$ubicaciones = $ready ? ubicacionesConInventario() : [];
```

- [ ] **Step 2: `movimientos.php`**

Reemplazar (línea 10):

```php
$ubicaciones = $ready ? Database::fetchAll("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, nombre") : [];
```

por:

```php
$ubicaciones = $ready ? ubicacionesConInventario() : [];
```

- [ ] **Step 3: `salida_evento.php`**

Reemplazar (línea 10):

```php
$ubicaciones = Database::fetchAll("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, nombre");
```

por:

```php
$ubicaciones = ubicacionesConInventario();
```

- [ ] **Step 4: `compra_form.php`**

Reemplazar (línea 11):

```php
$ubicaciones = Database::fetchAll("SELECT id,nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, nombre");
```

por:

```php
$ubicaciones = ubicacionesConInventario();
```

- [ ] **Step 5: Verificar que `compra_form.php` incluye inventario.php**

Run: `grep -n "includes/inventario.php" admin/inventory/compra_form.php`
Expected: una línea con el `require_once`. Si NO aparece, agregar `require_once __DIR__ . '/../../includes/inventario.php';` junto a los otros `require_once` del tope del archivo.

- [ ] **Step 6: Lint**

Run: `for f in stock movimientos salida_evento compra_form; do php -l admin/inventory/$f.php; done`
Expected: `No syntax errors detected` en los cuatro.

- [ ] **Step 7: Commit**

```bash
git add admin/inventory/stock.php admin/inventory/movimientos.php admin/inventory/salida_evento.php admin/inventory/compra_form.php
git commit -m "feat(inventario): selectores usan ubicacionesConInventario (incluye almacén central)"
```

---

## Task 5: Pantalla `operar.php` (3 modos)

**Files:**
- Create: `admin/inventory/operar.php`

- [ ] **Step 1: Crear `admin/inventory/operar.php`**

Crear el archivo con exactamente este contenido:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_stock');

$ready       = inventarioListo();
$ubicaciones = $ready ? ubicacionesConInventario() : [];
$ubiF        = cleanInt($_GET['ubi'] ?? $_POST['ubicacion_id'] ?? 0) ?: ($ubicaciones[0]['id'] ?? 0);
$modo        = $_GET['modo'] ?? $_POST['modo'] ?? 'ingresos';
if (!in_array($modo, ['ingresos','salidas','conteo'], true)) $modo = 'ingresos';

// Ubicación actual + si es almacén
$ubiActual = null;
foreach ($ubicaciones as $u) { if ((int)$u['id'] === (int)$ubiF) { $ubiActual = $u; break; } }
$esAlmacen = $ubiActual ? ((int)($ubiActual['es_almacen'] ?? 0) === 1) : false;
$idsValidos = array_map(fn($u) => (int)$u['id'], $ubicaciones);

function _back($ubi, $modo) { redirect('/admin/inventory/operar.php?ubi=' . (int)$ubi . '&modo=' . $modo); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready && $ubiF) {
    verifyCsrf();
    $cant = is_array($_POST['cant'] ?? null) ? $_POST['cant'] : [];

    // Mapa de stock actual de esta ubicación (para validar y para conteo)
    $stockMap = [];
    foreach (Database::fetchAll("SELECT insumo_id, stock FROM insumo_stock WHERE ubicacion_id=?", [$ubiF]) as $s) {
        $stockMap[(int)$s['insumo_id']] = (float)$s['stock'];
    }

    if ($modo === 'ingresos') {
        $n = 0;
        foreach ($cant as $iid => $v) {
            $iid = (int)$iid; $v = cleanFloat($v);
            if ($iid > 0 && $v > 0) { invMovimiento($ubiF, $iid, 'ingreso', $v, ['motivo' => 'Ingreso · recepción']); $n++; }
        }
        flashMessage($n ? 'success' : 'error', $n ? "Ingresos registrados: $n insumo(s)." : 'No ingresaste cantidades.');
        _back($ubiF, 'ingresos');
    }

    if ($modo === 'salidas') {
        if ($esAlmacen) {
            $destino = cleanInt($_POST['destino'] ?? 0);
            if ($destino <= 0 || $destino === (int)$ubiF || !in_array($destino, $idsValidos, true)) {
                flashMessage('error', 'Elige un destino válido para el despacho.');
                _back($ubiF, 'salidas');
            }
            $items = []; $faltan = 0;
            foreach ($cant as $iid => $v) {
                $iid = (int)$iid; $v = cleanFloat($v);
                if ($iid <= 0 || $v <= 0) continue;
                if ($v > ($stockMap[$iid] ?? 0) + 0.0001) { $faltan++; continue; }
                $items[$iid] = $v;
            }
            if ($faltan) {
                flashMessage('error', "No hay stock suficiente en $faltan insumo(s). No se despachó nada — corrige las cantidades.");
                _back($ubiF, 'salidas');
            }
            if (!$items) { flashMessage('error', 'No ingresaste cantidades.'); _back($ubiF, 'salidas'); }
            $ref = invTransferir($ubiF, $destino, $items, 'Despacho');
            flashMessage($ref ? 'success' : 'error', $ref ? 'Despacho realizado: ' . count($items) . ' insumo(s) transferido(s).' : 'No se pudo completar el despacho.');
            _back($ubiF, 'salidas');
        } else {
            $n = 0; $faltan = 0;
            foreach ($cant as $iid => $v) {
                $iid = (int)$iid; $v = cleanFloat($v);
                if ($iid <= 0 || $v <= 0) continue;
                if ($v > ($stockMap[$iid] ?? 0) + 0.0001) { $faltan++; continue; }
                invMovimiento($ubiF, $iid, 'merma', -$v, ['motivo' => 'Salida / merma']); $n++;
            }
            if ($faltan) flashMessage('error', "$faltan insumo(s) sin stock suficiente no se registraron.");
            if ($n)      flashMessage('success', "Salidas registradas: $n insumo(s).");
            if (!$n && !$faltan) flashMessage('error', 'No ingresaste cantidades.');
            _back($ubiF, 'salidas');
        }
    }

    if ($modo === 'conteo') {
        $n = 0;
        foreach ($cant as $iid => $v) {
            $iid = (int)$iid;
            if ($iid <= 0 || trim((string)$v) === '') continue;
            $real  = cleanFloat($v);
            $delta = round($real - ($stockMap[$iid] ?? 0), 3);
            if (abs($delta) >= 0.001) { invMovimiento($ubiF, $iid, 'ajuste', $delta, ['motivo' => 'Conteo de inventario']); $n++; }
        }
        flashMessage('success', "Conteo guardado: $n ajuste(s).");
        _back($ubiF, 'conteo');
    }
}

// Datos para render
$insumos = ($ready && $ubiF) ? Database::fetchAll(
    "SELECT i.id, i.nombre, i.unidad, i.tipo, COALESCE(s.stock,0) stock
     FROM insumos i
     LEFT JOIN insumo_stock s ON s.insumo_id = i.id AND s.ubicacion_id = ?
     WHERE i.activo = 1 ORDER BY i.nombre",
    [$ubiF]
) : [];
$destinos = array_values(array_filter($ubicaciones, fn($u) => (int)$u['id'] !== (int)$ubiF));

function nf($n) { return rtrim(rtrim(number_format((float)$n, 3, '.', ''), '0'), '.'); }

$MODOS = [
    'ingresos' => ['Ingresos', 'Cantidad recibida', 'Guardar ingresos', '#1f9d55'],
    'salidas'  => ['Salidas',  'Cantidad salida',   ($esAlmacen ? 'Despachar' : 'Guardar salidas'), '#d64545'],
    'conteo'   => ['Conteo',   'Stock real (conteo)', 'Guardar conteo', '#d9920a'],
];

$pageTitle  = 'Operar inventario';
$activePage = 'inv-operar';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left">
  <h1>Operar inventario</h1>
  <p>Ingresos, salidas (despacho) y conteo, por ubicación</p>
</div></div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state">
    <h3>Falta crear el módulo de inventario</h3>
    <p>Aplica <code>install/inventario.sql</code> en phpMyAdmin.</p>
  </div></div>
<?php elseif (empty($ubicaciones)): ?>
  <div class="card"><div class="empty-state"><h3>Sin ubicaciones</h3><p>Crea una ubicación primero.</p></div></div>
<?php else: ?>

<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
  <select onchange="location.href='?ubi='+this.value+'&modo=<?= $modo ?>'" style="padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-weight:700;background:#fff">
    <?php foreach ($ubicaciones as $u): ?>
      <option value="<?= (int)$u['id'] ?>" <?= $ubiF==$u['id']?'selected':'' ?>><?= !empty($u['es_almacen']) ? '🏬 ' : '🍔 ' ?><?= clean($u['nombre']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if ($esAlmacen): ?>
    <span class="badge" style="background:var(--brand);color:#1e1e1e">Almacén central · no vende</span>
  <?php else: ?>
    <span class="badge badge-secondary" style="background:#FFBBC8;color:#1e1e1e">Restaurante</span>
  <?php endif; ?>
</div>

<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <?php foreach ($MODOS as $key => $m): $on = $modo===$key; ?>
    <a href="?ubi=<?= (int)$ubiF ?>&modo=<?= $key ?>"
       style="flex:1;min-width:130px;text-align:center;padding:12px;border-radius:12px;font-weight:800;font-size:14px;text-decoration:none;border:2px solid <?= $on?$m[3]:'var(--border)' ?>;color:<?= $on?$m[3]:'var(--text-secondary)' ?>;background:<?= $on?($m[3].'14'):'#fff' ?>">
      <?= $m[0] ?>
    </a>
  <?php endforeach; ?>
</div>

<form method="post" id="opForm">
  <?= csrfField() ?>
  <input type="hidden" name="ubicacion_id" value="<?= (int)$ubiF ?>">
  <input type="hidden" name="modo" value="<?= $modo ?>">

  <?php if ($modo === 'salidas' && $esAlmacen): ?>
    <div class="card" style="margin-bottom:14px;border-left:3px solid #d9920a"><div class="card-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <strong>Despachar a:</strong>
      <select name="destino" required style="padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-weight:700;background:#fff">
        <option value="">— elige restaurante —</option>
        <?php foreach ($destinos as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= clean($d['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <span style="font-size:13px;color:var(--text-muted)">El stock baja aquí y <strong>sube</strong> en el destino (transferencia enlazada).</span>
    </div></div>
  <?php endif; ?>

  <div class="card">
    <?php if (empty($insumos)): ?>
      <div class="empty-state"><h3>Sin insumos activos</h3><p>Crea insumos en la sección «Insumos».</p></div>
    <?php else: ?>
    <div class="table-wrap" style="border:none;border-radius:0">
      <table class="data-table">
        <thead><tr>
          <th>Insumo</th><th>Tipo</th><th>Stock actual</th><th><?= $MODOS[$modo][1] ?></th>
          <?php if ($modo==='conteo'): ?><th>Diferencia</th><?php endif; ?>
        </tr></thead>
        <tbody>
          <?php foreach ($insumos as $it): $desc = ($it['tipo'] ?? '') === 'descartable'; ?>
          <tr>
            <td><strong><?= clean($it['nombre']) ?></strong></td>
            <td><span class="badge <?= $desc?'badge-info':'badge-secondary' ?>" style="font-size:10px"><?= $desc?'descartable':'ingrediente' ?></span></td>
            <td class="op-stock" data-stock="<?= (float)$it['stock'] ?>"><?= nf($it['stock']) ?> <span style="color:var(--text-muted);font-size:12px"><?= clean($it['unidad']) ?></span></td>
            <td><input type="text" inputmode="decimal" name="cant[<?= (int)$it['id'] ?>]" class="op-input" data-id="<?= (int)$it['id'] ?>" placeholder="0" style="width:96px;padding:8px;border:1.5px solid var(--border);border-radius:8px;text-align:right"></td>
            <?php if ($modo==='conteo'): ?><td class="op-diff" data-id="<?= (int)$it['id'] ?>" style="font-weight:800;color:var(--text-muted)">—</td><?php endif; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:12px;padding:14px 16px;border-top:1px solid var(--border);align-items:center">
      <span id="opResumen" style="font-size:13px;color:var(--text-secondary)"></span>
      <button type="submit" class="btn btn-primary" style="margin-left:auto;background:<?= $MODOS[$modo][3] ?>;border-color:<?= $MODOS[$modo][3] ?>"><?= $MODOS[$modo][2] ?></button>
    </div>
    <?php endif; ?>
  </div>
</form>

<script>
(function(){
  var modo = <?= json_encode($modo) ?>;
  var inputs = Array.prototype.slice.call(document.querySelectorAll('.op-input'));
  var resumen = document.getElementById('opResumen');
  function fnum(v){ var n = parseFloat(String(v).replace(',','.')); return isNaN(n)?null:n; }
  function refresh(){
    var n = inputs.filter(function(i){ return i.value.trim()!=='' && fnum(i.value)!==null; }).length;
    if(resumen) resumen.innerHTML = '<strong>'+n+'</strong> insumo(s) con '+(modo==='conteo'?'conteo':'cantidad')+'.';
  }
  inputs.forEach(function(inp){
    inp.addEventListener('input', function(){
      if(modo==='conteo'){
        var cell = document.querySelector('.op-diff[data-id="'+inp.dataset.id+'"]');
        var stock = parseFloat(inp.closest('tr').querySelector('.op-stock').dataset.stock)||0;
        var v = fnum(inp.value);
        if(v===null || inp.value.trim()===''){ cell.textContent='—'; cell.style.color='var(--text-muted)'; }
        else { var d = Math.round((v-stock)*1000)/1000; cell.textContent=(d>0?'+':'')+d; cell.style.color = d>0?'#1f9d55':(d<0?'#d64545':'var(--text-muted)'); }
      }
      refresh();
    });
  });
  refresh();
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Lint**

Run: `php -l admin/inventory/operar.php`
Expected: `No syntax errors detected in admin/inventory/operar.php`

- [ ] **Step 3: Commit**

```bash
git add admin/inventory/operar.php
git commit -m "feat(inventario): pantalla Operar con 3 modos (ingresos/salidas/conteo) + despacho por transferencia"
```

---

## Task 6: Item de menú "Operar" en el sidebar

**Files:**
- Modify: `admin/layout-top.php` (grupo Inventario, después del bloque "Stock" que termina ~línea 257)

- [ ] **Step 1: Agregar el enlace**

En `admin/layout-top.php`, justo DESPUÉS del bloque `<?php endif; ?>` que cierra el enlace de Stock (línea 257) y ANTES del bloque `<?php if (can('inv_recetas')): ?>` (línea 259), insertar:

```php
        <?php if (can('inv_stock')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/operar.php"
           class="nav-link <?php echo ($activePage??'')==='inv-operar'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v18M3 12h18M7 7l10 10M17 7 7 17"/></svg></span> Operar
        </a>
        <?php endif; ?>
```

- [ ] **Step 2: Lint**

Run: `php -l admin/layout-top.php`
Expected: `No syntax errors detected in admin/layout-top.php`

- [ ] **Step 3: Commit**

```bash
git add admin/layout-top.php
git commit -m "feat(inventario): item de menú Operar en el grupo Inventario"
```

---

## Task 7: Documentar en CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Documentar el flag en `ubicaciones`**

En `CLAUDE.md`, en la fila de `**`ubicaciones`**` (sección "Campos clave"), añadir `es_almacen` a la lista de columnas. Reemplazar el inicio de esa entrada:

```
**`ubicaciones`** — `slug`, `sales_mode ENUM('menu','whatsapp','izipay')`, `whatsapp_number`, `hora_apertura/cierre`, `cerrado_manual`, `activa`, `es_principal`,
```

por:

```
**`ubicaciones`** — `slug`, `sales_mode ENUM('menu','whatsapp','izipay')`, `whatsapp_number`, `hora_apertura/cierre`, `cerrado_manual`, `activa`, `es_principal`, **`es_almacen`** (almacén central: guarda y despacha, nunca vende; se crea con `activa=0`+`es_almacen=1`),
```

- [ ] **Step 2: Documentar la pantalla y los helpers**

En la sección "### Inventario" (subsistemas), agregar al final del párrafo una frase:

```
**Operar (3 modos):** `admin/inventory/operar.php` — Ingresos / Salidas / Conteo por ubicación, sobre `invMovimiento`. El **almacén central** (`es_almacen`) nunca vende y su modo Salidas **despacha a un restaurante** vía `invTransferir` (transferencia enlazada con `ref` común). La salida a evento (foodtruck) elige origen entre almacén central y restaurantes. Selector compartido: `ubicacionesConInventario()` (`activa=1 OR es_almacen=1`).
```

- [ ] **Step 3: Registrar la migración**

En la sección "## Migraciones / despliegue", en la frase "Las más recientes:", agregar `49_almacen_central.sql` a la lista.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: documentar almacén central + Operar (3 modos) en CLAUDE.md"
```

---

## Verificación final (manual, tras aplicar la migración 49 en phpMyAdmin)

Tras aplicar `install/49_almacen_central.sql`:

1. **Crear Almacén Central:** Ubicaciones → Nueva → nombre "Almacén Central", marcar **Almacén central**, desmarcar **Activa**. Guardar. En la lista aparece badge "Almacén".
2. **Oculto de venta:** abrir `carta/selector.php`, POS terminal, KDS → el Almacén Central NO aparece.
3. **Visible en inventario:** Operar, Stock, Movimientos, Compra, Salida a evento → el Almacén Central SÍ aparece en el selector.
4. **Ingresos:** Operar → Almacén Central → Ingresos → poner cantidades → Guardar. Stock sube (verlo en Stock). Movimiento 'ingreso' en Movimientos.
5. **Salidas/Despacho:** Operar → Almacén Central → Salidas → elegir restaurante destino + cantidades → Despachar. Stock baja en el central y **sube** en el restaurante. En Movimientos hay dos 'transferencia' con la misma `ref` TRF-...
6. **Despacho > stock:** intentar despachar más que el stock → avisa y no descuenta nada.
7. **Conteo:** Operar → un restaurante → Conteo → cambiar un valor (la columna Diferencia se pinta en vivo) → Guardar conteo. El stock queda igual al conteo; insumos sin cambio no generan movimiento.
8. **Salida a evento:** el selector "Almacén origen" ofrece Almacén Central + restaurantes.
9. `git push origin main` y desplegar (`git pull` en el server + aplicar la migración).

---

## Self-Review (hecho)

- **Spec coverage:** migración (T1), helpers invTransferir+ubicacionesConInventario (T2), form/badge es_almacen (T3), selectores ampliados (T4), pantalla 3 modos (T5), menú (T6), docs (T7), salida a evento con origen elegible (T4 + verif. paso 8). Todos los requisitos del spec tienen tarea.
- **Placeholder scan:** sin TBD/TODO; todo el código está completo.
- **Type consistency:** `ubicacionesConInventario()` siempre devuelve filas con `id,nombre,es_almacen` (incluido el fallback) → consumido igual en T4 y T5. `invTransferir($origen,$destino,$items,$motivo)` se llama con esa firma en operar.php. `$activePage='inv-operar'` coincide entre operar.php (T5) y el menú (T6).
