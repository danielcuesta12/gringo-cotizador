# Control de Asistencia — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Módulo de control de asistencia con marcaje anti-trampa Nivel 1 (selfie + GPS/geocerca + hora de servidor + revisión manual), padrón de empleados, y corrección manual de olvidos.

**Architecture:** Patrón admin del proyecto (config+database+helpers, `requirePermission`, `layout-top/bottom`). Página de marcaje **pública con token** (como `solicitud.php`: sin CSRF, con honeypot). Datos en 2 tablas nuevas (`empleados`, `asistencia_marcas`) + columnas de geocerca en `ubicaciones`. Reutiliza el patrón de foto+autoborrado de `gastos`.

**Tech Stack:** PHP 8 + MySQL/PDO, JS vanilla (getUserMedia + geolocation). **Sin framework de tests** → verificación = `php -l` + prueba manual en navegador.

**Spec:** `docs/superpowers/specs/2026-06-15-control-asistencia-design.md`
**Mockup:** `docs/superpowers/specs/mockups/asistencia.html`

---

## Estructura de archivos

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `install/42_asistencia.sql` | Tablas `empleados`, `asistencia_marcas` + columnas geocerca en `ubicaciones` | Crear |
| `includes/permissions.php` | Permiso `asistencia` (grupo "Personal") + ruta | Modificar |
| `admin/layout-top.php` | Grupo "Personal" en el sidebar | Modificar |
| `admin/locations/form.php` | Campos de geocerca/modo + botón capturar ubicación | Modificar |
| `admin/asistencia/empleados.php` | Listado de empleados | Crear |
| `admin/asistencia/empleado_form.php` | Alta/edición de empleado | Crear |
| `asistencia/marcar.php` | Página pública de marcaje (selfie+GPS) | Crear |
| `api/asistencia.php` | Endpoint de marcaje (token, foto, haversine, tipo) | Crear |
| `admin/asistencia/index.php` | Revisión de marcajes + corrección manual + autoborrado fotos | Crear |
| `admin/asistencia/reporte.php` | Reporte de horas por empleado/periodo | Crear |

> No hay tests automatizados; cada tarea deja el código con `php -l` OK y se verifica manualmente.

---

## Task 1: Migración (tablas + columnas de geocerca)

**Files:**
- Create: `install/42_asistencia.sql`

- [ ] **Step 1: Crear la migración**

Contenido de `install/42_asistencia.sql`:

```sql
-- Control de asistencia: padrón de empleados + ledger de marcas + geocerca por local.

CREATE TABLE IF NOT EXISTS empleados (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(120) NOT NULL,
  foto_referencia VARCHAR(255) NULL,
  ubicacion_id    INT NULL,
  user_id         INT NULL,                 -- vínculo opcional a users (no se duplica)
  pin_hash        VARCHAR(255) NULL,        -- PIN opcional (hash); NULL = sin PIN
  cargo           VARCHAR(80) NULL,
  activo          TINYINT NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asistencia_marcas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  empleado_id     INT NOT NULL,
  ubicacion_id    INT NULL,
  tipo            ENUM('entrada','salida') NOT NULL,
  foto            VARCHAR(255) NULL,        -- ruta relativa; se borra a los 2 meses
  lat             DECIMAL(10,7) NULL,
  lng             DECIMAL(10,7) NULL,
  distancia_m     INT NULL,
  dentro_geocerca TINYINT NOT NULL DEFAULT 1,
  fuente          ENUM('tablet','celular') NOT NULL DEFAULT 'tablet',
  verificacion    VARCHAR(40) NULL,         -- reservado para facial/liveness futuro
  origen          ENUM('app','manual') NOT NULL DEFAULT 'app',
  nota            VARCHAR(255) NULL,        -- motivo del ajuste manual
  registrada_por  INT NULL,                 -- user_id del admin si fue manual
  marcada_at      DATETIME NOT NULL,        -- hora del SERVIDOR
  KEY idx_emp_fecha (empleado_id, marcada_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE ubicaciones
  ADD COLUMN lat             DECIMAL(10,7) NULL,
  ADD COLUMN lng             DECIMAL(10,7) NULL,
  ADD COLUMN geocerca_radio  INT NOT NULL DEFAULT 100,
  ADD COLUMN geocerca_activa TINYINT NOT NULL DEFAULT 0,
  ADD COLUMN modo_marcaje    ENUM('tablet','celular') NOT NULL DEFAULT 'tablet',
  ADD COLUMN asistencia_token VARCHAR(40) NULL;
```

