# SP3a · Evento + inventario inicial — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans. Steps usan checkbox (`- [ ]`).

**Goal:** Crear la entidad "Evento" (vinculable a una cotización o suelta), permitir que al confirmar una salida a evento el requerimiento quede como **inventario inicial** del evento, y listar/ver eventos con su inventario de apertura.

**Architecture:** Dos tablas nuevas (`eventos`, `evento_insumos`). La "Salida a evento" gana un bloque opcional "Asignar a evento" (nuevo o existente) que, al confirmar, crea/usa el evento y guarda las líneas como `evento_insumos` (con costo snapshot). El descuento de stock se conserva (la salida saca el stock del local; el evento es un libro aparte cuyo saldo de apertura es ese requerimiento). Nueva sección admin: lista de eventos + detalle. **El control diario y el cierre son SP3b.**

**Tech Stack:** PHP 8 + MySQL/PDO. **Sin tests** → `php -l` + prueba manual. Permiso reusado: `inv_evento`.

**Spec maestro:** `docs/superpowers/specs/2026-06-16-liquidacion-evento-design.md` (SP3).

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `install/45_eventos.sql` | Tablas `eventos` + `evento_insumos` | Crear |
| `admin/inventory/salida_evento.php` | Bloque "Asignar a evento" + guardar `evento_insumos` al confirmar | Modificar |
| `admin/inventory/eventos.php` | Lista de eventos | Crear |
| `admin/inventory/evento_detalle.php` | Detalle: meta + inventario inicial | Crear |
| `admin/layout-top.php` | Link "Eventos" en el grupo Inventario | Modificar |

---

## Task 1: Migración — tablas de evento

**Files:**
- Create: `install/45_eventos.sql`

- [ ] **Step 1: Crear la migración**

