# SP2 · Planificación por productos → requerimiento consolidado — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) o superpowers:executing-plans. Steps usan checkbox (`- [ ]`).

**Goal:** Mejorar la "Salida a evento" para planificar por productos **con modificadores**, quitar ingredientes por producto ("sin tocino"), agregar **descartables sueltos**, y producir un requerimiento consolidado revisable antes de descontar del stock.

**Architecture:** Todo el explosionado vive en el JS de `admin/inventory/salida_evento.php`. SP2 amplía: (1) el PHP carga también las recetas de modificador y qué modificadores aplican a cada producto; (2) el paso 1 permite elegir modificadores y excluir ingredientes por línea de producto; (3) `calcular()` explota producto + modificadores − exclusiones; (4) el paso 2 permite sumar insumos sueltos (descartables) con el buscador al vuelo de SP1. El guardado (descontar stock) se conserva. **El vínculo a un evento y el inventario inicial son SP3** — SP2 termina en el descuento de stock como hoy.

**Tech Stack:** PHP 8 + JS vanilla. **Sin tests** → `php -l` + prueba manual.

**Spec maestro:** `docs/superpowers/specs/2026-06-16-liquidacion-evento-design.md` (SP2). Reusa `api/insumos.php` (buscar/crear) de SP1.

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `admin/inventory/salida_evento.php` | Datos PHP (modificadores+recetas), UI paso 1 (modificadores + exclusiones), explosión, paso 2 (sueltos) | Modificar |

Estado actual (referencia): el archivo carga `$products`, `$insumos` (id,nombre,unidad,costo), `$recetas` (producto→insumo). JS: `evProds=[{pid,qty}]`, `addProd()`, `calcular()` (agrega `RECETAS[pid]`), `sumCost()`, confirma vía POST `insumo_id[]`/`cantidad[]` → `invMovimiento('evento', -c)`.

---

## Task 1: Backend — cargar modificadores, sus recetas y el mapa producto→modificadores

**Files:**
- Modify: `admin/inventory/salida_evento.php` (zona de carga de datos ~L31-39)

- [ ] **Step 1: Cargar datos extra y pasarlos al JS**

Tras la carga de `$recetas`, añade:
```php
// Insumos con tipo (para distinguir descartables en el buscador/paso 2)
$insumos  = Database::fetchAll("SELECT id,nombre,unidad,costo_unitario,tipo FROM insumos WHERE activo=1 ORDER BY nombre");

// Recetas de modificador: modificador_id -> [{insumo_id,cantidad}]
$recMod = [];
try {
    foreach (Database::fetchAll("SELECT modificador_id,insumo_id,cantidad FROM receta_modificadores") as $r) {
        $recMod[(int)$r['modificador_id']][] = ['insumo_id'=>(int)$r['insumo_id'],'cantidad'=>(float)$r['cantidad']];
    }
} catch (\Throwable $e) { $recMod = []; }

// Modificadores aplicables por producto: product_id -> [{id,nombre}] (solo los que tienen receta)
$modsByProd = [];
try {
    $rows = Database::fetchAll(
        "SELECT pmg.product_id, m.id, m.nombre
           FROM product_modifier_groups pmg
           JOIN modificadores m ON m.grupo_id = pmg.grupo_id
          WHERE EXISTS (SELECT 1 FROM receta_modificadores rm WHERE rm.modificador_id = m.id)
          ORDER BY m.nombre"
    );
    foreach ($rows as $r) { $modsByProd[(int)$r['product_id']][] = ['id'=>(int)$r['id'],'nombre'=>$r['nombre']]; }
} catch (\Throwable $e) { $modsByProd = []; }
```
(El `costo_unitario` de la línea `$insumos` ya existía; solo se le agrega `,tipo`. Si ya tiene su propia query, añade `tipo` ahí.)

- [ ] **Step 2: Exponer al JS**