- [ ] **Step 2: Revisión visual** — confirmar que los tipos coinciden con el spec. (No hay BD local; se aplica en phpMyAdmin al desplegar.)

- [ ] **Step 3: Commit**

```bash
git add install/42_asistencia.sql
git commit -m "feat(asistencia): migración — empleados, asistencia_marcas y geocerca en ubicaciones"
```

---

## Task 2: Permiso `asistencia` + grupo en el sidebar

**Files:**
- Modify: `includes/permissions.php` (catálogo ~línea 48-51 y mapa de rutas ~línea 117)
- Modify: `admin/layout-top.php` (tras el grupo Finanzas ~línea 324)

- [ ] **Step 1: Añadir el permiso al catálogo**

En `includes/permissions.php`, después del grupo `'Finanzas' => [ 'gastos' => 'Registro de gastos', ]`, añadir un grupo nuevo:

```php
        'Personal' => [
            'asistencia' => 'Control de asistencia',
        ],
```

- [ ] **Step 2: Añadir la ruta en `firstAllowedPath`**

En el mapa de rutas de `includes/permissions.php` (donde está `'gastos' => '/admin/gastos/index.php'`), añadir:

```php
        'asistencia' => '/admin/asistencia/index.php',
```

- [ ] **Step 3: Añadir el grupo al sidebar**

En `admin/layout-top.php`, después del bloque del grupo Finanzas (`<?php if (can('gastos')): ?> ... <?php endif; ?>`), añadir un grupo análogo (copiar el estilo/estructura del de Finanzas, con su acento):

```php
    <?php /* ===== 6.6 Personal ===== */ ?>
    <?php if (can('asistencia')): ?>
      <div class="sb-group">
        <button type="button" class="sb-group-head" onclick="sbToggle(this)">
          <span class="sb-dot sb-dot-pink"></span>Personal<span class="sb-chevron">&#9662;</span>
        </button>
        <div class="sb-group-items">
          <a href="<?= APP_URL ?>/admin/asistencia/index.php" class="sb-link <?= $activePage==='asistencia'?'active':'' ?>">Asistencia</a>
        </div>
      </div>
    <?php endif; ?>
```

(Ajustar nombres de clases/estructura a lo que use REALMENTE el grupo de Finanzas en ese archivo; copiar ese bloque y cambiar textos.)

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l includes/permissions.php && php -l admin/layout-top.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add includes/permissions.php admin/layout-top.php
git commit -m "feat(asistencia): permiso 'asistencia' + grupo Personal en el sidebar"
```

---

## Task 3: Geocerca y modo de marcaje en Ubicaciones

**Files:**
- Modify: `admin/locations/form.php` (defaults del array, columnas de INSERT/UPDATE, y el HTML del form)

- [ ] **Step 1: Capturar los campos en el POST**

En `admin/locations/form.php`, en el array `$data` (donde se arman los campos del POST), añadir:

```php
        'lat'             => ($_POST['lat'] ?? '') !== '' ? (float)$_POST['lat'] : null,
        'lng'             => ($_POST['lng'] ?? '') !== '' ? (float)$_POST['lng'] : null,
        'geocerca_radio'  => max(20, (int)($_POST['geocerca_radio'] ?? 100)),
        'geocerca_activa' => !empty($_POST['geocerca_activa']) ? 1 : 0,
        'modo_marcaje'    => in_array($_POST['modo_marcaje'] ?? '', ['tablet','celular']) ? $_POST['modo_marcaje'] : 'tablet',
