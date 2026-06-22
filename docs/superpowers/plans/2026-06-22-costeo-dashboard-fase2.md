# Costeo Fase 2 — Dashboard de Costeo · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Un dashboard de solo lectura que rankea los platos por food cost (menor = más rentable), con KPIs y alertas, bajo un permiso nuevo `inv_costeo`.

**Architecture:** Página `admin/inventory/costeo.php` que, por cada producto activo con receta, calcula costo (`recetaCosto`) y food cost (`foodCostCalc`) usando el precio del local principal; ordena ascendente por food cost; muestra KPIs (promedio, nº platos, alertas >35%) y filtros por categoría/búsqueda. Permiso `inv_costeo` en el grupo Inventario.

**Tech Stack:** PHP 8 + PDO, layout admin, helpers existentes (`recetaCosto`, `recetaComponentes`, `foodCostCalc`, `fcClase`, `getSetting`).

## Global Constraints

- Sin emojis pictográficos (símbolos/SVG). SQL con `?`. `requirePermission('inv_costeo')`. Solo lectura (sin POST/escrituras).
- Semáforo food cost: verde `#16a34a` (≤35%), naranja `#ca8a04` (≤42%), rojo `#dc2626` (resto) — semántico, vía `fcClase()` (fracción 0..1).
- IGV de `getSetting('igv_pct','18')`. Precio de venta = `location_products` del local principal (`es_principal`); platos sin precio se marcan "sin precio" y se excluyen de promedio/alertas.
- Layout admin estándar; `$activePage='inv-costeo'`. Degradar con `inventarioListo()`.

## File Structure

- `admin/inventory/costeo.php` — **nuevo**. El dashboard.
- `includes/permissions.php` — **modificar**. Registrar `inv_costeo` (label/grupo/template/path).
- `admin/layout-top.php` — **modificar**. Condición del grupo Inventario + link "Costeo".

---

### Task 1: Dashboard de Costeo + permiso `inv_costeo`

**Files:**
- Create: `admin/inventory/costeo.php`
- Modify: `includes/permissions.php` (4 puntos)
- Modify: `admin/layout-top.php` (condición del grupo + nav link)

**Interfaces:**
- Consumes: `recetaCosto(int):float`, `recetaComponentes(int):array`, `foodCostCalc(float,float,float):array` (`['neto','fc','margen']`, fracción), `fcClase(float):string` (`'ok'|'warn'|'bad'`), `inventarioListo()`, `getSetting`.

- [ ] **Step 1: Registrar el permiso `inv_costeo` en `includes/permissions.php`**

(a) En el grupo `'Inventario'` (donde están `inv_insumos`…`inv_evento`), agregar tras `'inv_evento' => 'Salida a evento',`:

```php
            'inv_costeo'      => 'Costeo',
```

(b) En `permissionTemplates()`, en `'inventario' => [...]`, agregar `'inv_costeo'` al final del array:

```php
        'inventario' => ['inv_insumos', 'inv_stock', 'inv_recetas', 'inv_movimientos', 'inv_compras', 'inv_evento', 'inv_costeo'],
```

(c) En el mapa de paths (donde está `'inv_evento' => '/admin/inventory/salida_evento.php',`), agregar:

```php
        'inv_costeo'      => '/admin/inventory/costeo.php',
```

- [ ] **Step 2: Crear `admin/inventory/costeo.php`**

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/inventario.php';

requirePermission('inv_costeo');
$ready = inventarioListo();

$catF = cleanInt($_GET['cat'] ?? 0);
$q    = trim((string)($_GET['q'] ?? ''));
$igv  = (float) getSetting('igv_pct', '18');

$cats = $ready ? Database::fetchAll("SELECT id, name FROM categories ORDER BY sort_order, name") : [];
$ubiPrincipal = $ready ? Database::fetch("SELECT id, nombre FROM ubicaciones WHERE activa=1 ORDER BY es_principal DESC, nombre LIMIT 1") : null;
$locId = $ubiPrincipal['id'] ?? 0;

