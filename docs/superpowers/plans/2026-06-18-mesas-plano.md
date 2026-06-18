# Mesas POS — Sub-build A (Mesas & Plano) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Definir mesas y un plano interactivo multi-piso por local: un editor de plano (admin) donde se colocan/redimensionan mesas con su capacidad, etiquetas, formas decorativas e imagen de fondo, y un render reutilizable que dibuja el plano con estados por color.

**Architecture:** Tres tablas (`mesa_pisos`, `mesas`, `mesa_elementos`) por `ubicacion_id`. Un endpoint `api/mesas.php` carga y guarda el plano (upsert+delete por piso en transacción) y sube la imagen de fondo. Dos módulos JS vanilla: `plano-render.js` (dibujo read-only, reutilizable por la app del mozo en Sub-build B) y `plano-editor.js` (lienzo editable: arrastrar, redimensionar, propiedades). Dos páginas admin: el editor y un tablero de solo-lectura. Coordenadas en un espacio lógico por piso (`ancho`×`alto`, default 1000×700); el render escala responsivamente.

**Tech Stack:** PHP 8 puro + PDO (clase `Database`), HTML + CSS propio + JS vanilla (sin frameworks). Deploy: git push → git pull en cPanel → aplicar SQL en phpMyAdmin.

## Global Constraints

- Nunca concatenar variables en SQL — siempre `?` con prepared statements (`Database::fetch/fetchAll/insert/execute`).
- `verifyCsrf()` en todo POST del admin y en escrituras de la API (token por header `X-CSRF-Token`).
- Sanitizar entradas con `clean()` / `cleanInt()` / `cleanFloat()`.
- `requirePermission('mesas')` por página; API `requireLogin()` + `can('mesas')`. Admin (role='admin') siempre `true`.
- `uploadImage(array $file, string $folder)` retorna ruta relativa (ej. `planos/x.png`); el consumidor antepone `UPLOAD_URL`.
- Multi-local: todo cuelga de `ubicacion_id`.
- Marca: negro `#1E1E1E`, amarillo `#FFDF00`, rosa `#FFBBC8`, crema `#FFEFBC`. Editor para escritorio/tablet; el render debe verse bien en celular.
- Espacio lógico por piso: mesas/elementos guardan `pos_x,pos_y,ancho,alto` en unidades del lienzo (default 1000×700). Snap de grilla = 10 unidades.
- NO hay framework de tests. Verificación = `php -l <archivo>` (PHP 8.5 local) + `node --check <archivo.js>` + checklist funcional + SQL en phpMyAdmin.
- Estados de mesa y su color (definidos aquí, los no-libres se activan en Sub-build B): `libre` blanco/borde verde · `ocupada` amarillo `#FFDF00` · `precuenta` rosa `#FFBBC8` · `por_cobrar` naranja.

### Contrato del JSON del plano (compartido por api / editor / render)
```js
piso = {
  id, nombre, orden, fondo_img, ancho, alto,
  mesas:     [ {id, numero, capacidad, forma:'cuadrada'|'redonda', pos_x, pos_y, ancho, alto} ],
  elementos: [ {id, tipo:'etiqueta'|'forma', texto, pos_x, pos_y, ancho, alto} ]
}
```
Al guardar un piso, ids nuevos llegan como `null` → INSERT; ids existentes → UPDATE; ids en BD ausentes del arreglo → DELETE.

---

### Task 1: Migración de esquema

**Files:**
- Create: `install/56_mesas.sql`
- Modify: `install/check_migraciones.sql`

**Interfaces:**
- Produces: tablas `mesa_pisos`, `mesas`, `mesa_elementos`.

- [ ] **Step 1: Escribir la migración**

Create `install/56_mesas.sql`:

```sql
-- 56_mesas.sql — Mesas POS Sub-build A: pisos, mesas, elementos del plano.
-- Idempotente. Aplicar en phpMyAdmin tras git pull.

CREATE TABLE IF NOT EXISTS `mesa_pisos` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ubicacion_id` INT UNSIGNED NOT NULL,
  `nombre`       VARCHAR(80) NOT NULL,
  `orden`        INT NOT NULL DEFAULT 0,
  `fondo_img`    VARCHAR(255) NULL,
  `ancho`        INT NOT NULL DEFAULT 1000,
  `alto`         INT NOT NULL DEFAULT 700,
  `activo`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mp_ubi` (`ubicacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mesas` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `piso_id`      INT UNSIGNED NOT NULL,
  `ubicacion_id` INT UNSIGNED NOT NULL,
  `numero`       VARCHAR(20) NOT NULL,
  `capacidad`    INT NOT NULL DEFAULT 4,
  `forma`        ENUM('cuadrada','redonda') NOT NULL DEFAULT 'cuadrada',
  `pos_x`        INT NOT NULL DEFAULT 0,
  `pos_y`        INT NOT NULL DEFAULT 0,
  `ancho`        INT NOT NULL DEFAULT 60,
  `alto`         INT NOT NULL DEFAULT 60,
  `activa`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mesas_piso` (`piso_id`),
  KEY `idx_mesas_ubi` (`ubicacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mesa_elementos` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `piso_id` INT UNSIGNED NOT NULL,
  `tipo`    ENUM('etiqueta','forma') NOT NULL,
  `texto`   VARCHAR(120) NULL,
  `pos_x`   INT NOT NULL DEFAULT 0,
  `pos_y`   INT NOT NULL DEFAULT 0,
  `ancho`   INT NOT NULL DEFAULT 100,
  `alto`    INT NOT NULL DEFAULT 30,
  PRIMARY KEY (`id`),
  KEY `idx_melem_piso` (`piso_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Añadir el chequeo en check_migraciones.sql**

READ `install/check_migraciones.sql` para ver su formato exacto (cada fila es un SELECT con `UNION ALL`). Agrega una fila que verifique la existencia de la tabla `mesa_pisos`, respetando el formato del archivo:

```sql
SELECT '56_mesas.sql' AS migracion,
       IF(COUNT(*)>0,'✅','❌') AS aplicada
FROM information_schema.tables
WHERE table_schema=DATABASE() AND table_name='mesa_pisos'
UNION ALL
```
(Insértala respetando dónde van los `UNION ALL` — si es la última fila, sin el `UNION ALL` final, igual que las demás del archivo.)

- [ ] **Step 3: Verificar estructura SQL**

No hay MySQL local. Revisa que los 3 `CREATE TABLE` terminen en `;`, sin comas finales en las listas de columnas, y que el `ENUM` esté bien escrito.

- [ ] **Step 4: Commit**

```bash
git add install/56_mesas.sql install/check_migraciones.sql
git commit -m "feat(mesas): migración — pisos, mesas y elementos del plano"
```

- [ ] **Step 5: (Deploy) aplicar y verificar**

Tras `git pull` en el servidor, pega `install/56_mesas.sql` en phpMyAdmin y verifica:
```sql
SHOW TABLES LIKE 'mesa_%';   -- mesa_pisos, mesa_elementos
SHOW TABLES LIKE 'mesas';
```

---

### Task 2: Permiso `mesas`

**Files:**
- Modify: `includes/permissions.php`

**Interfaces:**
- Produces: clave de permiso `mesas` válida (catálogo + ruta), usable por `can('mesas')`/`requirePermission('mesas')`.

- [ ] **Step 1: Añadir la clave al catálogo**

En `includes/permissions.php`, dentro de `permissionCatalog()`, en el grupo `'Carta / POS'`, añade la clave `mesas` (después de `'pos_clientes'`):

```php
        'Carta / POS' => [
            'pedidos'      => 'Pedidos',
            'kds'          => 'KDS (cocina)',
            'pos_terminal' => 'POS Terminal',
            'pos_metodos'  => 'POS Métodos',
            'pos_caja'     => 'POS Caja (arqueo)',
            'pos_monitor'  => 'POS En vivo',
            'pos_clientes' => 'Clientes POS',
            'mesas'        => 'Mesas / Plano',
        ],
```

- [ ] **Step 2: Añadir la ruta**

En `permissionPaths()`, añade (junto a las rutas de POS):

```php
        'mesas'        => '/admin/mesas/index.php',
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l includes/permissions.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add includes/permissions.php
git commit -m "feat(mesas): permiso 'mesas' (catálogo + ruta)"
```

---

### Task 3: API del plano (`api/mesas.php`)

**Files:**
- Create: `api/mesas.php`

**Interfaces:**
- Consumes: `Database::*`, `helpers.php` (`requireLogin`, `can`, `verifyCsrf`, `clean*`, `uploadImage`).
- Produces (JSON), todas requieren `can('mesas')`; escrituras requieren `verifyCsrf()`:
  - GET `?action=plano&ubicacion_id=N` → `{ok:true, pisos:[piso...]}` (cada piso con `mesas` y `elementos`).
  - POST `?action=crear_piso` (`ubicacion_id`, `nombre`) → `{ok:true, piso:{id,nombre,orden,ancho,alto,fondo_img:null,mesas:[],elementos:[]}}`.
  - POST `?action=renombrar_piso` (`piso_id`, `nombre`) → `{ok:true}`.
  - POST `?action=eliminar_piso` (`piso_id`) → `{ok:true}` (borra mesas y elementos del piso).
  - POST `?action=guardar_piso` (`piso_id`, `mesas` JSON, `elementos` JSON) → upsert+delete del contenido en transacción → `{ok:true, idmap:{tmpNeg:nuevoId,...}}`.
  - POST `?action=subir_fondo` (multipart: `piso_id`, file `fondo`) → `{ok:true, fondo_img:'planos/x.png'}`.

- [ ] **Step 1: Escribir el endpoint**

Create `api/mesas.php`:

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
if (!can('mesas')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'forbidden']); exit; }
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$writes = ['crear_piso', 'renombrar_piso', 'eliminar_piso', 'guardar_piso', 'subir_fondo'];
if (in_array($action, $writes, true)) verifyCsrf();

function jout($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

/** Carga un piso completo (mesas + elementos) como arreglo asociativo. */
function pisoFull(int $pisoId): ?array {
    $p = Database::fetch("SELECT id, nombre, orden, fondo_img, ancho, alto FROM mesa_pisos WHERE id = ?", [$pisoId]);
    if (!$p) return null;
    $p['mesas'] = Database::fetchAll(
        "SELECT id, numero, capacidad, forma, pos_x, pos_y, ancho, alto FROM mesas WHERE piso_id = ? AND activa = 1 ORDER BY id", [$pisoId]);
    $p['elementos'] = Database::fetchAll(
        "SELECT id, tipo, texto, pos_x, pos_y, ancho, alto FROM mesa_elementos WHERE piso_id = ? ORDER BY id", [$pisoId]);
    return $p;
}

switch ($action) {

    case 'plano':
        $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
        $pisos = [];
        foreach (Database::fetchAll("SELECT id FROM mesa_pisos WHERE ubicacion_id = ? AND activo = 1 ORDER BY orden, id", [$ubi]) as $row) {
            $full = pisoFull((int)$row['id']);
            if ($full) $pisos[] = $full;
        }
        jout(['ok' => true, 'pisos' => $pisos]);

    case 'crear_piso':
        $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
        $nombre = clean($_POST['nombre'] ?? '') ?: 'Piso';
        if ($ubi <= 0) jout(['ok' => false, 'error' => 'ubicación inválida']);
        $orden = (int)(Database::fetch("SELECT COALESCE(MAX(orden),0)+1 n FROM mesa_pisos WHERE ubicacion_id = ?", [$ubi])['n'] ?? 1);
        $id = Database::insert("INSERT INTO mesa_pisos (ubicacion_id, nombre, orden) VALUES (?,?,?)", [$ubi, $nombre, $orden]);
        jout(['ok' => true, 'piso' => ['id' => $id, 'nombre' => $nombre, 'orden' => $orden, 'fondo_img' => null, 'ancho' => 1000, 'alto' => 700, 'mesas' => [], 'elementos' => []]]);

    case 'renombrar_piso':
        $pid = cleanInt($_POST['piso_id'] ?? 0);
        $nombre = clean($_POST['nombre'] ?? '');
        if ($pid && $nombre !== '') Database::execute("UPDATE mesa_pisos SET nombre = ? WHERE id = ?", [$nombre, $pid]);
        jout(['ok' => true]);

    case 'eliminar_piso':
        $pid = cleanInt($_POST['piso_id'] ?? 0);
        if ($pid) {
            Database::execute("DELETE FROM mesas WHERE piso_id = ?", [$pid]);
            Database::execute("DELETE FROM mesa_elementos WHERE piso_id = ?", [$pid]);
            Database::execute("DELETE FROM mesa_pisos WHERE id = ?", [$pid]);
        }
        jout(['ok' => true]);

    case 'guardar_piso':
        $pid = cleanInt($_POST['piso_id'] ?? 0);
        $piso = Database::fetch("SELECT id, ubicacion_id FROM mesa_pisos WHERE id = ?", [$pid]);
        if (!$piso) jout(['ok' => false, 'error' => 'piso no encontrado']);
        $ubi = (int)$piso['ubicacion_id'];
        $mesas = json_decode($_POST['mesas'] ?? '[]', true) ?: [];
        $elems = json_decode($_POST['elementos'] ?? '[]', true) ?: [];

        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $idmap = [];
            // Mesas: upsert + recolectar ids vivos
            $keepM = [];
            foreach ($mesas as $m) {
                $forma = ($m['forma'] ?? 'cuadrada') === 'redonda' ? 'redonda' : 'cuadrada';
                $numero = substr(trim((string)($m['numero'] ?? '')), 0, 20) ?: '?';
                $cap = max(1, (int)($m['capacidad'] ?? 4));
                $x = (int)($m['pos_x'] ?? 0); $y = (int)($m['pos_y'] ?? 0);
                $w = max(20, (int)($m['ancho'] ?? 60)); $h = max(20, (int)($m['alto'] ?? 60));
                $mid = isset($m['id']) && (int)$m['id'] > 0 ? (int)$m['id'] : 0;
                if ($mid > 0) {
                    Database::execute("UPDATE mesas SET numero=?, capacidad=?, forma=?, pos_x=?, pos_y=?, ancho=?, alto=? WHERE id=? AND piso_id=?",
                        [$numero, $cap, $forma, $x, $y, $w, $h, $mid, $pid]);
                } else {
                    $mid = Database::insert("INSERT INTO mesas (piso_id, ubicacion_id, numero, capacidad, forma, pos_x, pos_y, ancho, alto) VALUES (?,?,?,?,?,?,?,?,?)",
                        [$pid, $ubi, $numero, $cap, $forma, $x, $y, $w, $h]);
                    if (isset($m['id'])) $idmap[(string)$m['id']] = $mid;
                }
                $keepM[] = $mid;
            }
            // Borrar mesas que ya no están
            $existM = Database::fetchAll("SELECT id FROM mesas WHERE piso_id = ?", [$pid]);
            foreach ($existM as $row) {
                if (!in_array((int)$row['id'], $keepM, true)) Database::execute("DELETE FROM mesas WHERE id = ?", [(int)$row['id']]);
            }
            // Elementos: mismo patrón
            $keepE = [];
            foreach ($elems as $e) {
                $tipo = ($e['tipo'] ?? 'etiqueta') === 'forma' ? 'forma' : 'etiqueta';
                $texto = substr(trim((string)($e['texto'] ?? '')), 0, 120) ?: null;
                $x = (int)($e['pos_x'] ?? 0); $y = (int)($e['pos_y'] ?? 0);
                $w = max(10, (int)($e['ancho'] ?? 100)); $h = max(8, (int)($e['alto'] ?? 30));
                $eid = isset($e['id']) && (int)$e['id'] > 0 ? (int)$e['id'] : 0;
                if ($eid > 0) {
                    Database::execute("UPDATE mesa_elementos SET tipo=?, texto=?, pos_x=?, pos_y=?, ancho=?, alto=? WHERE id=? AND piso_id=?",
                        [$tipo, $texto, $x, $y, $w, $h, $eid, $pid]);
                } else {
                    $eid = Database::insert("INSERT INTO mesa_elementos (piso_id, tipo, texto, pos_x, pos_y, ancho, alto) VALUES (?,?,?,?,?,?,?)",
                        [$pid, $tipo, $texto, $x, $y, $w, $h]);
                    if (isset($e['id'])) $idmap[(string)$e['id']] = $eid;
                }
                $keepE[] = $eid;
            }
            $existE = Database::fetchAll("SELECT id FROM mesa_elementos WHERE piso_id = ?", [$pid]);
            foreach ($existE as $row) {
                if (!in_array((int)$row['id'], $keepE, true)) Database::execute("DELETE FROM mesa_elementos WHERE id = ?", [(int)$row['id']]);
            }
            $pdo->commit();
            jout(['ok' => true, 'idmap' => $idmap]);
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            jout(['ok' => false, 'error' => 'no se pudo guardar']);
        }

    case 'subir_fondo':
        $pid = cleanInt($_POST['piso_id'] ?? 0);
        if (!$pid || empty($_FILES['fondo']['name'])) jout(['ok' => false, 'error' => 'falta archivo']);
        $up = uploadImage($_FILES['fondo'], 'planos');
        if (!$up) jout(['ok' => false, 'error' => 'no se pudo subir']);
        Database::execute("UPDATE mesa_pisos SET fondo_img = ? WHERE id = ?", [$up, $pid]);
        jout(['ok' => true, 'fondo_img' => $up]);

    default:
        http_response_code(400);
        jout(['ok' => false, 'error' => 'acción inválida']);
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l api/mesas.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Self-check de seguridad**

Confirma: `can('mesas')` gatea todo; `verifyCsrf()` en las 5 escrituras; todas las queries con `?`; `guardar_piso` en transacción con rollback. `uploadImage($_FILES['fondo'],'planos')`.

- [ ] **Step 4: Commit**

```bash
git add api/mesas.php
git commit -m "feat(mesas): api/mesas.php — cargar/guardar plano, pisos, fondo"
```

---

### Task 4: Render del plano (`assets/js/plano-render.js`)

**Files:**
- Create: `assets/js/plano-render.js`

**Interfaces:**
- Produces: global `window.PlanoRender` con:
  - `PlanoRender.draw(container, piso, opts)` — dibuja un piso (read-only). `opts = {estados:{mesaId:'libre'|'ocupada'|'precuenta'|'por_cobrar'}, montos:{mesaId:number}, onMesaTap:(id,mesa)=>void, uploadUrl:string}`.
  - `PlanoRender.COLORS` — mapa de estados a colores.

- [ ] **Step 1: Escribir el módulo**

Create `assets/js/plano-render.js`:

```javascript
/* PlanoRender — dibuja un piso del plano en modo read-only. Vanilla, sin deps. */
(function () {
  'use strict';
  var COLORS = {
    libre:      { bg: '#ffffff', border: '#9cc5a1', sub: '#6b8e6f' },
    ocupada:    { bg: '#FFF3B0', border: '#FFDF00', sub: '#8a6d00' },
    precuenta:  { bg: '#FFD8E0', border: '#FF8FA6', sub: '#b03a63' },
    por_cobrar: { bg: '#FFE0B2', border: '#FB8C00', sub: '#9a5b00' }
  };

  function elem(tag, css) {
    var n = document.createElement(tag);
    if (css) n.style.cssText = css;
    return n;
  }

  function draw(container, piso, opts) {
    opts = opts || {};
    var estados = opts.estados || {}, montos = opts.montos || {}, onTap = opts.onMesaTap;
    var W = piso.ancho || 1000, H = piso.alto || 700;
    var cw = container.clientWidth || W;
    var scale = cw / W;

    container.innerHTML = '';
    container.style.position = 'relative';
    container.style.overflow = 'hidden';
    container.style.height = (H * scale) + 'px';

    var stage = elem('div', 'position:absolute;left:0;top:0;transform-origin:top left;width:' + W + 'px;height:' + H + 'px;transform:scale(' + scale + ');');

    if (piso.fondo_img) {
      var bg = elem('img', 'position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;opacity:.45;');
      bg.src = (opts.uploadUrl || '') + piso.fondo_img;
      stage.appendChild(bg);
    }

    (piso.elementos || []).forEach(function (e) {
      var d = elem('div', 'position:absolute;left:' + e.pos_x + 'px;top:' + e.pos_y + 'px;width:' + e.ancho + 'px;height:' + e.alto + 'px;');
      if (e.tipo === 'etiqueta') {
        d.textContent = e.texto || '';
        d.style.cssText += 'font-weight:800;color:#1E1E1E;font-size:13px;display:flex;align-items:center;';
      } else {
        d.style.cssText += 'background:#1E1E1E;opacity:.8;border-radius:5px;';
      }
      stage.appendChild(d);
    });

    (piso.mesas || []).forEach(function (m) {
      var st = estados[m.id] || 'libre';
      var c = COLORS[st] || COLORS.libre;
      var d = elem('div',
        'position:absolute;left:' + m.pos_x + 'px;top:' + m.pos_y + 'px;width:' + m.ancho + 'px;height:' + m.alto + 'px;' +
        'background:' + c.bg + ';border:2px solid ' + c.border + ';color:#1E1E1E;' +
        'border-radius:' + (m.forma === 'redonda' ? '50%' : '12px') + ';' +
        'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
        'box-shadow:0 2px 6px rgba(0,0,0,.1);cursor:' + (onTap ? 'pointer' : 'default') + ';user-select:none;');
      d.setAttribute('data-mesa-id', m.id);
      var num = elem('b', 'font-size:16px;line-height:1.1;');
      num.textContent = m.numero;
      d.appendChild(num);
      var sub = elem('span', 'font-size:9px;font-weight:800;color:' + c.sub + ';');
      sub.textContent = (montos[m.id] != null) ? ('S/ ' + Number(montos[m.id]).toFixed(0)) : (m.capacidad + ' pers');
      d.appendChild(sub);
      if (onTap) d.addEventListener('click', function () { onTap(m.id, m); });
      stage.appendChild(d);
    });

    container.appendChild(stage);
  }

  window.PlanoRender = { draw: draw, COLORS: COLORS };
})();
```

- [ ] **Step 2: Verificar sintaxis**

Run: `node --check assets/js/plano-render.js`
Expected: exit 0, sin salida. (Si no hay node: revisar a ojo que llaves/paréntesis cierran.)

- [ ] **Step 3: Commit**

```bash
git add assets/js/plano-render.js
git commit -m "feat(mesas): plano-render.js — render read-only reutilizable del plano"
```

---

### Task 5: Editor de lienzo (`assets/js/plano-editor.js`)

**Files:**
- Create: `assets/js/plano-editor.js`

**Interfaces:**
- Consumes: `window.EG_MESAS_API` (URL de `api/mesas.php`, definida por la página), `window.EG_CSRF` (token), `window.EG_UPLOAD_URL` (UPLOAD_URL).
- Produces: global `window.PlanoEditor.init(opts)` con `opts = {mount: HTMLElement, ubicacionId: int, pisos: [piso...]}`. Monta el editor completo (pestañas de piso, toolbar, lienzo editable, panel de propiedades, guardar) dentro de `mount`.

- [ ] **Step 1: Escribir el editor**

Create `assets/js/plano-editor.js`:

```javascript
/* PlanoEditor — editor de lienzo del plano. Vanilla, sin deps.
   Maneja pisos en memoria; cada cambio re-renderiza; guarda vía api/mesas.php. */
(function () {
  'use strict';
  var GRID = 10;
  var api, csrf, uploadUrl;
  var st = { ubi: 0, pisos: [], pi: 0, sel: null }; // sel = {kind:'mesa'|'elem', ref:obj}
  var tmpSeq = -1; // ids temporales negativos para nuevos
  var mount, elCanvas, elProps, elTabs;

  function snap(v) { return Math.round(v / GRID) * GRID; }
  function piso() { return st.pisos[st.pi]; }
  function post(action, body) {
    body = body || {};
    var fd = new FormData();
    fd.append('action', action);
    Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
    return fetch(api + '?action=' + action, { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd })
      .then(function (r) { return r.json(); });
  }

  // ---------- toolbar + tabs ----------
  function renderTabs() {
    elTabs.innerHTML = '';
    st.pisos.forEach(function (p, i) {
      var t = document.createElement('span');
      t.className = 'pe-tab' + (i === st.pi ? ' on' : '');
      t.textContent = p.nombre;
      t.addEventListener('click', function () { st.pi = i; st.sel = null; renderAll(); });
      t.addEventListener('dblclick', function () {
        var nv = prompt('Nombre del piso:', p.nombre);
        if (nv && nv.trim()) { p.nombre = nv.trim(); post('renombrar_piso', { piso_id: p.id, nombre: p.nombre }); renderTabs(); }
      });
      elTabs.appendChild(t);
    });
    var add = document.createElement('span');
    add.className = 'pe-tab pe-add';
    add.textContent = '＋ Piso';
    add.addEventListener('click', function () {
      var nombre = prompt('Nombre del nuevo piso:', 'Piso ' + (st.pisos.length + 1));
      if (!nombre) return;
      post('crear_piso', { ubicacion_id: st.ubi, nombre: nombre }).then(function (d) {
        if (d.ok) { st.pisos.push(d.piso); st.pi = st.pisos.length - 1; st.sel = null; renderAll(); }
      });
    });
    elTabs.appendChild(add);
  }

  // ---------- canvas ----------
  function renderCanvas() {
    var p = piso();
    elCanvas.innerHTML = '';
    elCanvas.style.width = p.ancho + 'px';
    elCanvas.style.height = p.alto + 'px';
    if (p.fondo_img) {
      var bg = document.createElement('img');
      bg.src = uploadUrl + p.fondo_img;
      bg.style.cssText = 'position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;opacity:.4;pointer-events:none;';
      elCanvas.appendChild(bg);
    }
    p.elementos.forEach(function (e) { elCanvas.appendChild(nodeFor('elem', e)); });
    p.mesas.forEach(function (m) { elCanvas.appendChild(nodeFor('mesa', m)); });
  }

  function nodeFor(kind, obj) {
    var d = document.createElement('div');
    var selected = st.sel && st.sel.ref === obj;
    var base = 'position:absolute;left:' + obj.pos_x + 'px;top:' + obj.pos_y + 'px;width:' + obj.ancho + 'px;height:' + obj.alto + 'px;box-sizing:border-box;cursor:move;user-select:none;';
    if (kind === 'mesa') {
      base += 'background:#fff;border:2px solid ' + (selected ? '#FFDF00' : '#ccc') + ';' +
        'border-radius:' + (obj.forma === 'redonda' ? '50%' : '12px') + ';' +
        'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
        (selected ? 'box-shadow:0 0 0 3px rgba(255,223,0,.3);' : '');
      var num = document.createElement('b'); num.style.cssText = 'font-size:16px;'; num.textContent = obj.numero;
      var sub = document.createElement('span'); sub.style.cssText = 'font-size:8px;color:#888;font-weight:700;'; sub.textContent = obj.capacidad + ' pers';
      d.appendChild(num); d.appendChild(sub);
    } else if (obj.tipo === 'etiqueta') {
      base += 'display:flex;align-items:center;font-weight:800;color:#1E1E1E;font-size:13px;' + (selected ? 'outline:2px solid #FFDF00;' : '');
      d.textContent = obj.texto || '(texto)';
    } else {
      base += 'background:#1E1E1E;opacity:.8;border-radius:5px;' + (selected ? 'outline:2px solid #FFDF00;' : '');
    }
    d.style.cssText = base;
    attachDrag(d, kind, obj);
    if (selected) addHandles(d, obj);
    return d;
  }

  // ---------- drag (mover) ----------
  function attachDrag(node, kind, obj) {
    node.addEventListener('pointerdown', function (ev) {
      if (ev.target.getAttribute('data-handle')) return; // resize maneja aparte
      ev.preventDefault();
      st.sel = { kind: kind, ref: obj };
      renderProps();
      var rect = elCanvas.getBoundingClientRect();
      var ox = ev.clientX - rect.left - obj.pos_x;
      var oy = ev.clientY - rect.top - obj.pos_y;
      function move(e) {
        obj.pos_x = Math.max(0, snap(e.clientX - rect.left - ox));
        obj.pos_y = Math.max(0, snap(e.clientY - rect.top - oy));
        node.style.left = obj.pos_x + 'px';
        node.style.top = obj.pos_y + 'px';
      }
      function up() { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); renderCanvas(); }
      document.addEventListener('pointermove', move);
      document.addEventListener('pointerup', up);
      renderCanvas();
    });
  }

  // ---------- resize (tirador esquina inferior-derecha) ----------
  function addHandles(node, obj) {
    var h = document.createElement('span');
    h.setAttribute('data-handle', '1');
    h.style.cssText = 'position:absolute;right:-6px;bottom:-6px;width:12px;height:12px;background:#FFDF00;border:2px solid #1E1E1E;border-radius:50%;cursor:nwse-resize;';
    h.addEventListener('pointerdown', function (ev) {
      ev.preventDefault(); ev.stopPropagation();
      var rect = elCanvas.getBoundingClientRect();
      function move(e) {
        obj.ancho = Math.max(20, snap(e.clientX - rect.left - obj.pos_x));
        obj.alto = Math.max(20, snap(e.clientY - rect.top - obj.pos_y));
        node.style.width = obj.ancho + 'px';
        node.style.height = obj.alto + 'px';
      }
      function up() { document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); }
      document.addEventListener('pointermove', move);
      document.addEventListener('pointerup', up);
    });
    node.appendChild(h);
  }

  // ---------- panel de propiedades ----------
  function renderProps() {
    elProps.innerHTML = '';
    if (!st.sel) { elProps.innerHTML = '<p style="color:#888;font-size:12px">Selecciona un elemento para editarlo.</p>'; return; }
    var o = st.sel.ref;
    if (st.sel.kind === 'mesa') {
      elProps.appendChild(field('Número / nombre', inputText(o.numero, function (v) { o.numero = v; renderCanvas(); })));
      elProps.appendChild(field('Comensales', stepper(o.capacidad, function (v) { o.capacidad = v; renderCanvas(); })));
      elProps.appendChild(field('Forma', formaToggle(o)));
    } else if (o.tipo === 'etiqueta') {
      elProps.appendChild(field('Texto', inputText(o.texto || '', function (v) { o.texto = v; renderCanvas(); })));
    } else {
      elProps.appendChild(field('Forma decorativa', span('Arrástrala y redimensiónala en el lienzo.')));
    }
    var del = document.createElement('button');
    del.textContent = '🗑 Eliminar';
    del.style.cssText = 'margin-top:10px;background:none;border:none;color:#dc2626;font-weight:800;cursor:pointer;font-size:13px;';
    del.addEventListener('click', function () {
      var arr = st.sel.kind === 'mesa' ? piso().mesas : piso().elementos;
      var i = arr.indexOf(o); if (i >= 0) arr.splice(i, 1);
      st.sel = null; renderCanvas(); renderProps();
    });
    elProps.appendChild(del);
  }

  function field(label, control) {
    var wrap = document.createElement('div'); wrap.style.cssText = 'margin-bottom:10px;';
    var l = document.createElement('div'); l.textContent = label; l.style.cssText = 'font-size:10px;font-weight:800;text-transform:uppercase;color:#888;margin-bottom:3px;';
    wrap.appendChild(l); wrap.appendChild(control); return wrap;
  }
  function span(txt) { var s = document.createElement('p'); s.textContent = txt; s.style.cssText = 'font-size:12px;color:#888;'; return s; }
  function inputText(val, onChange) {
    var i = document.createElement('input'); i.type = 'text'; i.value = val;
    i.style.cssText = 'width:100%;padding:7px 9px;border:1.5px solid #ddd;border-radius:7px;font-size:13px;';
    i.addEventListener('input', function () { onChange(i.value); });
    return i;
  }
  function stepper(val, onChange) {
    var box = document.createElement('div'); box.style.cssText = 'display:flex;align-items:center;gap:10px;';
    var minus = btn('−'), plus = btn('＋'), b = document.createElement('b'); b.textContent = val; b.style.fontSize = '15px';
    minus.addEventListener('click', function () { val = Math.max(1, val - 1); b.textContent = val; onChange(val); });
    plus.addEventListener('click', function () { val = val + 1; b.textContent = val; onChange(val); });
    box.appendChild(minus); box.appendChild(b); box.appendChild(plus); return box;
  }
  function btn(t) { var x = document.createElement('span'); x.textContent = t; x.style.cssText = 'background:#1E1E1E;color:#fff;width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-weight:800;cursor:pointer;'; return x; }
  function formaToggle(o) {
    var box = document.createElement('div'); box.style.cssText = 'display:flex;gap:8px;';
    ['cuadrada', 'redonda'].forEach(function (f) {
      var s = document.createElement('span');
      s.style.cssText = 'width:28px;height:28px;border:2px solid ' + (o.forma === f ? '#FFDF00' : '#ccc') + ';cursor:pointer;border-radius:' + (f === 'redonda' ? '50%' : '6px') + ';';
      s.addEventListener('click', function () { o.forma = f; renderCanvas(); renderProps(); });
      box.appendChild(s);
    });
    return box;
  }

  // ---------- crear elementos ----------
  function addMesa(forma) {
    var p = piso();
    p.mesas.push({ id: tmpSeq--, numero: String(p.mesas.length + 1), capacidad: 4, forma: forma, pos_x: 40, pos_y: 40, ancho: 60, alto: 60 });
    renderCanvas();
  }
  function addElem(tipo) {
    var p = piso();
    p.elementos.push({ id: tmpSeq--, tipo: tipo, texto: tipo === 'etiqueta' ? 'Texto' : null, pos_x: 40, pos_y: 40, ancho: tipo === 'etiqueta' ? 90 : 120, alto: tipo === 'etiqueta' ? 24 : 18 });
    renderCanvas();
  }

  // ---------- guardar ----------
  function save(statusEl) {
    var p = piso();
    statusEl.textContent = 'Guardando…';
    post('guardar_piso', { piso_id: p.id, mesas: JSON.stringify(p.mesas), elementos: JSON.stringify(p.elementos) })
      .then(function (d) {
        if (d.ok) {
          // reemplazar ids temporales por los reales
          if (d.idmap) {
            p.mesas.forEach(function (m) { if (d.idmap[String(m.id)]) m.id = d.idmap[String(m.id)]; });
            p.elementos.forEach(function (e) { if (d.idmap[String(e.id)]) e.id = d.idmap[String(e.id)]; });
          }
          statusEl.textContent = 'Guardado ✓';
          renderCanvas();
        } else { statusEl.textContent = 'Error al guardar'; }
        setTimeout(function () { statusEl.textContent = ''; }, 2500);
      });
  }

  function subirFondo(file, statusEl) {
    var p = piso();
    var fd = new FormData(); fd.append('action', 'subir_fondo'); fd.append('piso_id', p.id); fd.append('fondo', file);
    statusEl.textContent = 'Subiendo fondo…';
    fetch(api + '?action=subir_fondo', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.ok) { p.fondo_img = d.fondo_img; renderCanvas(); } statusEl.textContent = d.ok ? 'Fondo listo ✓' : 'Error'; setTimeout(function () { statusEl.textContent = ''; }, 2500); });
  }

  // ---------- render maestro ----------
  function renderAll() { renderTabs(); renderCanvas(); renderProps(); }

  function init(opts) {
    api = window.EG_MESAS_API; csrf = window.EG_CSRF; uploadUrl = window.EG_UPLOAD_URL || '';
    st.ubi = opts.ubicacionId; st.pisos = opts.pisos || []; st.pi = 0; st.sel = null;
    mount = opts.mount;
    mount.innerHTML =
      '<div class="pe-tabs"></div>' +
      '<div class="pe-main">' +
        '<div class="pe-tools">' +
          '<div class="pe-tool" data-t="mesaR">⬤ Mesa redonda</div>' +
          '<div class="pe-tool" data-t="mesaC">▢ Mesa cuadrada</div>' +
          '<div class="pe-tool" data-t="etiqueta">🔤 Etiqueta</div>' +
          '<div class="pe-tool" data-t="forma">▬ Barra / pared</div>' +
          '<label class="pe-tool" style="cursor:pointer">🖼 Fondo<input type="file" accept="image/*" style="display:none"></label>' +
          '<div class="pe-save">Guardar</div>' +
          '<div class="pe-status"></div>' +
        '</div>' +
        '<div class="pe-canvas-wrap"><div class="pe-canvas"></div></div>' +
        '<div class="pe-props"></div>' +
      '</div>';
    elTabs = mount.querySelector('.pe-tabs');
    elCanvas = mount.querySelector('.pe-canvas');
    elProps = mount.querySelector('.pe-props');
    var statusEl = mount.querySelector('.pe-status');
    mount.querySelector('[data-t="mesaR"]').addEventListener('click', function () { addMesa('redonda'); });
    mount.querySelector('[data-t="mesaC"]').addEventListener('click', function () { addMesa('cuadrada'); });
    mount.querySelector('[data-t="etiqueta"]').addEventListener('click', function () { addElem('etiqueta'); });
    mount.querySelector('[data-t="forma"]').addEventListener('click', function () { addElem('forma'); });
    mount.querySelector('.pe-save').addEventListener('click', function () { save(statusEl); });
    mount.querySelector('input[type=file]').addEventListener('change', function () { if (this.files[0]) subirFondo(this.files[0], statusEl); });
    // clic en vacío deselecciona
    mount.querySelector('.pe-canvas-wrap').addEventListener('pointerdown', function (e) {
      if (e.target === this || e.target === elCanvas) { st.sel = null; renderCanvas(); renderProps(); }
    });
    if (!st.pisos.length) {
      // crear un primer piso por defecto
      post('crear_piso', { ubicacion_id: st.ubi, nombre: 'Piso 1' }).then(function (d) { if (d.ok) { st.pisos.push(d.piso); renderAll(); } });
    } else { renderAll(); }
  }

  window.PlanoEditor = { init: init };
})();
```

- [ ] **Step 2: Verificar sintaxis**

Run: `node --check assets/js/plano-editor.js`
Expected: exit 0, sin salida.

- [ ] **Step 3: Self-review**

Confirma: mesas y elementos nuevos usan ids temporales negativos (`tmpSeq--`); al guardar, `idmap` reemplaza esos ids por los reales; drag y resize usan `snap()`; el panel de propiedades edita por referencia al objeto en memoria; eliminar quita del arreglo y re-renderiza.

- [ ] **Step 4: Commit**

```bash
git add assets/js/plano-editor.js
git commit -m "feat(mesas): plano-editor.js — editor de lienzo (drag, resize, props, pisos, guardar)"
```

---

### Task 6: Página del editor (`admin/mesas/index.php`) + sidebar + CSS

**Files:**
- Create: `admin/mesas/index.php`
- Modify: `assets/css/style.css` (bloque `.pe-*`)
- Modify: `admin/layout-top.php` (link en grupo Operación)

**Interfaces:**
- Consumes: `PlanoEditor.init` (Task 5), `api/mesas.php` (Task 3), `can('mesas')`.

- [ ] **Step 1: Crear la página del editor**

Create `admin/mesas/index.php`:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('mesas');

$ready = (bool) Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='mesa_pisos'");

$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, sort_order, nombre");
$ubiSel = cleanInt($_GET['ubicacion_id'] ?? 0) ?: (int)($ubis[0]['id'] ?? 0);

$pisos = [];
if ($ready && $ubiSel) {
    foreach (Database::fetchAll("SELECT id FROM mesa_pisos WHERE ubicacion_id = ? AND activo = 1 ORDER BY orden, id", [$ubiSel]) as $row) {
        $pid = (int)$row['id'];
        $p = Database::fetch("SELECT id, nombre, orden, fondo_img, ancho, alto FROM mesa_pisos WHERE id = ?", [$pid]);
        $p['mesas'] = Database::fetchAll("SELECT id, numero, capacidad, forma, pos_x, pos_y, ancho, alto FROM mesas WHERE piso_id = ? AND activa = 1 ORDER BY id", [$pid]);
        $p['elementos'] = Database::fetchAll("SELECT id, tipo, texto, pos_x, pos_y, ancho, alto FROM mesa_elementos WHERE piso_id = ? ORDER BY id", [$pid]);
        $pisos[] = $p;
    }
}

$pageTitle = 'Mesas / Plano';
$activePage = 'mesas';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1>Mesas / Plano</h1></div>
  <div class="page-header-right">
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <label style="font-size:13px;color:var(--text-muted)">Local</label>
      <select name="ubicacion_id" onchange="this.form.submit()">
        <?php foreach ($ubis as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id']===$ubiSel?'selected':'' ?>><?= clean($u['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
      <a href="<?= APP_URL ?>/admin/mesas/tablero.php?ubicacion_id=<?= $ubiSel ?>" class="btn btn-secondary">Ver tablero</a>
    </form>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="card-body"><p>El módulo de mesas necesita su migración. Aplica <code>install/56_mesas.sql</code> en phpMyAdmin y recarga.</p></div></div>
<?php elseif (!$ubiSel): ?>
  <div class="card"><div class="card-body"><p>Crea primero una ubicación activa.</p></div></div>
<?php else: ?>
  <div id="plano-editor"></div>
<?php endif; ?>

<?php if ($ready && $ubiSel): ?>
<script>
window.EG_MESAS_API  = '<?= APP_URL ?>/api/mesas.php';
window.EG_CSRF       = <?= json_encode(csrfToken()) ?>;
window.EG_UPLOAD_URL = '<?= UPLOAD_URL ?>';
</script>
<script src="<?= APP_URL ?>/assets/js/plano-editor.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/plano-editor.js') ?: time() ?>"></script>
<script>
PlanoEditor.init({
  mount: document.getElementById('plano-editor'),
  ubicacionId: <?= $ubiSel ?>,
  pisos: <?= json_encode($pisos, JSON_UNESCAPED_UNICODE) ?>
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Añadir los estilos del editor a style.css**

Agrega al final de `assets/css/style.css`:

```css
/* ── Editor de plano (Mesas) ── */
.pe-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.pe-tab{background:#262626;color:#bbb;font-weight:700;padding:7px 14px;border-radius:9px;font-size:13px;cursor:pointer}
.pe-tab.on{background:#FFDF00;color:#1E1E1E;font-weight:800}
.pe-tab.pe-add{color:#FFBBC8;background:#262626}
.pe-main{display:flex;gap:12px;align-items:flex-start}
.pe-tools{display:flex;flex-direction:column;gap:8px;width:160px;flex-shrink:0}
.pe-tool{background:#fff;border:1.5px solid var(--border,#e3e3e3);border-radius:9px;padding:9px 11px;font-size:13px;font-weight:700;cursor:pointer}
.pe-tool:hover{border-color:#FFDF00}
.pe-save{background:#FFDF00;color:#1E1E1E;text-align:center;font-weight:800;padding:10px;border-radius:9px;cursor:pointer}
.pe-status{font-size:12px;color:var(--text-muted,#888);text-align:center;min-height:16px}
.pe-canvas-wrap{flex:1;overflow:auto;border:1px solid var(--border,#e3e3e3);border-radius:10px;background:
  repeating-linear-gradient(0deg,#f0ede6,#f0ede6 1px,transparent 1px,transparent 20px),
  repeating-linear-gradient(90deg,#f0ede6,#f0ede6 1px,transparent 1px,transparent 20px),#f7f5f0;max-height:70vh}
.pe-canvas{position:relative}
.pe-props{width:180px;flex-shrink:0;background:#fff;border:1px solid var(--border,#e3e3e3);border-radius:10px;padding:12px}
@media(max-width:760px){.pe-main{flex-direction:column}.pe-tools,.pe-props{width:100%}.pe-tools{flex-direction:row;flex-wrap:wrap}.pe-tool{flex:1;min-width:120px}}
```

- [ ] **Step 3: Añadir el link en el sidebar (grupo Operación)**

En `admin/layout-top.php`, dentro del grupo `data-sb-group="operacion"` (la sección "Operación · POS y Cartas"), después del link del KDS y dentro del mismo `.sb-items`, añade (gateado por `can('mesas')`):

```php
        <?php if (can('mesas')): ?>
        <a href="<?php echo APP_URL; ?>/admin/mesas/index.php"
           class="nav-link <?php echo ($activePage??'')==='mesas'?'active':''; ?>">
          <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span> Mesas / Plano
        </a>
        <?php endif; ?>
```
(Además, añade `|| can('mesas')` a la condición `if (...)` que abre el grupo Operación, para que el grupo aparezca cuando el usuario solo tenga el permiso `mesas`.)

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/mesas/index.php && php -l admin/layout-top.php`
Expected: `No syntax errors detected` (ambos)

- [ ] **Step 5: Verificación funcional (post-deploy)**

Acceptance: en `/admin/mesas/index.php` con un local elegido, aparece el editor; "Mesa redonda/cuadrada" agrega mesas; se arrastran y redimensionan; el panel edita número/comensales/forma; "＋ Piso" crea un piso; "Guardar" persiste (recargar muestra las mesas guardadas); subir fondo lo muestra detrás.

- [ ] **Step 6: Commit**

```bash
git add admin/mesas/index.php assets/css/style.css admin/layout-top.php
git commit -m "feat(mesas): página del editor de plano + estilos + link en sidebar"
```

---

### Task 7: Tablero (`admin/mesas/tablero.php`)

**Files:**
- Create: `admin/mesas/tablero.php`

**Interfaces:**
- Consumes: `PlanoRender.draw` (Task 4), `can('mesas')`.

- [ ] **Step 1: Crear el tablero**

Create `admin/mesas/tablero.php`:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('mesas');

$ready = (bool) Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='mesa_pisos'");
$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY es_principal DESC, sort_order, nombre");
$ubiSel = cleanInt($_GET['ubicacion_id'] ?? 0) ?: (int)($ubis[0]['id'] ?? 0);

$pisos = [];
if ($ready && $ubiSel) {
    foreach (Database::fetchAll("SELECT id FROM mesa_pisos WHERE ubicacion_id = ? AND activo = 1 ORDER BY orden, id", [$ubiSel]) as $row) {
        $pid = (int)$row['id'];
        $p = Database::fetch("SELECT id, nombre, orden, fondo_img, ancho, alto FROM mesa_pisos WHERE id = ?", [$pid]);
        $p['mesas'] = Database::fetchAll("SELECT id, numero, capacidad, forma, pos_x, pos_y, ancho, alto FROM mesas WHERE piso_id = ? AND activa = 1 ORDER BY id", [$pid]);
        $p['elementos'] = Database::fetchAll("SELECT id, tipo, texto, pos_x, pos_y, ancho, alto FROM mesa_elementos WHERE piso_id = ? ORDER BY id", [$pid]);
        $pisos[] = $p;
    }
}

$pageTitle = 'Tablero de mesas';
$activePage = 'mesas';
include __DIR__ . '/../layout-top.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1>Tablero de mesas</h1></div>
  <div class="page-header-right"><a href="<?= APP_URL ?>/admin/mesas/index.php?ubicacion_id=<?= $ubiSel ?>" class="btn btn-secondary">Editar plano</a></div>
</div>

<?php if (!$ready): ?>
  <div class="card"><div class="card-body"><p>Aplica <code>install/56_mesas.sql</code> en phpMyAdmin y recarga.</p></div></div>
<?php elseif (!$pisos): ?>
  <div class="card"><div class="card-body"><p>No hay pisos/mesas para este local todavía. <a href="<?= APP_URL ?>/admin/mesas/index.php?ubicacion_id=<?= $ubiSel ?>">Arma el plano</a>.</p></div></div>
<?php else: ?>
  <div id="tb-tabs" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px"></div>
  <div id="tb-board" class="card" style="padding:10px"></div>
<?php endif; ?>

<?php if ($ready && $pisos): ?>
<script src="<?= APP_URL ?>/assets/js/plano-render.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/plano-render.js') ?: time() ?>"></script>
<script>
var PISOS = <?= json_encode($pisos, JSON_UNESCAPED_UNICODE) ?>;
var UPLOAD = '<?= UPLOAD_URL ?>';
var cur = 0;
var tabs = document.getElementById('tb-tabs');
var board = document.getElementById('tb-board');
function draw() {
  PlanoRender.draw(board, PISOS[cur], { uploadUrl: UPLOAD, onMesaTap: function (id, m) { /* Sub-build B: abrir cuenta */ } });
}
PISOS.forEach(function (p, i) {
  var t = document.createElement('span');
  t.style.cssText = 'padding:7px 14px;border-radius:9px;font-weight:700;font-size:13px;cursor:pointer;background:' + (i === 0 ? '#FFDF00' : '#eee') + ';color:#1E1E1E';
  t.textContent = p.nombre;
  t.addEventListener('click', function () { cur = i; tabs.querySelectorAll('span').forEach(function (s, j) { s.style.background = j === i ? '#FFDF00' : '#eee'; }); draw(); });
  tabs.appendChild(t);
});
window.addEventListener('resize', draw);
draw();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l admin/mesas/tablero.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Verificación funcional (post-deploy)**

Acceptance: `/admin/mesas/tablero.php` muestra las mesas del plano guardado, escaladas al ancho de la pantalla, todas en estado "libre" (blanco/borde verde), con pestañas por piso. Tocar una mesa no hace nada todavía (gancho para Sub-build B).

- [ ] **Step 4: Commit**

```bash
git add admin/mesas/tablero.php
git commit -m "feat(mesas): tablero de mesas (render read-only por piso)"
```

---

## Self-Review

**Spec coverage:**
- A.1 modelo de datos (3 tablas) → Task 1. ✅
- A.2 editor (lienzo, drag, resize, props, pisos, fondo, decoración) → Task 5 (editor.js) + Task 6 (página). ✅
- A.3 render reutilizable + tablero + mapa de colores → Task 4 (render.js) + Task 7 (tablero). ✅
- A.4 API (plano, guardar_piso upsert+delete, crear/renombrar/eliminar piso, subir_fondo) → Task 3. ✅
- A.5 permiso `mesas` + sidebar + multi-local → Task 2 + Task 6 Step 3. ✅
- Coordenadas lógicas + escala responsive → render.js (scale por clientWidth) + editor (canvas a tamaño lógico con scroll). ✅

**Type/contract consistency:** el JSON del plano (`{id,numero,capacidad,forma,pos_x,pos_y,ancho,alto}` para mesas; `{id,tipo,texto,pos_x,pos_y,ancho,alto}` para elementos) es idéntico en api/mesas.php (Task 3), plano-render.js (Task 4), plano-editor.js (Task 5) y las páginas (Tasks 6,7). `guardar_piso` espera `mesas`/`elementos` como JSON string y devuelve `idmap`; el editor lo consume así. Globals `EG_MESAS_API`/`EG_CSRF`/`EG_UPLOAD_URL` definidos en la página (Task 6) y leídos por editor.js (Task 5).

**Placeholder scan:** sin TBD/TODO; todo el código está completo. El `onMesaTap` vacío en el tablero (Task 7) es un gancho intencional documentado para Sub-build B, no un placeholder de implementación.

**Notas de integración a verificar en ejecución:**
- En `admin/layout-top.php`, la condición que abre el grupo Operación debe incluir `|| can('mesas')` (Task 6 Step 3) para que el grupo se muestre a quien solo tenga ese permiso.
- `csrfToken()` y `UPLOAD_URL` existen en helpers/config (usados por otras páginas) — confirmar al transcribir.