```

Y en los defaults (el array que inicializa `$data` para alta nueva), añadir: `'lat'=>null,'lng'=>null,'geocerca_radio'=>100,'geocerca_activa'=>0,'modo_marcaje'=>'tablet'`.

- [ ] **Step 2: Incluir las columnas en INSERT y UPDATE**

Añadir `lat,lng,geocerca_radio,geocerca_activa,modo_marcaje` a la lista de columnas y sus `?` y `$params` tanto en el UPDATE como en el INSERT de `admin/locations/form.php` (seguir el patrón exacto que ya usa el archivo para las demás columnas). El `asistencia_token` se genera si está vacío: antes del guardado, `if (empty($data_token_actual)) $token = generateToken(20);` — guardarlo en la columna `asistencia_token` (añadir esa columna también al INSERT/UPDATE, generándola una sola vez por ubicación).

- [ ] **Step 3: Añadir el HTML del form (tras el bloque de sales_mode)**

```php
        <div class="form-group">
          <label>Modo de marcaje de asistencia</label>
          <select name="modo_marcaje">
            <option value="tablet"  <?= $data['modo_marcaje']==='tablet'?'selected':'' ?>>Tablet/equipo fijo en el local</option>
            <option value="celular" <?= $data['modo_marcaje']==='celular'?'selected':'' ?>>Celular propio (food truck / delivery)</option>
          </select>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Latitud</label>
            <input type="text" name="lat" id="loc-lat" value="<?= $data['lat'] !== null ? clean((string)$data['lat']) : '' ?>">
          </div>
          <div class="form-group">
            <label>Longitud</label>
            <input type="text" name="lng" id="loc-lng" value="<?= $data['lng'] !== null ? clean((string)$data['lng']) : '' ?>">
          </div>
        </div>
        <button type="button" class="btn btn-secondary" onclick="capturarUbicacion()">📍 Capturar mi ubicación actual</button>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Radio de geocerca (metros)</label>
            <input type="number" name="geocerca_radio" min="20" value="<?= (int)$data['geocerca_radio'] ?>">
          </div>
          <div class="form-group" style="display:flex;align-items:center;gap:8px;margin-top:24px">
            <input type="checkbox" name="geocerca_activa" value="1" id="geo-act" <?= !empty($data['geocerca_activa'])?'checked':'' ?>>
            <label for="geo-act" style="margin:0">Geocerca activa (apágala para el food truck)</label>
          </div>
        </div>
        <script>
        function capturarUbicacion(){
          if(!navigator.geolocation){ alert('Tu navegador no da ubicación'); return; }
          navigator.geolocation.getCurrentPosition(function(p){
            document.getElementById('loc-lat').value = p.coords.latitude.toFixed(7);
            document.getElementById('loc-lng').value = p.coords.longitude.toFixed(7);
          }, function(){ alert('No se pudo obtener la ubicación. Da permiso de GPS.'); }, {enableHighAccuracy:true});
        }
        </script>
```

- [ ] **Step 4: Verificar sintaxis**

Run: `php -l admin/locations/form.php`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit**

```bash
git add admin/locations/form.php
git commit -m "feat(asistencia): geocerca y modo de marcaje configurables por ubicación"
```

---

## Task 4: CRUD de empleados

**Files:**
- Create: `admin/asistencia/empleados.php` (listado)
- Create: `admin/asistencia/empleado_form.php` (alta/edición)

- [ ] **Step 1: Listado `admin/asistencia/empleados.php`**

Seguir el patrón admin estándar. Cabecera:

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
requirePermission('asistencia');
$empleados = Database::fetchAll(
  "SELECT e.*, u.nombre AS ubi_nombre FROM empleados e LEFT JOIN ubicaciones u ON u.id=e.ubicacion_id ORDER BY e.activo DESC, e.nombre"
);
$pageTitle = 'Empleados'; $activePage = 'asistencia';
include __DIR__ . '/../../admin/layout-top.php';
?>
```
Contenido: tabla con foto de referencia (thumbnail desde `UPLOAD_URL . $e['foto_referencia']`), nombre, cargo, local, PIN (sí/no), activo, y botón "Editar" → `empleado_form.php?id=`. Botón "+ Nuevo empleado". Cerrar con `include layout-bottom.php`.

- [ ] **Step 2: Form `admin/asistencia/empleado_form.php`**

Cabecera igual (requirePermission('asistencia')). POST con `verifyCsrf()`. Campos: `nombre` (req), `foto_referencia` (file → `uploadImage($_FILES['foto'], 'empleados')`, retorna ruta relativa), `ubicacion_id` (select de ubicaciones), `user_id` (select opcional de users, "— ninguno —"), `pin` (texto 4 dígitos, opcional → guardar `password_hash($pin, PASSWORD_DEFAULT)` solo si no vacío; si vacío en edición, conservar el existente), `cargo`, `activo` (checkbox). Guardado:

```php
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $nombre = clean($_POST['nombre'] ?? '');
    $ubicacionId = cleanInt($_POST['ubicacion_id'] ?? 0) ?: null;
    $userId = cleanInt($_POST['user_id'] ?? 0) ?: null;
    $cargo  = clean($_POST['cargo'] ?? '');
    $activo = !empty($_POST['activo']) ? 1 : 0;
    $pin    = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    $pinHash = ($pin !== '') ? password_hash($pin, PASSWORD_DEFAULT) : null;
    $foto = $existing['foto_referencia'] ?? null;
    if (!empty($_FILES['foto']['name'])) { $up = uploadImage($_FILES['foto'], 'empleados'); if ($up) $foto = $up; }
    if ($nombre === '') { flashMessage('error','El nombre es obligatorio.'); redirect('/admin/asistencia/empleado_form'); }
    if ($id > 0) {
        // si pin vacío, no tocar pin_hash
        if ($pinHash !== null) {
            Database::execute("UPDATE empleados SET nombre=?,foto_referencia=?,ubicacion_id=?,user_id=?,pin_hash=?,cargo=?,activo=? WHERE id=?",
                [$nombre,$foto,$ubicacionId,$userId,$pinHash,$cargo,$activo,$id]);
        } else {
            Database::execute("UPDATE empleados SET nombre=?,foto_referencia=?,ubicacion_id=?,user_id=?,cargo=?,activo=? WHERE id=?",
                [$nombre,$foto,$ubicacionId,$userId,$cargo,$activo,$id]);
        }
    } else {
        Database::insert("INSERT INTO empleados (nombre,foto_referencia,ubicacion_id,user_id,pin_hash,cargo,activo) VALUES (?,?,?,?,?,?,?)",
            [$nombre,$foto,$ubicacionId,$userId,$pinHash,$cargo,$activo]);
    }
    flashMessage('success','Empleado guardado.');
    redirect('/admin/asistencia/empleados');
}
```

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/asistencia/empleados.php && php -l admin/asistencia/empleado_form.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add admin/asistencia/empleados.php admin/asistencia/empleado_form.php
git commit -m "feat(asistencia): CRUD de empleados (foto, local, vínculo a usuario, PIN)"
```

---

## Task 5: Página pública de marcaje

**Files:**
- Create: `asistencia/marcar.php`

- [ ] **Step 1: Crear la página (pública con token, sin CSRF, con honeypot)**

Patrón de `solicitud.php`: requires al tope (config/database/helpers ANTES de cualquier HTML). Lógica PHP:

```php
<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$slug  = clean($_GET['u'] ?? '');
$token = clean($_GET['t'] ?? '');
$ubi = Database::fetch("SELECT * FROM ubicaciones WHERE slug=? AND asistencia_token=? AND activa=1", [$slug, $token]);
if (!$ubi || $token === '') { http_response_code(404); exit('Enlace de marcaje inválido.'); }
$empleados = Database::fetchAll("SELECT id, nombre, foto_referencia, (pin_hash IS NOT NULL) AS tiene_pin FROM empleados WHERE ubicacion_id=? AND activo=1 ORDER BY nombre", [$ubi['id']]);
?>
```
HTML: replicar la estructura visual del mockup `asistencia.html` (vista "Marcaje"): header con local, grilla del padrón, panel de selfie + GPS + botones Entrada/Salida. JS:
- `getUserMedia({video:{facingMode:'user'}})` para mostrar la cámara; al marcar, dibujar el frame en un `<canvas>` y `canvas.toDataURL('image/jpeg',0.7)` → base64.
- `navigator.geolocation.getCurrentPosition` para lat/lng (si falla, marcar igual sin coords).
- Botones Entrada/Salida: el seleccionado se manda como `tipo`. Resaltar el sugerido (consultar al cargar la última marca del empleado vía un fetch opcional, o simplemente resaltar "Entrada" por defecto — la sugerencia es solo visual).
- POST a `api/asistencia.php` con `{ubicacion_id, token, empleado_id, pin, tipo, foto(base64), lat, lng, fuente, website(honeypot)}`.
- Aviso de consentimiento visible (foto para control de asistencia, se borra a 2 meses).
- `fuente` = `<?= $ubi['modo_marcaje'] ?>`.

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l asistencia/marcar.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add asistencia/marcar.php
git commit -m "feat(asistencia): página pública de marcaje (selfie + GPS + entrada/salida)"
```