```sql
-- Evento del food truck: unidad de liquidación. Vinculable a una cotización o suelto.
CREATE TABLE IF NOT EXISTS eventos (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(160) NOT NULL,
  quote_id      INT UNSIGNED NULL,          -- vínculo opcional a quotes (cotización/evento del calendario)
  ubicacion_id  INT UNSIGNED NULL,          -- el truck / local
  fecha_inicio  DATE NOT NULL,
  fecha_fin     DATE NULL,                  -- NULL = un día
  venta_manual  DECIMAL(10,2) NULL,         -- ingreso manual (se usa en SP4)
  estado        ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ev_fecha (fecha_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventario inicial (apertura) del evento: lo que salió en la salida.
CREATE TABLE IF NOT EXISTS evento_insumos (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  evento_id        INT NOT NULL,
  insumo_id        INT UNSIGNED NOT NULL,
  cantidad_inicial DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  costo_unitario   DECIMAL(10,4) NOT NULL DEFAULT 0.0000,   -- snapshot al confirmar
  UNIQUE KEY uq_ev_ins (evento_id, insumo_id),
  INDEX idx_ei_ev (evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Commit**

```bash
git add install/45_eventos.sql
git commit -m "feat(eventos): migración — tablas eventos y evento_insumos (inventario inicial)"
```

---

## Task 2: "Asignar a evento" al confirmar la salida

**Files:**
- Modify: `admin/inventory/salida_evento.php` (carga de cotizaciones/eventos abiertos; UI en el pie del paso 2; handler POST)

- [ ] **Step 1: Cargar datos para el selector (zona PHP de datos)**

Añade tras la carga de `$ubicaciones`:
```php
// Cotizaciones/eventos del calendario (para vincular)
$cotizaciones = Database::fetchAll("SELECT id, quote_number, event_date FROM quotes WHERE origin='event' OR status='aceptada' ORDER BY id DESC LIMIT 100");
// Eventos abiertos (para agregar a uno existente)
$eventosAbiertos = [];
try { $eventosAbiertos = Database::fetchAll("SELECT id, nombre, fecha_inicio FROM eventos WHERE estado='abierto' ORDER BY fecha_inicio DESC"); } catch (\Throwable $e) {}
```

- [ ] **Step 2: UI en el pie del paso 2 (dentro de `<form id="evForm">`, antes del botón confirmar)**

```php
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border)">
          <label style="font-size:13px;font-weight:700;display:block;margin-bottom:6px">Asignar a evento <small style="font-weight:400;color:var(--text-muted)">(opcional — para liquidar después)</small></label>
          <select name="evento_modo" id="evModo" onchange="evModoChange()" style="margin-bottom:8px">
            <option value="no">No registrar como evento</option>
            <option value="nuevo">Crear un evento nuevo</option>
            <?php if (!empty($eventosAbiertos)): ?><option value="existente">Agregar a un evento abierto</option><?php endif; ?>
          </select>
          <div id="evNuevo" style="display:none">
            <input type="text" name="evento_nombre" placeholder="Nombre del evento (ej. Feria de Barranco)" style="margin-bottom:6px">
            <div class="form-row form-row-2" style="margin-bottom:6px">
              <input type="date" name="evento_fecha_inicio" value="<?= date('Y-m-d') ?>">
              <input type="date" name="evento_fecha_fin" placeholder="hasta (opcional)">
            </div>
            <select name="evento_quote_id">
              <option value="">— Sin vincular a cotización —</option>
              <?php foreach ($cotizaciones as $c): ?><option value="<?= (int)$c['id'] ?>"><?= clean($c['quote_number']) ?><?= $c['event_date'] ? ' · ' . clean($c['event_date']) : '' ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php if (!empty($eventosAbiertos)): ?>
          <div id="evExist" style="display:none">
            <select name="evento_id">
              <?php foreach ($eventosAbiertos as $e): ?><option value="<?= (int)$e['id'] ?>"><?= clean($e['nombre']) ?> · <?= clean($e['fecha_inicio']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
        </div>
```
Y el JS:
```html
<script>
function evModoChange(){
  var m = document.getElementById('evModo').value;
  document.getElementById('evNuevo').style.display = m==='nuevo' ? 'block' : 'none';
  var ex = document.getElementById('evExist'); if (ex) ex.style.display = m==='existente' ? 'block' : 'none';
}
</script>
```

- [ ] **Step 3: Handler POST — crear/usar evento y guardar `evento_insumos`**

En el handler POST, DESPUÉS del bloque que acumula `$agg` y descuenta stock (donde está el `foreach ($agg ...)` con `invMovimiento`), añade:
```php
    // Asignar a evento (opcional): el requerimiento = inventario inicial del evento.
    $evModo = $_POST['evento_modo'] ?? 'no';
    $eventoId = 0;
    try {
        if ($evModo === 'nuevo') {
            $evNombre = clean($_POST['evento_nombre'] ?? '');
            $fIni = clean($_POST['evento_fecha_inicio'] ?? '') ?: date('Y-m-d');
            $fFin = clean($_POST['evento_fecha_fin'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFin) || $fFin < $fIni) $fFin = null;
            $qid  = cleanInt($_POST['evento_quote_id'] ?? 0) ?: null;
            if ($evNombre === '') $evNombre = 'Evento ' . $fIni;
            $eventoId = Database::insert(
                "INSERT INTO eventos (nombre,quote_id,ubicacion_id,fecha_inicio,fecha_fin,estado) VALUES (?,?,?,?,?, 'abierto')",
                [$evNombre, $qid, $ubiId, $fIni, $fFin]
            );
        } elseif ($evModo === 'existente') {
            $eventoId = cleanInt($_POST['evento_id'] ?? 0);
            $ok = $eventoId ? Database::fetch("SELECT id FROM eventos WHERE id=? AND estado='abierto'", [$eventoId]) : null;
            if (!$ok) $eventoId = 0;
        }
        if ($eventoId > 0) {
            // costo snapshot por insumo
            $costos = [];
            foreach (Database::fetchAll("SELECT id, costo_unitario FROM insumos") as $ix) { $costos[(int)$ix['id']] = (float)$ix['costo_unitario']; }
            foreach ($agg as $iid => $c) {
                Database::execute(
                    "INSERT INTO evento_insumos (evento_id,insumo_id,cantidad_inicial,costo_unitario) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE cantidad_inicial = cantidad_inicial + VALUES(cantidad_inicial)",
                    [$eventoId, (int)$iid, $c, $costos[(int)$iid] ?? 0]
                );
            }
        }
    } catch (\Throwable $e) { /* tablas de evento aún no migradas → la salida igual descontó */ }

    $msgEv = $eventoId > 0 ? ' Inventario inicial guardado en el evento.' : '';
    flashMessage('success', "Salida de evento registrada: $n insumo(s) descontados del stock.$msgEv");
    if ($eventoId > 0) { redirect('/admin/inventory/evento_detalle.php?id=' . $eventoId); }
    redirect('/admin/inventory/movimientos.php?tipo=evento');
```
(Reemplaza el `flashMessage` + `redirect` finales existentes por este bloque, conservando `$n`.)

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/inventory/salida_evento.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add admin/inventory/salida_evento.php
git commit -m "feat(eventos): asignar la salida a un evento (nuevo o abierto) → inventario inicial"
```

---

## Task 3: Lista de eventos + link en el sidebar

**Files:**
- Create: `admin/inventory/eventos.php`
- Modify: `admin/layout-top.php` (grupo Inventario)

- [ ] **Step 1: Crear `admin/inventory/eventos.php`**

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
requirePermission('inv_evento');
$eventos = Database::fetchAll(
  "SELECT e.*, u.nombre AS ubi_nombre, q.quote_number,
          (SELECT COUNT(*) FROM evento_insumos ei WHERE ei.evento_id=e.id) AS n_insumos
     FROM eventos e
     LEFT JOIN ubicaciones u ON u.id=e.ubicacion_id
     LEFT JOIN quotes q ON q.id=e.quote_id
    ORDER BY e.estado='abierto' DESC, e.fecha_inicio DESC"
);
$pageTitle = 'Eventos'; $activePage = 'inv-eventos';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header"><div class="page-header-left"><h1>Eventos</h1>
  <p>Inventario y liquidación por evento del food truck</p></div></div>
<div class="card"><div class="card-body" style="padding:0">
  <?php if (!$eventos): ?>
    <div class="empty-state" style="padding:40px;text-align:center"><h3>Sin eventos</h3><p>Crea uno desde «Salida a evento» asignando la salida a un evento.</p></div>
  <?php else: ?>
  <table class="data-table" style="width:100%;border-collapse:collapse">
    <thead><tr>
      <th style="text-align:left;padding:10px">Evento</th><th style="text-align:left;padding:10px">Fechas</th>
      <th style="text-align:left;padding:10px">Local</th><th style="text-align:left;padding:10px">Cotización</th>
      <th style="text-align:center;padding:10px">Insumos</th><th style="text-align:center;padding:10px">Estado</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($eventos as $e): ?>
      <tr style="border-top:1px solid var(--border)">
        <td style="padding:10px;font-weight:700"><?= clean($e['nombre']) ?></td>
        <td style="padding:10px"><?= clean($e['fecha_inicio']) ?><?= $e['fecha_fin'] ? ' → ' . clean($e['fecha_fin']) : '' ?></td>
        <td style="padding:10px"><?= $e['ubi_nombre'] ? clean($e['ubi_nombre']) : '—' ?></td>
        <td style="padding:10px"><?= $e['quote_number'] ? clean($e['quote_number']) : '—' ?></td>
        <td style="padding:10px;text-align:center"><?= (int)$e['n_insumos'] ?></td>
        <td style="padding:10px;text-align:center"><span class="badge <?= $e['estado']==='abierto'?'badge-success':'badge-secondary' ?>"><?= $e['estado']==='abierto'?'Abierto':'Cerrado' ?></span></td>
        <td style="padding:10px;text-align:right"><a href="<?= APP_URL ?>/admin/inventory/evento_detalle.php?id=<?= (int)$e['id'] ?>" class="btn btn-secondary" style="padding:6px 14px;font-size:12px">Ver</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div></div>
<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Link en el sidebar (grupo Inventario)**

En `admin/layout-top.php`, dentro del grupo Inventario (busca el bloque que tiene los links `inv_insumos`/`inv_evento`), añade un link "Eventos" → `/admin/inventory/eventos.php` con `active` cuando `$activePage==='inv-eventos'`, gateado por `can('inv_evento')` (copia el formato de los otros links del grupo). El link de "Salida a evento" existente se conserva.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/inventory/eventos.php && php -l admin/layout-top.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add admin/inventory/eventos.php admin/layout-top.php
git commit -m "feat(eventos): lista de eventos + link en el sidebar de Inventario"
```

---

## Task 4: Detalle del evento (meta + inventario inicial)

**Files:**
- Create: `admin/inventory/evento_detalle.php`

- [ ] **Step 1: Crear `admin/inventory/evento_detalle.php`**

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
requirePermission('inv_evento');
$id = cleanInt($_GET['id'] ?? 0);
$ev = $id ? Database::fetch(
  "SELECT e.*, u.nombre AS ubi_nombre, q.quote_number FROM eventos e
     LEFT JOIN ubicaciones u ON u.id=e.ubicacion_id LEFT JOIN quotes q ON q.id=e.quote_id WHERE e.id=?",
  [$id]
) : null;
if (!$ev) { flashMessage('error','Evento no encontrado.'); redirect('/admin/inventory/eventos.php'); }
$insumos = Database::fetchAll(
  "SELECT ei.*, i.nombre, i.unidad, i.tipo FROM evento_insumos ei JOIN insumos i ON i.id=ei.insumo_id WHERE ei.evento_id=? ORDER BY i.tipo, i.nombre",
  [$id]
);
$costoTotal = 0; foreach ($insumos as $r) { $costoTotal += (float)$r['cantidad_inicial'] * (float)$r['costo_unitario']; }
$pageTitle = 'Evento · ' . $ev['nombre']; $activePage = 'inv-eventos';
include __DIR__ . '/../layout-top.php';
?>
<div class="breadcrumb"><a href="<?= APP_URL ?>/admin/inventory/eventos.php">Eventos</a><span class="breadcrumb-sep">›</span><span class="breadcrumb-current"><?= clean($ev['nombre']) ?></span></div>
<div class="page-header"><div class="page-header-left"><h1><?= clean($ev['nombre']) ?></h1>
  <p><?= clean($ev['fecha_inicio']) ?><?= $ev['fecha_fin'] ? ' → ' . clean($ev['fecha_fin']) : '' ?>
     <?= $ev['ubi_nombre'] ? ' · ' . clean($ev['ubi_nombre']) : '' ?>
     <?= $ev['quote_number'] ? ' · ' . clean($ev['quote_number']) : '' ?>
     · <span class="badge <?= $ev['estado']==='abierto'?'badge-success':'badge-secondary' ?>"><?= $ev['estado']==='abierto'?'Abierto':'Cerrado' ?></span></p></div></div>

<div class="card"><div class="card-header"><span class="card-title">Inventario inicial (apertura)</span></div>
<div class="card-body" style="padding:0">
  <?php if (!$insumos): ?>
    <div class="empty-state" style="padding:30px;text-align:center"><p>Este evento no tiene inventario inicial cargado.</p></div>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse;font-size:14px">
    <thead><tr><th style="text-align:left;padding:9px 12px">Insumo</th><th style="padding:9px 12px">Tipo</th><th style="text-align:right;padding:9px 12px">Cantidad</th><th style="text-align:right;padding:9px 12px">Costo</th></tr></thead>
    <tbody>
    <?php foreach ($insumos as $r): $sub = (float)$r['cantidad_inicial'] * (float)$r['costo_unitario']; ?>
      <tr style="border-top:1px solid var(--border)">
        <td style="padding:9px 12px;font-weight:600"><?= clean($r['nombre']) ?></td>
        <td style="padding:9px 12px;text-align:center"><span class="badge <?= ($r['tipo']??'ingrediente')==='descartable'?'badge-secondary':'badge-info' ?>"><?= ($r['tipo']??'ingrediente')==='descartable'?'Descartable':'Ingrediente' ?></span></td>
        <td style="padding:9px 12px;text-align:right"><?= rtrim(rtrim(number_format((float)$r['cantidad_inicial'],3,'.',''),'0'),'.') ?> <?= clean($r['unidad']) ?></td>
        <td style="padding:9px 12px;text-align:right"><?= formatMoney($sub) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr style="border-top:2px solid var(--border);font-weight:800"><td colspan="3" style="padding:10px 12px;text-align:right">Costo de mercadería inicial</td><td style="padding:10px 12px;text-align:right"><?= formatMoney($costoTotal) ?></td></tr></tfoot>
  </table>
  <?php endif; ?>
</div></div>
<div class="card"><div class="card-body" style="color:var(--text-muted);font-size:13px">El control diario (consumo, conteo) y la liquidación se agregan en los siguientes pasos (SP3b / SP4).</div></div>
<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l admin/inventory/evento_detalle.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add admin/inventory/evento_detalle.php
git commit -m "feat(eventos): detalle de evento con inventario inicial y costo de mercadería"
```

---

## Verificación final (manual)

- [ ] Aplicar `install/45_eventos.sql` en phpMyAdmin.
- [ ] En "Salida a evento": armar el requerimiento, elegir "Crear un evento nuevo" (nombre + fecha, opcional vincular cotización), confirmar → descuenta stock Y crea el evento; redirige al detalle.
- [ ] El detalle muestra el inventario inicial (ingredientes + descartables) y el costo de mercadería inicial.
- [ ] Confirmar otra salida "Agregar a un evento abierto" → suma al `evento_insumos` del evento (no duplica filas; acumula por insumo).
- [ ] La lista de eventos muestra abiertos primero, con su nº de insumos y estado.
- [ ] Confirmar una salida con "No registrar como evento" → comportamiento de hoy (solo descuenta, va a movimientos).

## Nota de alcance (SP3b / SP4)
SP3a deja el evento con su inventario inicial. **SP3b** agrega el **control diario** (consumo teórico del POS + corregido + conteo, multi-día) y el **cierre** (devolver sobrante al stock). **SP4** agrega la **liquidación** (ingresos − mercadería − papelería − otros gastos = utilidad + rendimiento). El `venta_manual` y `estado='cerrado'` ya están en el modelo, listos para esos pasos.
