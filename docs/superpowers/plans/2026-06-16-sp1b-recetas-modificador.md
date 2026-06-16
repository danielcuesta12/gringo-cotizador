# SP1b · Recetas de modificador — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans. Steps usan checkbox (`- [ ]`).

**Goal:** Asignar insumos a cada opción de modificador ("doble carne" = +1 carne, "+queso" = +20 g) desde la ventana de modificadores, con el mismo buscador/creador al vuelo.

**Architecture:** Las opciones de modificador hoy se guardan con DELETE+INSERT (IDs cambian). Primero se refactoriza ese guardado a **upsert con IDs estables** (prerrequisito), luego cada opción guardada tiene un botón que abre un modal-editor de su receta (`receta_modificadores`), reusando `api/insumos.php` (buscar/crear) + dos acciones nuevas (cargar/guardar receta del modificador).

**Tech Stack:** PHP 8 + MySQL/PDO, JS vanilla (fetch). **Sin tests** → `php -l` + prueba manual.

**Spec maestro:** `docs/superpowers/specs/2026-06-16-liquidacion-evento-design.md` (SP1b). La tabla `receta_modificadores` ya existe (migración 44).

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `admin/modifiers/form.php` | Guardado de opciones por **upsert** (IDs estables) + botón/modal de insumos por opción | Modificar |
| `api/insumos.php` | Acciones `receta_mod_get` / `receta_mod_save` + permitir `can('modifiers')` | Modificar |

---

## Task 1: Guardar opciones por upsert (IDs estables)

**Files:**
- Modify: `admin/modifiers/form.php` (bloque de sincronización de opciones ~L53-57; render de filas ~L128-136; JS `addOpt` ~L154-160)

- [ ] **Step 1: Añadir `opt_id[]` al render de cada fila**

En el render de `.opt-row` (dentro del `foreach ($opciones as $o)`), añade como primer hijo un hidden con el id:
```php
          <div class="opt-row">
            <input type="hidden" name="opt_id[]" value="<?= (int)($o['id'] ?? 0) ?>">
            <input type="text" name="opt_nombre[]" class="opt-n" value="<?= clean($o['nombre']) ?>" placeholder="Ej: Mayonesa de la casa">
```
(El resto de la fila queda igual.)

- [ ] **Step 2: Reemplazar el DELETE+INSERT por upsert**

Reemplaza el bloque de sincronización de opciones (hoy: `Database::execute("DELETE FROM modificadores WHERE grupo_id = ?", [$gid]); foreach ($opciones ...) INSERT ...`) por:
```php
        // Upsert de opciones (IDs estables → no rompe receta_modificadores)
        $optIds = $_POST['opt_id'] ?? [];
        $optNom = $_POST['opt_nombre'] ?? [];
        $optPre = $_POST['opt_precio'] ?? [];
        $kept = [];
        foreach ($optNom as $i => $nombre) {
            $nombre = clean($nombre);
            if ($nombre === '') continue;
            $precio = max(0, cleanFloat($optPre[$i] ?? 0));
            $oid = (int)($optIds[$i] ?? 0);
            if ($oid > 0) {
                Database::execute(
                    "UPDATE modificadores SET nombre=?, precio_adicional=?, orden=? WHERE id=? AND grupo_id=?",
                    [$nombre, $precio, $i, $oid, $gid]
                );
                $kept[] = $oid;
            } else {
                $kept[] = (int) Database::insert(
                    "INSERT INTO modificadores (grupo_id,nombre,precio_adicional,orden) VALUES (?,?,?,?)",
                    [$gid, $nombre, $precio, $i]
                );
            }
        }
        // Borrar las opciones que ya no están (y su receta de insumos)
        $existentes = Database::fetchAll("SELECT id FROM modificadores WHERE grupo_id=?", [$gid]);
        foreach ($existentes as $e) {
            if (!in_array((int)$e['id'], $kept, true)) {
                Database::execute("DELETE FROM receta_modificadores WHERE modificador_id=?", [(int)$e['id']]);
                Database::execute("DELETE FROM modificadores WHERE id=?", [(int)$e['id']]);
            }
        }
```
(Para grupo nuevo, `$gid` es el id recién insertado y todas las opciones entran por INSERT — funciona igual.)

- [ ] **Step 3: `addOpt()` debe resetear el `opt_id` del clon a vacío**

