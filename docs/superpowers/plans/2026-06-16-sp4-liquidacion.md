# SP4 · Liquidación del evento — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans. Steps usan checkbox (`- [ ]`).

**Goal:** Cerrar el módulo: en el detalle del evento, calcular su rentabilidad — Ingresos (cotización / ventas POS / monto manual) − Mercadería − Papelería/descartables − Otros gastos = **Utilidad** y **Rendimiento %**.

**Architecture:** Tabla nueva `evento_gastos` (otros gastos categorizados). Un helper `eventoSaldoFinal($id)` (extraído de la lógica del cierre, para única fuente de verdad) da el saldo por insumo; **consumo = inicial − saldoFinal** → mercadería (ingredientes) y papelería (descartables) × costo. Ingresos = `eventos.venta_manual` (editable; default = ventas POS del truck en el rango, o total de la cotización). Todo se renderiza/opera dentro de `admin/inventory/evento_detalle.php`.

**Tech Stack:** PHP 8 + MySQL. **Sin tests** → `php -l` + prueba manual.

**Spec maestro:** `docs/superpowers/specs/2026-06-16-liquidacion-evento-design.md` (SP4). Reusa `gasto_categorias` (creación al vuelo, patrón de `admin/gastos/form.php`).

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `install/48_evento_gastos.sql` | Tabla `evento_gastos` | Crear |
| `includes/inventario.php` | Helper `eventoSaldoFinal()` | Modificar |
| `admin/inventory/evento_detalle.php` | Refactor del cierre a usar el helper + otros gastos (CRUD) + liquidación | Modificar |

---

## Task 1: Migración — `evento_gastos`

**Files:** Create `install/48_evento_gastos.sql`

- [ ] **Step 1: Crear**
```sql
CREATE TABLE IF NOT EXISTS evento_gastos (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  evento_id   INT NOT NULL,
  categoria_id INT UNSIGNED NULL,
  monto       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  descripcion VARCHAR(160) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_eg_ev (evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
- [ ] **Step 2: Commit**
```bash
git add install/48_evento_gastos.sql
git commit -m "feat(eventos): migración — evento_gastos (otros gastos del evento)"
```

---

## Task 2: Helper `eventoSaldoFinal()` + refactor del cierre

**Files:** Modify `includes/inventario.php`, `admin/inventory/evento_detalle.php`

- [ ] **Step 1: Añadir el helper en `includes/inventario.php`**

(Junto a `eventoConsumoTeorico`. Replica EXACTO la matemática del control diario para que el saldo coincida siempre.)
```php
if (!function_exists('eventoSaldoFinal')) {
    /** Saldo final por insumo de un evento (inicial − consumo acumulado por día). @return array insumo_id=>saldo */
    function eventoSaldoFinal(int $eventoId): array
    {
        $saldo = [];
        try {
            $insAll = Database::fetchAll("SELECT insumo_id, cantidad_inicial FROM evento_insumos WHERE evento_id=?", [$eventoId]);
            foreach ($insAll as $r) { $saldo[(int)$r['insumo_id']] = (float)$r['cantidad_inicial']; }
            $dias = Database::fetchAll("SELECT * FROM evento_dias WHERE evento_id=? ORDER BY dia_num", [$eventoId]);
            $cont = [];
            foreach (Database::fetchAll("SELECT dc.* FROM evento_dia_conteo dc JOIN evento_dias d ON d.id=dc.dia_id WHERE d.evento_id=?", [$eventoId]) as $c) {
                $cont[(int)$c['dia_id']][(int)$c['insumo_id']] = $c;
            }
            foreach ($dias as $d) {
                $teo = eventoConsumoTeorico($eventoId, $d['fecha']);
                foreach ($insAll as $r) {
                    $iid = (int)$r['insumo_id']; $cfg = $cont[(int)$d['id']][$iid] ?? null;
                    $corr = ($cfg && $cfg['corregido'] !== null) ? (float)$cfg['corregido'] : null;
                    $cnt  = ($cfg && $cfg['conteo']    !== null) ? (float)$cfg['conteo']    : null;
                    $consumo  = $corr !== null ? $corr : round($teo[$iid] ?? 0, 3);
                    $saldoEsp = round(($saldo[$iid] ?? 0) - $consumo, 3);
                    $saldo[$iid] = $cnt !== null ? $cnt : $saldoEsp;
                }
            }
        } catch (\Throwable $e) {}
        return $saldo;
    }
}
```

- [ ] **Step 2: Refactor del cierre en `evento_detalle.php` para usar el helper**

En el handler `accion === 'cerrar'`, REEMPLAZA todo el bloque que recalcula `$saldo` (el que arma `$insAll`/`$diasC`/`$cont2` y el doble `foreach`) por:
```php
        $saldo = eventoSaldoFinal($id);