---

## Task 6: API de marcaje

**Files:**
- Create: `api/asistencia.php`

- [ ] **Step 1: Crear el endpoint**

```php
<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!empty($in['website'])) { echo json_encode(['ok'=>false]); exit; } // honeypot

$token = clean($in['token'] ?? '');
$empId = cleanInt($in['empleado_id'] ?? 0);
$tipo  = in_array($in['tipo'] ?? '', ['entrada','salida']) ? $in['tipo'] : '';
$ubi = Database::fetch("SELECT * FROM ubicaciones WHERE id=? AND asistencia_token=? AND activa=1", [cleanInt($in['ubicacion_id'] ?? 0), $token]);
if (!$ubi || $token === '' || !$tipo) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }

$emp = Database::fetch("SELECT * FROM empleados WHERE id=? AND ubicacion_id=? AND activo=1", [$empId, $ubi['id']]);
if (!$emp) { echo json_encode(['ok'=>false,'error'=>'Empleado no válido']); exit; }

// PIN (si el empleado tiene)
if (!empty($emp['pin_hash'])) {
    $pin = preg_replace('/\D/', '', $in['pin'] ?? '');
    if (!password_verify($pin, $emp['pin_hash'])) { echo json_encode(['ok'=>false,'error'=>'PIN incorrecto']); exit; }
}

// Foto (base64 → archivo)
$fotoRel = null;
$b64 = $in['foto'] ?? '';
if (preg_match('#^data:image/\w+;base64,#', $b64)) {
    $bin = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $b64));
    if ($bin !== false && strlen($bin) < 3000000) {
        $name = 'asistencia/' . date('Ymd') . '_' . $empId . '_' . bin2hex(random_bytes(4)) . '.jpg';
        @mkdir(UPLOAD_PATH . 'asistencia', 0775, true);
        if (file_put_contents(UPLOAD_PATH . $name, $bin) !== false) $fotoRel = $name;
    }
}

// GPS + geocerca (haversine)
$lat = isset($in['lat']) && $in['lat'] !== '' ? (float)$in['lat'] : null;
$lng = isset($in['lng']) && $in['lng'] !== '' ? (float)$in['lng'] : null;
$dist = null; $dentro = 1;
if (!empty($ubi['geocerca_activa']) && $ubi['lat'] !== null && $lat !== null) {
    $R = 6371000; $dLat = deg2rad($lat - $ubi['lat']); $dLng = deg2rad($lng - $ubi['lng']);
    $a = sin($dLat/2)**2 + cos(deg2rad($ubi['lat']))*cos(deg2rad($lat))*sin($dLng/2)**2;
    $dist = (int) round($R * 2 * atan2(sqrt($a), sqrt(1-$a)));
    $dentro = $dist <= (int)$ubi['geocerca_radio'] ? 1 : 0;
} elseif (!empty($ubi['geocerca_activa']) && $lat === null) {
    $dentro = 0; // geocerca activa pero sin GPS → marcar en rojo
}

$fuente = in_array($ubi['modo_marcaje'] ?? '', ['tablet','celular']) ? $ubi['modo_marcaje'] : 'tablet';

Database::insert(
  "INSERT INTO asistencia_marcas (empleado_id,ubicacion_id,tipo,foto,lat,lng,distancia_m,dentro_geocerca,fuente,origen,marcada_at)
   VALUES (?,?,?,?,?,?,?,?,?, 'app', NOW())",
  [$empId, $ubi['id'], $tipo, $fotoRel, $lat, $lng, $dist, $dentro, $fuente]
);
echo json_encode(['ok'=>true, 'tipo'=>$tipo, 'dentro'=>$dentro]);
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l api/asistencia.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add api/asistencia.php
git commit -m "feat(asistencia): API de marcaje (token, PIN, foto base64, geocerca haversine, hora servidor)"
```

---

## Task 7: Revisión de marcajes (admin) + autoborrado de fotos

**Files:**
- Create: `admin/asistencia/index.php`

- [ ] **Step 1: Crear la pantalla de revisión**

Cabecera estándar (`requirePermission('asistencia')`, `$activePage='asistencia'`). Antes de consultar, **autoborrado de fotos > 2 meses** (patrón de `gastos/index.php`):