Donde se imprimen `RECETAS`/`INSUMOS` en el `<script>`, añade:
```php
  var RECETAS_MOD = <?= json_encode($recMod) ?>;
  var MODS_BY_PROD = <?= json_encode($modsByProd) ?>;
```
Y en `INSUMOS` (mapa `$insMap`) incluye el tipo: al construir `$insMap`, agrega `'tipo'=>$i['tipo']`.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/inventory/salida_evento.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add admin/inventory/salida_evento.php
git commit -m "feat(salida-evento): cargar recetas de modificador y modificadores por producto"
```

---

## Task 2: Paso 1 — elegir modificadores y excluir ingredientes por línea

**Files:**
- Modify: `admin/inventory/salida_evento.php` (UI paso 1 + `evProds`/`addProd`/`renderProds`)

- [ ] **Step 1: La línea de producto guarda modificadores y exclusiones**

`evProds` pasa de `[{pid,qty}]` a `[{pid,qty,mods:[],excl:[]}]` (`mods` = ids de modificador seleccionados; `excl` = insumo_ids excluidos de la receta del producto). Reemplaza `addProd()`:
```js
function addProd(){
  var pid = parseInt(document.getElementById('prodSel').value);
  var qty = parseFloat(document.getElementById('prodQty').value) || 0;
  if (!pid || qty <= 0) return;
  evProds.push({ pid: pid, qty: qty, mods: [], excl: [] });  // cada línea es independiente (permite "50 con doble carne" aparte)
  renderProds();
}
```
(Ya NO se fusiona con líneas iguales: una línea "50 gringos sin tocino" es distinta de "50 gringos normales".)

- [ ] **Step 2: Render con modificadores (checkbox) y exclusión de ingredientes**

Reemplaza `renderProds()` para que cada línea muestre, debajo del nombre, los modificadores disponibles del producto (de `MODS_BY_PROD[pid]`) como checkboxes que togglean `x.mods`, y un botón "ajustar" que despliega los ingredientes de la receta (`RECETAS[pid]`) con checkboxes para excluir (togglean `x.excl`):
```js
function rmProd(idx){ evProds.splice(idx,1); renderProds(); }
function toggleMod(idx, mid, on){ var a=evProds[idx].mods; if(on){ if(a.indexOf(mid)<0)a.push(mid);} else { evProds[idx].mods=a.filter(function(m){return m!==mid;}); } }
function toggleExcl(idx, iid, on){ var a=evProds[idx].excl; if(on){ if(a.indexOf(iid)<0)a.push(iid);} else { evProds[idx].excl=a.filter(function(x){return x!==iid;}); } }
function renderProds(){
  var el = document.getElementById('prodList');
  if (!evProds.length){ el.innerHTML = '<p style="color:var(--text-muted);font-size:13px;margin:0">Sin productos aún.</p>'; return; }
  el.innerHTML = evProds.map(function(x, idx){
    var sinReceta = !RECETAS[x.pid] ? ' <span style="color:#dc2626;font-size:11px">(sin receta)</span>' : '';
    var html = '<div style="padding:8px 0;border-bottom:1px solid var(--border)">';
    html += '<div style="display:flex;justify-content:space-between;align-items:center;font-size:14px">'
      + '<span><strong>'+x.qty+'×</strong> '+(PRODNAMES[x.pid]||('#'+x.pid))+sinReceta+'</span>'
      + '<button type="button" onclick="rmProd('+idx+')" style="background:none;border:none;color:#dc2626;cursor:pointer">✕</button></div>';
    // modificadores disponibles
    var mods = MODS_BY_PROD[x.pid] || [];
    if (mods.length){
      html += '<div style="margin-top:5px;display:flex;flex-wrap:wrap;gap:10px">' + mods.map(function(m){
        var chk = x.mods.indexOf(m.id)>=0 ? 'checked' : '';
        return '<label style="font-size:12px;display:flex;gap:4px;align-items:center"><input type="checkbox" '+chk+' onchange="toggleMod('+idx+','+m.id+',this.checked)"> +'+m.nombre+'</label>';
      }).join('') + '</div>';
    }
    // exclusiones (ingredientes de la receta del producto)
    var rec = RECETAS[x.pid] || [];
    if (rec.length){
      html += '<details style="margin-top:5px"><summary style="font-size:12px;color:var(--text-muted);cursor:pointer">Quitar ingredientes</summary><div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:5px">' + rec.map(function(r){
        var info = INSUMOS[r.insumo_id] || {nombre:'#'+r.insumo_id};
        var chk = x.excl.indexOf(r.insumo_id)>=0 ? 'checked' : '';
        return '<label style="font-size:12px;display:flex;gap:4px;align-items:center"><input type="checkbox" '+chk+' onchange="toggleExcl('+idx+','+r.insumo_id+',this.checked)"> sin '+info.nombre+'</label>';
      }).join('') + '</div></details>';
    }
    html += '</div>';
    return html;
  }).join('');
}
```

- [ ] **Step 2.5: Verificar sintaxis + commit**

Run: `php -l admin/inventory/salida_evento.php` → sin errores.
```bash
git add admin/inventory/salida_evento.php
git commit -m "feat(salida-evento): modificadores y exclusión de ingredientes por línea de producto"
```

---

## Task 3: Explosión — producto + modificadores − exclusiones

**Files:**
- Modify: `admin/inventory/salida_evento.php` (`calcular()`)

- [ ] **Step 1: Reemplazar el agregado en `calcular()`**

La parte que arma `agg` (insumo_id → cantidad) debe sumar, por cada línea: la receta del producto (sin los `excl`) + la receta de cada modificador seleccionado, todo × `qty`:
```js
  var agg = {};
  evProds.forEach(function(x){
    (RECETAS[x.pid] || []).forEach(function(r){
      if (x.excl.indexOf(r.insumo_id) >= 0) return;          // ingrediente excluido para esta línea
      agg[r.insumo_id] = (agg[r.insumo_id] || 0) + r.cantidad * x.qty;
    });
    (x.mods || []).forEach(function(mid){
      (RECETAS_MOD[mid] || []).forEach(function(r){
        agg[r.insumo_id] = (agg[r.insumo_id] || 0) + r.cantidad * x.qty;
      });
    });
  });
