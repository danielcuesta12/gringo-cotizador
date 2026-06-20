# App del mozo Sub-build D — Servicio · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mejorar el servicio diario de la app del mozo: avisar al mozo cuando la cocina marca Listo (solo sus mesas), estado en vivo en la cuenta, búsqueda + agregar de un toque en el catálogo, y reintento ante caída de red al enviar a cocina.

**Architecture:** Todo del lado de la app del mozo. Una consulta nueva en el backend (`plano_estados` devuelve además los pedidos `listo` de las mesas de ese mozo); el resto es JS en `mozo/index.php` que se engancha al poll existente (5s) y a los flujos actuales (catálogo, enviar a cocina). Sin tablas nuevas → sin migración.

**Tech Stack:** PHP 8 + PDO (`Database`), JS vanilla inline, WebAudio (ding), localStorage. Sin frameworks.

## Global Constraints

- **Sin migración** (no toca esquema). SQL siempre con `?`; scope multi-local por `ubicacion_id`; el aviso se filtra además por `empleado_id` (cada mozo solo sus mesas).
- **Sesión de mozo** (no `requireLogin`); las lecturas ya están gateadas por `mozoEmp()`/`mozoUbi()`.
- **Sin emojis** (regla del proyecto): estado por **color + texto/símbolos** (`✕ · −`), nunca pictogramas. Tokens de marca de `brandHead()` (ya en el `<head>`), nunca hex de marca hardcodeado.
- **Aviso de Listo = notificación, no estado a cerrar:** NO agregar "marcar entregado". El aviso se desvanece solo.
- Mobile-first, táctil ≥44px (`var(--tap)`).
- **Verificación (sin framework de tests):** `php -l <archivo>` en cada PHP tocado; checklist funcional; captura headless a 390px para piezas visuales (banner/toggle). Chrome headless disponible en `/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`.

---

### Task 1: Backend — `plano_estados` devuelve los pedidos Listo del mozo

**Files:**
- Modify: `includes/cuentas.php` (añadir `comandasListas()`)
- Modify: `api/mozo.php` (`plano_estados` suma la clave `listos`)

**Interfaces:**
- Produces: `comandasListas(int $ubicacionId, int $empleadoId): array` → `[['pedido_id'=>int, 'mesa'=>string, 'resumen'=>string], ...]` — comandas `origen='mesa'` en estado `listo` de las cuentas **abiertas de ese empleado** en ese local. `resumen` = hasta 3 ítems no anulados (`qty× nombre`, unidos por ` · `) + ` …` si hay más. `[]` si `$empleadoId<=0`.

- [ ] **Step 1: Añadir `comandasListas()` a `includes/cuentas.php`**

Al final del archivo (después de `cuentaPagosArqueo`), añadir:

```php
/** Comandas (origen='mesa') en estado 'listo' de las cuentas abiertas de un mozo. Para el aviso de "Listo". */
function comandasListas(int $ubicacionId, int $empleadoId): array {
    if ($empleadoId <= 0) return [];
    $out = [];
    foreach (Database::fetchAll(
        "SELECT p.id AS pedido_id, m.numero AS mesa, p.items_json
         FROM pedidos p
         JOIN cuentas c ON c.id = p.cuenta_id
         LEFT JOIN mesas m ON m.id = p.mesa_id
         WHERE c.ubicacion_id = ? AND c.empleado_id = ? AND c.estado = 'abierta'
           AND p.origen = 'mesa' AND p.estado = 'listo'
         ORDER BY p.id", [$ubicacionId, $empleadoId]) as $r) {
        $items = json_decode($r['items_json'] ?? '[]', true) ?: [];
        $nombres = []; $total = 0;
        foreach ($items as $it) {
            if (!empty($it['anulado'])) continue;
            $total++;
            if (count($nombres) < 3) $nombres[] = (int)($it['qty'] ?? 1) . '× ' . (string)($it['nombre'] ?? 'Ítem');
        }
        $resumen = implode(' · ', $nombres);
        if ($total > count($nombres)) $resumen .= ' …';
        $out[] = ['pedido_id' => (int)$r['pedido_id'], 'mesa' => (string)($r['mesa'] ?? ''), 'resumen' => $resumen];
    }
    return $out;
}
```

