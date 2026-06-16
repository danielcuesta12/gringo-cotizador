# SP3b · Control diario del evento — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recomendado) o superpowers:executing-plans. Steps usan checkbox (`- [ ]`).

**Goal:** Controlar el inventario del evento día a día: consumo **teórico** (ventas POS del local en esa fecha, explotando receta de producto + modificadores) → **corregido** (manual) → **conteo físico** → diferencia; el saldo arrastra al día siguiente; agregar días; cerrar el evento devolviendo el sobrante al stock.

**Architecture:** Dos tablas nuevas (`evento_dias`, `evento_dia_conteo`). Un helper `eventoConsumoTeorico($eventoId,$fecha)` en `includes/inventario.php` (espeja `descontarStockPedido` + suma recetas de modificador). El control diario se renderiza y opera dentro de `admin/inventory/evento_detalle.php` (pestañas por día, tabla editable, guardar día, agregar día, cerrar evento). El **teórico se calcula en vivo**; solo se guardan `corregido` y `conteo` por (día, insumo).

**Tech Stack:** PHP 8 + JS vanilla. **Sin tests** → `php -l` + prueba manual.

**Spec maestro:** `docs/superpowers/specs/2026-06-16-liquidacion-evento-design.md` (SP3). **Mockup:** `docs/superpowers/specs/mockups/foodtruck-control-diario.html`.

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `install/46_evento_dias.sql` | Tablas `evento_dias` + `evento_dia_conteo` | Crear |
| `includes/inventario.php` | Helper `eventoConsumoTeorico()` (teórico desde POS) | Modificar |
| `admin/inventory/evento_detalle.php` | Control diario: render por día + handlers (guardar día, agregar día, cerrar) | Modificar |

---

## Definiciones (matemática del control)

Para cada insumo del inventario inicial (`evento_insumos`), por día (ordenados por `dia_num`):
- `inicial(día 1)` = `evento_insumos.cantidad_inicial`.
- `inicial(día N>1)` = `saldoFinal(día N−1)`.
- `teorico` = `eventoConsumoTeorico(evento, fecha_del_día)[insumo]` (en vivo desde el POS).
- `consumo` = `corregido` si el usuario lo ingresó (no null), si no `teorico`.
- `saldoEsperado` = `inicial − consumo`.
- `conteo` = conteo físico del usuario (nullable).
- `diferencia` = `conteo − saldoEsperado` (solo si hay conteo).
- `saldoFinal` (arrastre al día siguiente) = `conteo` si hay conteo, si no `saldoEsperado`.

Al **cerrar**: el `saldoFinal` del último día (por insumo) = sobrante → se devuelve al stock del local (`invMovimiento(+sobrante)`), y `eventos.estado='cerrado'`.

---

## Task 1: Migración — días y conteo

**Files:**
- Create: `install/46_evento_dias.sql`

- [ ] **Step 1: Crear la migración**

