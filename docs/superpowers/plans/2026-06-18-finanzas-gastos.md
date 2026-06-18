# Finanzas — Gastos v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir el registro de gastos en un módulo financiero completo: categorías→subcategorías, gastos multi-línea (1 línea por defecto, multi opcional), enganche opcional con inventario, reportes por categoría/subcategoría, integración al dashboard (utilidad), y unificación de los gastos del POS y de la liquidación de evento en una sola tabla `gastos`.

**Architecture:** Una librería compartida `includes/gastos.php` concentra toda la lógica de persistencia (cabecera `gastos` + líneas `gasto_items`, recálculo de total, enganche/reversa de inventario, búsqueda y creación al vuelo de categorías/subcategorías). Esa librería la consumen: el form de registro, el endpoint `api/gastos.php` (para los combobox de búsqueda en vivo), el cierre de turno del POS y la liquidación de evento. Un componente combobox vanilla (`assets/js/combobox.js`) provee búsqueda en vivo + crear al vuelo y se reutiliza en todas las vistas. `gastos` es la única fuente de verdad para reportes y dashboard → sin doble conteo.

**Tech Stack:** PHP 8 puro (sin frameworks), MySQL/MariaDB vía PDO (clase `Database`), HTML + CSS propio + JS vanilla. Deploy: git push → git pull en cPanel → aplicar migración SQL en phpMyAdmin.

## Global Constraints

- Nunca concatenar variables en SQL — siempre `?` con prepared statements (`Database::fetch/fetchAll/insert/execute`).
- `verifyCsrf()` en todo POST del admin y en escrituras de la API (token por header `X-CSRF-Token`).
- Sanitizar entradas con `clean()` / `cleanInt()` / `cleanFloat()`.
- `requirePermission('gastos')` por página (admin = superusuario, siempre `true`). Gestión de categorías y panel del dashboard: `isAdmin()`.
- No-admin solo ve/crea **sus** préstamos (gate en servidor, ya existente).
- No hay framework de tests. Verificación = `php -l <archivo>` (PHP 8.5 local) + checklist funcional + queries SQL en phpMyAdmin.
- Marca: negro `#1E1E1E`, amarillo `#FFDF00`, rosa `#FFBBC8`, crema `#FFEFBC`. Mobile-first.
- Moneda `formatMoney()` → "S/ 1,234.50". Fechas BD `yyyy-mm-dd`, display `formatDate()` dd/mm/yyyy. TZ America/Lima.
- Combobox de catálogo (categoría, subcategoría, insumo, proveedor): SIEMPRE búsqueda en vivo + crear al vuelo (nunca `<select>` plano).

---

### Task 1: Migración de esquema

**Files:**
- Create: `install/55_gastos_v2.sql`
- Modify: `install/check_migraciones.sql` (añadir fila de chequeo)

**Interfaces:**
- Produces: tabla `gasto_subcategorias(id, categoria_id, nombre)`; tabla `gasto_items(id, gasto_id, concepto, monto, categoria_id, subcategoria_id, insumo_id, cantidad, inv_movimiento_id, created_at)`; columnas nuevas en `gastos`: `origen ENUM('manual','pos','evento')`, `turno_id`, `evento_id`, `proveedor_id`; backfill de una `gasto_items` por cada `gastos` existente.

- [ ] **Step 1: Escribir la migración**

Create `install/55_gastos_v2.sql`:

```sql
-- 55_gastos_v2.sql — Gastos v2: subcategorías, líneas (multi), origen/enlaces.
-- Idempotente (re-ejecutable). Aplicar en phpMyAdmin tras git pull.

-- 1) Subcategorías
CREATE TABLE IF NOT EXISTS `gasto_subcategorias` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `categoria_id` INT UNSIGNED NOT NULL,
  `nombre`       VARCHAR(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_gsub` (`categoria_id`,`nombre`),
  KEY `idx_gsub_cat` (`categoria_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Líneas de gasto
CREATE TABLE IF NOT EXISTS `gasto_items` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gasto_id`          INT UNSIGNED NOT NULL,
  `concepto`          VARCHAR(200) NULL,
  `monto`             DECIMAL(10,2) NOT NULL DEFAULT 0,
  `categoria_id`      INT UNSIGNED NULL,
  `subcategoria_id`   INT UNSIGNED NULL,
  `insumo_id`         INT UNSIGNED NULL,
  `cantidad`          DECIMAL(12,3) NULL,
  `inv_movimiento_id` INT UNSIGNED NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gi_gasto` (`gasto_id`),
  KEY `idx_gi_cat` (`categoria_id`),
  KEY `idx_gi_sub` (`subcategoria_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3) Columnas nuevas en gastos (guard portable MySQL 5.7 / MariaDB)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gastos' AND column_name='origen');
