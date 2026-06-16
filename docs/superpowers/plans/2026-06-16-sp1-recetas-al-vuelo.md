# SP1 · Recetas al vuelo + tipo de insumo — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`).

**Goal:** Editar la receta de un producto agregando insumos con un buscador en vivo que permite crear el insumo al instante (unidad + tipo + costo opcional), y clasificar cada insumo como ingrediente o descartable.

**Architecture:** Una API JSON nueva (`api/insumos.php`) para buscar/crear insumos; el editor de receta (`receta_form.php`) se reconstruye con autocompletado + mini-modal de creación; `insumos` gana columna `tipo`. Las **recetas de modificador** quedan fuera de SP1 (van en SP1b porque requieren refactorizar el guardado de opciones de modificador a IDs estables).

**Tech Stack:** PHP 8 + MySQL/PDO, JS vanilla (fetch). **Sin tests automatizados** → verificación = `php -l` + prueba manual.

**Spec maestro:** `docs/superpowers/specs/2026-06-16-liquidacion-evento-design.md` (SP1). **Mockup:** `docs/superpowers/specs/mockups/receta-insumo-vivo.html`.

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `install/44_insumo_tipo_receta_modificador.sql` | `insumos.tipo` + tabla `receta_modificadores` (esta última se usa en SP1b) | Crear |
| `api/insumos.php` | Buscar y crear insumos (JSON) | Crear |
| `admin/inventory/insumo_form.php` | Campo `tipo` al crear/editar insumo | Modificar |
| `admin/inventory/insumos.php` | Mostrar `tipo` en el listado | Modificar |
| `admin/inventory/receta_form.php` | Editor con autocompletado + mini-modal de creación | Modificar (reescribe el bloque de filas) |

---

## Task 1: Migración — tipo de insumo + tabla de receta de modificador

**Files:**
- Create: `install/44_insumo_tipo_receta_modificador.sql`

- [ ] **Step 1: Crear la migración**

```sql
-- Tipo de insumo: ingrediente (va en recetas → mercadería) o descartable (papelería).
ALTER TABLE insumos
  ADD COLUMN tipo ENUM('ingrediente','descartable') NOT NULL DEFAULT 'ingrediente';

-- Receta de modificador (se usa en SP1b): qué insumos consume cada adicional.
CREATE TABLE IF NOT EXISTS receta_modificadores (
  modificador_id INT UNSIGNED NOT NULL,
  insumo_id      INT UNSIGNED NOT NULL,
  cantidad       DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (modificador_id, insumo_id),
  INDEX idx_rm_insumo (insumo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
(Sin FK a `modificadores` por ahora: las opciones se borran/reinsertan hasta el refactor de SP1b; en SP1b se migra a IDs estables y se puede añadir FK.)

- [ ] **Step 2: Revisión visual** — confirmar el enum y la tabla. Se aplica en phpMyAdmin al desplegar.

- [ ] **Step 3: Commit**

```bash
git add install/44_insumo_tipo_receta_modificador.sql
git commit -m "feat(inventario): migración — tipo de insumo (ingrediente/descartable) + tabla receta_modificadores"
```

---

## Task 2: API de insumos (buscar + crear)

**Files:**
- Create: `api/insumos.php`

- [ ] **Step 1: Crear el endpoint**

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
header('Content-Type: application/json; charset=utf-8');

requireLogin();
if (!can('inv_recetas') && !can('inv_insumos')) { echo json_encode(['ok'=>false,'error'=>'Sin permisos']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'buscar') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { echo json_encode(['ok'=>true,'items'=>[]]); exit; }
    $rows = Database::fetchAll(
        "SELECT id, nombre, unidad, tipo FROM insumos WHERE activo=1 AND nombre LIKE ? ORDER BY nombre LIMIT 12",
        ['%' . $q . '%']
    );
    echo json_encode(['ok'=>true, 'items'=>$rows]);
    exit;
}

if ($action === 'crear') {
    verifyCsrf();  // token por header X-CSRF-Token
    $nombre = clean($_POST['nombre'] ?? '');
    $unidad = clean($_POST['unidad'] ?? 'unidad') ?: 'unidad';
    $tipo   = in_array($_POST['tipo'] ?? '', ['ingrediente','descartable']) ? $_POST['tipo'] : 'ingrediente';
    $costo  = max(0, cleanFloat($_POST['costo_unitario'] ?? 0));
    if ($nombre === '') { echo json_encode(['ok'=>false,'error'=>'Falta el nombre']); exit; }
    // reusar si ya existe uno con el mismo nombre (case-insensitive)
    $exist = Database::fetch("SELECT id, nombre, unidad, tipo FROM insumos WHERE activo=1 AND LOWER(nombre)=LOWER(?) LIMIT 1", [$nombre]);
    if ($exist) { echo json_encode(['ok'=>true, 'insumo'=>$exist, 'reusado'=>true]); exit; }
    $id = Database::insert("INSERT INTO insumos (nombre,unidad,costo_unitario,tipo,activo) VALUES (?,?,?,?,1)", [$nombre,$unidad,$costo,$tipo]);
    echo json_encode(['ok'=>true, 'insumo'=>['id'=>$id,'nombre'=>$nombre,'unidad'=>$unidad,'tipo'=>$tipo]]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Acción inválida']);
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l api/insumos.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add api/insumos.php
git commit -m "feat(inventario): API de insumos (buscar + crear con tipo, reusa por nombre)"
```

