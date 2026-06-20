# Mesas POS Sub-build E2 — Fusionar + Transferir · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fusionar dos cuentas abiertas en una (mover comandas + absorber mesas + cerrar la origen) y transferir una cuenta a una mesa libre, desde la app del mozo, antes de cobrar.

**Architecture:** Reusa la base de E1 (`cuenta_mesas`, `cuentaAbiertaDeMesa`, `cuentaMesasLista`, `cuentaTieneCobro`). Lógica nueva en `includes/cuentas.php`, acciones en `api/mozo.php`, y la UI extiende el picker "Juntar mesa" (libres + ocupadas) y agrega "Mover a mesa libre". Sin migración.

**Tech Stack:** PHP 8 + PDO (`Database`, `getInstance()` para transacción), JS vanilla inline. Sin frameworks.

## Global Constraints

- **Sin migración** (reusa `cuenta_mesas` y `cuentas.estado='cancelada'`).
- **Fusionar:** ambas cuentas **sin pagos** (`cuentaTieneCobro`); comandas pasan a la destino **manteniendo su `mesa_id`**; mesas de la origen → secundarias de la destino; origen → `'cancelada'`; recalc destino; **transaccional**.
- **Transferir:** cambia `cuentas.mesa_id` a una mesa **libre**; permitido en cualquier cuenta abierta.
- SQL siempre con `?`; scope por `ubicacion_id`; sesión de mozo; `verifyCsrf()` + `geoGate($ubi)` en escrituras. Guard `cuentaMesasListo()`.
- **Sin emojis**; tokens de marca; táctil ≥44px; reusar helpers (`get`/`post`/`geo`/`withGeo`/`toast`/`esc`/`openModal`/`closeModal`) y el modal `#m-pick` de E1.
- **Verificación (sin framework de tests):** `php -l` en cada PHP tocado + checklist funcional/lógico. Sin BD de dev.

---

### Task 1: Lógica de fusionar / transferir / mesas para juntar

**Files:**
- Modify: `includes/cuentas.php` (añadir `mesasParaJuntar`, `cuentaTransferir`, `cuentaFusionar`)

**Interfaces:**
- Consumes: `cuentaMesasListo`, `cuentaMesasLista`, `cuentaAbiertaDeMesa`, `cuentaTieneCobro`, `cuentaTotalRecalc` (E1/anteriores); `Database::getInstance()`.
- Produces:
  - `mesasParaJuntar(int $cuentaId, int $ubicacionId): array` → `[['id'=>int,'numero'=>string,'ocupada'=>bool], ...]` (mesas del local fuera del grupo actual; libres primero).
  - `cuentaTransferir(int $cuentaId, int $mesaDestino, int $ubicacionId): array` → `['ok'=>bool,'error'?=>string,'mesas'?=>cuentaMesasLista]`.
  - `cuentaFusionar(int $cuentaDestino, int $mesaOrigen, int $ubicacionId): array` → idem.

- [ ] **Step 1: Añadir las tres funciones al final de `includes/cuentas.php`**