```
(El resto del cierre — el `foreach ($saldo as $iid=>$s) invMovimiento(+$s)` y el `UPDATE estado='cerrado'` — se conserva. Así el cierre y la liquidación usan exactamente el mismo saldo.)

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l includes/inventario.php && php -l admin/inventory/evento_detalle.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/inventario.php admin/inventory/evento_detalle.php
git commit -m "feat(eventos): helper eventoSaldoFinal + cierre lo reutiliza (saldo único para cierre y liquidación)"
```

---

## Task 3: Otros gastos del evento (CRUD)

**Files:** Modify `admin/inventory/evento_detalle.php`

- [ ] **Step 1: Handlers POST (agregar/eliminar gasto), dentro del bloque POST existente**

```php
    if ($accion === 'gasto_add') {
        $monto = max(0, cleanFloat($_POST['monto'] ?? 0));
        $desc  = clean($_POST['descripcion'] ?? '');
        $catId = cleanInt($_POST['categoria_id'] ?? 0) ?: null;
        $nuevaCat = clean($_POST['nueva_categoria'] ?? '');
        if ($nuevaCat !== '') {
            Database::execute("INSERT IGNORE INTO gasto_categorias (nombre) VALUES (?)", [$nuevaCat]);
            $cr = Database::fetch("SELECT id FROM gasto_categorias WHERE nombre=?", [$nuevaCat]);
            if ($cr) $catId = (int)$cr['id'];
        }
        if ($monto > 0) {
            Database::insert("INSERT INTO evento_gastos (evento_id,categoria_id,monto,descripcion) VALUES (?,?,?,?)", [$id, $catId, $monto, ($desc ?: null)]);
            flashMessage('success', 'Gasto agregado.');
        }
        redirect('/admin/inventory/evento_detalle.php?id=' . $id);
    }
    if ($accion === 'gasto_del') {
        $gid = cleanInt($_POST['gasto_id'] ?? 0);
        if ($gid) Database::execute("DELETE FROM evento_gastos WHERE id=? AND evento_id=?", [$gid, $id]);
        redirect('/admin/inventory/evento_detalle.php?id=' . $id);
    }
```

- [ ] **Step 2: Cargar gastos + categorías (zona de datos, tras `$insumos`)**

```php
$catsGasto = Database::fetchAll("SELECT id, nombre FROM gasto_categorias ORDER BY nombre");
$otrosGastos = Database::fetchAll("SELECT eg.*, gc.nombre AS cat_nombre FROM evento_gastos eg LEFT JOIN gasto_categorias gc ON gc.id=eg.categoria_id WHERE eg.evento_id=? ORDER BY eg.id DESC", [$id]);
$totalOtros = 0; foreach ($otrosGastos as $g) { $totalOtros += (float)$g['monto']; }
```

- [ ] **Step 3: Render de "Otros gastos" (tarjeta, antes de la liquidación)**

Una tarjeta que lista `$otrosGastos` (categoría, descripción, monto, botón eliminar = form POST `gasto_del`), y un form `gasto_add` con: monto, selector `categoria_id` (de `$catsGasto`) + input `nueva_categoria` ("Nueva categoría…"), descripción. Solo editable si `$ev['estado']` lo permite (puedes permitir gastos aún en cerrado, pero por simpleza: igual que el resto, si cerrado deshabilita). Cada form con `csrfField()`.

- [ ] **Step 4: Verificar + commit**

Run: `php -l admin/inventory/evento_detalle.php` → sin errores.
```bash
git add admin/inventory/evento_detalle.php
git commit -m "feat(eventos): otros gastos del evento (categorizados, categoría al vuelo)"
```

---

## Task 4: Liquidación (ingresos − costos = utilidad + rendimiento)

**Files:** Modify `admin/inventory/evento_detalle.php`

- [ ] **Step 1: Handler para guardar los ingresos (venta_manual)**

En el bloque POST:
```php
    if ($accion === 'guardar_ingresos') {
        $venta = ($_POST['venta_manual'] ?? '') !== '' ? max(0, cleanFloat($_POST['venta_manual'])) : null;
        Database::execute("UPDATE eventos SET venta_manual=? WHERE id=?", [$venta, $id]);
        flashMessage('success', 'Ingresos del evento guardados.');
        redirect('/admin/inventory/evento_detalle.php?id=' . $id);
    }
```

- [ ] **Step 2: Cálculo de la liquidación (zona de datos)**