SET @s := IF(@c=0, "ALTER TABLE `gastos` ADD COLUMN `origen` ENUM('manual','pos','evento') NOT NULL DEFAULT 'manual'", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gastos' AND column_name='turno_id');
SET @s := IF(@c=0, "ALTER TABLE `gastos` ADD COLUMN `turno_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gastos' AND column_name='evento_id');
SET @s := IF(@c=0, "ALTER TABLE `gastos` ADD COLUMN `evento_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='gastos' AND column_name='proveedor_id');
SET @s := IF(@c=0, "ALTER TABLE `gastos` ADD COLUMN `proveedor_id` INT UNSIGNED NULL", "SELECT 1");
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4) Backfill: una línea por cada gasto existente (idempotente)
INSERT INTO `gasto_items` (`gasto_id`,`concepto`,`monto`,`categoria_id`)
SELECT g.`id`, g.`concepto`, g.`monto`, g.`categoria_id`
FROM `gastos` g
WHERE NOT EXISTS (SELECT 1 FROM `gasto_items` gi WHERE gi.`gasto_id` = g.`id`);

-- 5) Categoría por defecto para gastos del POS
INSERT IGNORE INTO `gasto_categorias` (`nombre`) VALUES ('Caja / Operación');
```

- [ ] **Step 2: Añadir el chequeo en check_migraciones.sql**

Abre `install/check_migraciones.sql` y agrega (al final de la lista de SELECT UNION, siguiendo el formato existente del archivo) una fila que verifique la columna `gastos.origen`:

```sql
SELECT '55_gastos_v2.sql' AS migracion,
       IF(COUNT(*)>0, '✅', '❌') AS aplicada
FROM information_schema.columns
WHERE table_schema=DATABASE() AND table_name='gastos' AND column_name='origen'
UNION ALL
```
(Respeta el patrón exacto del archivo: si las filas usan `UNION ALL` entre cada SELECT, inserta la nueva antes del último SELECT y conserva la sintaxis.)

- [ ] **Step 3: Verificar sintaxis SQL localmente (parse)**

No hay MySQL local. Verificación manual: revisa que cada bloque `PREPARE/EXECUTE/DEALLOCATE` esté balanceado y que los `CREATE TABLE` terminen en `;`. Confirma que no hay comas finales en las listas de columnas.

- [ ] **Step 4: Commit**

```bash
git add install/55_gastos_v2.sql install/check_migraciones.sql
git commit -m "feat(gastos): migración v2 — subcategorías, líneas, origen/enlaces, backfill"
```

- [ ] **Step 5: Aplicar en phpMyAdmin (deploy) y verificar**

Tras `git pull` en el servidor, pega `install/55_gastos_v2.sql` en phpMyAdmin. Verificación:
```sql
SELECT COUNT(*) FROM gasto_items;          -- == nº de filas en gastos (backfill)
SHOW COLUMNS FROM gastos LIKE 'origen';     -- existe, ENUM('manual','pos','evento')
SELECT * FROM gasto_categorias WHERE nombre='Caja / Operación';  -- 1 fila
```

---

### Task 2: `invEntradaCompra` retorna el id del movimiento

**Files:**
- Modify: `includes/inventario.php:94-111` (función `invEntradaCompra`)

**Interfaces:**
- Produces: `invEntradaCompra(int $ubicacionId, int $insumoId, float $cantidad, float $costoUnit, array $opts = []): int` — retorna el id del `inventario_movimientos` creado (0 si no aplica/falla). Los callers actuales ignoran el retorno → cambio seguro.

- [ ] **Step 1: Cambiar firma y capturar el id de invMovimiento**

Reemplaza la función completa `invEntradaCompra` en `includes/inventario.php` por:

```php
function invEntradaCompra(int $ubicacionId, int $insumoId, float $cantidad, float $costoUnit, array $opts = []): int
{
    if ($insumoId <= 0 || $cantidad <= 0) return 0;
    try {
        $stockPrev = (float)(Database::fetch("SELECT COALESCE(SUM(stock),0) s FROM insumo_stock WHERE insumo_id = ?", [$insumoId])['s'] ?? 0);
        $costoPrev = (float)(Database::fetch("SELECT costo_unitario c FROM insumos WHERE id = ?", [$insumoId])['c'] ?? 0);
        $denom = $stockPrev + $cantidad;
        $nuevoCosto = $denom > 0 ? (($stockPrev * $costoPrev) + ($cantidad * $costoUnit)) / $denom : $costoUnit;

        $movId = invMovimiento($ubicacionId, $insumoId, 'compra', $cantidad, [
            'costo_unitario' => $costoUnit,
            'motivo'         => $opts['motivo'] ?? 'Compra',
            'ref'            => $opts['ref'] ?? null,
        ]);
        Database::execute("UPDATE insumos SET costo_unitario = ? WHERE id = ?", [round($nuevoCosto, 4), $insumoId]);
        return $movId;
    } catch (Exception $e) { return 0; }
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l includes/inventario.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verificar que no rompió compras**

Run: `grep -rn "invEntradaCompra" admin/ api/ includes/`
Expected: los callers existentes llaman sin usar el retorno (p.ej. `invEntradaCompra(...);`) → siguen válidos.

- [ ] **Step 4: Commit**

```bash
git add includes/inventario.php
git commit -m "refactor(inventario): invEntradaCompra retorna id del movimiento (para enganche de gastos)"
```

---

### Task 3: Librería compartida `includes/gastos.php`

**Files:**
- Create: `includes/gastos.php`

**Interfaces:**
- Consumes: `Database::*`, `includes/inventario.php` (`invEntradaCompra`, `invMovimiento`, `inventarioListo`), `helpers.php` (`currentUser`).
- Produces:
  - `gastosListo(): bool`
  - `gastoCategorias(?string $q = null, int $limit = 30): array` → `[['id'=>int,'nombre'=>string], ...]`
  - `gastoSubcategorias(int $catId, ?string $q = null, int $limit = 30): array` → idem
  - `gastoCrearCategoria(string $nombre): array` → `['id'=>int,'nombre'=>string]`
  - `gastoCrearSubcategoria(int $catId, string $nombre): array` → idem
  - `gastoGuardar(array $h, array $items, ?int $id = null): int` → id del gasto
  - `gastoEliminar(int $id): void`
  - `gastoItems(int $gastoId): array` → líneas con `cat_nombre`, `sub_nombre`, `insumo_nombre`
  - `gastoMigrarEventoLegacy(int $eventoId, int $usuarioId): void` → mueve `evento_gastos` de ese evento a `gastos`/`gasto_items` y borra el origen (idempotente)
  - Formato de `$h`: `['tipo','concepto','ubicacion_id','proveedor_id','fecha','tags','foto','nota','estado','usuario_id','origen','turno_id','evento_id']`
  - Formato de cada item: `['concepto','monto','categoria_id','subcategoria_id','insumo_id','cantidad']`

- [ ] **Step 1: Escribir la librería**

Create `includes/gastos.php`:

```php
<?php
/**
 * Gastos v2 — persistencia compartida (cabecera + líneas), búsqueda/creación
 * de categorías/subcategorías y enganche opcional con inventario.
 * Consumido por: admin/gastos/form.php, api/gastos.php, api/pos.php (cerrar_turno),
 * admin/inventory/evento_detalle.php.
 */
require_once __DIR__ . '/inventario.php';

/** ¿Existen las tablas de gastos v2? */
function gastosListo(): bool
{
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'gasto_items'"
        );
    } catch (\Throwable $e) { return false; }
}

/** Lista/busca categorías de gasto. */
function gastoCategorias(?string $q = null, int $limit = 30): array
{
    $limit = max(1, min(100, $limit));
    if ($q !== null && $q !== '') {
        return Database::fetchAll(
            "SELECT id, nombre FROM gasto_categorias WHERE nombre LIKE ? ORDER BY nombre LIMIT $limit",
            ['%' . $q . '%']
        );
    }
    return Database::fetchAll("SELECT id, nombre FROM gasto_categorias ORDER BY nombre LIMIT $limit");
}

/** Lista/busca subcategorías dentro de una categoría. */
function gastoSubcategorias(int $catId, ?string $q = null, int $limit = 30): array
{
    if ($catId <= 0) return [];
    $limit = max(1, min(100, $limit));
    if ($q !== null && $q !== '') {
        return Database::fetchAll(
            "SELECT id, nombre FROM gasto_subcategorias WHERE categoria_id = ? AND nombre LIKE ? ORDER BY nombre LIMIT $limit",
            [$catId, '%' . $q . '%']
        );
    }
    return Database::fetchAll(
        "SELECT id, nombre FROM gasto_subcategorias WHERE categoria_id = ? ORDER BY nombre LIMIT $limit",
        [$catId]
    );
}

/** Crea (o recupera) una categoría por nombre. */
function gastoCrearCategoria(string $nombre): array
{
    $nombre = trim($nombre);
    if ($nombre === '') return ['id' => 0, 'nombre' => ''];
    Database::execute("INSERT IGNORE INTO gasto_categorias (nombre) VALUES (?)", [$nombre]);
    $r = Database::fetch("SELECT id, nombre FROM gasto_categorias WHERE nombre = ?", [$nombre]);
    return $r ?: ['id' => 0, 'nombre' => $nombre];
}

/** Crea (o recupera) una subcategoría dentro de una categoría. */
function gastoCrearSubcategoria(int $catId, string $nombre): array
{
    $nombre = trim($nombre);
    if ($catId <= 0 || $nombre === '') return ['id' => 0, 'nombre' => ''];
    Database::execute("INSERT IGNORE INTO gasto_subcategorias (categoria_id, nombre) VALUES (?,?)", [$catId, $nombre]);
    $r = Database::fetch("SELECT id, nombre FROM gasto_subcategorias WHERE categoria_id = ? AND nombre = ?", [$catId, $nombre]);
    return $r ?: ['id' => 0, 'nombre' => $nombre];
}

/** Líneas de un gasto con nombres legibles. */
function gastoItems(int $gastoId): array
{
    return Database::fetchAll(
        "SELECT gi.*, c.nombre AS cat_nombre, s.nombre AS sub_nombre, i.nombre AS insumo_nombre
         FROM gasto_items gi
         LEFT JOIN gasto_categorias c ON c.id = gi.categoria_id
         LEFT JOIN gasto_subcategorias s ON s.id = gi.subcategoria_id
         LEFT JOIN insumos i ON i.id = gi.insumo_id
         WHERE gi.gasto_id = ? ORDER BY gi.id",
        [$gastoId]
    );
}

/** Revierte (con un ajuste compensatorio) el inventario de todas las líneas de un gasto. */
function gastoRevertirInventario(int $gastoId): void
{
    if (!inventarioListo()) return;
    $ubi = (int)(Database::fetch("SELECT ubicacion_id FROM gastos WHERE id = ?", [$gastoId])['ubicacion_id'] ?? 0);
    foreach (Database::fetchAll("SELECT id, insumo_id, cantidad, inv_movimiento_id FROM gasto_items WHERE gasto_id = ? AND inv_movimiento_id IS NOT NULL", [$gastoId]) as $it) {
        $ins = (int)$it['insumo_id']; $cant = (float)$it['cantidad'];
        if ($ubi > 0 && $ins > 0 && $cant > 0) {
            invMovimiento($ubi, $ins, 'ajuste', -$cant, ['motivo' => 'Reversa gasto #' . $gastoId]);
        }
        Database::execute("UPDATE gasto_items SET inv_movimiento_id = NULL WHERE id = ?", [(int)$it['id']]);
    }
}

/** Aplica el enganche de inventario para una línea (compra). Devuelve el id del movimiento o 0. */
function gastoAplicarInventarioItem(int $ubicacionId, int $insumoId, float $cantidad, float $monto): int
{
    if (!inventarioListo() || $ubicacionId <= 0 || $insumoId <= 0 || $cantidad <= 0) return 0;
    $costoUnit = $cantidad > 0 ? $monto / $cantidad : 0;
    return invEntradaCompra($ubicacionId, $insumoId, $cantidad, $costoUnit, ['motivo' => 'Gasto']);
}

/**
 * Inserta/actualiza un gasto (cabecera + líneas). Recalcula el total de la cabecera
 * como la suma de las líneas y aplica el enganche de inventario en líneas con insumo+cantidad.
 * En edición: revierte el inventario previo, reemplaza líneas y reaplica (recompute fresco).
 */
function gastoGuardar(array $h, array $items, ?int $id = null): int
{
    $tipo     = in_array($h['tipo'] ?? '', ['empresa','prestamo'], true) ? $h['tipo'] : 'empresa';
    $concepto = trim((string)($h['concepto'] ?? ''));
    $ubiId    = !empty($h['ubicacion_id']) ? (int)$h['ubicacion_id'] : null;
    $provId   = !empty($h['proveedor_id']) ? (int)$h['proveedor_id'] : null;
    $fecha    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($h['fecha'] ?? '')) ? $h['fecha'] : date('Y-m-d');
    $tags     = ($h['tags'] ?? '') ?: null;
    $foto     = $h['foto'] ?? null;
    $nota     = ($h['nota'] ?? '') ?: null;
    $estado   = in_array($h['estado'] ?? '', ['pendiente','pagado'], true) ? $h['estado'] : ($tipo === 'empresa' ? 'pagado' : 'pendiente');
    $usuario  = (int)($h['usuario_id'] ?? (currentUser()['id'] ?? 0));
    $origen   = in_array($h['origen'] ?? '', ['manual','pos','evento'], true) ? $h['origen'] : 'manual';
    $turnoId  = !empty($h['turno_id'])  ? (int)$h['turno_id']  : null;
    $eventoId = !empty($h['evento_id']) ? (int)$h['evento_id'] : null;

    // Normaliza líneas (descarta las de monto<=0 salvo que tengan concepto)
    $clean = [];
    $total = 0.0;
    foreach ($items as $it) {
        $monto = round((float)($it['monto'] ?? 0), 2);
        $cat   = !empty($it['categoria_id'])    ? (int)$it['categoria_id']    : null;
        $sub   = !empty($it['subcategoria_id']) ? (int)$it['subcategoria_id'] : null;
        $ins   = !empty($it['insumo_id'])       ? (int)$it['insumo_id']       : null;
        $cant  = isset($it['cantidad']) && $it['cantidad'] !== '' ? (float)$it['cantidad'] : null;
        $conc  = trim((string)($it['concepto'] ?? ''));
        if ($monto <= 0 && $conc === '') continue;
        $clean[] = ['concepto' => ($conc ?: null), 'monto' => $monto, 'categoria_id' => $cat,
                    'subcategoria_id' => $sub, 'insumo_id' => $ins, 'cantidad' => $cant];
        $total += $monto;
    }
    if (!$clean) { $clean[] = ['concepto' => ($concepto ?: 'Gasto'), 'monto' => 0, 'categoria_id' => null,
                               'subcategoria_id' => null, 'insumo_id' => null, 'cantidad' => null]; }
    $total = round($total, 2);

    if ($id) {
        // Edición: revierte inventario previo y reemplaza líneas.
        gastoRevertirInventario($id);
        Database::execute("DELETE FROM gasto_items WHERE gasto_id = ?", [$id]);
        Database::execute(
            "UPDATE gastos SET tipo=?, concepto=?, monto=?, ubicacion_id=?, proveedor_id=?, fecha=?, tags=?, foto=?, nota=?, estado=? WHERE id=?",
            [$tipo, $concepto, $total, $ubiId, $provId, $fecha, $tags, $foto, $nota, $estado, $id]
        );
        $gid = $id;
    } else {
        $gid = Database::insert(
            "INSERT INTO gastos (tipo, concepto, monto, ubicacion_id, proveedor_id, usuario_id, fecha, tags, foto, nota, estado, origen, turno_id, evento_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [$tipo, $concepto, $total, $ubiId, $provId, $usuario, $fecha, $tags, $foto, $nota, $estado, $origen, $turnoId, $eventoId]
        );
    }

    foreach ($clean as $it) {
        $movId = null;
        if ($ubiId && $it['insumo_id'] && $it['cantidad'] && $it['cantidad'] > 0) {
            $m = gastoAplicarInventarioItem($ubiId, (int)$it['insumo_id'], (float)$it['cantidad'], (float)$it['monto']);
            $movId = $m ?: null;
        }
        Database::execute(
            "INSERT INTO gasto_items (gasto_id, concepto, monto, categoria_id, subcategoria_id, insumo_id, cantidad, inv_movimiento_id)
             VALUES (?,?,?,?,?,?,?,?)",
            [$gid, $it['concepto'], $it['monto'], $it['categoria_id'], $it['subcategoria_id'], $it['insumo_id'], $it['cantidad'], $movId]
        );
    }
    return (int)$gid;
}

/** Elimina un gasto: revierte inventario, borra líneas, borra foto y cabecera. */
function gastoEliminar(int $id): void
{
    if ($id <= 0) return;
    gastoRevertirInventario($id);
    $g = Database::fetch("SELECT foto FROM gastos WHERE id = ?", [$id]);
    if ($g && !empty($g['foto']) && defined('UPLOAD_PATH') && is_file(UPLOAD_PATH . $g['foto'])) @unlink(UPLOAD_PATH . $g['foto']);
    Database::execute("DELETE FROM gasto_items WHERE gasto_id = ?", [$id]);
    Database::execute("DELETE FROM gastos WHERE id = ?", [$id]);
}

/** Mueve los evento_gastos legacy de un evento al registro global (idempotente: borra el origen). */
function gastoMigrarEventoLegacy(int $eventoId, int $usuarioId): void
{
    if ($eventoId <= 0) return;
    try {
        $rows = Database::fetchAll("SELECT * FROM evento_gastos WHERE evento_id = ?", [$eventoId]);
    } catch (\Throwable $e) { return; } // tabla legacy no existe
    foreach ($rows as $eg) {
        gastoGuardar(
            ['tipo' => 'empresa', 'concepto' => (string)($eg['descripcion'] ?? 'Gasto de evento'),
             'ubicacion_id' => null, 'fecha' => date('Y-m-d', strtotime((string)$eg['created_at'])),
             'estado' => 'pagado', 'usuario_id' => $usuarioId, 'origen' => 'evento', 'evento_id' => $eventoId],
            [['concepto' => (string)($eg['descripcion'] ?? ''), 'monto' => (float)$eg['monto'], 'categoria_id' => ($eg['categoria_id'] ?? null)]]
        );
        Database::execute("DELETE FROM evento_gastos WHERE id = ?", [(int)$eg['id']]);
    }
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l includes/gastos.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/gastos.php
git commit -m "feat(gastos): librería compartida de persistencia (cabecera+líneas, inventario, categorías)"
```

---

### Task 4: Componente combobox (búsqueda en vivo + crear al vuelo)

**Files:**
- Create: `assets/js/combobox.js`
- Modify: `assets/css/style.css` (añadir bloque `.egc`)
- Modify: `admin/layout-bottom.php` (cargar el script)

**Interfaces:**
- Produces: marcado reutilizable
  ```html
  <div class="egc" data-egc data-search="buscar_categorias" data-create="crear_categoria"
       data-csrf="<token>" data-dep="" data-dep-create-key="categoria_id">
    <input type="text" class="egc-input" placeholder="Buscar o crear…" autocomplete="off">
    <input type="hidden" class="egc-id" name="categoria_id[]" value="">
    <div class="egc-menu"></div>
  </div>
  ```
  - `data-search` / `data-create`: nombres de acción de `api/gastos.php`.
  - `data-dep`: selector CSS (relativo al `[data-egc-scope]` contenedor) del `.egc-id` del que depende (subcategoría depende de categoría). Vacío = sin dependencia.
  - `data-dep-create-key`: nombre del parámetro extra a enviar al crear (p.ej. `categoria_id`).
  - JS global `window.EGCombo.init(rootEl)` inicializa todos los `.egc` dentro de `rootEl` (idempotente). Se autoejecuta en `DOMContentLoaded` sobre `document`.
  - Constante `window.EG_GASTOS_API` debe existir (URL de `api/gastos.php`); si no, usa `'/api/gastos.php'` relativo a `APP_URL` vía atributo.

- [ ] **Step 1: Escribir el JS**

Create `assets/js/combobox.js`:

```javascript
/* EGCombo — combobox con búsqueda en vivo + crear al vuelo. Vanilla, sin deps. */
(function () {
  'use strict';
  function api() { return (window.EG_GASTOS_API || '/api/gastos.php'); }

  function depValue(el) {
    var sel = el.getAttribute('data-dep');
    if (!sel) return '';
    var scope = el.closest('[data-egc-scope]') || document;
    var dep = scope.querySelector(sel);
    return dep ? (dep.value || '') : '';
  }

  function setup(el) {
    if (el.getAttribute('data-egc-ready')) return;
    el.setAttribute('data-egc-ready', '1');
    var input  = el.querySelector('.egc-input');
    var hidden = el.querySelector('.egc-id');
    var menu   = el.querySelector('.egc-menu');
    var searchAction = el.getAttribute('data-search');
    var createAction = el.getAttribute('data-create');
    var csrf   = el.getAttribute('data-csrf') || '';
    var depKey = el.getAttribute('data-dep-create-key') || '';
    var timer = null, lastQ = '';

    function close() { menu.classList.remove('on'); menu.innerHTML = ''; }
    function open()  { menu.classList.add('on'); }

    function pick(id, nombre) {
      hidden.value = id;
      input.value = nombre;
      close();
      el.dispatchEvent(new CustomEvent('egc:change', { bubbles: true, detail: { id: id, nombre: nombre } }));
    }

    function render(list, q) {
      menu.innerHTML = '';
      list.forEach(function (it) {
        var d = document.createElement('div');
        d.className = 'egc-opt';
        d.textContent = it.nombre;
        d.addEventListener('mousedown', function (e) { e.preventDefault(); pick(it.id, it.nombre); });
        menu.appendChild(d);
      });
      var exact = list.some(function (it) { return it.nombre.toLowerCase() === q.toLowerCase(); });
      if (q && !exact && createAction) {
        var c = document.createElement('div');
        c.className = 'egc-opt egc-create';
        c.textContent = '➕ Crear «' + q + '»';
        c.addEventListener('mousedown', function (e) { e.preventDefault(); create(q); });
        menu.appendChild(c);
      }
      if (menu.children.length) open(); else close();
    }

    function search(q) {
      var url = api() + '?action=' + encodeURIComponent(searchAction) + '&q=' + encodeURIComponent(q);
      var dep = depValue(el);
      if (depKey && dep) url += '&' + depKey + '=' + encodeURIComponent(dep);
      fetch(url, { headers: { 'X-CSRF-Token': csrf } })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok) render(d.items || [], q); })
        .catch(function () { close(); });
    }

    function create(nombre) {
      var body = 'action=' + encodeURIComponent(createAction) + '&nombre=' + encodeURIComponent(nombre);
      var dep = depValue(el);
      if (depKey && dep) body += '&' + depKey + '=' + encodeURIComponent(dep);
      fetch(api(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: body
      })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d && d.ok && d.item) pick(d.item.id, d.item.nombre); })
        .catch(function () {});
    }

    input.addEventListener('input', function () {
      hidden.value = ''; // al teclear se invalida la selección previa
      var q = input.value.trim();
      lastQ = q;
      clearTimeout(timer);
      timer = setTimeout(function () { if (q === lastQ) search(q); }, 180);
    });
    input.addEventListener('focus', function () { search(input.value.trim()); });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
  }

  window.EGCombo = {
    init: function (root) {
      (root || document).querySelectorAll('.egc[data-egc]').forEach(setup);
    }
  };
  document.addEventListener('DOMContentLoaded', function () { window.EGCombo.init(document); });
})();
```

- [ ] **Step 2: Añadir estilos en style.css**

Agrega al final de `assets/css/style.css`:

```css
/* ── EGCombo (combobox búsqueda en vivo + crear al vuelo) ── */
.egc{position:relative}
.egc-input{width:100%}
.egc-menu{position:absolute;left:0;right:0;top:calc(100% + 4px);z-index:50;background:#fff;border:1px solid var(--border,#ddd);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;display:none}
.egc-menu.on{display:block}
.egc-opt{padding:10px 12px;font-size:14px;cursor:pointer;color:var(--text-primary,#1E1E1E)}
.egc-opt:hover{background:var(--bg-page,#f1f1f4)}
.egc-create{font-weight:800;color:#1E1E1E;border-top:1px solid var(--border,#eee);background:#fffbe6}
```

- [ ] **Step 3: Cargar el script en el layout admin**

En `admin/layout-bottom.php`, antes del `</body>` (o junto a los demás scripts globales), añade:

```php
<script>window.EG_GASTOS_API = '<?php echo APP_URL; ?>/api/gastos.php';</script>
<script src="<?php echo APP_URL; ?>/assets/js/combobox.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/combobox.js') ?: time(); ?>"></script>
```

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/layout-bottom.php`
Expected: `No syntax errors detected`
Run: `node --check assets/js/combobox.js`  (si hay node; si no, revisar visualmente que llaves/paréntesis cierran)
Expected: sin errores.

- [ ] **Step 5: Commit**

```bash
git add assets/js/combobox.js assets/css/style.css admin/layout-bottom.php
git commit -m "feat(ui): componente combobox búsqueda en vivo + crear al vuelo (EGCombo)"
```

---

### Task 5: Endpoint `api/gastos.php`

**Files:**
- Create: `api/gastos.php`

**Interfaces:**
- Consumes: `includes/gastos.php` (`gastoCategorias`, `gastoSubcategorias`, `gastoCrearCategoria`, `gastoCrearSubcategoria`), `includes/inventario.php`/`inventarioListo`, tablas `insumos` y `proveedores`.
- Produces (JSON):
  - GET `?action=buscar_categorias&q=` → `{ok:true, items:[{id,nombre}]}`
  - GET `?action=buscar_subcategorias&q=&categoria_id=` → idem
  - GET `?action=buscar_insumos&q=` → `{ok:true, items:[{id,nombre}]}`
  - GET `?action=buscar_proveedores&q=` → `{ok:true, items:[{id,nombre}]}`
  - POST `?action=crear_categoria` (`nombre`) → `{ok:true, item:{id,nombre}}`
  - POST `?action=crear_subcategoria` (`nombre`, `categoria_id`) → idem
  - POST `?action=crear_insumo` (`nombre`) → idem (unidad por defecto 'und')
  - POST `?action=crear_proveedor` (`nombre`) → idem
  - Escrituras requieren `verifyCsrf()` (header `X-CSRF-Token`).

- [ ] **Step 1: Escribir el endpoint**

Create `api/gastos.php`:

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/gastos.php';

requireLogin();
if (!can('gastos')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
header('Content-Type: application/json; charset=utf-8');

$action  = $_GET['action'] ?? $_POST['action'] ?? '';
$writes  = ['crear_categoria', 'crear_subcategoria', 'crear_insumo', 'crear_proveedor'];
if (in_array($action, $writes, true)) verifyCsrf();

function jout($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

switch ($action) {
    case 'buscar_categorias':
        jout(['ok' => true, 'items' => gastoCategorias(trim((string)($_GET['q'] ?? '')))]);

    case 'buscar_subcategorias':
        jout(['ok' => true, 'items' => gastoSubcategorias(cleanInt($_GET['categoria_id'] ?? 0), trim((string)($_GET['q'] ?? '')))]);

    case 'buscar_insumos':
        if (!inventarioListo()) jout(['ok' => true, 'items' => []]);
        $q = trim((string)($_GET['q'] ?? ''));
        $rows = $q !== ''
            ? Database::fetchAll("SELECT id, nombre FROM insumos WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 30", ['%' . $q . '%'])
            : Database::fetchAll("SELECT id, nombre FROM insumos WHERE activo=1 ORDER BY nombre LIMIT 30");
        jout(['ok' => true, 'items' => $rows]);

    case 'buscar_proveedores':
        try {
            $q = trim((string)($_GET['q'] ?? ''));
            $rows = $q !== ''
                ? Database::fetchAll("SELECT id, nombre FROM proveedores WHERE nombre LIKE ? ORDER BY nombre LIMIT 30", ['%' . $q . '%'])
                : Database::fetchAll("SELECT id, nombre FROM proveedores ORDER BY nombre LIMIT 30");
        } catch (\Throwable $e) { $rows = []; }
        jout(['ok' => true, 'items' => $rows]);

    case 'crear_categoria':
        $item = gastoCrearCategoria(clean($_POST['nombre'] ?? ''));
        jout($item['id'] ? ['ok' => true, 'item' => $item] : ['ok' => false, 'error' => 'nombre vacío']);

    case 'crear_subcategoria':
        $item = gastoCrearSubcategoria(cleanInt($_POST['categoria_id'] ?? 0), clean($_POST['nombre'] ?? ''));
        jout($item['id'] ? ['ok' => true, 'item' => $item] : ['ok' => false, 'error' => 'falta categoría o nombre']);

    case 'crear_insumo':
        if (!inventarioListo()) jout(['ok' => false, 'error' => 'inventario no disponible']);
        $nombre = clean($_POST['nombre'] ?? '');
        if ($nombre === '') jout(['ok' => false, 'error' => 'nombre vacío']);
        $ex = Database::fetch("SELECT id, nombre FROM insumos WHERE nombre = ?", [$nombre]);
        if ($ex) jout(['ok' => true, 'item' => $ex]);
        $id = Database::insert("INSERT INTO insumos (nombre, unidad, costo_unitario, activo) VALUES (?, 'und', 0, 1)", [$nombre]);
        jout(['ok' => true, 'item' => ['id' => $id, 'nombre' => $nombre]]);

    case 'crear_proveedor':
        $nombre = clean($_POST['nombre'] ?? '');
        if ($nombre === '') jout(['ok' => false, 'error' => 'nombre vacío']);
        try {
            $ex = Database::fetch("SELECT id, nombre FROM proveedores WHERE nombre = ?", [$nombre]);
            if ($ex) jout(['ok' => true, 'item' => $ex]);
            $id = Database::insert("INSERT INTO proveedores (nombre) VALUES (?)", [$nombre]);
            jout(['ok' => true, 'item' => ['id' => $id, 'nombre' => $nombre]]);
        } catch (\Throwable $e) { jout(['ok' => false, 'error' => 'proveedores no disponible']); }

    default:
        http_response_code(400);
        jout(['ok' => false, 'error' => 'acción inválida']);
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l api/gastos.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verificar columnas reales de insumos/proveedores**

Run: `grep -niE "CREATE TABLE .*(insumos|proveedores)" install/*.sql`
Confirma que `insumos` tiene columnas `nombre, unidad, costo_unitario, activo` y `proveedores` tiene `nombre`. Si difieren, ajusta los INSERT/SELECT del endpoint a los nombres reales.

- [ ] **Step 4: Commit**

```bash
git add api/gastos.php
git commit -m "feat(gastos): api/gastos.php — búsqueda en vivo + creación al vuelo de catálogos"
```

---

### Task 6: Rediseño del form de registro (`admin/gastos/form.php`)

**Files:**
- Modify (rewrite): `admin/gastos/form.php`

**Interfaces:**
- Consumes: `includes/gastos.php` (`gastoGuardar`, `gastoItems`, `gastosListo`), `api/gastos.php` (vía combobox), `uploadImage`, `csrfToken`.
- Produces: form con cabecera (tipo, fecha, tienda, proveedor combobox, foto, nota, tags) + líneas repetibles (concepto, monto, categoría combobox, subcategoría combobox dependiente, enganche insumo+cantidad), total auto-sumado. Guarda vía `gastoGuardar`.

- [ ] **Step 1: Reescribir el form**

Replace el contenido completo de `admin/gastos/form.php` por:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/gastos.php';

requirePermission('gastos');

$admin = isAdmin();
$uid   = (int) (currentUser()['id'] ?? 0);
$id    = cleanInt($_GET['id'] ?? 0);
$g     = $id ? Database::fetch("SELECT * FROM gastos WHERE id = ?", [$id]) : null;
if ($id && !$g) { flashMessage('error', 'Gasto no encontrado.'); redirect('/admin/gastos/index.php'); }
if ($g && !$admin && ((int)$g['usuario_id'] !== $uid || $g['tipo'] !== 'prestamo')) {
    flashMessage('error', 'No tienes acceso a ese gasto.');
    redirect('/admin/gastos/index.php');
}
$isEdit = (bool) $g;
$ubis   = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, sort_order, nombre");
$invOk  = function_exists('inventarioListo') && inventarioListo();

/** Normaliza tags a slugs por coma. */
function normalizeTags(string $raw): string {
    $out = [];
    foreach (preg_split('/[,\s]+/', $raw) as $t) {
        $t = ltrim(trim($t), '#'); $t = strtolower($t);
        $t = preg_replace('/[^a-z0-9áéíóúñ]+/u', '-', $t); $t = trim($t, '-');
        if ($t !== '' && !in_array($t, $out, true)) $out[] = $t;
    }
    return implode(',', array_slice($out, 0, 12));
}

$data  = $g ?? ['tipo' => $admin ? 'empresa' : 'prestamo', 'concepto' => '', 'ubicacion_id' => null,
                'proveedor_id' => null, 'fecha' => date('Y-m-d'), 'tags' => '', 'foto' => null, 'nota' => ''];
$items = $isEdit ? gastoItems($id) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $tipo = $admin ? (in_array($_POST['tipo'] ?? '', ['empresa','prestamo'], true) ? $_POST['tipo'] : 'empresa') : 'prestamo';

    // Foto
    $foto = $data['foto'] ?? null;
    if (!empty($_FILES['foto']['name'])) { $up = uploadImage($_FILES['foto'], 'gastos'); if ($up) $foto = $up; }

    // Líneas (arrays paralelos)
    $L = [];
    $lc = $_POST['l_concepto']   ?? [];
    $lm = $_POST['l_monto']      ?? [];
    $lk = $_POST['categoria_id'] ?? [];
    $ls = $_POST['subcategoria_id'] ?? [];
    $li = $_POST['insumo_id']    ?? [];
    $lq = $_POST['l_cantidad']   ?? [];
    $n  = max(count($lm), count($lc));
    for ($i = 0; $i < $n; $i++) {
        $L[] = [
            'concepto'        => clean($lc[$i] ?? ''),
            'monto'           => cleanFloat($lm[$i] ?? 0),
            'categoria_id'    => cleanInt($lk[$i] ?? 0) ?: null,
            'subcategoria_id' => cleanInt($ls[$i] ?? 0) ?: null,
            'insumo_id'       => cleanInt($li[$i] ?? 0) ?: null,
            'cantidad'        => ($lq[$i] ?? '') !== '' ? cleanFloat($lq[$i]) : null,
        ];
    }

    $header = [
        'tipo' => $tipo, 'concepto' => clean($_POST['concepto'] ?? ''),
        'ubicacion_id' => cleanInt($_POST['ubicacion_id'] ?? 0) ?: null,
        'proveedor_id' => cleanInt($_POST['proveedor_id'] ?? 0) ?: null,
        'fecha' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d'),
        'tags' => normalizeTags($_POST['tags'] ?? ''), 'foto' => $foto, 'nota' => clean($_POST['nota'] ?? ''),
        'usuario_id' => $isEdit ? (int)$g['usuario_id'] : $uid,
    ];
    if ($isEdit) $header['estado'] = $g['estado'];

    $totalLineas = array_sum(array_map(fn($x) => (float)$x['monto'], $L));
    if ($totalLineas <= 0) {
        flashMessage('error', 'Agrega al menos una línea con monto mayor a 0.');
    } else {
        gastoGuardar($header, $L, $isEdit ? $id : null);
        flashMessage('success', $isEdit ? 'Gasto actualizado.' : 'Gasto registrado.');
        redirect('/admin/gastos/index.php');
    }
}

$pageTitle  = $isEdit ? 'Editar gasto' : 'Nuevo gasto';
$activePage = 'gastos';
$csrf       = csrfToken();
$extraHead  = '<style>
.gform{max-width:600px}
.seg{display:flex;background:var(--bg-page,#f1f1f4);border-radius:12px;padding:4px;margin-bottom:18px}
.seg label{flex:1;text-align:center;padding:11px;border-radius:9px;font-size:14px;font-weight:800;color:var(--text-muted,#888);cursor:pointer}
.seg input{position:absolute;opacity:0;pointer-events:none}
.seg input:checked + label{background:#fff;color:#1E1E1E;box-shadow:0 1px 4px rgba(0,0,0,.12)}
.seg input.prest:checked + label{background:#FFBBC8;color:#1E1E1E}
.gline{border:1.5px solid var(--border,#e3e3e3);border-radius:14px;padding:12px;margin-bottom:10px;background:#fff}
.gline-row{display:flex;gap:8px;margin-bottom:8px}
.gline-row > *{flex:1}
.gline-row .mini{flex:0 0 110px}
.gline-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.gline-head b{font-size:12px;color:var(--text-muted,#888);text-transform:uppercase;letter-spacing:1px}
.gline-del{border:none;background:none;color:#e23744;font-weight:800;cursor:pointer;font-size:13px}
.inv-toggle{font-size:12px;font-weight:700;color:#1E1E1E;background:#fffbe6;border:1px dashed var(--c-brand,#FFDF00);border-radius:9px;padding:7px 11px;cursor:pointer;display:inline-block}
.inv-box{display:none;gap:8px;margin-top:8px}
.inv-box.on{display:flex}
.gline-total{text-align:right;font-size:12px;color:var(--text-muted,#888)}
.g-addline{width:100%;border:1.5px dashed var(--border,#ccc);background:var(--bg-page,#fafafa);border-radius:11px;padding:12px;font-weight:800;color:#1E1E1E;cursor:pointer;margin-bottom:14px}
.g-grandtotal{display:flex;justify-content:space-between;align-items:center;background:#1E1E1E;color:var(--c-brand,#FFDF00);border-radius:12px;padding:14px 16px;font-weight:900;font-size:18px;margin-bottom:16px}
.tags-box{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1.5px solid var(--border,#ddd);border-radius:10px;padding:8px;background:#fff}
.tagchip{background:#1E1E1E;color:var(--c-brand,#FFDF00);font-size:12px;font-weight:800;padding:4px 9px;border-radius:7px;display:inline-flex;gap:5px;align-items:center}
.tagchip b{cursor:pointer;opacity:.7}
#tag-input{flex:1;min-width:100px;border:none;outline:none;font-size:14px;padding:4px;background:transparent}
.foto-btn{flex:1;min-width:130px;display:flex;flex-direction:column;align-items:center;gap:6px;border:1.5px dashed var(--border,#ddd);border-radius:12px;padding:16px 12px;background:var(--bg-page,#fafafa);color:var(--text-muted,#666);font-size:13px;font-weight:600;cursor:pointer}
.foto-btn svg{width:26px;height:26px}
.foto-prev{margin-top:10px}.foto-prev img{max-width:100%;border-radius:10px}
</style>';
include __DIR__ . '/../layout-top.php';

/** Render de un combobox EGCombo. */
function egcombo(string $name, string $search, string $create, string $csrf, string $ph, $valId = '', string $valTxt = '', string $dep = '', string $depKey = ''): string {
    $h  = '<div class="egc" data-egc data-search="' . $search . '" data-create="' . $create . '" data-csrf="' . clean($csrf) . '"';
    $h .= ' data-dep="' . clean($dep) . '" data-dep-create-key="' . clean($depKey) . '">';
    $h .= '<input type="text" class="egc-input" placeholder="' . clean($ph) . '" autocomplete="off" value="' . clean($valTxt) . '">';
    $h .= '<input type="hidden" class="egc-id" name="' . clean($name) . '" value="' . clean((string)$valId) . '">';
    $h .= '<div class="egc-menu"></div></div>';
    return $h;
}
?>

<div class="page-header"><div class="page-header-left"><h1><?= $pageTitle ?></h1></div></div>

<div class="card gform"><div class="card-body">
<form method="post" enctype="multipart/form-data" id="gform">
  <?= csrfField() ?>

  <?php if ($admin): ?>
  <div class="seg">
    <input type="radio" name="tipo" id="tipo-emp" value="empresa" <?= ($data['tipo']??'')==='empresa'?'checked':'' ?>>
    <label for="tipo-emp">Empresa</label>
    <input type="radio" name="tipo" id="tipo-pre" value="prestamo" class="prest" <?= ($data['tipo']??'')==='prestamo'?'checked':'' ?>>
    <label for="tipo-pre">Préstamo</label>
  </div>
  <?php else: ?>
  <input type="hidden" name="tipo" value="prestamo">
  <div class="alert" style="background:#FFBBC8;color:#1E1E1E;border-radius:10px;padding:10px 14px;font-weight:700;margin-bottom:16px">Registrando un préstamo</div>
  <?php endif; ?>

  <div class="form-group">
    <label>Título / concepto general <span style="font-weight:400;color:#999">(opcional)</span></label>
    <input type="text" name="concepto" value="<?= clean($data['concepto'] ?? '') ?>" placeholder="Ej: Compra mercado, Pago de gas…">
  </div>

  <!-- LÍNEAS -->
  <div id="lines"></div>
  <button type="button" class="g-addline" onclick="addLine()">➕ Agregar línea</button>

  <div class="g-grandtotal"><span>Total</span><span id="grand">S/ 0.00</span></div>

  <div class="form-group">
    <label>Proveedor <span style="font-weight:400;color:#999">(opcional)</span></label>
    <?= egcombo('proveedor_id', 'buscar_proveedores', 'crear_proveedor', $csrf, 'Buscar o crear proveedor…', $data['proveedor_id'] ?? '', '') ?>
  </div>

  <?php if ($ubis): ?>
  <div class="form-group">
    <label>Tienda</label>
    <select name="ubicacion_id" id="ubic">
      <option value="">— Sin asignar —</option>
      <?php foreach ($ubis as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= (int)($data['ubicacion_id'] ?? 0)===(int)$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($invOk): ?><div style="font-size:11px;color:#999;margin-top:4px">El enganche con inventario requiere una tienda.</div><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="form-group">
    <label class="form-required">Fecha</label>
    <input type="date" name="fecha" value="<?= clean($data['fecha'] ?? date('Y-m-d')) ?>" required>
  </div>

  <div class="form-group">
    <label>Tags <span style="font-weight:400;color:#999">(para control / filtrar)</span></label>
    <div class="tags-box" id="tags-box" onclick="document.getElementById('tag-input').focus()">
      <input type="text" id="tag-input" placeholder="agregar tag…" autocomplete="off">
    </div>
    <input type="hidden" name="tags" id="tags-hidden" value="<?= clean($data['tags'] ?? '') ?>">
  </div>

  <div class="form-group">
    <label>Comprobante (foto)</label>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button type="button" class="foto-btn" onclick="fotoPick(true)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.66-.9l.82-1.2A2 2 0 0110.07 4h3.86a2 2 0 011.66.9l.82 1.2a2 2 0 001.66.9H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><circle cx="12" cy="13" r="3"/></svg>
        Tomar foto
      </button>
      <button type="button" class="foto-btn" onclick="fotoPick(false)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.5-4.5a2 2 0 012.83 0L16 16m-2-2l1.5-1.5a2 2 0 012.83 0L21 16M3 6a2 2 0 012-2h14a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V6z"/></svg>
        Subir de galería
      </button>
    </div>
    <div style="font-size:11px;margin-top:6px;color:#888">Se elimina automáticamente a los 2 meses</div>
    <input type="file" id="foto-input" name="foto" accept="image/*" style="display:none" onchange="previewFoto(this)">
    <div class="foto-prev" id="foto-prev"><?php if (!empty($data['foto'])): ?><img src="<?= UPLOAD_URL . clean($data['foto']) ?>" alt="comprobante"><?php endif; ?></div>
  </div>

  <div class="form-group">
    <label>Nota <span style="font-weight:400;color:#999">(opcional)</span></label>
    <input type="text" name="nota" value="<?= clean($data['nota'] ?? '') ?>" placeholder="Detalle adicional…">
  </div>

  <div style="display:flex;gap:10px;margin-top:8px">
    <a href="<?= APP_URL ?>/admin/gastos/index.php" class="btn btn-secondary">Cancelar</a>
    <button type="submit" class="btn btn-primary" style="flex:1"><?= $isEdit ? 'Guardar cambios' : 'Guardar gasto' ?></button>
  </div>
</form>
</div></div>

<!-- Plantilla de línea -->
<template id="line-tpl">
  <div class="gline">
    <div class="gline-head"><b>Línea</b><button type="button" class="gline-del" onclick="delLine(this)">Quitar</button></div>
    <div class="gline-row">
      <input type="text" name="l_concepto[]" placeholder="Concepto (opcional)">
      <input class="mini" type="text" name="l_monto[]" inputmode="decimal" placeholder="Monto S/" oninput="recalc()">
    </div>
    <div class="gline-row">
      <?= egcombo('categoria_id[]', 'buscar_categorias', 'crear_categoria', $csrf, 'Categoría…') ?>
      <?= egcombo('subcategoria_id[]', 'buscar_subcategorias', 'crear_subcategoria', $csrf, 'Subcategoría…', '', '', '.egc-cat .egc-id', 'categoria_id') ?>
    </div>
    <?php if ($invOk): ?>
    <span class="inv-toggle" onclick="this.nextElementSibling.classList.toggle('on')">📦 Vincular a insumo (alimenta stock)</span>
    <div class="inv-box">
      <?= egcombo('insumo_id[]', 'buscar_insumos', 'crear_insumo', $csrf, 'Insumo…') ?>
      <input class="mini" type="text" name="l_cantidad[]" inputmode="decimal" placeholder="Cantidad">
    </div>
    <?php else: ?>
    <input type="hidden" name="insumo_id[]" value=""><input type="hidden" name="l_cantidad[]" value="">
    <?php endif; ?>
  </div>
</template>

<script>
var CSRF = <?= json_encode($csrf) ?>;
var EXISTING = <?= json_encode(array_map(function($it){ return [
  'concepto'=>$it['concepto'], 'monto'=>$it['monto'], 'categoria_id'=>$it['categoria_id'], 'cat_nombre'=>$it['cat_nombre'],
  'subcategoria_id'=>$it['subcategoria_id'], 'sub_nombre'=>$it['sub_nombre'], 'insumo_id'=>$it['insumo_id'],
  'insumo_nombre'=>$it['insumo_nombre'], 'cantidad'=>$it['cantidad'] ]; }, $items), JSON_UNESCAPED_UNICODE) ?>;

function markCat(line){ // marca el combo de categoría para que la subcategoría lo encuentre
  var combos = line.querySelectorAll('.egc');
  combos[0].setAttribute('data-egc-scope-cat','1');
  combos[0].classList.add('egc-cat');
  line.setAttribute('data-egc-scope','1');
}
function addLine(data){
  var tpl = document.getElementById('line-tpl').content.cloneNode(true);
  var line = tpl.querySelector('.gline');
  document.getElementById('lines').appendChild(tpl);
  var added = document.getElementById('lines').lastElementChild;
  markCat(added);
  if (data){
    added.querySelector('[name="l_concepto[]"]').value = data.concepto || '';
    added.querySelector('[name="l_monto[]"]').value = data.monto || '';
    setCombo(added, 0, data.categoria_id, data.cat_nombre);
    setCombo(added, 1, data.subcategoria_id, data.sub_nombre);
    var insBox = added.querySelector('.inv-box');
    if (insBox && data.insumo_id){ insBox.classList.add('on'); setCombo(added, 2, data.insumo_id, data.insumo_nombre);
      var q = added.querySelector('[name="l_cantidad[]"]'); if (q) q.value = data.cantidad || ''; }
  }
  if (window.EGCombo) window.EGCombo.init(added);
  recalc();
}
function setCombo(line, idx, id, txt){
  var combos = line.querySelectorAll('.egc');
  if (!combos[idx] || !id) return;
  combos[idx].querySelector('.egc-id').value = id;
  combos[idx].querySelector('.egc-input').value = txt || '';
}
function delLine(btn){ var l = btn.closest('.gline'); if (document.querySelectorAll('#lines .gline').length > 1) l.remove(); else { l.querySelectorAll('input').forEach(function(i){i.value='';}); l.querySelectorAll('.egc-id').forEach(function(i){i.value='';}); } recalc(); }
function recalc(){
  var t = 0;
  document.querySelectorAll('[name="l_monto[]"]').forEach(function(i){ var v = parseFloat((i.value||'').replace(',','.')); if(!isNaN(v)) t += v; });
  document.getElementById('grand').textContent = 'S/ ' + t.toLocaleString('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2});
}

// init
if (EXISTING.length) EXISTING.forEach(function(d){ addLine(d); }); else addLine();

// ── foto ──
function fotoPick(cam){ var inp = document.getElementById('foto-input'); if(cam) inp.setAttribute('capture','environment'); else inp.removeAttribute('capture'); inp.click(); }
function previewFoto(inp){ if(!inp.files||!inp.files[0])return; var p=document.getElementById('foto-prev'); p.innerHTML='<img src="'+URL.createObjectURL(inp.files[0])+'">'; }

// ── tags ──
var tags = (document.getElementById('tags-hidden').value||'').split(',').filter(Boolean);
function slugTag(t){ return (t||'').toLowerCase().replace(/^#+/,'').replace(/[^a-z0-9áéíóúñ]+/g,'-').replace(/^-+|-+$/g,''); }
function syncTags(){ document.getElementById('tags-hidden').value = tags.join(','); renderTags(); }
function renderTags(){ var box=document.getElementById('tags-box'); box.querySelectorAll('.tagchip').forEach(function(c){c.remove();}); var inp=document.getElementById('tag-input');
  tags.forEach(function(t){ var s=document.createElement('span'); s.className='tagchip'; s.innerHTML='#'+t+' <b>&times;</b>'; s.querySelector('b').onclick=function(){ tags=tags.filter(function(x){return x!==t;}); syncTags(); }; box.insertBefore(s,inp); }); }
function addTag(t){ t=slugTag(t); if(t&&tags.indexOf(t)===-1){ tags.push(t); syncTags(); } document.getElementById('tag-input').value=''; }
document.getElementById('tag-input').addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===','){ e.preventDefault(); addTag(this.value);} else if(e.key==='Backspace'&&this.value===''&&tags.length){ tags.pop(); syncTags(); } });
document.getElementById('tag-input').addEventListener('blur', function(){ if(this.value.trim()) addTag(this.value); });
renderTags();
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l admin/gastos/form.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verificación funcional (post-deploy)**

Acceptance:
- Abrir "Nuevo gasto": aparece 1 línea. "Agregar línea" añade otra; "Quitar" la elimina (mínimo 1).
- Escribir en categoría filtra en vivo; si no existe, "➕ Crear «X»" la crea y la selecciona; la subcategoría filtra dentro de esa categoría.
- El Total se actualiza al teclear montos.
- Guardar crea 1 fila en `gastos` + N en `gasto_items`. Editar recarga las líneas y guarda los cambios.
- Con tienda + insumo + cantidad, al guardar sube stock (verificar en inventario).

- [ ] **Step 4: Commit**

```bash
git add admin/gastos/form.php
git commit -m "feat(gastos): form multi-línea con categoría/subcategoría en vivo y enganche a insumo"
```

---

### Task 7: Lista de gastos (`admin/gastos/index.php`) — total e info desde líneas

**Files:**
- Modify: `admin/gastos/index.php`

**Interfaces:**
- Consumes: `gastos.monto` (total ya cacheado por `gastoGuardar`), `gasto_items` (para mostrar categorías de la primera línea / nº de líneas), columna `origen`.

- [ ] **Step 1: Mostrar categorías desde las líneas y badge de origen**

En `admin/gastos/index.php`, reemplaza la query principal `$gastos = ...` por una que traiga el resumen de categorías por gasto (la cabecera ya no tiene categoría útil):

```php
$gastos = $ready ? Database::fetchAll(
    "SELECT g.*, ub.nombre AS ubicacion, u.name AS usuario,
            (SELECT GROUP_CONCAT(DISTINCT c.nombre SEPARATOR ', ')
               FROM gasto_items gi LEFT JOIN gasto_categorias c ON c.id = gi.categoria_id
              WHERE gi.gasto_id = g.id AND c.nombre IS NOT NULL) AS categorias,
            (SELECT COUNT(*) FROM gasto_items gi WHERE gi.gasto_id = g.id) AS n_lineas
     FROM gastos g
     LEFT JOIN ubicaciones ub ON ub.id = g.ubicacion_id
     LEFT JOIN users u ON u.id = g.usuario_id
     $wsql
     ORDER BY g.fecha DESC, g.id DESC
     LIMIT 200", $params) : [];
```

- [ ] **Step 2: Actualizar el render de la tarjeta**

En el bloque que dibuja `.g-meta`, reemplaza la línea de categoría única por las categorías agregadas + badge de origen. Busca:

```php
      <?php if ($g['categoria']): ?><span class="g-tag cat"><?= clean($g['categoria']) ?></span><?php endif; ?>
```

y reemplázala por:

```php
      <?php if (!empty($g['categorias'])): ?><span class="g-tag cat"><?= clean($g['categorias']) ?></span><?php endif; ?>
      <?php if ((int)($g['n_lineas'] ?? 1) > 1): ?><span class="g-tag cat"><?= (int)$g['n_lineas'] ?> líneas</span><?php endif; ?>
      <?php if (($g['origen'] ?? 'manual') === 'pos'): ?><span class="g-tag cat">POS</span><?php endif; ?>
      <?php if (($g['origen'] ?? 'manual') === 'evento'): ?><span class="g-tag cat">Evento</span><?php endif; ?>
```

- [ ] **Step 3: Reemplazar el `if (!$ready)` para que cite la nueva migración**

Busca el texto `Aplica <code>install/gastos.sql</code>` y cámbialo por `Aplica <code>install/gastos.sql</code> y <code>install/55_gastos_v2.sql</code>`.

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/gastos/index.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add admin/gastos/index.php
git commit -m "feat(gastos): lista muestra categorías por líneas, nº de líneas y origen"
```

---

### Task 8: Gestión de categorías (`admin/gastos/categorias.php`)

**Files:**
- Create: `admin/gastos/categorias.php`
- Modify: `admin/layout-top.php` (link en el grupo Finanzas)

**Interfaces:**
- Consumes: `gasto_categorias`, `gasto_subcategorias`, `includes/gastos.php`.
- Produces: página admin con árbol categoría→subcategoría; crear/renombrar/eliminar. Eliminar categoría: pone `categoria_id=NULL` en `gasto_items` que la usaban; eliminar subcategoría: `subcategoria_id=NULL`.

- [ ] **Step 1: Escribir la página**

Create `admin/gastos/categorias.php`:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/gastos.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $a = $_POST['accion'] ?? '';
    if ($a === 'cat_add')      { gastoCrearCategoria(clean($_POST['nombre'] ?? '')); }
    elseif ($a === 'cat_ren')  { $cid=cleanInt($_POST['id']??0); $nm=clean($_POST['nombre']??''); if($cid&&$nm) Database::execute("UPDATE gasto_categorias SET nombre=? WHERE id=?", [$nm,$cid]); }
    elseif ($a === 'cat_del')  { $cid=cleanInt($_POST['id']??0); if($cid){ Database::execute("UPDATE gasto_items SET categoria_id=NULL WHERE categoria_id=?", [$cid]); Database::execute("DELETE FROM gasto_subcategorias WHERE categoria_id=?", [$cid]); Database::execute("DELETE FROM gasto_categorias WHERE id=?", [$cid]); } }
    elseif ($a === 'sub_add')  { gastoCrearSubcategoria(cleanInt($_POST['categoria_id']??0), clean($_POST['nombre']??'')); }
    elseif ($a === 'sub_ren')  { $sid=cleanInt($_POST['id']??0); $nm=clean($_POST['nombre']??''); if($sid&&$nm) Database::execute("UPDATE gasto_subcategorias SET nombre=? WHERE id=?", [$nm,$sid]); }
    elseif ($a === 'sub_del')  { $sid=cleanInt($_POST['id']??0); if($sid){ Database::execute("UPDATE gasto_items SET subcategoria_id=NULL WHERE subcategoria_id=?", [$sid]); Database::execute("DELETE FROM gasto_subcategorias WHERE id=?", [$sid]); } }
    flashMessage('success', 'Listo.');
    redirect('/admin/gastos/categorias.php');
}

$cats = Database::fetchAll("SELECT id, nombre FROM gasto_categorias ORDER BY nombre");
$subsByCat = [];
foreach (Database::fetchAll("SELECT id, categoria_id, nombre FROM gasto_subcategorias ORDER BY nombre") as $s) {
    $subsByCat[(int)$s['categoria_id']][] = $s;
}

$pageTitle = 'Categorías de gastos';
$activePage = 'gastos_cat';
$extraHead = '<style>
.catcard{background:#fff;border:1px solid var(--border,#eee);border-radius:14px;padding:14px;margin-bottom:11px}
.catcard h3{font-size:16px;margin:0 0 8px;display:flex;justify-content:space-between;align-items:center}
.sub-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-top:1px dashed var(--border,#eee);font-size:14px}
.inline-f{display:inline-flex;gap:6px}
.inline-f input{padding:7px 10px;border:1.5px solid var(--border,#ddd);border-radius:8px;font-size:13px}
.lk{border:none;background:none;cursor:pointer;font-weight:800;font-size:12px}
.lk.del{color:#e23744}
</style>';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header"><div class="page-header-left"><h1>Categorías de gastos</h1></div></div>

<div class="card" style="margin-bottom:16px"><div class="card-body">
  <form method="post" class="inline-f"><?= csrfField() ?>
    <input type="hidden" name="accion" value="cat_add">
    <input type="text" name="nombre" placeholder="Nueva categoría…" required>
    <button class="btn btn-primary" type="submit">Agregar categoría</button>
  </form>
</div></div>

<?php foreach ($cats as $c): $cid=(int)$c['id']; ?>
<div class="catcard">
  <h3>
    <span><?= clean($c['nombre']) ?></span>
    <form method="post" onsubmit="return confirm('¿Eliminar la categoría y sus subcategorías? Los gastos quedan sin categoría.')"><?= csrfField() ?>
      <input type="hidden" name="accion" value="cat_del"><input type="hidden" name="id" value="<?= $cid ?>">
      <button class="lk del" type="submit">Eliminar</button>
    </form>
  </h3>
  <?php foreach (($subsByCat[$cid] ?? []) as $s): ?>
  <div class="sub-row">
    <span><?= clean($s['nombre']) ?></span>
    <form method="post" onsubmit="return confirm('¿Eliminar subcategoría?')"><?= csrfField() ?>
      <input type="hidden" name="accion" value="sub_del"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
      <button class="lk del" type="submit">✕</button>
    </form>
  </div>
  <?php endforeach; ?>
  <div class="sub-row">
    <form method="post" class="inline-f"><?= csrfField() ?>
      <input type="hidden" name="accion" value="sub_add"><input type="hidden" name="categoria_id" value="<?= $cid ?>">
      <input type="text" name="nombre" placeholder="Nueva subcategoría…" required>
      <button class="lk" type="submit">+ Subcategoría</button>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Añadir link en el sidebar (grupo Finanzas)**

En `admin/layout-top.php`, dentro de `<div class="sb-items">` del grupo Finanzas (después del `<a>` de "Registro de gastos", antes de cerrar el div en la línea ~355), agrega (solo admin):

```php
        <?php if (isAdmin()): ?>
        <a href="<?php echo APP_URL; ?>/admin/gastos/categorias.php"
           class="nav-link <?php echo ($activePage??'')==='gastos_cat'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18M3 12h18M3 17h10"/></svg></span> Categorías
        </a>
        <?php endif; ?>
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/gastos/categorias.php && php -l admin/layout-top.php`
Expected: `No syntax errors detected` (ambos)

- [ ] **Step 4: Commit**

```bash
git add admin/gastos/categorias.php admin/layout-top.php
git commit -m "feat(gastos): gestión de categorías/subcategorías + link en sidebar"
```

---

### Task 9: Reportes (`admin/gastos/reportes.php`)

**Files:**
- Create: `admin/gastos/reportes.php`
- Modify: `admin/layout-top.php` (link en grupo Finanzas)

**Interfaces:**
- Consumes: `gasto_items` join `gastos` + `gasto_categorias` + `gasto_subcategorias`.
- Produces: filtros (rango fechas, tienda, tipo, origen) + desglose por categoría con drill-down a subcategoría (monto, %, nº), comparativa mes anterior, export CSV.

- [ ] **Step 1: Escribir la página**

Create `admin/gastos/reportes.php`:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('gastos');

$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-t');
$fTipo   = in_array($_GET['tipo'] ?? '', ['empresa','prestamo'], true) ? $_GET['tipo'] : '';
$fOrigen = in_array($_GET['origen'] ?? '', ['manual','pos','evento'], true) ? $_GET['origen'] : '';
$fUbi    = cleanInt($_GET['ubicacion_id'] ?? 0);

$where = ["g.fecha BETWEEN ? AND ?"]; $params = [$desde, $hasta];
if ($fTipo)   { $where[] = "g.tipo = ?";        $params[] = $fTipo; }
if ($fOrigen) { $where[] = "g.origen = ?";      $params[] = $fOrigen; }
if ($fUbi)    { $where[] = "g.ubicacion_id = ?"; $params[] = $fUbi; }
$wsql = 'WHERE ' . implode(' AND ', $where);

// Export CSV
if (($_GET['export'] ?? '') === 'csv') {
    $rows = Database::fetchAll(
        "SELECT g.fecha, g.tipo, g.origen, c.nombre AS categoria, s.nombre AS subcategoria, gi.concepto, gi.monto
         FROM gasto_items gi JOIN gastos g ON g.id = gi.gasto_id
         LEFT JOIN gasto_categorias c ON c.id = gi.categoria_id
         LEFT JOIN gasto_subcategorias s ON s.id = gi.subcategoria_id
         $wsql ORDER BY g.fecha, g.id", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="gastos-' . $desde . '_' . $hasta . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fecha','Tipo','Origen','Categoría','Subcategoría','Concepto','Monto']);
    foreach ($rows as $r) fputcsv($out, [$r['fecha'],$r['tipo'],$r['origen'],$r['categoria'],$r['subcategoria'],$r['concepto'],$r['monto']]);
    fclose($out); exit;
}

$total = (float)(Database::fetch("SELECT COALESCE(SUM(gi.monto),0) t FROM gasto_items gi JOIN gastos g ON g.id=gi.gasto_id $wsql", $params)['t'] ?? 0);

$porCat = Database::fetchAll(
    "SELECT COALESCE(c.nombre,'(sin categoría)') categoria, COALESCE(gi.categoria_id,0) cid,
            COUNT(*) n, COALESCE(SUM(gi.monto),0) monto
     FROM gasto_items gi JOIN gastos g ON g.id=gi.gasto_id
     LEFT JOIN gasto_categorias c ON c.id=gi.categoria_id
     $wsql GROUP BY gi.categoria_id, c.nombre ORDER BY monto DESC", $params);

$porSub = [];
foreach (Database::fetchAll(
    "SELECT COALESCE(gi.categoria_id,0) cid, COALESCE(s.nombre,'(sin subcategoría)') subcategoria,
            COUNT(*) n, COALESCE(SUM(gi.monto),0) monto
     FROM gasto_items gi JOIN gastos g ON g.id=gi.gasto_id
     LEFT JOIN gasto_subcategorias s ON s.id=gi.subcategoria_id
     $wsql GROUP BY gi.categoria_id, gi.subcategoria_id, s.nombre ORDER BY monto DESC", $params) as $r) {
    $porSub[(int)$r['cid']][] = $r;
}

$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones ORDER BY es_principal DESC, nombre");
$expQs = http_build_query(['desde'=>$desde,'hasta'=>$hasta,'tipo'=>$fTipo,'origen'=>$fOrigen,'ubicacion_id'=>$fUbi?:'','export'=>'csv']);

$pageTitle = 'Reportes de gastos';
$activePage = 'gastos_rep';
$extraHead = '<style>
.rep-f{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end}
.rep-f .fg{display:flex;flex-direction:column;gap:3px}
.rep-f label{font-size:11px;font-weight:800;text-transform:uppercase;color:#888}
.rep-f input,.rep-f select{padding:8px 10px;border:1.5px solid var(--border,#ddd);border-radius:8px;font-size:13px}
.rep-tot{background:#1E1E1E;color:var(--c-brand,#FFDF00);border-radius:14px;padding:16px;font-weight:900;font-size:22px;margin-bottom:16px}
.rep-cat{background:#fff;border:1px solid var(--border,#eee);border-radius:14px;margin-bottom:10px;overflow:hidden}
.rep-cat-h{display:flex;justify-content:space-between;align-items:center;padding:14px;cursor:pointer}
.rep-cat-h .nm{font-weight:800;font-size:15px}
.rep-bar{height:6px;background:var(--bg-page,#f1f1f4);border-radius:6px;margin-top:6px;overflow:hidden}
.rep-bar i{display:block;height:100%;background:var(--c-brand,#FFDF00)}
.rep-subs{display:none;border-top:1px solid var(--border,#eee);background:var(--bg-page,#fafafa)}
.rep-subs.on{display:block}
.rep-sub{display:flex;justify-content:space-between;padding:9px 16px;font-size:13px;border-bottom:1px dashed var(--border,#eee)}
</style>';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header"><div class="page-header-left"><h1>Reportes de gastos</h1></div>
  <div class="page-header-right"><a class="btn btn-secondary" href="<?= APP_URL ?>/admin/gastos/reportes.php?<?= clean($expQs) ?>">Exportar CSV</a></div>
</div>

<form method="get" class="rep-f">
  <div class="fg"><label>Desde</label><input type="date" name="desde" value="<?= clean($desde) ?>"></div>
  <div class="fg"><label>Hasta</label><input type="date" name="hasta" value="<?= clean($hasta) ?>"></div>
  <div class="fg"><label>Tipo</label><select name="tipo"><option value="">Todos</option><option value="empresa" <?= $fTipo==='empresa'?'selected':'' ?>>Empresa</option><option value="prestamo" <?= $fTipo==='prestamo'?'selected':'' ?>>Préstamo</option></select></div>
  <div class="fg"><label>Origen</label><select name="origen"><option value="">Todos</option><option value="manual" <?= $fOrigen==='manual'?'selected':'' ?>>Manual</option><option value="pos" <?= $fOrigen==='pos'?'selected':'' ?>>POS</option><option value="evento" <?= $fOrigen==='evento'?'selected':'' ?>>Evento</option></select></div>
  <div class="fg"><label>Tienda</label><select name="ubicacion_id"><option value="">Todas</option><?php foreach ($ubis as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $fUbi===(int)$u['id']?'selected':'' ?>><?= clean($u['nombre']) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-primary" type="submit">Aplicar</button>
</form>

<div class="rep-tot">Total: <?= formatMoney($total) ?></div>

<?php if (!$porCat): ?>
  <p style="color:#888;text-align:center;padding:30px">Sin gastos en el periodo.</p>
<?php else: foreach ($porCat as $c): $cid=(int)$c['cid']; $pct = $total>0 ? round($c['monto']/$total*100) : 0; ?>
<div class="rep-cat">
  <div class="rep-cat-h" onclick="this.parentNode.querySelector('.rep-subs').classList.toggle('on')">
    <div style="flex:1">
      <div class="nm"><?= clean($c['categoria']) ?> <span style="color:#999;font-weight:600;font-size:12px">· <?= (int)$c['n'] ?></span></div>
      <div class="rep-bar"><i style="width:<?= $pct ?>%"></i></div>
    </div>
    <div style="text-align:right;margin-left:12px"><div style="font-weight:900"><?= formatMoney((float)$c['monto']) ?></div><div style="font-size:11px;color:#999"><?= $pct ?>%</div></div>
  </div>
  <div class="rep-subs">
    <?php foreach (($porSub[$cid] ?? []) as $s): ?>
    <div class="rep-sub"><span><?= clean($s['subcategoria']) ?> <span style="color:#aaa">· <?= (int)$s['n'] ?></span></span><b><?= formatMoney((float)$s['monto']) ?></b></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Añadir link en el sidebar (grupo Finanzas)**

En `admin/layout-top.php`, dentro de `<div class="sb-items">` del grupo Finanzas, añade (gateado por `can('gastos')`):

```php
        <a href="<?php echo APP_URL; ?>/admin/gastos/reportes.php"
           class="nav-link <?php echo ($activePage??'')==='gastos_rep'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18M7 14l4-4 3 3 5-6"/></svg></span> Reportes
        </a>
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/gastos/reportes.php && php -l admin/layout-top.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add admin/gastos/reportes.php admin/layout-top.php
git commit -m "feat(gastos): reportes por categoría/subcategoría con filtros y export CSV"
```

---

### Task 10: Integración al Dashboard (gastos del mes + utilidad)

**Files:**
- Modify: `admin/dashboard.php`

**Interfaces:**
- Consumes: tabla `gastos`/`gasto_items` (única fuente). `$mes` (variable de mes ya existente en dashboard, formato `%Y-%m`). Sección admin consolidada (`if (isAdmin()):`, ~línea 597).

- [ ] **Step 1: Calcular gastos del mes y utilidad**

En `admin/dashboard.php`, después del bloque de queries de POS por tienda (~línea 151, junto a los demás `try { ... } catch`), añade:

```php
// ── Gastos del mes (tipo empresa) + utilidad ──
$gastosMes = 0.0; $gastosPorCat = []; $utilidadMes = 0.0;
try {
    $gastosMes = (float)(Database::fetch(
        "SELECT COALESCE(SUM(monto),0) t FROM gastos WHERE tipo='empresa' AND DATE_FORMAT(fecha,'%Y-%m')=?", [$mes])['t'] ?? 0);
    $gastosPorCat = Database::fetchAll(
        "SELECT COALESCE(c.nombre,'(sin categoría)') categoria, COALESCE(SUM(gi.monto),0) monto
         FROM gasto_items gi JOIN gastos g ON g.id=gi.gasto_id
         LEFT JOIN gasto_categorias c ON c.id=gi.categoria_id
         WHERE g.tipo='empresa' AND DATE_FORMAT(g.fecha,'%Y-%m')=?
         GROUP BY gi.categoria_id, c.nombre ORDER BY monto DESC LIMIT 6", [$mes]);
} catch (\Throwable $e) { /* tablas no migradas */ }
```

> **Nota:** confirma que `$mes` existe en el scope; el dashboard ya lo usa en las queries de eventos libres (`DATE_FORMAT(...,'%Y-%m')=?", [$mes]`). Si el nombre real difiere, usa esa misma variable.

- [ ] **Step 2: Calcular la utilidad con el ingreso consolidado**

Localiza dónde el dashboard arma el total de ingreso consolidado del mes (suma de los 3 buckets: cotizaciones + eventos libres + POS) dentro de la sección `if (isAdmin())`. Inmediatamente después de que esa variable esté disponible (llámala `$ingresoConsolidado`; si tiene otro nombre, úsalo), añade:

```php
$utilidadMes = $ingresoConsolidado - $gastosMes;
```

Si el dashboard no tiene una sola variable consolidada, súmala explícitamente a partir de los buckets ya calculados (cotizaciones aceptadas del mes + `$ventaEventosLibres` + total POS del mes) justo antes de esta línea.

- [ ] **Step 3: Renderizar el panel (solo admin)**

Dentro del bloque `if (isAdmin()):` de la sección consolidada (~línea 597), antes de la barra consolidada, agrega el panel:

```php
<div class="card" style="margin-bottom:16px"><div class="card-body">
  <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div><div style="font-size:11px;font-weight:800;text-transform:uppercase;color:#888">Gastos del mes (empresa)</div>
      <div style="font-size:24px;font-weight:900;color:#e23744"><?= formatMoney($gastosMes) ?></div></div>
    <div style="text-align:right"><div style="font-size:11px;font-weight:800;text-transform:uppercase;color:#888">Utilidad (ingresos − gastos)</div>
      <div style="font-size:24px;font-weight:900;color:<?= $utilidadMes>=0?'#16a34a':'#e23744' ?>"><?= formatMoney($utilidadMes) ?></div></div>
  </div>
  <?php if ($gastosPorCat): ?>
  <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:6px">
    <?php foreach ($gastosPorCat as $gc): ?>
      <span style="background:var(--bg-page,#f1f1f4);border-radius:7px;padding:4px 9px;font-size:12px;font-weight:700"><?= clean($gc['categoria']) ?>: <?= formatMoney((float)$gc['monto']) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div style="margin-top:10px"><a href="<?= APP_URL ?>/admin/gastos/reportes.php" style="font-size:13px;font-weight:800;color:#1E1E1E">Ver reportes →</a></div>
</div></div>
```

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/dashboard.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Verificación funcional (post-deploy)**

Acceptance: el dashboard (como admin) muestra "Gastos del mes" y "Utilidad" coherentes; los chips de categoría suman ≈ el total de gastos empresa del mes; "Ver reportes" navega a `reportes.php`.

- [ ] **Step 6: Commit**

```bash
git add admin/dashboard.php
git commit -m "feat(dashboard): panel de gastos del mes + utilidad (ingresos − gastos)"
```

---

### Task 11: Absorción de gastos del POS (`api/pos.php` → `cerrar_turno`)

**Files:**
- Modify: `api/pos.php` (acción `cerrar_turno`, ~línea 158-195)

**Interfaces:**
- Consumes: `includes/gastos.php` (`gastoGuardar`, `gastosListo`); variables locales de `cerrar_turno`: `$gastos` (array `[['concepto','monto'], ...]`), `$tid`, `$uid`, `$ubi`/`ubicacion del turno`.

- [ ] **Step 1: Incluir la librería**

En la cabecera de `api/pos.php` (junto a los demás `require_once`), añade:

```php
require_once __DIR__ . '/../includes/gastos.php';
```

- [ ] **Step 2: Insertar la absorción tras guardar el turno**

En la acción `cerrar_turno`, justo después del `UPDATE pos_turnos SET estado='cerrado' ...` (la query que cierra el turno, ~línea 188-193) y antes del `pout(...)`/respuesta, añade:

```php
        // Absorber los gastos del turno al registro global (origen='pos'). No cambia el arqueo.
        if (gastosListo()) {
            $ubiTurno = (int)($t['ubicacion_id'] ?? 0) ?: null;
            $catPos = (int)(Database::fetch("SELECT id FROM gasto_categorias WHERE nombre='Caja / Operación'")['id'] ?? 0) ?: null;
            foreach ($gastos as $gx) {
                $m = (float)($gx['monto'] ?? 0);
                if ($m <= 0) continue;
                gastoGuardar(
                    ['tipo' => 'empresa', 'concepto' => (string)($gx['concepto'] ?? 'Gasto de caja'),
                     'ubicacion_id' => $ubiTurno, 'fecha' => date('Y-m-d'), 'estado' => 'pagado',
                     'usuario_id' => $uid, 'origen' => 'pos', 'turno_id' => $tid],
                    [['concepto' => (string)($gx['concepto'] ?? ''), 'monto' => $m, 'categoria_id' => $catPos]]
                );
            }
        }
```

> **Nota:** verifica el nombre exacto de la variable del array de gastos en esa acción (`$gastos`) y de la ubicación del turno (`$t['ubicacion_id']`). El cálculo de `caja_esperada`/`gastos_total` NO se toca.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l api/pos.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Verificación funcional (post-deploy)**

Acceptance: cerrar un turno con 2 gastos crea 2 filas en `gastos` con `origen='pos'` y `turno_id` correcto; aparecen en reportes (filtro origen=POS); el arqueo (caja esperada) sigue calculando igual que antes.
```sql
SELECT id, concepto, monto, origen, turno_id FROM gastos WHERE origen='pos' ORDER BY id DESC LIMIT 5;
```

- [ ] **Step 5: Commit**

```bash
git add api/pos.php
git commit -m "feat(pos): absorber gastos del turno al registro global de gastos (origen=pos)"
```

---

### Task 12: Unificar los "otros gastos" de la liquidación de evento

**Files:**
- Modify: `admin/inventory/evento_detalle.php`

**Interfaces:**
- Consumes: `includes/gastos.php` (`gastoGuardar`, `gastoEliminar`, `gastoMigrarEventoLegacy`, combobox vía `api/gastos.php`).
- Cambia: el manejo de `gasto_add`/`gasto_del` y el render de "Otros gastos" para usar `gastos` (origen='evento') con categoría+subcategoría en vivo; lee el total desde `gastos`; migra legacy al cargar.

- [ ] **Step 1: Incluir la librería y migrar legacy al cargar**

En la cabecera de `admin/inventory/evento_detalle.php` (junto a los `require_once`), añade:

```php
require_once __DIR__ . '/../../includes/gastos.php';
```

Después de resolver `$id` (el id del evento) y antes de procesar acciones, añade la migración perezosa:

```php
if (function_exists('gastosListo') && gastosListo()) {
    gastoMigrarEventoLegacy((int)$id, (int)(currentUser()['id'] ?? 0));
}
```

- [ ] **Step 2: Reemplazar el handler `gasto_add`**

Busca el bloque `if ($accion === 'gasto_add') { ... }` (~línea 87-101) y reemplázalo por:

```php
    if ($accion === 'gasto_add') {
        $monto = cleanFloat($_POST['monto'] ?? 0);
        $desc  = clean($_POST['descripcion'] ?? '');
        $catId = cleanInt($_POST['categoria_id'] ?? 0) ?: null;
        $subId = cleanInt($_POST['subcategoria_id'] ?? 0) ?: null;
        if ($monto > 0) {
            gastoGuardar(
                ['tipo' => 'empresa', 'concepto' => ($desc ?: 'Gasto de evento'),
                 'ubicacion_id' => null, 'fecha' => date('Y-m-d'), 'estado' => 'pagado',
                 'usuario_id' => (int)(currentUser()['id'] ?? 0), 'origen' => 'evento', 'evento_id' => (int)$id],
                [['concepto' => $desc, 'monto' => $monto, 'categoria_id' => $catId, 'subcategoria_id' => $subId]]
            );
            flashMessage('success', 'Gasto agregado.');
        }
        redirect('/admin/inventory/evento_detalle.php?id=' . (int)$id);
    }
```

- [ ] **Step 3: Reemplazar el handler `gasto_del`**

Busca `if ($accion === 'gasto_del') { ... }` (~línea 103-106) y reemplázalo por:

```php
    if ($accion === 'gasto_del') {
        $gid = cleanInt($_POST['gasto_id'] ?? 0);
        if ($gid) {
            $own = Database::fetch("SELECT id FROM gastos WHERE id=? AND origen='evento' AND evento_id=?", [$gid, (int)$id]);
            if ($own) gastoEliminar($gid);
        }
        redirect('/admin/inventory/evento_detalle.php?id=' . (int)$id);
    }
```

- [ ] **Step 4: Reemplazar la lectura de los gastos del evento**

Busca `$otrosGastos = Database::fetchAll(...)` (~línea 153) y reemplázala por:

```php
$otrosGastos = Database::fetchAll(
    "SELECT g.id, g.monto, gi.concepto AS descripcion, c.nombre AS cat_nombre, s.nombre AS sub_nombre
     FROM gastos g
     LEFT JOIN gasto_items gi ON gi.gasto_id = g.id
     LEFT JOIN gasto_categorias c ON c.id = gi.categoria_id
     LEFT JOIN gasto_subcategorias s ON s.id = gi.subcategoria_id
     WHERE g.origen='evento' AND g.evento_id=? ORDER BY g.id DESC", [(int)$id]);
```

Si más abajo se calcula el total de otros gastos sumando `$otrosGastos`, confírmalo; si se calculaba con una query a `evento_gastos`, cámbiala a:

```php
$totOtrosGastos = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) t FROM gastos WHERE origen='evento' AND evento_id=?", [(int)$id])['t'] ?? 0);
```

- [ ] **Step 5: Reemplazar el form de "Otros gastos" con combobox en vivo**

Busca el `<form>` que postea `gasto_add` (con el `<select name="categoria_id">` y el `<input name="nueva_categoria">`, ~línea 355-378) y reemplaza ese `<select>`+`nueva_categoria` por dos combobox (categoría + subcategoría dependiente). El form completo queda:

```php
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start" data-egc-scope="1">
        <?= csrfField() ?>
        <input type="hidden" name="accion" value="gasto_add">
        <input type="text" name="descripcion" placeholder="Descripción" style="min-width:150px">
        <input type="text" name="monto" inputmode="decimal" placeholder="Monto S/" style="width:110px">
        <div class="egc egc-cat" data-egc data-search="buscar_categorias" data-create="crear_categoria" data-csrf="<?= clean(csrfToken()) ?>" data-dep="" data-dep-create-key="" style="min-width:160px">
          <input type="text" class="egc-input" placeholder="Categoría…" autocomplete="off">
          <input type="hidden" class="egc-id" name="categoria_id" value="">
          <div class="egc-menu"></div>
        </div>
        <div class="egc" data-egc data-search="buscar_subcategorias" data-create="crear_subcategoria" data-csrf="<?= clean(csrfToken()) ?>" data-dep=".egc-cat .egc-id" data-dep-create-key="categoria_id" style="min-width:160px">
          <input type="text" class="egc-input" placeholder="Subcategoría…" autocomplete="off">
          <input type="hidden" class="egc-id" name="subcategoria_id" value="">
          <div class="egc-menu"></div>
        </div>
        <button type="submit" class="btn btn-primary">Agregar gasto</button>
      </form>
```

Y en el render de la lista de `$otrosGastos`, asegúrate de mostrar `descripcion`, `cat_nombre` y (si existe) `sub_nombre`. Si el render usaba `$g['cat_nombre']`, ahora también puedes mostrar `· <?= clean($g['sub_nombre']) ?>` cuando no sea null.

> **Nota:** `evento_detalle.php` es una página admin → ya carga `combobox.js` vía `layout-bottom.php` (Task 4). `EG_GASTOS_API` también. No requiere scripts extra.

- [ ] **Step 6: Verificar sintaxis**

Run: `php -l admin/inventory/evento_detalle.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: Verificación funcional (post-deploy)**

Acceptance:
- Al abrir un evento con `evento_gastos` legacy, esas filas se migran (desaparecen de `evento_gastos`, aparecen en `gastos` con origen='evento').
- Agregar un "otro gasto" con categoría+subcategoría en vivo crea una fila en `gastos` (origen='evento', evento_id) + su línea; aparece en la liquidación y en reportes (filtro origen=Evento).
- Eliminar un gasto lo borra de `gastos`.
- El total "Otros gastos" y la utilidad del evento siguen cuadrando.
```sql
SELECT id, concepto, monto, evento_id FROM gastos WHERE origen='evento' ORDER BY id DESC LIMIT 5;
SELECT COUNT(*) FROM evento_gastos;  -- baja a medida que se visitan los eventos
```

- [ ] **Step 8: Commit**

```bash
git add admin/inventory/evento_detalle.php
git commit -m "feat(eventos): unificar otros gastos de liquidación en el registro global (origen=evento) con categorías en vivo"
```

---

## Self-Review

**Spec coverage:**
- Modelo de datos (subcategorías, items, columnas, backfill) → Task 1. ✅
- Combobox búsqueda en vivo + crear al vuelo → Task 4 (componente) + Task 5 (API) + usado en Tasks 6, 12. ✅
- Form multi-línea con categoría/subcategoría + enganche insumo → Task 6 + librería Task 3. ✅
- Gestión de categorías → Task 8. ✅
- Reportes por categoría/subcategoría + export → Task 9. ✅
- Dashboard utilidad → Task 10. ✅
- Absorción POS → Task 11. ✅
- Unificación evento → Task 12 + `gastoMigrarEventoLegacy` (Task 3). ✅
- Enganche inventario (apply/revert, idempotente) → Task 3 + cambio firma Task 2. ✅
- Permisos → cada página usa `requirePermission('gastos')` / `requireAdmin()`; API `can('gastos')`. ✅

**Type consistency:** `gastoGuardar($h, $items, $id)`, `gastoEliminar($id)`, `gastoItems($id)`, `gastoMigrarEventoLegacy($eventoId,$usuarioId)`, `invEntradaCompra(...):int` usados consistentemente en Tasks 6, 11, 12. Combobox: `data-search`/`data-create`/`data-dep`/`data-dep-create-key` y clase `.egc-cat` consistentes entre Task 4, 6 y 12.

**Notas de riesgo a confirmar en ejecución (no son placeholders, son verificaciones de integración):**
- Nombre real de la variable de mes (`$mes`) y del array de gastos del turno (`$gastos`) y ubicación del turno en `api/pos.php` — Task 10/11 lo indican.
- Columnas reales de `insumos`/`proveedores` — Task 5 Step 3 lo verifica.
- Caveat documentado: editar un gasto con enganche de inventario hace revert+reapply (el costo promedio ponderado puede tener una desviación mínima tras ediciones repetidas; el stock siempre queda correcto).