El `addOpt()` actual clona la primera fila y vacía todos los inputs (`clone.querySelectorAll('input').forEach(i=>i.value='')`), lo cual ya deja `opt_id` en '' → `(int)` 0 → nueva opción. **Verifica** que ese reset siga aplicando a todos los inputs (incluido el hidden). Si el reset excluyera hidden, ajústalo para incluirlo.

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/modifiers/form.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Verificación manual**

Editar un grupo existente: cambiar el nombre de una opción y guardar → la opción conserva su id (verificable porque su receta de insumos, si la tiene, no se pierde). Agregar y quitar opciones funciona igual que antes.

- [ ] **Step 6: Commit**

```bash
git add admin/modifiers/form.php
git commit -m "refactor(modifiers): guardar opciones por upsert (IDs estables) para colgar recetas de modificador"
```

---

## Task 2: API — cargar y guardar la receta de un modificador

**Files:**
- Modify: `api/insumos.php` (ampliar permiso + 2 acciones nuevas)

- [ ] **Step 1: Permitir el permiso `modifiers`**

Cambia la línea de permisos:
```php
if (!can('inv_recetas') && !can('inv_insumos')) { echo json_encode(['ok'=>false,'error'=>'Sin permisos']); exit; }
```
por:
```php
if (!can('inv_recetas') && !can('inv_insumos') && !can('modifiers')) { echo json_encode(['ok'=>false,'error'=>'Sin permisos']); exit; }
```

- [ ] **Step 2: Añadir las acciones `receta_mod_get` y `receta_mod_save`**

Antes de la línea final `echo json_encode(['ok'=>false,'error'=>'Acción inválida']);`, añade:
```php
if ($action === 'receta_mod_get') {
    $mid = cleanInt($_GET['modificador_id'] ?? 0);
    $rows = Database::fetchAll(
        "SELECT rm.insumo_id, rm.cantidad, i.nombre, i.unidad, i.costo_unitario
           FROM receta_modificadores rm JOIN insumos i ON i.id = rm.insumo_id
          WHERE rm.modificador_id = ? ORDER BY i.nombre",
        [$mid]
    );
    echo json_encode(['ok'=>true, 'items'=>$rows]);
    exit;
}

if ($action === 'receta_mod_save') {
    verifyCsrf();
    $mid = cleanInt($_POST['modificador_id'] ?? 0);
    if ($mid <= 0) { echo json_encode(['ok'=>false,'error'=>'Modificador inválido']); exit; }
    $ins  = $_POST['insumo_id'] ?? [];
    $cant = $_POST['cantidad'] ?? [];
    Database::execute("DELETE FROM receta_modificadores WHERE modificador_id = ?", [$mid]);
    $seen = [];
    foreach ($ins as $idx => $iid) {
        $iid = (int)$iid; $c = (float)($cant[$idx] ?? 0);
        if ($iid <= 0 || $c <= 0 || isset($seen[$iid])) continue;
        $seen[$iid] = true;
        Database::insert("INSERT INTO receta_modificadores (modificador_id,insumo_id,cantidad) VALUES (?,?,?)", [$mid, $iid, $c]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l api/insumos.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add api/insumos.php
git commit -m "feat(inventario): API receta de modificador (cargar/guardar) + permiso modifiers"
```

---

## Task 3: Botón + modal de insumos por opción de modificador

**Files:**
- Modify: `admin/modifiers/form.php` (botón por fila + modal + JS; CSS en `$extraHead`)

- [ ] **Step 1: Botón "Insumos" por opción (solo si ya está guardada)**

En el render de `.opt-row`, después del botón de borrar (`.opt-del`), añade un botón que abre el editor de insumos — solo si la opción tiene id (`$o['id']`):
```php
            <?php if (!empty($o['id'])): ?>
            <button type="button" class="opt-ins" onclick="modIns(<?= (int)$o['id'] ?>, this)" title="Insumos que consume">🧪 Insumos</button>
            <?php endif; ?>
```
(Las opciones nuevas sin guardar no muestran el botón; al guardar el grupo obtienen id y aparece.)

- [ ] **Step 2: Modal de receta de modificador**