```sql
-- Días del evento (multi-día) y el conteo/corrección por insumo por día.
CREATE TABLE IF NOT EXISTS evento_dias (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  evento_id  INT NOT NULL,
  fecha      DATE NOT NULL,
  dia_num    INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_ev_dia (evento_id, dia_num),
  INDEX idx_ed_ev (evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evento_dia_conteo (
  dia_id    INT NOT NULL,
  insumo_id INT UNSIGNED NOT NULL,
  corregido DECIMAL(12,3) NULL,   -- override del consumo (null = usar teórico)
  conteo    DECIMAL(12,3) NULL,   -- conteo físico (null = no contado)
  PRIMARY KEY (dia_id, insumo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Commit**

```bash
git add install/46_evento_dias.sql
git commit -m "feat(eventos): migración — evento_dias y evento_dia_conteo (control diario)"
```

---

## Task 2: Helper de consumo teórico desde el POS

**Files:**
- Modify: `includes/inventario.php` (añadir función, junto a `descontarStockPedido`)

- [ ] **Step 1: Añadir `eventoConsumoTeorico()`**

```php
if (!function_exists('eventoConsumoTeorico')) {
    /**
     * Consumo teórico de un evento en una fecha: explota las ventas (pedidos) del
     * local del evento ese día por receta de producto + recetas de modificador.
     * @return array insumo_id => cantidad
     */
    function eventoConsumoTeorico(int $eventoId, string $fecha): array
    {
        $out = [];
        try {
            $ev = Database::fetch("SELECT ubicacion_id FROM eventos WHERE id=?", [$eventoId]);
            if (!$ev || empty($ev['ubicacion_id'])) return $out;
            $ubi = (int)$ev['ubicacion_id'];
            $pedidos = Database::fetchAll(
                "SELECT items_json FROM pedidos
                  WHERE ubicacion_id=? AND DATE(created_at)=? AND estado<>'cancelado'",
                [$ubi, $fecha]
            );
            foreach ($pedidos as $p) {
                $items = json_decode($p['items_json'] ?? '[]', true) ?: [];
                foreach ($items as $it) {
                    $qty = (float)($it['qty'] ?? 0);
                    if ($qty <= 0) continue;
                    $pid = (int)($it['product_id'] ?? 0);
                    if ($pid > 0) {
                        foreach (Database::fetchAll("SELECT insumo_id, cantidad FROM recetas WHERE product_id=?", [$pid]) as $r) {
                            $out[(int)$r['insumo_id']] = ($out[(int)$r['insumo_id']] ?? 0) + (float)$r['cantidad'] * $qty;
                        }
                    }
                    foreach (($it['modificadores'] ?? []) as $m) {
                        $mid = (int)($m['id'] ?? 0);
                        if ($mid <= 0) continue;
                        foreach (Database::fetchAll("SELECT insumo_id, cantidad FROM receta_modificadores WHERE modificador_id=?", [$mid]) as $r) {
                            $out[(int)$r['insumo_id']] = ($out[(int)$r['insumo_id']] ?? 0) + (float)$r['cantidad'] * $qty;
                        }
                    }
                }
            }
        } catch (\Throwable $e) { /* tablas no migradas */ }
        return $out;
    }
}
```
(Nota: el teórico explota por `product_id` y por `modificadores[].id` de `items_json`, que el POS/carta sí guardan. Si un modificador no trae `id`, se ignora — el conteo físico cubre la diferencia.)

- [ ] **Step 2: Verificar sintaxis + commit**

Run: `php -l includes/inventario.php` → sin errores.
```bash
git add includes/inventario.php
git commit -m "feat(eventos): helper eventoConsumoTeorico (consumo por ventas POS + modificadores)"
```

---

## Task 3: Control diario en el detalle del evento (render + guardar + agregar día)

**Files:**
- Modify: `admin/inventory/evento_detalle.php`

- [ ] **Step 1: Handlers POST (al inicio, tras cargar `$ev`, con `verifyCsrf`)**

Antes del HTML, añade el manejo de acciones (solo si el evento está abierto para editar):
```php
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'agregar_dia' && $ev['estado']==='abierto') {
        $max = (int)(Database::fetch("SELECT COALESCE(MAX(dia_num),0) m FROM evento_dias WHERE evento_id=?", [$id])['m'] ?? 0);
        // fecha del nuevo día = fecha_inicio + max (días)
        $fecha = date('Y-m-d', strtotime($ev['fecha_inicio'] . ' +' . $max . ' day'));
        Database::insert("INSERT INTO evento_dias (evento_id,fecha,dia_num) VALUES (?,?,?)", [$id, $fecha, $max+1]);
        flashMessage('success', 'Día agregado.');
        redirect('/admin/inventory/evento_detalle.php?id=' . $id);
    }
    if ($accion === 'guardar_dia' && $ev['estado']==='abierto') {
        $diaId = cleanInt($_POST['dia_id'] ?? 0);
        $dia = $diaId ? Database::fetch("SELECT id FROM evento_dias WHERE id=? AND evento_id=?", [$diaId, $id]) : null;
        if ($dia) {
            $corr = $_POST['corregido'] ?? [];   // insumo_id => valor (string)
            $cont = $_POST['conteo'] ?? [];
            Database::execute("DELETE FROM evento_dia_conteo WHERE dia_id=?", [$diaId]);
            $iids = array_unique(array_merge(array_keys($corr), array_keys($cont)));
            foreach ($iids as $iid) {
                $iid = (int)$iid; if ($iid<=0) continue;
                $cv = ($corr[$iid] ?? '') !== '' ? (float)$corr[$iid] : null;
                $kv = ($cont[$iid] ?? '') !== '' ? (float)$cont[$iid] : null;
                if ($cv === null && $kv === null) continue;
                Database::execute("INSERT INTO evento_dia_conteo (dia_id,insumo_id,corregido,conteo) VALUES (?,?,?,?)", [$diaId,$iid,$cv,$kv]);
            }
            flashMessage('success', 'Día guardado.');
        }
        redirect('/admin/inventory/evento_detalle.php?id=' . $id . '&dia=' . $diaId);
    }
}
```

- [ ] **Step 2: Cargar días + calcular saldos acumulados (PHP, tras cargar `$insumos`)**

```php
require_once __DIR__ . '/../../includes/inventario.php';
// Asegura al menos el día 1
$dias = Database::fetchAll("SELECT * FROM evento_dias WHERE evento_id=? ORDER BY dia_num", [$id]);
if (!$dias && $insumos) {
    Database::insert("INSERT INTO evento_dias (evento_id,fecha,dia_num) VALUES (?,?,1)", [$id, $ev['fecha_inicio']]);
    $dias = Database::fetchAll("SELECT * FROM evento_dias WHERE evento_id=? ORDER BY dia_num", [$id]);
}
// Conteos guardados: dia_id => insumo_id => ['corregido'=>,'conteo'=>]
$conteos = [];
foreach (Database::fetchAll("SELECT dc.* FROM evento_dia_conteo dc JOIN evento_dias d ON d.id=dc.dia_id WHERE d.evento_id=?", [$id]) as $c) {
    $conteos[(int)$c['dia_id']][(int)$c['insumo_id']] = ['corregido'=>$c['corregido'],'conteo'=>$c['conteo']];
}
// Inicial por insumo (día 1)
$inicial = []; foreach ($insumos as $r) { $inicial[(int)$r['insumo_id']] = (float)$r['cantidad_inicial']; }
// Calcular, por día, el estado de cada insumo y el saldo que arrastra
$diaData = [];     // dia_id => insumo_id => ['inicial','teorico','consumo','saldo','conteo','dif']
$saldoPrev = $inicial;
foreach ($dias as $d) {
    $teo = eventoConsumoTeorico($id, $d['fecha']);
    $rows = [];
    $saldoNext = [];
    foreach ($insumos as $r) {
        $iid = (int)$r['insumo_id'];
        $ini = $saldoPrev[$iid] ?? 0;
        $t   = round($teo[$iid] ?? 0, 3);
        $cfg = $conteos[(int)$d['id']][$iid] ?? null;
        $corr = ($cfg && $cfg['corregido'] !== null) ? (float)$cfg['corregido'] : null;
        $cont = ($cfg && $cfg['conteo'] !== null) ? (float)$cfg['conteo'] : null;
        $consumo = $corr !== null ? $corr : $t;
        $saldoEsp = round($ini - $consumo, 3);
        $dif = $cont !== null ? round($cont - $saldoEsp, 3) : null;
        $rows[$iid] = ['inicial'=>$ini,'teorico'=>$t,'corregido'=>$corr,'consumo'=>$consumo,'saldo'=>$saldoEsp,'conteo'=>$cont,'dif'=>$dif];
        $saldoNext[$iid] = $cont !== null ? $cont : $saldoEsp;
    }
    $diaData[(int)$d['id']] = $rows;
    $saldoPrev = $saldoNext;
}
$saldoFinal = $saldoPrev;  // sobrante tras el último día (para cerrar)
$diaSel = cleanInt($_GET['dia'] ?? 0) ?: (int)($dias[count($dias)-1]['id'] ?? 0);
```

- [ ] **Step 3: Render del control diario (tras la tabla de inventario inicial)**

Añade una sección con pestañas por día (replicando `foodtruck-control-diario.html`): botones de día + "Agregar día" (POST `accion=agregar_dia`), y para el día seleccionado (`$diaSel`) un `<form method=post>` con `accion=guardar_dia` + `dia_id`, y una tabla por insumo con columnas: **Insumo · Inicial · Teórico · Corregido (input `name="corregido[insumo_id]"`) · Saldo esperado · Conteo (input `name="conteo[insumo_id]"`) · Diferencia** (rojo si ≠0). Usa `$diaData[$diaSel][$iid]` para los valores. Botón "Guardar día". Si `$ev['estado']==='cerrado'`, los inputs van `disabled` y no se muestran botones de edición. Helper de formato: reutiliza el `number_format`/`rtrim` ya usado en el archivo. La fila usa `formatMoney` solo si quieres mostrar costo (opcional).

(Ejemplo de fila — adapta al estilo del archivo:)
```php
<?php foreach ($insumos as $r): $iid=(int)$r['insumo_id']; $x=$diaData[$diaSel][$iid] ?? null; if(!$x) continue; ?>
  <tr>
    <td><?= clean($r['nombre']) ?> <small><?= clean($r['unidad']) ?></small></td>
    <td style="text-align:right"><?= $x['inicial'] ?></td>
    <td style="text-align:right;color:#888"><?= $x['teorico'] ?></td>
    <td><input type="text" inputmode="decimal" name="corregido[<?= $iid ?>]" value="<?= $x['corregido'] !== null ? $x['corregido'] : '' ?>" placeholder="<?= $x['teorico'] ?>" style="width:80px;text-align:right" <?= $ev['estado']==='cerrado'?'disabled':'' ?>></td>
    <td style="text-align:right"><?= $x['saldo'] ?></td>
    <td><input type="text" inputmode="decimal" name="conteo[<?= $iid ?>]" value="<?= $x['conteo'] !== null ? $x['conteo'] : '' ?>" style="width:80px;text-align:right" <?= $ev['estado']==='cerrado'?'disabled':'' ?>></td>
    <td style="text-align:right;<?= ($x['dif']!==null && abs($x['dif'])>0.0001)?'color:#d64545;font-weight:700':'color:#1f9d55' ?>"><?= $x['dif']!==null ? $x['dif'] : '—' ?></td>
  </tr>