```php
/** Mesas del local fuera del grupo de $cuentaId, marcadas libre/ocupada. Libres primero. */
function mesasParaJuntar(int $cuentaId, int $ubicacionId): array {
    $grupo = [];
    foreach (cuentaMesasLista($cuentaId) as $m) $grupo[(int)$m['id']] = 1;
    $out = [];
    foreach (Database::fetchAll("SELECT id, numero FROM mesas WHERE ubicacion_id = ? AND activa = 1 ORDER BY numero+0, numero", [$ubicacionId]) as $m) {
        $mid = (int)$m['id'];
        if (isset($grupo[$mid])) continue;
        $out[] = ['id' => $mid, 'numero' => (string)$m['numero'], 'ocupada' => cuentaAbiertaDeMesa($mid) ? true : false];
    }
    usort($out, function ($a, $b) { return ($a['ocupada'] ? 1 : 0) - ($b['ocupada'] ? 1 : 0); }); // libres primero (usort estable en PHP 8)
    return $out;
}

/** Mueve la cuenta a una mesa LIBRE (cambia la principal; la mesa vieja queda libre). */
function cuentaTransferir(int $cuentaId, int $mesaDestino, int $ubicacionId): array {
    $c = Database::fetch("SELECT * FROM cuentas WHERE id = ? AND estado = 'abierta' AND (? = 0 OR ubicacion_id = ?)", [$cuentaId, $ubicacionId, $ubicacionId]);
    if (!$c) return ['ok' => false, 'error' => 'cuenta no abierta'];
    if ($mesaDestino === (int)$c['mesa_id']) return ['ok' => false, 'error' => 'ya está en esa mesa'];
    $m = Database::fetch("SELECT id FROM mesas WHERE id = ? AND ubicacion_id = ? AND activa = 1", [$mesaDestino, (int)$c['ubicacion_id']]);
    if (!$m) return ['ok' => false, 'error' => 'mesa inválida'];
    if (cuentaAbiertaDeMesa($mesaDestino)) return ['ok' => false, 'error' => 'la mesa no está libre'];
    Database::execute("UPDATE cuentas SET mesa_id = ? WHERE id = ?", [$mesaDestino, $cuentaId]);
    return ['ok' => true, 'mesas' => cuentaMesasLista($cuentaId)];
}

/** Fusiona la cuenta abierta de $mesaOrigen DENTRO de $cuentaDestino. Ambas sin pagos. Transaccional. */
function cuentaFusionar(int $cuentaDestino, int $mesaOrigen, int $ubicacionId): array {
    if (!cuentaMesasListo()) return ['ok' => false, 'error' => 'función no disponible'];
    $dest = Database::fetch("SELECT * FROM cuentas WHERE id = ? AND estado = 'abierta' AND (? = 0 OR ubicacion_id = ?)", [$cuentaDestino, $ubicacionId, $ubicacionId]);
    if (!$dest) return ['ok' => false, 'error' => 'cuenta no abierta'];
    $orig = cuentaAbiertaDeMesa($mesaOrigen);
    if (!$orig) return ['ok' => false, 'error' => 'la mesa no tiene cuenta'];
    $origId = (int)$orig['id'];
    if ($origId === $cuentaDestino) return ['ok' => false, 'error' => 'es la misma cuenta'];
    if ((int)$orig['ubicacion_id'] !== (int)$dest['ubicacion_id']) return ['ok' => false, 'error' => 'otra ubicación'];
    if (cuentaTieneCobro($cuentaDestino) || cuentaTieneCobro($origId)) return ['ok' => false, 'error' => 'alguna cuenta ya tiene pagos'];
    $pdo = Database::getInstance();
    try {
        $pdo->beginTransaction();
        Database::execute("UPDATE pedidos SET cuenta_id = ? WHERE cuenta_id = ?", [$cuentaDestino, $origId]);          // comandas (mantienen mesa_id)
        Database::execute("UPDATE cuenta_mesas SET cuenta_id = ? WHERE cuenta_id = ?", [$cuentaDestino, $origId]);     // secundarias de la origen → destino
        Database::execute("INSERT IGNORE INTO cuenta_mesas (cuenta_id, mesa_id) VALUES (?, ?)", [$cuentaDestino, (int)$orig['mesa_id']]); // principal de la origen → secundaria
        Database::execute("UPDATE cuentas SET estado = 'cancelada', cerrada_at = NOW() WHERE id = ?", [$origId]);      // cerrar origen
        cuentaTotalRecalc($cuentaDestino);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'no se pudo fusionar'];
    }
    return ['ok' => true, 'mesas' => cuentaMesasLista($cuentaDestino)];
}
```

- [ ] **Step 2: Verificar**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l includes/cuentas.php`
Expected: `No syntax errors detected in includes/cuentas.php`

- [ ] **Step 3: Revisión de lógica (checklist)**

Confirmar leyendo:
- `cuentaFusionar`: resuelve origen por `cuentaAbiertaDeMesa` (principal o secundaria); rechaza misma cuenta, otra ubicación, o pagos en cualquiera; dentro de la transacción mueve comandas (mantiene `mesa_id`), reasigna `cuenta_mesas` de origen a destino, agrega la principal de origen como secundaria, cierra origen `'cancelada'`, recalc destino; rollback ante excepción.
- `cuentaTransferir`: exige mesa destino libre (`cuentaAbiertaDeMesa` null), del local, distinta de la principal; cambia `cuentas.mesa_id`.
- `mesasParaJuntar`: excluye el grupo actual; marca ocupada por `cuentaAbiertaDeMesa`; libres primero.
- Todo con `?`; guard `cuentaMesasListo()` donde toca `cuenta_mesas`.

- [ ] **Step 4: Commit**
```bash
git add includes/cuentas.php
git commit -m "feat(mesas): cuentaFusionar + cuentaTransferir + mesasParaJuntar"
```

---

### Task 2: Acciones de API (`api/mozo.php`)

**Files:**
- Modify: `api/mozo.php` (writes array + casos `mesas_para_juntar`, `fusionar_cuenta`, `transferir_cuenta`)

**Interfaces:**
- Consumes: `mesasParaJuntar`, `cuentaFusionar`, `cuentaTransferir` (Task 1); `$ubi` (= `mozoUbi()`), `geoGate()`.
- Produces (JSON): `mesas_para_juntar` → `{ok, mesas:[{id,numero,ocupada}]}`; `fusionar_cuenta`/`transferir_cuenta` → passthrough.

- [ ] **Step 1: Registrar escrituras**

Localizar la línea `$writes = [...]` (ya incluye `'juntar_mesa', 'separar_mesa'`) y agregar `'fusionar_cuenta'` y `'transferir_cuenta'` al final del array.

old_string:
```php
$writes = ['login_pin', 'logout', 'abrir_cuenta', 'enviar_comanda', 'anular', 'cerrar_cuenta_vacia', 'precuenta', 'cobrar', 'juntar_mesa', 'separar_mesa'];
```
new_string:
```php
$writes = ['login_pin', 'logout', 'abrir_cuenta', 'enviar_comanda', 'anular', 'cerrar_cuenta_vacia', 'precuenta', 'cobrar', 'juntar_mesa', 'separar_mesa', 'fusionar_cuenta', 'transferir_cuenta'];
```

- [ ] **Step 2: Añadir los casos**

Insertar antes de `default:` (junto a `juntar_mesa`/`separar_mesa`):
```php
    case 'mesas_para_juntar':
        mout(['ok' => true, 'mesas' => mesasParaJuntar(cleanInt($_GET['cuenta_id'] ?? 0), $ubi)]);

    case 'fusionar_cuenta':
        geoGate($ubi);
        mout(cuentaFusionar(cleanInt($_POST['cuenta_id'] ?? 0), cleanInt($_POST['mesa_id'] ?? 0), $ubi));

    case 'transferir_cuenta':
        geoGate($ubi);
        mout(cuentaTransferir(cleanInt($_POST['cuenta_id'] ?? 0), cleanInt($_POST['mesa_id'] ?? 0), $ubi));