---

## Task 3: Campo `tipo` en el form y listado de insumos

**Files:**
- Modify: `admin/inventory/insumo_form.php`
- Modify: `admin/inventory/insumos.php`

- [ ] **Step 1: Añadir `tipo` al guardado del form**

En `admin/inventory/insumo_form.php`, en el array `$data` (POST) añade `'tipo'` y en los defaults también:
- defaults: `$data = $ins ?? ['nombre'=>'','unidad'=>'g','costo_unitario'=>'','activo'=>1,'tipo'=>'ingrediente'];`
- POST: `'tipo' => in_array($_POST['tipo'] ?? '', ['ingrediente','descartable']) ? $_POST['tipo'] : 'ingrediente',`
- UPDATE: añade `,tipo=?` y el valor `$data['tipo']` en su posición.
- INSERT: añade `,tipo` a columnas, `,?` a valores y `$data['tipo']` a params.

- [ ] **Step 2: Añadir el selector al HTML del form**

Junto al campo `unidad`, añade:
```php
        <div class="form-group">
          <label>Tipo</label>
          <select name="tipo">
            <option value="ingrediente" <?= ($data['tipo']??'ingrediente')==='ingrediente'?'selected':'' ?>>Ingrediente (va en recetas)</option>
            <option value="descartable" <?= ($data['tipo']??'')==='descartable'?'selected':'' ?>>Descartable / papelería</option>
          </select>
          <div class="form-hint">Los descartables (cajas, vasos) no se cargan en recetas; se controlan como papelería.</div>
        </div>
```

- [ ] **Step 3: Mostrar `tipo` en el listado**

En `admin/inventory/insumos.php`, agrega la columna `tipo` a la query `SELECT` (si usa `SELECT *` ya viene) y muéstralo como una etiqueta en la tabla (ej. badge "Ingrediente" / "Descartable"). Sigue el estilo de badges que ya use el archivo.

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/inventory/insumo_form.php && php -l admin/inventory/insumos.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add admin/inventory/insumo_form.php admin/inventory/insumos.php
git commit -m "feat(inventario): tipo de insumo (ingrediente/descartable) en form y listado"
```

---

## Task 4: Editor de receta con autocompletado + creación al vuelo

**Files:**
- Modify: `admin/inventory/receta_form.php`

Estado actual: el form lista insumos en un `<select>` por fila (`.rec-row`) con `name="insumo_id[]"` y `name="cantidad[]"`, y al guardar hace DELETE+INSERT en `recetas`. **El guardado POST se conserva igual.** Solo se reemplaza la UI de agregar/editar filas por un buscador en vivo.

- [ ] **Step 1: Reemplazar la UI de filas por autocompletado**

En el HTML, reemplaza el bloque de filas existentes + el control de "agregar fila" por:
```php
<div id="rec-rows">
  <?php foreach ($receta as $r):
      $ins = null; foreach ($insumos as $ix) { if ((int)$ix['id'] === (int)$r['insumo_id']) { $ins = $ix; break; } }
      if (!$ins) continue; ?>
  <div class="rec-row">
    <span class="rec-nm"><?= clean($ins['nombre']) ?></span>
    <input type="hidden" name="insumo_id[]" value="<?= (int)$ins['id'] ?>">
    <input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="<?= nf($r['cantidad']) ?>">
    <span class="rec-u"><?= clean($ins['unidad']) ?></span>
    <button type="button" class="rec-del" onclick="this.closest('.rec-row').remove()">✕</button>
  </div>
  <?php endforeach; ?>