$platos = [];
$sumFc = 0; $nConFc = 0; $nAlertas = 0;
if ($ready && $locId) {
    $sql = "SELECT p.id, p.name, c.name AS cat,
                   (SELECT lp.price FROM location_products lp WHERE lp.product_id=p.id AND lp.location_id=? LIMIT 1) AS precio
              FROM products p LEFT JOIN categories c ON c.id=p.category_id
             WHERE p.active=1";
    $params = [$locId];
    if ($catF > 0) { $sql .= " AND p.category_id = ?"; $params[] = $catF; }
    if ($q !== '')  { $sql .= " AND p.name LIKE ?";    $params[] = '%' . $q . '%'; }
    $sql .= " ORDER BY p.name";
    foreach (Database::fetchAll($sql, $params) as $p) {
        if (count(recetaComponentes((int)$p['id'])) === 0) continue;   // solo platos con receta
        $costo  = recetaCosto((int)$p['id']);
        $precio = $p['precio'] !== null ? (float)$p['precio'] : null;
        $fc = null;
        if ($precio !== null && $precio > 0) {
            $fc = foodCostCalc($costo, $precio, $igv)['fc'];
            $sumFc += $fc; $nConFc++;
            if ($fc > 0.35) $nAlertas++;
        }
        $platos[] = ['name'=>$p['name'],'cat'=>$p['cat'],'costo'=>$costo,'precio'=>$precio,'fc'=>$fc];
    }
    usort($platos, function ($a, $b) {
        if ($a['fc'] === null && $b['fc'] === null) return strcmp($a['name'], $b['name']);
        if ($a['fc'] === null) return 1;
        if ($b['fc'] === null) return -1;
        return $a['fc'] <=> $b['fc'];
    });
}
$avgFc = $nConFc ? $sumFc / $nConFc : null;

$pageTitle  = 'Costeo';
$activePage = 'inv-costeo';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header"><div class="page-header-left">
  <h1>Costeo</h1>
  <p>Ranking de platos por food cost<?= $ubiPrincipal ? ' · precio en ' . clean($ubiPrincipal['nombre']) : '' ?> (menor food cost = más rentable)</p>
</div></div>

<?php if (!$ready): ?>
  <div class="card"><div class="empty-state"><h3>Falta el módulo de inventario</h3><p>Aplica <code>install/inventario.sql</code>.</p></div></div>
<?php else: ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px">
  <div class="card"><div class="card-body" style="text-align:center">
    <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Food cost promedio</div>
    <div style="font-size:28px;font-weight:800;margin-top:2px;color:<?= $avgFc===null?'var(--text-muted)':($avgFc<=0.35?'#16a34a':($avgFc<=0.42?'#ca8a04':'#dc2626')) ?>">
      <?= $avgFc===null ? '—' : round($avgFc*100) . '%' ?></div>
  </div></div>
  <div class="card"><div class="card-body" style="text-align:center">
    <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Platos con receta</div>
    <div style="font-size:28px;font-weight:800;margin-top:2px"><?= count($platos) ?></div>
  </div></div>
  <div class="card"><div class="card-body" style="text-align:center">
    <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px">Alertas (food cost &gt; 35%)</div>
    <div style="font-size:28px;font-weight:800;margin-top:2px;color:<?= $nAlertas?'#dc2626':'inherit' ?>"><?= $nAlertas ?></div>
  </div></div>
</div>