<?php endforeach; ?>
```

- [ ] **Step 4: Verificar sintaxis + prueba manual**

Run: `php -l admin/inventory/evento_detalle.php` → sin errores.
Manual: en un evento con inventario inicial, ver el día 1 con teórico (si hubo ventas POS ese día) e inicial = apertura; editar corregido/conteo → guardar → diferencia y saldo recalculan; "Agregar día" → día 2 con inicial = saldo del día 1.

- [ ] **Step 5: Commit**

```bash
git add admin/inventory/evento_detalle.php
git commit -m "feat(eventos): control diario (teórico/corregido/conteo, multi-día con arrastre de saldo)"
```

---

## Task 4: Cerrar evento (devolver sobrante al stock)

**Files:**
- Modify: `admin/inventory/evento_detalle.php` (handler + botón)

- [ ] **Step 1: Handler de cierre**

Dentro del bloque POST, añade:
```php
    if ($accion === 'cerrar' && $ev['estado']==='abierto') {
        require_once __DIR__ . '/../../includes/inventario.php';
        // Recalcular el saldo final (sobrante) repitiendo el cálculo del control:
        $insAll = Database::fetchAll("SELECT insumo_id, cantidad_inicial FROM evento_insumos WHERE evento_id=?", [$id]);
        $diasC  = Database::fetchAll("SELECT * FROM evento_dias WHERE evento_id=? ORDER BY dia_num", [$id]);
        $cont2  = [];
        foreach (Database::fetchAll("SELECT dc.* FROM evento_dia_conteo dc JOIN evento_dias d ON d.id=dc.dia_id WHERE d.evento_id=?", [$id]) as $c) { $cont2[(int)$c['dia_id']][(int)$c['insumo_id']] = $c; }
        $saldo = []; foreach ($insAll as $r) { $saldo[(int)$r['insumo_id']] = (float)$r['cantidad_inicial']; }
        foreach ($diasC as $d) {
            $teo = eventoConsumoTeorico($id, $d['fecha']);
            foreach ($insAll as $r) {
                $iid=(int)$r['insumo_id']; $cfg=$cont2[(int)$d['id']][$iid] ?? null;
                $corr = ($cfg && $cfg['corregido']!==null) ? (float)$cfg['corregido'] : null;
                $cnt  = ($cfg && $cfg['conteo']!==null) ? (float)$cfg['conteo'] : null;
                $consumo = $corr !== null ? $corr : ($teo[$iid] ?? 0);
                $saldoEsp = ($saldo[$iid] ?? 0) - $consumo;
                $saldo[$iid] = $cnt !== null ? $cnt : $saldoEsp;
            }
        }
        $ubiEv = (int)($ev['ubicacion_id'] ?? 0);
        if ($ubiEv > 0) {
            foreach ($saldo as $iid => $s) {
                if ($s > 0.0001) invMovimiento($ubiEv, (int)$iid, 'ajuste', (float)$s, ['motivo' => 'Cierre evento #' . $id . ': sobrante devuelto']);
            }
        }
        Database::execute("UPDATE eventos SET estado='cerrado' WHERE id=?", [$id]);
        flashMessage('success', 'Evento cerrado. El sobrante volvió al stock del local.');
        redirect('/admin/inventory/evento_detalle.php?id=' . $id);
    }