</div>

<div class="add-wrap" style="position:relative;margin-top:8px">
  <input type="text" id="rec-add" autocomplete="off" placeholder="🔍 Agregar insumo (busca o crea)…"
         oninput="recBuscar(this.value)" onfocus="recBuscar(this.value)"
         style="width:100%;padding:11px 13px;border:1.5px dashed #c9c9d2;border-radius:10px">
  <div id="rec-drop" class="rec-drop" style="display:none;position:absolute;left:0;right:0;top:48px;background:#fff;border:1px solid var(--border,#eee);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:30;overflow:hidden"></div>
</div>
```

- [ ] **Step 2: Añadir el mini-modal de creación**

Antes de `layout-bottom`:
```php
<div id="ins-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.5);z-index:50;align-items:center;justify-content:center;padding:18px">
  <div style="width:340px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden">
    <div style="background:#fafafb;padding:13px 16px;border-bottom:1px solid var(--border,#eee);font-weight:800;color:var(--navy,#1B1F4B)">Crear insumo: «<span id="ins-name"></span>»</div>
    <div style="padding:16px">
      <div class="form-group"><label>Unidad</label>
        <select id="ins-unidad"><option value="unidad">unidad</option><option value="g">gramos (g)</option><option value="ml">ml</option><option value="kg">kg</option><option value="l">l</option><option value="lonja">lonja</option><option value="porcion">porción</option></select></div>
      <div class="form-group"><label>Tipo</label>
        <select id="ins-tipo"><option value="ingrediente">Ingrediente</option><option value="descartable">Descartable / papelería</option></select></div>
      <div class="form-group"><label>Costo por unidad (opcional)</label><input id="ins-costo" inputmode="decimal" placeholder="0.00"></div>
    </div>
    <div style="display:flex;gap:8px;padding:0 16px 16px">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="insCerrar()">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="insCrear()">Crear y agregar</button>
    </div>
  </div>
