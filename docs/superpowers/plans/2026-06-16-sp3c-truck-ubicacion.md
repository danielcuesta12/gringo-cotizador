# SP3c · Ubicación del truck en el evento — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans. Steps usan checkbox (`- [ ]`).

**Goal:** Que el evento distinga el **almacén de origen** (de donde sale la salida y vuelve el sobrante) de la **ubicación del truck** (donde vende, para el teórico), permitiendo varios trucks/eventos a la vez sin mezclar ventas.

**Architecture:** El evento ya guarda `ubicacion_id` = almacén de origen (salida + devolución). Se agrega `eventos.truck_ubicacion_id` = la ubicación del food truck (donde corre su POS). El selector del truck se elige al "Asignar a evento" en la salida. `eventoConsumoTeorico` pasa a leer las ventas del **truck** (`truck_ubicacion_id`, con fallback a `ubicacion_id` para eventos viejos).

**Tech Stack:** PHP 8 + MySQL. **Sin tests** → `php -l` + prueba manual.

**Spec maestro:** `docs/superpowers/specs/2026-06-16-liquidacion-evento-design.md` (SP3).

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `install/47_evento_truck.sql` | `eventos.truck_ubicacion_id` | Crear |
| `admin/inventory/salida_evento.php` | Selector de truck al crear evento + guardar `truck_ubicacion_id` | Modificar |
| `includes/inventario.php` | `eventoConsumoTeorico` lee ventas del truck | Modificar |
| `admin/inventory/evento_detalle.php` | Mostrar el truck en la meta | Modificar |

---

## Task 1: Migración — `truck_ubicacion_id`

**Files:** Create `install/47_evento_truck.sql`

- [ ] **Step 1: Crear**
```sql
ALTER TABLE eventos ADD COLUMN truck_ubicacion_id INT UNSIGNED NULL AFTER ubicacion_id;
```
- [ ] **Step 2: Commit**
```bash
git add install/47_evento_truck.sql
git commit -m "feat(eventos): migración — truck_ubicacion_id (ubicación de venta del food truck)"
```

---

## Task 2: Selector del truck en la salida + guardar

**Files:** Modify `admin/inventory/salida_evento.php`

- [ ] **Step 1: Selector en el subpanel "evento nuevo"**

En el bloque "Asignar a evento", dentro de `<div id="evNuevo">` (donde están nombre/fechas/cotización), añade un selector del truck (lista las ubicaciones; `$ubicaciones` ya está cargado):
```php
            <select name="evento_truck_id" style="margin-top:6px">
              <option value="">— Truck / ubicación donde vende (opcional) —</option>
              <?php foreach ($ubicaciones as $u): ?><option value="<?= (int)$u['id'] ?>"><?= clean($u['nombre']) ?></option><?php endforeach; ?>
            </select>
```

- [ ] **Step 2: Guardar `truck_ubicacion_id` al crear el evento**

En el handler POST, en la rama `if ($evModo === 'nuevo')`, captura el truck y agrégalo al INSERT:
```php
            $truckId = cleanInt($_POST['evento_truck_id'] ?? 0) ?: null;
            ...
            $eventoId = Database::insert(
                "INSERT INTO eventos (nombre,quote_id,ubicacion_id,truck_ubicacion_id,fecha_inicio,fecha_fin,estado) VALUES (?,?,?,?,?,?, 'abierto')",
                [$evNombre, $qid, $ubiId, $truckId, $fIni, $fFin]
            );
```
(`$ubiId` = almacén de origen, sin cambio. Si la columna no existe aún, el `try/catch` que ya envuelve este bloque lo tolera.)

- [ ] **Step 3: Verificar + commit**

Run: `php -l admin/inventory/salida_evento.php` → sin errores.
```bash
git add admin/inventory/salida_evento.php
git commit -m "feat(eventos): elegir la ubicación del truck al asignar la salida a un evento nuevo"
```

---

## Task 3: El teórico lee las ventas del truck

**Files:** Modify `includes/inventario.php` (`eventoConsumoTeorico`)

- [ ] **Step 1: Usar `truck_ubicacion_id` con fallback**

En `eventoConsumoTeorico`, cambia la carga de la ubicación a:
```php
            $ev = Database::fetch("SELECT ubicacion_id, truck_ubicacion_id FROM eventos WHERE id=?", [$eventoId]);
            if (!$ev) return $out;
            $ubi = (int)($ev['truck_ubicacion_id'] ?: $ev['ubicacion_id']);   // ventas del truck; fallback al almacén (eventos viejos)
            if ($ubi <= 0) return $out;
```
(El resto de la función igual. Nota: si la columna `truck_ubicacion_id` no existe, el `SELECT` fallaría dentro del `try/catch` y devolvería `[]`; para tolerancia, deja el `SELECT` así — al aplicar la migración 47 funciona; sin ella el teórico sale 0, aceptable.)

- [ ] **Step 2: Verificar + commit**

Run: `php -l includes/inventario.php` → sin errores.
```bash
git add includes/inventario.php
git commit -m "feat(eventos): el teórico del evento lee las ventas del truck (fallback al almacén)"
```

---

## Task 4: Mostrar el truck en el detalle

**Files:** Modify `admin/inventory/evento_detalle.php`

- [ ] **Step 1: Traer el nombre del truck y mostrarlo**

En la query que carga `$ev`, agrega el join del truck:
```php
$ev = $id ? Database::fetch(
  "SELECT e.*, u.nombre AS ubi_nombre, q.quote_number, t.nombre AS truck_nombre
     FROM eventos e
     LEFT JOIN ubicaciones u ON u.id=e.ubicacion_id
     LEFT JOIN ubicaciones t ON t.id=e.truck_ubicacion_id
     LEFT JOIN quotes q ON q.id=e.quote_id WHERE e.id=?",
  [$id]
) : null;
```
En la línea de meta del header (donde muestra fechas/almacén/cotización), añade el truck si existe:
```php
     <?= !empty($ev['truck_nombre']) ? ' · 🚚 ' . clean($ev['truck_nombre']) : '' ?>
```
(Y donde dice "Local"/almacén en el detalle, su etiqueta puede aclarar "Almacén" — opcional.)

- [ ] **Step 2: Verificar + commit**

Run: `php -l admin/inventory/evento_detalle.php` → sin errores.
```bash
git add admin/inventory/evento_detalle.php
git commit -m "feat(eventos): mostrar el truck en el detalle del evento"
```

---

## Verificación final (manual)

- [ ] Aplicar `install/47_evento_truck.sql`.
- [ ] Crear (una vez) una ubicación "Food Truck" en Operación → Ubicaciones.
- [ ] En "Salida a evento": almacén = principal; "Asignar a evento → nuevo", elegir el **Food Truck** como truck; confirmar → descuenta del **principal**, crea el evento con `truck_ubicacion_id`.
- [ ] Registrar ventas POS bajo el **Food Truck** ese día → el teórico del evento las refleja; las ventas del local principal NO entran al evento.
- [ ] Cerrar el evento → el sobrante vuelve al **almacén principal** (`ubicacion_id`), no al truck.
- [ ] Varios eventos abiertos con trucks distintos → cada teórico lee solo su truck (sin mezcla).