```
El resto de `calcular()` (render del paso 2, `ingFoot`, `fUbi`/`fRef`, `sumCost`) se conserva.

- [ ] **Step 2: Verificar sintaxis + prueba manual**

Run: `php -l admin/inventory/salida_evento.php` → sin errores.
Manual: agregar "10 gringos" con "doble carne" marcado → al calcular, la carne suma receta + modificador × 10. Marcar "sin tocino" en otra línea → ese ingrediente no aparece para esa línea.

- [ ] **Step 3: Commit**

```bash
git add admin/inventory/salida_evento.php
git commit -m "feat(salida-evento): explosión incluye modificadores y respeta exclusiones por línea"
```

---

## Task 4: Paso 2 — agregar insumos sueltos (descartables) con buscador al vuelo

**Files:**
- Modify: `admin/inventory/salida_evento.php` (paso 2: buscador + mini-modal crear, reusando `api/insumos.php`)

- [ ] **Step 1: Añadir buscador en el paso 2**

Dentro del `<form id="evForm">`, después del `<div id="ingList">`, añade un buscador (igual patrón que el editor de receta) que agrega filas sueltas al `#ingList` con el mismo formato (`insumo_id[]`/`cantidad[]`, `.ev-row` con `data-iid`/`data-costo`):
```php
        <div class="add-wrap" style="position:relative;margin-top:10px">
          <input type="text" id="se-add" autocomplete="off" placeholder="🔍 Agregar insumo suelto (descartables: cajas, vasos…)" oninput="seBuscar(this.value)" onfocus="seBuscar(this.value)" style="width:100%;padding:10px 12px;border:1.5px dashed #c9c9d2;border-radius:10px">
          <div id="se-drop" style="display:none;position:absolute;left:0;right:0;top:46px;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 12px 30px rgba(0,0,0,.14);z-index:30;overflow:hidden"></div>
        </div>
```
Y el mini-modal de crear insumo (idéntico al de SP1: `#ins-ov`, `ins-name`, `ins-unidad`, `ins-tipo` con default "descartable" útil aquí, `ins-costo`).

- [ ] **Step 2: JS del buscador (reusa la API)**