<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
  <select name="cat" onchange="this.form.submit()" style="padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;background:#fff">
    <option value="0">Todas las categorías</option>
    <?php foreach ($cats as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $catF==$c['id']?'selected':'' ?>><?= clean($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="q" value="<?= clean($q) ?>" placeholder="Buscar plato…" style="padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;min-width:200px">
  <button type="submit" class="btn btn-ghost">Filtrar</button>
  <?php if ($catF || $q !== ''): ?><a href="<?= APP_URL ?>/admin/inventory/costeo.php" class="btn btn-ghost">Limpiar</a><?php endif; ?>
</form>

<div class="card">
  <?php if (empty($platos)): ?>
    <div class="empty-state"><h3>Sin platos con receta</h3><p>Crea recetas en «Recetas y costos» para verlos acá.</p></div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th style="width:40px">#</th><th>Plato</th><th>Categoría</th><th>Costo</th><th>Precio</th><th>Food cost</th><th>Margen</th></tr></thead>
      <tbody>
        <?php foreach ($platos as $i => $p):
          $clase = $p['fc']===null ? '' : fcClase($p['fc']);
          $col = $clase==='ok' ? '#16a34a' : ($clase==='warn' ? '#ca8a04' : ($clase==='bad' ? '#dc2626' : 'var(--text-muted)'));
          $margen = ($p['precio'] !== null && $p['precio'] > 0) ? ($p['precio'] - $p['costo']) : null;
        ?>
        <tr>
          <td style="color:var(--text-muted)"><?= $i+1 ?></td>
          <td><strong><?= clean($p['name']) ?></strong></td>
          <td style="color:var(--text-secondary)"><?= $p['cat'] !== null ? clean($p['cat']) : '—' ?></td>
          <td><?= formatMoney($p['costo']) ?></td>
          <td><?= $p['precio'] !== null ? formatMoney($p['precio']) : '<span style="color:var(--text-muted)">sin precio</span>' ?></td>
          <td><strong style="color:<?= $col ?>"><?= $p['fc']===null ? '—' : round($p['fc']*100) . '%' ?></strong></td>
          <td><?= $margen !== null ? formatMoney($margen) : '<span style="color:var(--text-muted)">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:12px 16px;border-top:1px solid var(--border);font-size:12px;color:var(--text-muted)">
    Food cost: <span style="color:#16a34a;font-weight:700">≤35% bueno</span> · <span style="color:#ca8a04;font-weight:700">36–42% cuidado</span> · <span style="color:#dc2626;font-weight:700">&gt;42% revisar</span>. Costo teórico de la receta; precio del local principal.
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 3: Sidebar — condición del grupo + link en `admin/layout-top.php`**

(a) En la condición que abre el grupo Inventario (el `<?php if (can('inv_insumos') || ... || can('inv_evento')): ?>`), agregar `|| can('inv_costeo')` al final de la cadena.

(b) Dentro del grupo Inventario, **después** del link de "Subrecetas" (su bloque `<?php if (can('inv_recetas')): ?>...<?php endif; ?>`), agregar:

```php
        <?php if (can('inv_costeo')): ?>
        <a href="<?php echo APP_URL; ?>/admin/inventory/costeo.php"
           class="nav-link <?php echo ($activePage??'')==='inv-costeo'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18M7 14l4-4 3 3 5-6"/></svg></span> Costeo
        </a>
        <?php endif; ?>
```

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/inventory/costeo.php && php -l includes/permissions.php && php -l admin/layout-top.php`
Expected: los tres `No syntax errors detected`.

- [ ] **Step 5: Verificación funcional (checklist, sin DB local)**

- `costeo.php` gateada por `requirePermission('inv_costeo')`, solo lectura (sin POST).
- El ranking ordena por food cost ascendente; platos sin precio caen al final con "sin precio" y NO entran al promedio ni a alertas.
- KPIs: promedio de food cost (solo platos con precio), nº platos con receta, alertas con `fc>0.35`.
- Semáforo vía `fcClase()` consistente con el editor (≤0.35 / ≤0.42).
- Filtros (categoría/búsqueda) por GET con `?` en el SQL.
- `inv_costeo` aparece en el form de usuarios (grupo Inventario) y en el sidebar; la plantilla "inventario" lo incluye.

- [ ] **Step 6: Commit**

```bash
git add admin/inventory/costeo.php includes/permissions.php admin/layout-top.php
git commit -m "feat(costeo): dashboard de costeo (ranking food cost + KPIs + alertas) bajo permiso inv_costeo"
```

---

## Self-Review

- Dashboard solo lectura con KPIs + ranking + semáforo + filtros → Step 2. ✓
- Permiso nuevo `inv_costeo` en grupo Inventario + template + path + sidebar → Steps 1, 3. ✓
- Precio del local principal, "sin precio" excluido de promedio/alertas → Step 2. ✓
- IGV de `getSetting`; food cost vía `foodCostCalc`/`fcClase` (mismos umbrales que el editor) → Step 2. ✓
- Sin placeholders; SQL parametrizado; sin emojis. ✓
- Type consistency: `foodCostCalc(...)['fc']` es fracción; `fcClase(fracción)`; `round(fc*100)` para mostrar. ✓