```

- [ ] **Step 3: Verificar**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l api/mozo.php`
Expected: `No syntax errors detected in api/mozo.php`

Confirmar (lectura): `fusionar_cuenta`/`transferir_cuenta` en `$writes` (CSRF) y llaman `geoGate($ubi)`; las tres pasan `$ubi`; `mesas_para_juntar` es lectura (`$_GET['cuenta_id']`).

- [ ] **Step 4: Commit**
```bash
git add api/mozo.php
git commit -m "feat(mesas): api/mozo — mesas_para_juntar, fusionar_cuenta, transferir_cuenta"
```

---

### Task 3: UI — picker unificado + mover a mesa libre (`mozo/index.php`)

**Files:**
- Modify: `mozo/index.php` (reescribir `openJuntar`; añadir `doFusionar`, `openMover`, `doTransferir`; botón "Mover a mesa libre" en la ficha)

**Interfaces:**
- Consumes: acciones `mesas_para_juntar`/`fusionar_cuenta`/`transferir_cuenta`/`mesas_libres` (Task 2 + E1); `doJuntar` (E1), `openMesaInfo`, helpers, modal `#m-pick`.

- [ ] **Step 1: `openJuntar` usa `mesas_para_juntar` (libres + ocupadas)**

old_string (el `openJuntar` actual de E1):
```javascript
function openJuntar(){
  get('mesas_libres').then(function(d){
    if(!d.ok){ toast('Error'); return; }
    if(!d.mesas.length){ toast('No hay mesas libres'); return; }
    $('pick-tit').textContent='Juntar a esta cuenta';
    var box=$('pick-body'); box.innerHTML='';
    d.mesas.forEach(function(m){ var b=document.createElement('button'); b.className='btn'; b.style.marginBottom='8px'; b.textContent='Mesa '+m.numero; b.onclick=function(){ doJuntar(m.id); }; box.appendChild(b); });
    openModal('m-pick');
  });
}
```
new_string:
```javascript
function openJuntar(){
  get('mesas_para_juntar&cuenta_id='+st.cuenta.id).then(function(d){
    if(!d.ok){ toast('Error'); return; }
    if(!d.mesas.length){ toast('No hay otras mesas'); return; }
    $('pick-tit').textContent='Juntar a esta cuenta';
    var box=$('pick-body'); box.innerHTML='';
    d.mesas.forEach(function(m){
      var b=document.createElement('button'); b.className='btn'; b.style.marginBottom='8px';
      b.textContent = m.ocupada ? ('Mesa '+m.numero+' · ocupada') : ('Mesa '+m.numero);
      b.onclick = m.ocupada
        ? function(){ if(confirm('¿Unir la cuenta de Mesa '+m.numero+' a esta?')) doFusionar(m.id); }
        : function(){ doJuntar(m.id); };
      box.appendChild(b);
    });
    openModal('m-pick');
  });
}
function doFusionar(mesaId){ geo().then(function(){ post('fusionar_cuenta', withGeo({cuenta_id:st.cuenta.id, mesa_id:mesaId})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} closeModal('m-pick'); toast('Cuentas unidas'); openMesaInfo(st.cuenta.mesa_id); }); }); }
```

- [ ] **Step 2: `openMover` + `doTransferir`**