```php
// Costos: consumo = inicial − saldo final, × costo, separando ingrediente/descartable
$saldoFin = eventoSaldoFinal($id);
$costoMercaderia = 0.0; $costoPapeleria = 0.0;
foreach ($insumos as $r) {
    $iid = (int)$r['insumo_id'];
    $consumo = max(0, (float)$r['cantidad_inicial'] - ($saldoFin[$iid] ?? 0));
    $costo = $consumo * (float)$r['costo_unitario'];
    if (($r['tipo'] ?? 'ingrediente') === 'descartable') $costoPapeleria += $costo; else $costoMercaderia += $costo;
}
// Ingresos: venta_manual si está; si no, ventas POS del truck en el rango; si no, total de la cotización
$ventaPOS = 0.0;
$truckId = (int)($ev['truck_ubicacion_id'] ?: $ev['ubicacion_id']);
if ($truckId > 0) {
    $fIni = $ev['fecha_inicio']; $fFin = $ev['fecha_fin'] ?: $ev['fecha_inicio'];
    $ventaPOS = (float)(Database::fetch("SELECT COALESCE(SUM(total),0) t FROM pedidos WHERE ubicacion_id=? AND DATE(created_at) BETWEEN ? AND ? AND estado<>'cancelado'", [$truckId, $fIni, $fFin])['t'] ?? 0);
}
$ventaCot = 0.0;
if (!empty($ev['quote_id'])) { $ventaCot = (float)(Database::fetch("SELECT total FROM quotes WHERE id=?", [$ev['quote_id']])['total'] ?? 0); }
$ingresoDefault = $ventaPOS > 0 ? $ventaPOS : $ventaCot;
$ingresos = $ev['venta_manual'] !== null ? (float)$ev['venta_manual'] : $ingresoDefault;
$utilidad = $ingresos - $costoMercaderia - $costoPapeleria - $totalOtros;
$rendimiento = $ingresos > 0 ? ($utilidad / $ingresos) * 100 : 0;
```

- [ ] **Step 3: Render de la liquidación (tarjeta final)**

Replica `liquidacion-evento-pipeline.html` (etapa 7). Una tarjeta "Liquidación":
- **Ingresos:** un `<form method=post>` (`accion=guardar_ingresos`, csrf) con un input `name="venta_manual"` prellenado con `$ingresos` y un hint que muestre el default (`Ventas POS: formatMoney($ventaPOS)` y, si hay, `Cotización: formatMoney($ventaCot)`), + botón "Guardar ingresos".
- Tabla/lista: `+ Ingresos` (verde), `− Mercadería` (`formatMoney($costoMercaderia)`), `− Papelería/descartables` (`formatMoney($costoPapeleria)`), `− Otros gastos` (`formatMoney($totalOtros)`), `= Utilidad` (`formatMoney($utilidad)`, rojo si <0).
- KPIs: Facturado (`$ingresos`), Utilidad (`$utilidad`), Rendimiento (`number_format($rendimiento,1).'%'`).

- [ ] **Step 4: Verificar + prueba manual**

Run: `php -l admin/inventory/evento_detalle.php` → sin errores.
Manual: en un evento con inventario inicial + control diario + algún gasto, la liquidación muestra ingresos (default POS, editable), mercadería y papelería separadas, otros gastos, utilidad y rendimiento coherentes.

- [ ] **Step 5: Commit**

```bash
git add admin/inventory/evento_detalle.php
git commit -m "feat(eventos): liquidación — ingresos − mercadería − papelería − otros = utilidad + rendimiento"
```

---

## Verificación final (manual)

- [ ] Aplicar `install/48_evento_gastos.sql`.
- [ ] Evento con inventario inicial + control diario: la **mercadería** = Σ (consumo ingredientes × costo); **papelería** = Σ (consumo descartables × costo); coinciden con el saldo del control (mismo helper).
- [ ] **Ingresos** por defecto = ventas POS del truck en las fechas; editable (se guarda en `venta_manual`); si hay cotización vinculada se muestra como referencia.
- [ ] Agregar **otros gastos** con categoría existente y con categoría **nueva al vuelo** → suman; eliminar funciona.
- [ ] **Utilidad** = ingresos − mercadería − papelería − otros; **rendimiento** = utilidad/ingresos.
- [ ] Cierre del evento y liquidación usan el **mismo saldo** (helper único) → no hay descuadres.

## Cierre del módulo
Con SP4 queda completo el pipeline de **liquidación de evento**: planificar por productos (SP2) → inventario inicial + asignar a evento (SP3a) → control diario (SP3b) → ubicación del truck (SP3c) → **liquidación (SP4)**. Sobre los cimientos de recetas al vuelo (SP1) y recetas de modificador (SP1b).