Antes de `layout-bottom`, añade:
```php
<div id="mi-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.5);z-index:60;align-items:center;justify-content:center;padding:18px">
  <div style="width:420px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden;max-height:90vh;display:flex;flex-direction:column">
    <div style="background:#fafafb;padding:13px 16px;border-bottom:1px solid var(--border,#eee);font-weight:800">Insumos del adicional</div>
    <div style="padding:16px;overflow-y:auto">
      <div id="mi-rows"></div>
      <div style="position:relative;margin-top:8px">
        <input type="text" id="mi-add" autocomplete="off" placeholder="🔍 Agregar insumo (busca o crea)…" oninput="miBuscar(this.value)" onfocus="miBuscar(this.value)" style="width:100%;padding:11px 13px;border:1.5px dashed #c9c9d2;border-radius:10px">
        <div id="mi-drop" style="display:none;position:absolute;left:0;right:0;top:48px;background:#fff;border:1px solid #eee;border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:70;overflow:hidden"></div>
      </div>
    </div>
    <div style="display:flex;gap:8px;padding:14px 16px;border-top:1px solid var(--border,#eee)">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="document.getElementById('mi-ov').style.display='none'">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="miGuardar()">Guardar insumos</button>
    </div>
  </div>
</div>
<!-- mini-modal crear insumo (reusa el de receta) -->
<div id="ins-ov" style="display:none;position:fixed;inset:0;background:rgba(15,15,20,.55);z-index:80;align-items:center;justify-content:center;padding:18px">
  <div style="width:340px;max-width:100%;background:#fff;border-radius:14px;overflow:hidden">
    <div style="background:#fafafb;padding:13px 16px;border-bottom:1px solid #eee;font-weight:800">Crear insumo: «<span id="ins-name"></span>»</div>
    <div style="padding:16px">
      <div class="form-group"><label>Unidad</label><select id="ins-unidad"><option value="unidad">unidad</option><option value="g">g</option><option value="ml">ml</option><option value="kg">kg</option><option value="l">l</option><option value="lonja">lonja</option><option value="porcion">porción</option></select></div>
      <div class="form-group"><label>Tipo</label><select id="ins-tipo"><option value="ingrediente">Ingrediente</option><option value="descartable">Descartable / papelería</option></select></div>
      <div class="form-group"><label>Costo (opcional)</label><input id="ins-costo" inputmode="decimal" placeholder="0.00"></div>
    </div>
    <div style="display:flex;gap:8px;padding:0 16px 16px">
      <button type="button" class="btn btn-ghost" style="flex:1" onclick="document.getElementById('ins-ov').style.display='none'">Cancelar</button>
      <button type="button" class="btn btn-primary" style="flex:1" onclick="insCrear()">Crear y agregar</button>
    </div>
  </div>
</div>
```

- [ ] **Step 3: JS del editor de insumos del modificador**