Añadir (cerca de `openJuntar`):
```javascript
function openMover(){
  get('mesas_libres').then(function(d){
    if(!d.ok){ toast('Error'); return; }
    if(!d.mesas.length){ toast('No hay mesas libres'); return; }
    $('pick-tit').textContent='Mover a mesa libre';
    var box=$('pick-body'); box.innerHTML='';
    d.mesas.forEach(function(m){ var b=document.createElement('button'); b.className='btn'; b.style.marginBottom='8px'; b.textContent='Mesa '+m.numero; b.onclick=function(){ doTransferir(m.id); }; box.appendChild(b); });
    openModal('m-pick');
  });
}
function doTransferir(mesaId){ geo().then(function(){ post('transferir_cuenta', withGeo({cuenta_id:st.cuenta.id, mesa_id:mesaId})).then(function(d){ if(!d.ok){toast(d.error||'No se pudo');return;} closeModal('m-pick'); toast('Cuenta movida'); openMesaInfo(mesaId); }); }); }
```
(Tras transferir, la principal es `mesaId` → refresca con `openMesaInfo(mesaId)`.)

- [ ] **Step 3: Botón "Mover a mesa libre" en la ficha**

En `openMesaInfo`, donde se arma `acciones`, insertar el botón antes del de "Cerrar".

old_string:
```javascript
    if (sinPagos && nSec > 0) acciones += '<button class="btn" style="margin-top:8px" onclick="openSeparar()">Separar mesa</button>';
    acciones += '<button class="btn" style="background:#eee;color:#555;margin-top:8px" onclick="closeModal(\'m-mesa\')">Cerrar</button>';
```
new_string:
```javascript
    if (sinPagos && nSec > 0) acciones += '<button class="btn" style="margin-top:8px" onclick="openSeparar()">Separar mesa</button>';
    acciones += '<button class="btn" style="margin-top:8px" onclick="openMover()">Mover a mesa libre</button>';
    acciones += '<button class="btn" style="background:#eee;color:#555;margin-top:8px" onclick="closeModal(\'m-mesa\')">Cerrar</button>';
```

- [ ] **Step 4: Verificar**

Run: `cd /Users/daniel/Documents/Proyectos/elgringo-cotizador && php -l mozo/index.php`
Expected: `No syntax errors detected in mozo/index.php`

- [ ] **Step 5: Checklist funcional**

- "Juntar mesa": el picker lista las otras mesas; las libres dicen "Mesa X", las ocupadas "Mesa X · ocupada".
- Tap libre → se agrega al grupo (E1). Tap ocupada → confirm → fusiona (la ficha pasa a incluir esa mesa; las comandas de la otra aparecen en esta cuenta).
- "Mover a mesa libre": lista mesas libres; al elegir, la cuenta se mueve (la ficha refresca en la nueva mesa, la vieja queda libre).
- Una cuenta con pagos no muestra Juntar/Separar; "Mover" sí está disponible.

- [ ] **Step 6: Commit**
```bash
git add mozo/index.php
git commit -m "feat(mozo): juntar unificado (libre/fusionar) + mover a mesa libre"
```

---

## Self-Review

**1. Spec coverage:**
- E2.1 fusionar → Task 1 (`cuentaFusionar`) + Task 2 (API) + Task 3 (UI rama ocupada). ✅
- E2.2 transferir → Task 1 (`cuentaTransferir`) + Task 2 + Task 3 (botón Mover). ✅
- E2.3 mesasParaJuntar → Task 1 + Task 2 + Task 3 (picker). ✅
- E2.4 API → Task 2. ✅
- E2.5 UI (picker unificado + mover) → Task 3. ✅

**2. Placeholder scan:** sin TBD/TODO; código completo en cada paso.

**3. Type consistency:** `mesasParaJuntar` → `[{id,numero,ocupada}]`, consumido por `mesas_para_juntar` (Task 2) y `openJuntar` (Task 3, usa `m.ocupada`/`m.id`/`m.numero`). `cuentaFusionar(destino, mesaOrigen, ubi)` / `cuentaTransferir(cuenta, mesaDestino, ubi)` → passthrough en Task 2, llamadas con `cuenta_id`/`mesa_id` en Task 3. `cuentaTotalRecalc`/`cuentaAbiertaDeMesa`/`cuentaTieneCobro`/`cuentaMesasLista` ya existen (E1).

**Riesgo conocido (para la revisión):** `cuentaFusionar` es transaccional y mueve filas entre cuentas — verificar que el `UPDATE cuenta_mesas SET cuenta_id` no choque con el UNIQUE (no debería: una mesa está en un solo grupo abierto) y que la origen quede sin secundarias propias tras la reasignación. La regla "una mesa, un grupo" la garantiza E1 (juntar exige mesa libre).