```php
try {
    $viejas = Database::fetchAll("SELECT id, foto FROM asistencia_marcas WHERE foto IS NOT NULL AND foto <> '' AND marcada_at < (NOW() - INTERVAL 2 MONTH)");
    foreach ($viejas as $v) {
        if (is_file(UPLOAD_PATH . $v['foto'])) @unlink(UPLOAD_PATH . $v['foto']);
        Database::execute("UPDATE asistencia_marcas SET foto=NULL WHERE id=?", [$v['id']]);
    }
} catch (Throwable $e) {}
```

Filtros: fecha (default hoy), ubicación, empleado. Query de marcas del día agrupadas/ordenadas por empleado:

```php
$fecha = clean($_GET['fecha'] ?? date('Y-m-d'));
$marcas = Database::fetchAll(
  "SELECT m.*, e.nombre AS emp_nombre, e.cargo, u.nombre AS ubi_nombre
     FROM asistencia_marcas m
     JOIN empleados e ON e.id=m.empleado_id
     LEFT JOIN ubicaciones u ON u.id=m.ubicacion_id
    WHERE DATE(m.marcada_at)=? ORDER BY e.nombre, m.marcada_at", [$fecha]);
```

Render (replicar la vista "Revisión" del mockup): por empleado, emparejar entrada→salida del día; mostrar foto (thumbnail `UPLOAD_URL.$m['foto']`, click abre grande), hora entrada, hora salida, horas trabajadas (diferencia), y **estado**:
- 🔴 rojo si alguna marca tiene `dentro_geocerca=0`.
- 🟠 ámbar "incompleta" si hay entrada sin salida (o salida sin entrada) en el día.
- 🟢 completo si el par está y dentro de geocerca.
- etiqueta "manual" si `origen='manual'`.
Botón **"Agregar marca manual"** y, por fila incompleta, **"Cerrar marca"** → ambos van a un POST manejado en este mismo archivo (Step 2).

- [ ] **Step 2: Corrección manual (POST en el mismo archivo)**

Antes del HTML, manejar el POST (con `verifyCsrf()`):

```php
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $accion = $_POST['accion'] ?? '';
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($accion === 'manual') {
        $empId = cleanInt($_POST['empleado_id'] ?? 0);
        $tipo  = in_array($_POST['tipo'] ?? '', ['entrada','salida']) ? $_POST['tipo'] : 'entrada';
        $fechaHora = clean($_POST['marcada_at'] ?? '');      // 'YYYY-MM-DD HH:MM'
        $nota  = clean($_POST['nota'] ?? '');
        $emp = Database::fetch("SELECT ubicacion_id FROM empleados WHERE id=?", [$empId]);
        if ($empId && $fechaHora) {
            Database::insert(
              "INSERT INTO asistencia_marcas (empleado_id,ubicacion_id,tipo,dentro_geocerca,fuente,origen,nota,registrada_por,marcada_at)
               VALUES (?,?,?,1,'tablet','manual',?,?,?)",
              [$empId, $emp['ubicacion_id'] ?? null, $tipo, ($nota ?: 'Ajuste manual'), $uid, $fechaHora]);
            flashMessage('success','Marca manual agregada.');
        }
        redirect('/admin/asistencia/index.php?fecha=' . urlencode($_POST['fecha'] ?? date('Y-m-d')));
    }
    if ($accion === 'editar') {  // editar hora/tipo de una marca existente → queda manual con nota
        $mid = cleanInt($_POST['marca_id'] ?? 0);
        $fechaHora = clean($_POST['marcada_at'] ?? '');
        $tipo = in_array($_POST['tipo'] ?? '', ['entrada','salida']) ? $_POST['tipo'] : 'entrada';
        $nota = clean($_POST['nota'] ?? 'Ajuste manual');
        if ($mid && $fechaHora) {
            Database::execute("UPDATE asistencia_marcas SET tipo=?, marcada_at=?, origen='manual', nota=?, registrada_por=? WHERE id=?",
              [$tipo, $fechaHora, $nota, $uid, $mid]);
            flashMessage('success','Marca corregida.');
        }
        redirect('/admin/asistencia/index.php?fecha=' . urlencode($_POST['fecha'] ?? date('Y-m-d')));
    }
}
```