```

- [ ] **Step 2: Botón "Cerrar evento" (solo si abierto)**

En el render, si `$ev['estado']==='abierto'`, un `<form method=post>` con `accion=cerrar` + `csrfField()` y un botón con `data-confirm="¿Cerrar el evento? El sobrante se devuelve al stock del local y ya no se podrá editar."`.

- [ ] **Step 3: Verificar sintaxis + prueba manual**

Run: `php -l admin/inventory/evento_detalle.php` → sin errores.
Manual: cerrar un evento con sobrante → se crea movimiento de ingreso (+sobrante) en el stock del local; el evento queda "cerrado" y sus inputs deshabilitados.

- [ ] **Step 4: Commit**

```bash
git add admin/inventory/evento_detalle.php
git commit -m "feat(eventos): cerrar evento devolviendo el sobrante al stock del local"
```

---

## Verificación final (manual)

- [ ] Aplicar `install/46_evento_dias.sql`.
- [ ] Evento con inventario inicial → día 1 muestra inicial = apertura, teórico = ventas POS del local ese día (si las hubo).
- [ ] Editar corregido/conteo y guardar → saldo esperado = inicial − (corregido||teórico); diferencia = conteo − saldo; persisten al recargar.
- [ ] "Agregar día" → día 2 con inicial = saldo final del día 1 (conteo si hubo, si no saldo esperado).
- [ ] Cerrar evento → sobrante (saldo del último día) vuelve al stock (`movimientos` tipo ajuste +); evento "cerrado", inputs deshabilitados, sin botones de edición.
- [ ] Sin ventas POS ese día → teórico 0 (se confía en el conteo). Modificadores con receta suman al teórico.

## Nota de alcance (SP4)
SP3b deja el evento controlado día a día y cerrable. **SP4** = liquidación: ingresos (cotización / POS / `venta_manual`) − mercadería (consumo real × costo) − papelería (descartables) − otros gastos (categorizados) = utilidad + rendimiento.