- [ ] **Step 2: `plano_estados` devuelve `listos`**

En `api/mozo.php`, el `case 'plano_estados'` actual:
```php
    case 'plano_estados':
        mout(array_merge([
            'ok' => true,
            'umbral_naranja' => (int)(getSetting('mesa_umbral_naranja', '20') ?: 20),
            'umbral_rojo'    => (int)(getSetting('mesa_umbral_rojo', '30') ?: 30),
        ], mesaEstados($ubi)));
```
reemplazar por (añade `listos`):
```php
    case 'plano_estados':
        mout(array_merge([
            'ok' => true,
            'umbral_naranja' => (int)(getSetting('mesa_umbral_naranja', '20') ?: 20),
            'umbral_rojo'    => (int)(getSetting('mesa_umbral_rojo', '30') ?: 30),
            'listos'         => comandasListas($ubi, mozoEmp()),
        ], mesaEstados($ubi)));
```

- [ ] **Step 3: Verificar sintaxis + el scope de la query**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l includes/cuentas.php && php -l api/mozo.php`
Expected: `No syntax errors detected` en ambos.

Confirmar (lectura): la query filtra por `c.empleado_id = ?` (cada mozo solo sus mesas) y `c.ubicacion_id = ?` (multi-local), usa `?` en todo, y solo trae `p.estado='listo'` + `p.origen='mesa'`.

- [ ] **Step 4: Verificar que D.4 (inputmode en cobro) ya está presente**

Run: `grep -c 'inputmode="decimal"' mozo/index.php`
Expected: `>= 3` (los inputs de monto de pago, monto de parte y descuento ya lo tienen). Si fuera 0, agregarlo a esos inputs; si ≥3, D.4 ya está cubierto (no hacer nada).

- [ ] **Step 5: Commit**

```bash
git add includes/cuentas.php api/mozo.php
git commit -m "feat(mozo): plano_estados devuelve los pedidos Listo de las mesas del mozo"
```

---

### Task 2: Aviso de "Listo" en el plano (banner + ding + toggle de sonido)

**Files:**
- Modify: `mozo/index.php` (CSS del banner + toggle; markup del toggle en la barra; JS `procesarListos`/`notificarListo`/`ding`/toggle; engancharlo en `pollEstados`)

**Interfaces:**
- Consumes: `d.listos` de `plano_estados` (Task 1) = `[{pedido_id, mesa, resumen}]`; helpers existentes `$()`, `get()`.
- Produces: efecto lateral del poll — al detectar un `pedido_id` nuevo en `listo`, banner + ding (si sonido on).

- [ ] **Step 1: CSS del banner + toggle**

En el `<style>`, junto a los otros componentes, añadir:

```css
.snd-tgl{background:rgba(255,255,255,.16);border:none;color:#fff;border-radius:10px;min-height:40px;padding:0 12px;font-weight:800;font-size:12px;cursor:pointer}
#aviso-listo{position:fixed;left:10px;right:10px;top:max(10px,env(safe-area-inset-top));z-index:60;background:var(--ng);color:#fff;border-radius:14px;padding:13px 15px;box-shadow:0 10px 30px rgba(0,0,0,.28);display:flex;align-items:center;gap:11px;transform:translateY(-140%);transition:transform .3s var(--ease);cursor:pointer}
#aviso-listo.on{transform:translateY(0)}
#aviso-listo .dot{width:11px;height:11px;border-radius:50%;background:var(--ok);flex:none;box-shadow:0 0 0 4px color-mix(in srgb, var(--ok) 30%, transparent)}
#aviso-listo .txt{flex:1;min-width:0;font-size:14px;font-weight:700;line-height:1.25}
#aviso-listo .txt b{font-weight:900}
@media (prefers-reduced-motion: reduce){#aviso-listo{transition:none}}
```

- [ ] **Step 2: Markup del banner + toggle**

Justo después de `<body>` (o junto al `#toast`), añadir el banner:
```html
<div id="aviso-listo" onclick="cerrarAviso()"><span class="dot"></span><span class="txt"></span></div>
```

En la barra superior del plano, el markup actual:
```html
  <div class="top"><span>Mesas · <span class="y" id="plano-piso">Piso 1</span></span><span id="plano-mozo"></span></div>
```
reemplazar por (añade el toggle de sonido a la izquierda del nombre):
```html
  <div class="top"><span>Mesas · <span class="y" id="plano-piso">Piso 1</span></span><span style="display:flex;align-items:center;gap:8px"><button type="button" class="snd-tgl" id="snd-tgl" onclick="toggleSonido()"></button><span id="plano-mozo"></span></span></div>
```

- [ ] **Step 3: JS — toggle de sonido + ding**

Añadir (cerca de `toast`):
```javascript
function sonidoOn(){ return localStorage.getItem('mozo_sonido') !== '0'; }
function pintarSndTgl(){ var b=$('snd-tgl'); if(b) b.textContent = sonidoOn() ? 'Son. on' : 'Son. off'; }
function toggleSonido(){ localStorage.setItem('mozo_sonido', sonidoOn() ? '0' : '1'); pintarSndTgl(); if(sonidoOn()) ding(); }
var _ac=null;
function ding(){
  if(!sonidoOn()) return;
  try{
    _ac = _ac || new (window.AudioContext||window.webkitAudioContext)();
    if(_ac.state==='suspended') _ac.resume();
    [0,160].forEach(function(off){
      var o=_ac.createOscillator(), g=_ac.createGain();
      o.type='sine'; o.frequency.value=880; o.connect(g); g.connect(_ac.destination);
      var t=_ac.currentTime+off/1000;
      g.gain.setValueAtTime(0.0001,t); g.gain.exponentialRampToValueAtTime(0.25,t+0.02);
      g.gain.exponentialRampToValueAtTime(0.0001,t+0.14);
      o.start(t); o.stop(t+0.16);
    });
  }catch(e){}
}
```

- [ ] **Step 4: JS — procesar listos + banner**

Añadir:
```javascript
var avisados={}; var avisosSeed=false; var _avisoTO=null;
function procesarListos(listos){
  listos = listos || [];
  var actuales={}; listos.forEach(function(L){ actuales[L.pedido_id]=L; });
  if(!avisosSeed){ Object.keys(actuales).forEach(function(id){ avisados[id]=1; }); avisosSeed=true; }
  else {
    var nuevos=[];
    listos.forEach(function(L){ if(!avisados[L.pedido_id]){ avisados[L.pedido_id]=1; nuevos.push(L); } });
    if(nuevos.length){ notificarListos(nuevos); }
  }
  Object.keys(avisados).forEach(function(id){ if(!actuales[id]) delete avisados[id]; });
}
function notificarListos(nuevos){
  var txt;
  if(nuevos.length===1){ var L=nuevos[0]; txt='<b>Mesa '+esc(L.mesa)+'</b> · '+esc(L.resumen)+' — Listo'; }
  else { txt='<b>'+nuevos.length+' pedidos listos</b> · '+nuevos.map(function(L){return 'Mesa '+esc(L.mesa);}).join(', '); }
  var el=$('aviso-listo'); el.querySelector('.txt').innerHTML=txt; el.classList.add('on');
  if(_avisoTO) clearTimeout(_avisoTO);
  _avisoTO=setTimeout(cerrarAviso, 6000);
  ding();
}
function cerrarAviso(){ var el=$('aviso-listo'); if(el) el.classList.remove('on'); }
```

- [ ] **Step 5: Enganchar en `pollEstados` + inicializar el toggle**

El `pollEstados` actual:
```javascript
function pollEstados(){
  get('plano_estados').then(function(d){
    if(d.ok){ EST={estados:d.estados||{},montos:d.montos||{},minutos:d.minutos||{},uN:d.umbral_naranja||20,uR:d.umbral_rojo||30}; if($('v-plano').classList.contains('on')) refreshEstados(); }
  }, function(){ /* error de red: ignorar, igual reprogramamos */ })
  .then(function(){ setTimeout(pollEstados, 5000); });
}
```
reemplazar la línea del `if(d.ok){...}` por una que también procese los listos:
```javascript
    if(d.ok){ EST={estados:d.estados||{},montos:d.montos||{},minutos:d.minutos||{},uN:d.umbral_naranja||20,uR:d.umbral_rojo||30}; procesarListos(d.listos); if($('v-plano').classList.contains('on')) refreshEstados(); }
```

Y donde arranca la app (`function enterApp(){ loadPlano(); showView('v-plano'); pollEstados(); navPush(); }`), añadir `pintarSndTgl();`:
```javascript
function enterApp(){ pintarSndTgl(); loadPlano(); showView('v-plano'); pollEstados(); navPush(); }
```

- [ ] **Step 6: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l mozo/index.php`
Expected: `No syntax errors detected in mozo/index.php`

- [ ] **Step 7: Evidencia visual del banner (captura a 390px)**

Crear `/tmp/aviso_preview.html` con el `:root` de tokens (negro `#1E1E1E`, ok `#16a34a`), el CSS de `#aviso-listo` (con `.on`) y un body con la barra superior del plano (toggle "Son. on") + el banner visible (`class="on"`) mostrando `<b>Mesa 5</b> · 1× Pollo Crispy · 2× Salchipapa — Listo`. Capturar:
```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=2 --window-size=420,400 --screenshot=/tmp/aviso_preview.png /tmp/aviso_preview.html
```
Revisar el PNG: el banner se ve legible (texto blanco sobre negro, contraste ≥4.5:1), el punto verde a la izquierda, y el toggle "Son. on" en la barra. Sin emojis.

- [ ] **Step 8: Commit**

```bash
git add mozo/index.php
git commit -m "feat(mozo): aviso de Listo por mozo (banner + ding + toggle de sonido)"
```

---

### Task 3: Estado en vivo en la cuenta (poll mientras está abierta)

**Files:**
- Modify: `mozo/index.php` (`startCuentaPoll`/`tickCuenta`; arrancarlo en `loadCuenta`)

**Interfaces:**
- Consumes: acción `cuenta` (existente), `renderCuenta()` (existente), `st.cuenta`.
- Produces: la vista de cuenta se refresca cada 5s mientras está visible (los badges en preparación→listo se actualizan solos).

- [ ] **Step 1: Añadir el poll de la cuenta**

Añadir (cerca de `loadCuenta`):
```javascript
var _cuentaPoll=false;
function startCuentaPoll(){ if(_cuentaPoll) return; _cuentaPoll=true; tickCuenta(); }
function tickCuenta(){
  if(!$('v-cuenta').classList.contains('on') || !st.cuenta){ _cuentaPoll=false; return; }
  var cid=st.cuenta.id;
  get('cuenta&cuenta_id='+cid).then(function(d){
    if(d.ok && d.cuenta && $('v-cuenta').classList.contains('on') && st.cuenta && +st.cuenta.id===+d.cuenta.id){ st.cuenta=d.cuenta; renderCuenta(); }
  }, function(){ /* red: ignorar */ })
  .then(function(){ if($('v-cuenta').classList.contains('on') && st.cuenta){ setTimeout(tickCuenta, 5000); } else { _cuentaPoll=false; } });
}
```

- [ ] **Step 2: Arrancar el poll al entrar a la cuenta**

El `loadCuenta` actual:
```javascript
function loadCuenta(cid){ get('cuenta&cuenta_id='+cid).then(function(d){ if(!d.ok){toast('Error');return;} st.cuenta=d.cuenta; renderCuenta(); showView('v-cuenta'); }); }
```
reemplazar por (arranca el poll tras mostrar la vista):
```javascript
function loadCuenta(cid){ get('cuenta&cuenta_id='+cid).then(function(d){ if(!d.ok){toast('Error');return;} st.cuenta=d.cuenta; renderCuenta(); showView('v-cuenta'); startCuentaPoll(); }); }
```

- [ ] **Step 3: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l mozo/index.php`
Expected: `No syntax errors detected in mozo/index.php`

- [ ] **Step 4: Revisión de lógica (checklist)**

Confirmar leyendo: el loop se corta al salir de `v-cuenta` (no reprograma), no arranca dos loops (`_cuentaPoll` guard), y no pisa la cuenta si cambiaste de mesa (compara `st.cuenta.id===d.cuenta.id`). Es solo lectura: no interfiere con modales (anular/cobro) que viven en `.modal` aparte.

- [ ] **Step 5: Commit**

```bash
git add mozo/index.php
git commit -m "feat(mozo): estado en vivo en la cuenta (poll 5s mientras está abierta)"
```

---

### Task 4: Búsqueda en el catálogo + agregar de un toque

**Files:**
- Modify: `mozo/index.php` (input de búsqueda en `v-cat`; `norm()`; `pushBorr()`; refactor `addBorr` y `drawCat`)

**Interfaces:**
- Consumes: `st.catProd` (lista de productos con `categoria`, `grupos`), `openProd()`, `updBorr()`, `toast()`.
- Produces: `pushBorr(p, qty, mods, nota)` (empuja un ítem al borrador con la forma estándar `{product_id,nombre,precio,qty,modificadores,nota}`); `drawCat` filtra por búsqueda y hace quick-add de productos sin modificadores.

- [ ] **Step 1: Input de búsqueda en `v-cat`**

El markup actual de `v-cat`:
```html
  <div id="cat-tabs" style="display:flex;gap:6px;padding:8px 10px;overflow:auto;background:#efece4"></div>
  <div class="body" id="cat-list"></div>
```
reemplazar por (input arriba de las pestañas):
```html
  <div style="padding:8px 10px 0"><input id="cat-search" type="text" inputmode="search" placeholder="Buscar producto…" oninput="drawCat()" style="width:100%;min-height:var(--tap);padding:0 14px;border:1.5px solid var(--line);border-radius:10px;font-size:15px;background:var(--surface)"></div>
  <div id="cat-tabs" style="display:flex;gap:6px;padding:8px 10px;overflow:auto;background:#efece4"></div>
  <div class="body" id="cat-list"></div>
```

- [ ] **Step 2: `pushBorr` + refactor de `addBorr`**

Añadir `pushBorr` y refactorizar `addBorr` para usarlo (DRY).

old_string (el `addBorr` actual):
```javascript
function addBorr(){ var s=st.prodSel; var mods=[]; Object.keys(s.sel).forEach(function(g){ Object.keys(s.sel[g]).forEach(function(o){ mods.push({nombre:s.sel[g][o].nombre, precio:s.sel[g][o].precio}); }); });
  var nota=($('prod-nota')||{}).value||'';
  st.borrador.push({product_id:s.p.id, nombre:s.p.nombre, precio:parseFloat(s.p.precio), qty:s.qty, modificadores:mods, nota:nota});
  closeModal('m-prod'); updBorr();
}
```
new_string:
```javascript
function pushBorr(p, qty, mods, nota){ st.borrador.push({product_id:p.id, nombre:p.nombre, precio:parseFloat(p.precio), qty:qty, modificadores:mods||[], nota:nota||''}); updBorr(); }
function addBorr(){ var s=st.prodSel; var mods=[]; Object.keys(s.sel).forEach(function(g){ Object.keys(s.sel[g]).forEach(function(o){ mods.push({nombre:s.sel[g][o].nombre, precio:s.sel[g][o].precio}); }); });
  var nota=($('prod-nota')||{}).value||'';
  pushBorr(s.p, s.qty, mods, nota);
  closeModal('m-prod');
}
```

- [ ] **Step 3: `drawCat` con búsqueda + quick-add**

old_string (el `drawCat` actual):
```javascript
function drawCat(){
  var cats=[]; st.catProd.forEach(function(p){ if(cats.indexOf(p.categoria)<0) cats.push(p.categoria); });
  var tabs=$('cat-tabs'); tabs.innerHTML='';
  cats.forEach(function(c){ var t=document.createElement('span'); t.className='chip'+(c===st.catCat?' on':''); t.textContent=c; t.onclick=function(){ st.catCat=c; drawCat(); }; tabs.appendChild(t); });
  var list=$('cat-list'); list.innerHTML='';
  st.catProd.filter(function(p){return p.categoria===st.catCat;}).forEach(function(p){
    var r=document.createElement('div'); r.className='row'; r.innerHTML='<div>'+esc(p.nombre)+(p.grupos&&p.grupos.length?'<br><small style="color:#999">toca para modificar</small>':'')+'</div><div style="display:flex;align-items:center;gap:9px"><b>S/ '+Number(p.precio).toFixed(0)+'</b><span class="plus">+</span></div>';
    r.onclick=function(){ openProd(p); };
    list.appendChild(r);
  });
}
```
new_string:
```javascript
function norm(s){ return (s||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }
function drawCat(){
  var q=norm(($('cat-search')||{}).value||'');
  var tabs=$('cat-tabs'); tabs.innerHTML=''; tabs.style.display=q?'none':'flex';
  if(!q){
    var cats=[]; st.catProd.forEach(function(p){ if(cats.indexOf(p.categoria)<0) cats.push(p.categoria); });
    cats.forEach(function(c){ var t=document.createElement('span'); t.className='chip'+(c===st.catCat?' on':''); t.textContent=c; t.onclick=function(){ st.catCat=c; drawCat(); }; tabs.appendChild(t); });
  }
  var list=$('cat-list'); list.innerHTML='';
  var prods = q ? st.catProd.filter(function(p){ return norm(p.nombre).indexOf(q)>=0; })
                : st.catProd.filter(function(p){ return p.categoria===st.catCat; });
  if(!prods.length){ list.innerHTML='<p style="padding:24px;text-align:center;color:#999">Sin resultados</p>'; return; }
  prods.forEach(function(p){
    var mods=p.grupos&&p.grupos.length;
    var r=document.createElement('div'); r.className='row'; r.innerHTML='<div>'+esc(p.nombre)+(mods?'<br><small style="color:#999">toca para modificar</small>':'')+'</div><div style="display:flex;align-items:center;gap:9px"><b>S/ '+Number(p.precio).toFixed(0)+'</b><span class="plus">+</span></div>';
    r.onclick = mods ? function(){ openProd(p); } : function(){ pushBorr(p,1,[],''); toast('+1 '+p.nombre); };
    list.appendChild(r);
  });
}
```

- [ ] **Step 4: Limpiar la búsqueda al abrir el catálogo**

`openCatalogo` deja el input con texto viejo entre visitas. En `openCatalogo` (donde setea `st.borrador=[]`), limpiar el input antes de `drawCat`. Localizar `function openCatalogo()` y, dentro, antes del primer `drawCat()`/`showView('v-cat')`, añadir: `var cs=$('cat-search'); if(cs) cs.value='';`

(Si `openCatalogo` llama `drawCat` en dos ramas —catálogo ya cargado vs por cargar— limpiar el input una sola vez al inicio de la función cubre ambas.)

- [ ] **Step 5: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l mozo/index.php`
Expected: `No syntax errors detected in mozo/index.php`

- [ ] **Step 6: Checklist funcional**

- Buscar "pol" muestra "Pollo…" de cualquier categoría; vacío vuelve a la vista por pestañas.
- Tocar un producto **sin** modificadores agrega 1 al borrador + toast "+1 …"; el botón "Ver borrador" sube el contador.
- Tocar un producto **con** modificadores abre el modal (como antes).
- Al confirmar en el modal, el ítem entra igual que antes (vía `pushBorr`).

- [ ] **Step 7: Commit**

```bash
git add mozo/index.php
git commit -m "feat(mozo): búsqueda en el catálogo + agregar de un toque (productos sin modificadores)"
```

---

### Task 5: Reintento simple al enviar a cocina

**Files:**
- Modify: `mozo/index.php` (`postRetry`; `enviarComanda` con reintento)

**Interfaces:**
- Consumes: `post()` (existente), `geo()`/`withGeo()` (existentes), `toast()`, `loadCuenta()`.
- Produces: `postRetry(action, body, tries)` — reintenta SOLO ante rechazo de red; un `{ok:false}` de negocio se devuelve sin reintentar.

- [ ] **Step 1: Añadir `postRetry`**

Añadir (cerca de `post`):
```javascript
// Reintenta solo si la promesa de red se RECHAZA (sin respuesta). Un {ok:false} de negocio NO se reintenta.
function postRetry(a, body, tries){
  tries = tries || 3;
  return post(a, body).catch(function(err){
    if(tries<=1) return Promise.reject(err);
    return new Promise(function(res){ setTimeout(res, 800); }).then(function(){ return postRetry(a, body, tries-1); });
  });
}
```

- [ ] **Step 2: `enviarComanda` usa `postRetry` y conserva el borrador si la red falla**

old_string (el `enviarComanda` actual):
```javascript
function enviarComanda(){ if(!st.borrador.length)return; geo().then(function(){ post('enviar_comanda', withGeo({cuenta_id:st.cuenta.id, items:JSON.stringify(st.borrador)})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} st.borrador=[]; closeModal('m-borr'); toast('Enviado a cocina · Ronda '+d.ronda); loadCuenta(st.cuenta.id); }); }); }
```
new_string:
```javascript
function enviarComanda(){ if(!st.borrador.length)return; geo().then(function(){ postRetry('enviar_comanda', withGeo({cuenta_id:st.cuenta.id, items:JSON.stringify(st.borrador)})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} st.borrador=[]; closeModal('m-borr'); toast('Enviado a cocina · Ronda '+d.ronda); loadCuenta(st.cuenta.id); }, function(){ toast('Sin señal · toca Enviar para reintentar'); }); }); }
```

(El borrador NO se limpia y la sheet del borrador queda abierta cuando la red falla tras los reintentos, así el mozo reintenta con un toque. El cobro sigue usando `post` directo — no se auto-reintenta.)

- [ ] **Step 3: Verificar sintaxis**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l mozo/index.php`
Expected: `No syntax errors detected in mozo/index.php`

- [ ] **Step 4: Revisión de lógica (checklist)**

Confirmar: `postRetry` solo reintenta en `.catch` (rechazo de red), no ante `{ok:false}`; `enviarComanda` en éxito limpia borrador + cierra sheet + toast Ronda; en fallo de red final conserva el borrador y avisa; el cobro (`cobrar`) NO fue tocado (sigue `post` directo).

- [ ] **Step 5: Commit**

```bash
git add mozo/index.php
git commit -m "feat(mozo): reintento simple al enviar a cocina (solo ante caída de red)"
```

---

## Self-Review

**1. Spec coverage:**
- D.1 aviso de Listo por mozo → Task 1 (backend `listos`) + Task 2 (banner/ding/toggle). ✅
- D.2 estado en vivo en la cuenta → Task 3. ✅
- D.3 búsqueda + agregar de un toque → Task 4. ✅
- D.4 inputmode decimal → ya presente; Task 1 Step 4 lo verifica. ✅
- D.5 reintento simple → Task 5. ✅
- KDS "el card desaparece al marcar listo" → la query de Task 1 solo lee `p.estado='listo'`; no requiere cambios de KDS. (Verificación de comportamiento del KDS queda como nota del spec, fuera de este plan.)

**2. Placeholder scan:** sin TBD/TODO; cada paso de código trae el código completo. El único "verificar y si falta agregarlo" (Task 1 Step 4) es una verificación condicional concreta (D.4 ya está), no un placeholder.

**3. Type consistency:** `comandasListas()` (Task 1) devuelve `{pedido_id, mesa, resumen}`, consumido por `procesarListos`/`notificarListos` (Task 2) con esos mismos campos. `pushBorr(p,qty,mods,nota)` (Task 4) lo usan `addBorr` y el quick-add con la misma firma. `postRetry(a,body,tries)` (Task 5) refleja la firma de `post(a,body)`. `d.listos` lo produce Task 1 y lo consume Task 2.

**Riesgo conocido (para la revisión):** todo Task 2-5 toca `mozo/index.php` con anclas `old_string` exactas; si alguna ancla cambió por un edit previo, reubicar por contexto. La evidencia visual del banner (Task 2 Step 7) es un harness estático: el banner real se prueba en el dispositivo.