Añade al `<script>` del archivo (junto a `addOpt`):
```html
<script>
const INS_API = '<?= APP_URL ?>/api/insumos.php';
const CSRF = '<?= csrfToken() ?>';
let miMid = 0, insPend = '';
function modIns(mid){
  miMid = mid;
  document.getElementById('mi-rows').innerHTML = '';
  document.getElementById('mi-add').value='';
  fetch(INS_API + '?action=receta_mod_get&modificador_id=' + mid)
    .then(r=>r.json()).then(d=>{ (d.items||[]).forEach(i=> miAgregar(i.insumo_id, i.nombre, i.unidad, i.cantidad)); });
  document.getElementById('mi-ov').style.display='flex';
}
function miBuscar(q){
  q=(q||'').trim(); const drop=document.getElementById('mi-drop');
  if(!q){ drop.style.display='none'; return; }
  fetch(INS_API+'?action=buscar&q='+encodeURIComponent(q)).then(r=>r.json()).then(d=>{
    let html=(d.items||[]).map(i=>`<div class="rec-opt" onclick="miAgregar(${i.id},'${i.nombre.replace(/'/g,"\\'")}','${i.unidad}',1)"><span>${i.nombre}</span><span class="rec-u">${i.unidad}</span></div>`).join('');
    const exacto=(d.items||[]).some(i=>i.nombre.toLowerCase()===q.toLowerCase());
    if(!exacto) html+=`<div class="rec-opt rec-create" onclick="insAbrir('${q.replace(/'/g,"\\'")}')">+ Crear «${q}»</div>`;
    drop.innerHTML=html; drop.style.display='block';
  });
}
function miAgregar(id, nombre, unidad, cant){
  if(document.querySelector('#mi-rows input[data-iid="'+id+'"]')){ document.getElementById('mi-drop').style.display='none'; document.getElementById('mi-add').value=''; return; }
  const row=document.createElement('div'); row.className='rec-row';
  row.innerHTML='<span class="rec-nm">'+nombre+'</span>'+
    '<input type="hidden" data-iid="'+id+'">'+
    '<input type="text" inputmode="decimal" class="rec-q mi-q" value="'+(cant||1)+'">'+
    '<span class="rec-u">'+unidad+'</span>'+
    '<button type="button" class="rec-del" onclick="this.closest(\'.rec-row\').remove()">✕</button>';
  document.getElementById('mi-rows').appendChild(row);
  document.getElementById('mi-add').value=''; document.getElementById('mi-drop').style.display='none';
}
function miGuardar(){
  const body=new URLSearchParams(); body.append('action','receta_mod_save'); body.append('modificador_id', miMid);
  document.querySelectorAll('#mi-rows .rec-row').forEach(row=>{
    body.append('insumo_id[]', row.querySelector('input[data-iid]').dataset.iid);
    body.append('cantidad[]', row.querySelector('.mi-q').value||'0');
  });
  fetch(INS_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body})
    .then(r=>r.json()).then(d=>{ if(d.ok){ document.getElementById('mi-ov').style.display='none'; } else alert(d.error||'Error'); });
}
function insAbrir(nombre){ insPend=nombre; document.getElementById('ins-name').textContent=nombre; document.getElementById('mi-drop').style.display='none'; document.getElementById('ins-ov').style.display='flex'; }
function insCrear(){
  const body=new URLSearchParams({action:'crear',nombre:insPend,unidad:document.getElementById('ins-unidad').value,tipo:document.getElementById('ins-tipo').value,costo_unitario:document.getElementById('ins-costo').value||'0'});
  fetch(INS_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body})
    .then(r=>r.json()).then(d=>{ if(d.ok){ miAgregar(d.insumo.id,d.insumo.nombre,d.insumo.unidad,1); document.getElementById('ins-costo').value=''; document.getElementById('ins-ov').style.display='none'; } else alert(d.error||'Error'); });
}
document.addEventListener('click', e=>{ if(!e.target.closest('#mi-add') && !e.target.closest('#mi-drop')){ const d=document.getElementById('mi-drop'); if(d) d.style.display='none'; } });
</script>
```

- [ ] **Step 4: CSS**

En `$extraHead` añade (reusando estilo del editor de receta):
```css
.opt-ins{background:#eef0ff;border:none;color:#3a40a0;font-size:12px;font-weight:800;border-radius:8px;padding:6px 10px;cursor:pointer;flex-shrink:0}
.rec-row{display:flex;gap:8px;align-items:center;margin-bottom:8px;background:#fafafb;border:1px solid #eee;border-radius:10px;padding:8px 10px}
.rec-nm{flex:1;font-weight:700} .rec-q{width:80px;text-align:right;padding:7px;border:1.5px solid #e7e7ec;border-radius:8px} .rec-u{width:42px;font-size:12px;color:#888}
.rec-del{background:none;border:none;color:#dc2626;cursor:pointer} .rec-opt{padding:10px 13px;cursor:pointer;display:flex;justify-content:space-between} .rec-opt:hover{background:#fffbe9} .rec-create{color:#1f9d55;font-weight:800;border-top:1px dashed #eee}
```

- [ ] **Step 5: Verificar sintaxis**

Run: `php -l admin/modifiers/form.php`
Expected: `No syntax errors detected`.

- [ ] **Step 6: Verificación manual**

En un grupo de adicionales con opciones guardadas: clic en "🧪 Insumos" de una opción → modal carga su receta (si tiene) → buscar/crear insumo + cantidad → Guardar insumos → reabrir y verificar que persiste. Crear un insumo nuevo desde aquí aparece luego en Insumos.

- [ ] **Step 7: Commit**

```bash
git add admin/modifiers/form.php
git commit -m "feat(modifiers): asignar insumos a cada opción de modificador (modal con buscar/crear al vuelo)"
```

---

## Verificación final (manual)

- [ ] Editar un grupo de adicionales, cambiar nombres/precios y guardar → las opciones conservan su id (su receta de insumos no se pierde).
- [ ] Asignar insumos a "doble carne" / "+queso"; reabrir y persiste.
- [ ] Crear un insumo nuevo desde el modal del modificador → queda en Insumos, reutilizable.
- [ ] Quitar una opción del grupo → se borra y se borra su `receta_modificadores`.
- [ ] Permiso: un usuario con `modifiers` (no-admin) puede usar el modal (la API ahora acepta `can('modifiers')`).