```html
<script>
const INS_API = '<?= APP_URL ?>/api/insumos.php';
const CSRF = '<?= csrfToken() ?>';
let insPend='';
function seAgregar(iid, nombre, unidad, costo){
  if (document.querySelector('#ingList .ev-row input[value="'+iid+'"]')) { document.getElementById('se-drop').style.display='none'; document.getElementById('se-add').value=''; return; }
  var div=document.createElement('div'); div.className='ev-row'; div.dataset.iid=iid; div.dataset.costo=costo||0;
  div.style.cssText='display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)';
  var nm=document.createElement('span'); nm.style.cssText='flex:1;font-size:14px'; nm.textContent=nombre;
  var hid=document.createElement('input'); hid.type='hidden'; hid.name='insumo_id[]'; hid.value=iid;
  var q=document.createElement('input'); q.type='text'; q.name='cantidad[]'; q.className='ev-q'; q.value='1'; q.style.cssText='width:90px;text-align:right'; q.setAttribute('inputmode','decimal'); q.addEventListener('input',sumCost);
  var u=document.createElement('span'); u.style.cssText='width:36px;font-size:12px;color:var(--text-muted)'; u.textContent=unidad;
  var del=document.createElement('button'); del.type='button'; del.textContent='✕'; del.style.cssText='background:none;border:none;color:#dc2626;cursor:pointer'; del.addEventListener('click',function(){ div.remove(); sumCost(); });
  div.appendChild(nm); div.appendChild(hid); div.appendChild(q); div.appendChild(u); div.appendChild(del);
  document.getElementById('ingList').appendChild(div);
  document.getElementById('ingFoot').style.display='block';
  document.getElementById('fUbi').value=document.getElementById('ubiSel').value;
  document.getElementById('fRef').value=document.getElementById('refInput').value;
  document.getElementById('se-add').value=''; document.getElementById('se-drop').style.display='none'; sumCost();
}
function seBuscar(qy){
  qy=(qy||'').trim(); var drop=document.getElementById('se-drop');
  if(!qy){ drop.style.display='none'; return; }
  fetch(INS_API+'?action=buscar&q='+encodeURIComponent(qy)).then(r=>r.json()).then(d=>{
    drop.innerHTML='';
    (d.items||[]).forEach(function(i){
      var o=document.createElement('div'); o.style.cssText='padding:9px 12px;cursor:pointer;display:flex;justify-content:space-between';
      var n=document.createElement('span'); n.textContent=i.nombre; var u=document.createElement('span'); u.style.color='#888'; u.style.fontSize='12px'; u.textContent=i.unidad;
      o.appendChild(n); o.appendChild(u);
      o.addEventListener('mouseenter',function(){o.style.background='#fffbe9';}); o.addEventListener('mouseleave',function(){o.style.background='';});
      o.addEventListener('click',function(){ seAgregar(i.id,i.nombre,i.unidad,parseFloat(i.costo_unitario)||0); });
      drop.appendChild(o);
    });
    var exacto=(d.items||[]).some(function(i){return i.nombre.toLowerCase()===qy.toLowerCase();});
    if(!exacto){ var c=document.createElement('div'); c.style.cssText='padding:9px 12px;cursor:pointer;color:#1f9d55;font-weight:800;border-top:1px dashed #eee'; c.textContent='+ Crear «'+qy+'»'; c.addEventListener('click',function(){ insAbrir(qy); }); drop.appendChild(c); }
    drop.style.display='block';
  });
}
function insAbrir(nombre){ insPend=nombre; document.getElementById('ins-name').textContent=nombre; document.getElementById('se-drop').style.display='none'; document.getElementById('ins-tipo').value='descartable'; document.getElementById('ins-ov').style.display='flex'; }
function insCrear(){
  var body=new URLSearchParams({action:'crear',nombre:insPend,unidad:document.getElementById('ins-unidad').value,tipo:document.getElementById('ins-tipo').value,costo_unitario:document.getElementById('ins-costo').value||'0'});
  fetch(INS_API,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':CSRF},body}).then(r=>r.json()).then(d=>{ if(d.ok){ seAgregar(d.insumo.id,d.insumo.nombre,d.insumo.unidad,0); document.getElementById('ins-costo').value=''; document.getElementById('ins-ov').style.display='none'; } else alert(d.error||'Error'); });
}
document.addEventListener('click',function(e){ if(!e.target.closest('#se-add') && !e.target.closest('#se-drop')){ var d=document.getElementById('se-drop'); if(d) d.style.display='none'; } });
</script>
```
(`sumCost` ya existe y recorre `.ev-row` por `data-costo` — las filas sueltas lo respetan.)

- [ ] **Step 3: Verificar sintaxis + prueba manual**

Run: `php -l admin/inventory/salida_evento.php` → sin errores.
Manual: tras calcular, buscar "cajas" → si no existe, crear (tipo descartable) → se agrega como fila suelta y suma al costo; confirmar descuenta también ese insumo.

- [ ] **Step 4: Commit**

```bash
git add admin/inventory/salida_evento.php
git commit -m "feat(salida-evento): agregar insumos sueltos/descartables al requerimiento con buscador al vuelo"
```

---

## Verificación final (manual)

- [ ] Agregar productos con modificadores ("10 gringos + doble carne") → la explosión suma la receta del modificador × cantidad.
- [ ] Marcar "sin tocino" en una línea → ese ingrediente no aparece para esa línea (pero sí para otras que no lo excluyan).
- [ ] Dos líneas del mismo producto con distintos ajustes coexisten (no se fusionan).
- [ ] Agregar un descartable suelto (crear "cajas" al vuelo, tipo descartable) → suma al costo y se descuenta al confirmar.
- [ ] Confirmar la salida descuenta del stock (comportamiento actual intacto) y redirige a movimientos.
- [ ] Productos sin receta siguen marcándose "(sin receta)" y no rompen el cálculo.

## Nota de alcance (SP3 — siguiente)
SP2 termina descontando del stock como hoy. El **vínculo a un evento concreto** y el **inventario inicial del evento** (que el requerimiento se vuelva el saldo de apertura) son **SP3**. Aquí solo dejamos el requerimiento consolidado correcto (con modificadores y descartables).