</div>
```

- [ ] **Step 3: Añadir el JS (autocompletado + crear + agregar fila)**

En `$extraScripts` (o un `<script>` al final, como haga el archivo):
```html
<script>
const INS_API = '<?= APP_URL ?>/api/insumos.php';
const CSRF = '<?= csrfToken() ?>';
let insPend = '';
function recBuscar(q){
  q = (q||'').trim();
  const drop = document.getElementById('rec-drop');
  if(!q){ drop.style.display='none'; return; }
  fetch(INS_API + '?action=buscar&q=' + encodeURIComponent(q))
    .then(r=>r.json()).then(d=>{
      let html = (d.items||[]).map(i =>
        `<div class="rec-opt" onclick="recAgregar(${i.id},'${i.nombre.replace(/'/g,"\\'")}','${i.unidad}')">`+
        `<span>${i.nombre}</span><span class="rec-u">${i.unidad}</span></div>`).join('');
      const exacto = (d.items||[]).some(i => i.nombre.toLowerCase() === q.toLowerCase());
      if(!exacto){ html += `<div class="rec-opt rec-create" onclick="insAbrir('${q.replace(/'/g,"\\'")}')">+ Crear «${q}»</div>`; }
      drop.innerHTML = html; drop.style.display = 'block';
    });
}
function recAgregar(id, nombre, unidad){
  if (document.querySelector('input[name="insumo_id[]"][value="'+id+'"]')) { document.getElementById('rec-drop').style.display='none'; document.getElementById('rec-add').value=''; return; }
  const row = document.createElement('div'); row.className='rec-row';
  row.innerHTML = '<span class="rec-nm">'+nombre+'</span>'+
    '<input type="hidden" name="insumo_id[]" value="'+id+'">'+
    '<input type="text" inputmode="decimal" name="cantidad[]" class="rec-q" value="1">'+
    '<span class="rec-u">'+unidad+'</span>'+
    '<button type="button" class="rec-del" onclick="this.closest(\'.rec-row\').remove()">✕</button>';
  document.getElementById('rec-rows').appendChild(row);
  document.getElementById('rec-add').value=''; document.getElementById('rec-drop').style.display='none';
}
function insAbrir(nombre){ insPend=nombre; document.getElementById('ins-name').textContent=nombre; document.getElementById('rec-drop').style.display='none'; document.getElementById('ins-ov').style.display='flex'; }
function insCerrar(){ document.getElementById('ins-ov').style.display='none'; }
function insCrear(){
  const body = new URLSearchParams({action:'crear', nombre:insPend, unidad:document.getElementById('ins-unidad').value, tipo:document.getElementById('ins-tipo').value, costo_unitario:document.getElementById('ins-costo').value||'0'});
  fetch(INS_API, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF}, body})
    .then(r=>r.json()).then(d=>{ if(d.ok){ recAgregar(d.insumo.id, d.insumo.nombre, d.insumo.unidad); document.getElementById('ins-costo').value=''; insCerrar(); } else { alert(d.error||'No se pudo crear'); } });
}
document.addEventListener('click', e=>{ if(!e.target.closest('.add-wrap')) { const d=document.getElementById('rec-drop'); if(d) d.style.display='none'; } });
</script>
```
Añade CSS mínimo (en `$extraHead`): `.rec-nm{flex:1;font-weight:700} .rec-opt{padding:10px 13px;cursor:pointer;display:flex;justify-content:space-between} .rec-opt:hover{background:#fffbe9} .rec-create{color:#1f9d55;font-weight:800;border-top:1px dashed #eee}` (y conserva las clases `.rec-row/.rec-q/.rec-u/.rec-del` existentes).

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/inventory/receta_form.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Verificación manual**

Editar receta de un producto: escribir un insumo existente (aparece coincidencia → se agrega), escribir uno nuevo (→ "Crear «X»" → mini-modal unidad/tipo/costo → se crea y agrega como fila). Guardar → la receta persiste; el insumo nuevo aparece en la pestaña Insumos.

- [ ] **Step 6: Commit**

```bash
git add admin/inventory/receta_form.php
git commit -m "feat(inventario): editor de receta con buscar/crear insumo al vuelo (unidad, tipo, costo opcional)"
```

---

## Verificación final (manual)

- [ ] Aplicar `install/44_insumo_tipo_receta_modificador.sql` en phpMyAdmin.
- [ ] Crear insumo desde el editor de receta (no hace falta ir antes a Insumos) → queda reutilizable y en la pestaña Insumos con su tipo.
- [ ] Buscar insumo existente y agregarlo; no permitir duplicado en la misma receta.
- [ ] Marcar un insumo como descartable (form de insumos) y verlo en el listado.
- [ ] Guardar receta y reabrir → las cantidades persisten.
- [ ] Permisos: el editor sigue gateado por `inv_recetas`; la API exige login + `inv_recetas`/`inv_insumos`.

## Nota de alcance (SP1b — siguiente)
Las **recetas de modificador** (asignar insumos a "doble carne", "+queso" en la ventana de modificadores) NO entran en SP1: requieren refactorizar el guardado de opciones de modificador (hoy DELETE+INSERT → IDs cambian) a upsert con IDs estables, para luego colgar `receta_modificadores`. Se planifica como SP1b reutilizando esta misma API (`api/insumos.php`) y el mismo editor.