El form de "Agregar marca manual" / "Cerrar marca" puede ser un modal simple o una fila de inputs (empleado, tipo, fecha+hora, nota) que postea `accion=manual`. "Cerrar marca" precarga `tipo=salida` y el empleado de la fila incompleta.

- [ ] **Step 3: Verificar sintaxis**

Run: `php -l admin/asistencia/index.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add admin/asistencia/index.php
git commit -m "feat(asistencia): revisión de marcajes (foto, banderas geocerca/incompleta) + corrección manual + autoborrado fotos"
```

---

## Task 8: Reporte de horas

**Files:**
- Create: `admin/asistencia/reporte.php`

- [ ] **Step 1: Crear el reporte**

Cabecera estándar (`requirePermission('asistencia')`). Filtros: rango de fechas (desde/hasta, default mes actual) + empleado opcional. Trae todas las marcas del rango ordenadas por empleado y `marcada_at`, y **empareja en PHP** entrada→salida por empleado por día:

```php
$desde = clean($_GET['desde'] ?? date('Y-m-01'));
$hasta = clean($_GET['hasta'] ?? date('Y-m-d'));
$rows = Database::fetchAll(
  "SELECT m.empleado_id, e.nombre, m.tipo, m.marcada_at
     FROM asistencia_marcas m JOIN empleados e ON e.id=m.empleado_id
    WHERE DATE(m.marcada_at) BETWEEN ? AND ? ORDER BY m.empleado_id, m.marcada_at", [$desde,$hasta]);

// Emparejar: por empleado, una entrada abre y la siguiente salida cierra (mismo día).
$acc = []; // empleado_id => ['nombre'=>, 'segundos'=>0, 'incompletas'=>0]
$abierta = []; // empleado_id => timestamp de entrada abierta (mismo día)
foreach ($rows as $r) {
    $eid = $r['empleado_id'];
    if (!isset($acc[$eid])) $acc[$eid] = ['nombre'=>$r['nombre'],'segundos'=>0,'incompletas'=>0];
    $ts = strtotime($r['marcada_at']); $dia = date('Y-m-d', $ts);
    if ($r['tipo']==='entrada') {
        if (isset($abierta[$eid])) $acc[$eid]['incompletas']++; // entrada previa sin cerrar
        $abierta[$eid] = ['ts'=>$ts,'dia'=>$dia];
    } else { // salida
        if (isset($abierta[$eid]) && $abierta[$eid]['dia']===$dia) {
            $acc[$eid]['segundos'] += max(0, $ts - $abierta[$eid]['ts']);
            unset($abierta[$eid]);
        } else { $acc[$eid]['incompletas']++; } // salida sin entrada del día
    }
}
foreach ($abierta as $eid=>$_) $acc[$eid]['incompletas']++; // entradas que quedaron abiertas
```

Render: tabla por empleado con horas totales (`floor(segundos/3600).'h '.floor((segundos%3600)/60).'m'`) y, si `incompletas>0`, una etiqueta ámbar "N jornadas incompletas — revisar". Cerrar con layout-bottom.

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l admin/asistencia/reporte.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add admin/asistencia/reporte.php
git commit -m "feat(asistencia): reporte de horas por empleado/periodo (jornadas incompletas señaladas)"
```

---

## Verificación final (manual, instancia de prueba)

- [ ] Aplicar `install/42_asistencia.sql` en phpMyAdmin.
- [ ] En Ubicaciones: configurar lat/lng (botón capturar), radio, geocerca activa, modo. Generar token.
- [ ] Crear 2-3 empleados (con y sin PIN; uno vinculado a un usuario).
- [ ] Abrir `asistencia/marcar.php?u=<slug>&t=<token>`: marcar entrada (selfie + GPS), luego salida. Probar PIN correcto/incorrecto.
- [ ] Marcar fuera de la geocerca → en revisión sale **rojo**; con geocerca apagada (food truck) → nunca rojo por ubicación.
- [ ] Dejar una entrada sin salida → en revisión sale **ámbar (incompleta)**; usar "Cerrar marca" → queda **manual** con nota; el reporte ya no la cuenta como incompleta.
- [ ] Reporte de horas suma correctamente los pares entrada/salida.
- [ ] Permiso: un usuario sin `asistencia` no ve el grupo Personal ni entra a las páginas; el token de marcaje funciona sin login.
- [ ] La hora registrada es la del servidor (cambiar la hora del dispositivo no afecta).
