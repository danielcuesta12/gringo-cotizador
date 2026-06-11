# Generador de cartas PDF · Fase 3 — Admin + Editor

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** La sección admin "Generador de cartas PDF": lista de cartas + editor de dos paneles que consume `api/cartas.php` y previsualiza con `carta/carta-print.php`.

**Architecture:** Páginas PHP del panel (layout estándar). El editor es una sola página con JS vanilla que llama a `api/cartas.php` (form-encoded, CSRF por header `X-CSRF-Token`) y refresca un `<iframe>` apuntando a `carta-print.php?id=&preview=1`.

**Tech Stack:** PHP 8, JS vanilla, CSS del design system existente. Reusa `csrfToken()`, `uploadImage` (vía API), `carta-print.php` (Fase 1), `api/cartas.php` (Fase 2).

**Rama:** `generador-cartas`. **Aislamiento:** archivos nuevos + 1 entrada de nav. No toca `carta/banner.php`, `admin/locations/*`, ni `products`. **Verificación:** `php -l` + revisión + QA humano (requiere aplicar `install/cartas.sql`).

## Estructura de archivos

- Create: `admin/cartas/index.php` — lista de cartas + nueva + borrar.
- Create: `admin/cartas/editor.php` — editor de 2 paneles.
- Modify: `admin/layout-top.php` — entrada de nav "Generador de cartas PDF" (`$activePage='cartas-pdf'`).

---

## Tarea 1: `admin/cartas/index.php`

**Files:** Create `admin/cartas/index.php`. (Patrón calcado de `admin/locations/index.php`.)

- [ ] **Step 1: Crear el archivo**

```php
<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
if (!isAdmin()) { flashMessage('error', 'Sin permisos.'); redirect('/admin/dashboard.php'); }

// Crear carta nueva (POST) → redirige al editor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nueva'])) {
    verifyCsrf();
    $nombre = clean($_POST['nombre'] ?? '') ?: 'Carta sin nombre';
    $id = Database::insert("INSERT INTO cartas (nombre) VALUES (?)", [$nombre]);
    redirect('/admin/cartas/editor.php?id=' . (int)$id);
}
// Eliminar carta (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verifyCsrf();
    Database::execute("DELETE FROM cartas WHERE id = ?", [cleanInt($_POST['delete_id'])]);
    flashMessage('success', 'Carta eliminada.');
    redirect('/admin/cartas/index.php');
}

$cartas = Database::fetchAll(
    "SELECT c.*, (SELECT COUNT(*) FROM carta_items i WHERE i.carta_id = c.id) AS item_count
     FROM cartas c ORDER BY c.updated_at DESC, c.id DESC");

$pageTitle  = 'Generador de cartas PDF';
$activePage = 'cartas-pdf';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Generador de cartas PDF</h1>
    <p>Arma cartas a medida (desde una ubicación o libres) y genera el banner imprimible</p>
  </div>
  <form method="post" style="margin:0">
    <?= csrfField() ?>
    <input type="hidden" name="nueva" value="1">
    <button type="submit" class="btn btn-primary" style="gap:6px">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
      Nueva carta
    </button>
  </form>
</div>

<div class="card">
  <?php if (empty($cartas)): ?>
    <div class="empty-state">
      <div class="empty-state-icon" style="color:var(--text-muted)"><svg viewBox="0 0 24 24" width="38" height="38" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/></svg></div>
      <h3>Sin cartas</h3>
      <p>Crea tu primera carta para el generador</p>
    </div>
  <?php else: ?>
  <div class="table-wrap" style="border:none;border-radius:0">
    <table class="data-table">
      <thead><tr><th>Carta</th><th>Tema</th><th>Ítems</th><th>Actualizada</th><th style="width:170px"></th></tr></thead>
      <tbody>
        <?php foreach ($cartas as $c): ?>
        <tr>
          <td><strong><?= clean($c['nombre']) ?></strong></td>
          <td><span class="badge badge-secondary"><?= $c['tema'] === 'dia' ? 'Crema' : 'Nocturna' ?></span></td>
          <td><?= (int)$c['item_count'] ?></td>
          <td style="font-size:12px;color:var(--text-secondary)"><?= formatDate($c['updated_at']) ?></td>
          <td>
            <div class="td-actions">
              <a href="<?= APP_URL ?>/admin/cartas/editor.php?id=<?= $c['id'] ?>" class="btn btn-ghost btn-sm">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>Editar
              </a>
              <form method="post" style="display:inline">
                <?= csrfField() ?><input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" data-confirm="¿Eliminar la carta «<?= clean($c['nombre']) ?>»? Esta acción no se puede deshacer.">
                  <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
```

- [ ] **Step 2:** `php -l admin/cartas/index.php` → sin errores.
- [ ] **Step 3:** Commit: `git add admin/cartas/index.php && git commit -m "feat(generador): lista de cartas (admin/cartas/index.php)"`

---

## Tarea 2: Entrada de nav

**Files:** Modify `admin/layout-top.php`.

- [ ] **Step 1:** Tras el bloque del enlace de "Ubicaciones" (el `<a ... /admin/locations/index.php ...>...Ubicaciones</a>` que cierra con `</a>`) e inmediatamente ANTES del enlace de "Landing", insertar:

```html
    <a href="<?php echo APP_URL; ?>/admin/cartas/index.php"
       class="nav-link <?php echo ($activePage??'')==='cartas-pdf'?'active':''; ?>">
      <span class="nav-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></svg></span> Generador cartas PDF
    </a>
```

- [ ] **Step 2:** `php -l admin/layout-top.php` → sin errores.
- [ ] **Step 3:** Commit: `git add admin/layout-top.php && git commit -m "feat(generador): entrada de nav Generador cartas PDF"`

---

## Tarea 3: `admin/cartas/editor.php` (editor de 2 paneles)

**Files:** Create `admin/cartas/editor.php`.

**Contrato del API** (`api/cartas.php`, ya construido) que el editor consume (POST salvo get/list; CSRF por header `X-CSRF-Token`; respuestas `{ok:bool, ...}`):
`get`(GET id)→`{carta, secciones:[{id,nombre,columnas,items:[{id,nombre,descripcion,precio,foto}]}]}` · `save_meta`(id,nombre,tema,ancho_mm,size_*) · `seccion_create`(carta_id,nombre)→{id} · `seccion_update`(id,nombre,columnas) · `seccion_delete`(id) · `seccion_reorder`(carta_id,ids[]) · `item_create`(carta_id,seccion_id,nombre,precio,descripcion,foto)→{id} · `item_update`(id,nombre,precio,descripcion,foto,[seccion_id]) · `item_delete`(id) · `item_reorder`(carta_id,seccion_id,ids[]) · `cargar_ubicacion`(carta_id,ubicacion_id) · `upload_foto`(FormData foto)→{foto}.

**Estructura PHP (cabecera):** `requireLogin()` + `isAdmin()`; cargar `$id` y la carta (404→redirect a index); cargar ubicaciones activas para el dropdown:
```php
$ubis = Database::fetchAll("SELECT id, nombre FROM ubicaciones WHERE activa = 1 ORDER BY nombre");
```
`$pageTitle='Editar carta'; $activePage='cartas-pdf';` layout-top/bottom. Exponer a JS: `var CARTA_ID = <?= (int)$id ?>; var CSRF = <?= json_encode(csrfToken()) ?>; var API = '<?= APP_URL ?>/api/cartas.php'; var PRINT = '<?= APP_URL ?>/carta/carta-print.php'; var UBIS = <?= json_encode($ubis) ?>;`

**Layout (HTML):** dos columnas flex, alto `calc(100vh - cabecera)`:
- **Barra superior**: input nombre (autosave on change), select de ubicaciones + botón "Cargar desde ubicación", botón "Generar PDF" (abre `PRINT?id=CARTA_ID&theme=<tema>` en pestaña nueva), link "← Volver".
- **Panel izquierdo `#builder`** (scroll): se renderiza desde el estado. Por cada sección: cabecera con input nombre (autosave), toggle **1 / 2 columnas**, botones **↑/↓** (reordenar sección), botón **borrar sección**, y "+ Agregar ítem". Por cada ítem: thumb (o placeholder), nombre, precio, **↑/↓** (reordenar dentro de la sección), **Editar**, **✕**. Al final "+ Agregar sección".
- **Panel derecho** (sticky): toggle **🌙 Noche / ☀️ Crema** (cambia `tema`, autosave + refresca preview), **`<iframe id="preview">`** a `PRINT?id=CARTA_ID&preview=1&theme=<tema>`, panel **Tamaños** con sliders (`size_section,size_name,size_price,size_desc,size_photo,size_header` en mm + `ancho_mm`), cada uno con su número en vivo; cambios → autosave (debounce 400ms) + refresca preview.
- **Modal de ítem** (oculto): form con nombre, precio, descripción (textarea), select de sección (para mover), input file de foto + preview, y botones Guardar/Cancelar/Eliminar. Subida: al elegir archivo → `upload_foto` (FormData) → guarda la ruta devuelta en un hidden y muestra el preview.

**JS — funciones y comportamiento (vanilla):**
- `apiGet(action, params)` → `fetch(API+'?action='+action+params, {headers}).then(r=>r.json())`.
- `apiPost(action, dataObj)` → `fetch(API+'?action='+action, {method:'POST', headers:{'X-CSRF-Token':CSRF,'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(dataObj)}).then(r=>r.json())`. Para arrays (`ids[]`) usar `URLSearchParams` con `append('ids[]', v)`.
- `state` = objeto carta cargado por `get`.
- `load()` → `apiGet('get',{id:CARTA_ID})` → guarda `state` → `renderBuilder()` + set valores de sliders/nombre/tema → `refreshPreview()`.
- `renderBuilder()` → arma el HTML del `#builder` desde `state.secciones`. **Escapar** todo texto con una función `esc(s)` (reemplaza `& < > " '`) al interpolar en `innerHTML`.
- `addSeccion()` → `apiPost('seccion_create',{carta_id:CARTA_ID,nombre:'Nueva sección'})` → `load()`.
- `renameSeccion(id,val)` (debounce) → `apiPost('seccion_update',{id,nombre:val,columnas:<colsActual>})` → `refreshPreview()`.
- `setCols(id,n)` → `apiPost('seccion_update',{id,nombre:<nombreActual>,columnas:n})` → `load()`.
- `delSeccion(id)` → confirm → `apiPost('seccion_delete',{id})` → `load()`.
- `moveSeccion(id,dir)` → recalcula orden de ids → `apiPost('seccion_reorder',{carta_id:CARTA_ID, 'ids[]':[...]})` → `load()`.
- `openItem(secId,itemId)` → abre modal; si itemId, precarga del `state`. `saveItem()` → `item_create` o `item_update` (con `seccion_id` del select) → cerrar modal → `load()`. `delItem(id)` → confirm → `item_delete` → `load()`.
- `moveItem(secId,itemId,dir)` → recalcula orden dentro de la sección → `item_reorder`(carta_id,seccion_id,ids[]) → `load()`.
- `uploadFoto(input)` → FormData → `fetch(API+'?action=upload_foto',{method:'POST',headers:{'X-CSRF-Token':CSRF},body:fd})` → set hidden `foto` + preview.
- `saveMeta()` (debounce 400ms) → recoge nombre, tema, ancho y todos los size_* → `apiPost('save_meta',{...})` → `refreshPreview()`.
- Sliders: `oninput` → actualiza el número mostrado + `saveMeta()`.
- `setTema(t)` → set state.carta.tema → `saveMeta()` + `refreshPreview()`.
- `refreshPreview()` (debounce 300ms) → `document.getElementById('preview').src = PRINT+'?id='+CARTA_ID+'&preview=1&theme='+tema+'&t='+Date.now()` (cache-bust).
- `generarPDF()` → `window.open(PRINT+'?id='+CARTA_ID+'&theme='+tema, '_blank')`.
- Al cargar: `load()`.

CSS: reusar clases del panel donde aplique; estilos propios inline o en un `<style>` para las dos columnas, el builder, los sliders y el modal. El modal puede seguir el patrón de otros modales del panel (overlay + card).

- [ ] **Step 1:** Crear `admin/cartas/editor.php` implementando lo anterior (PHP cabecera + HTML de 2 paneles + modal + `<script>` con las funciones). Escapar SIEMPRE el texto interpolado en `innerHTML` con `esc()`. Mandar CSRF por header en todos los POST.
- [ ] **Step 2:** `php -l admin/cartas/editor.php` → sin errores.
- [ ] **Step 3:** Commit: `git add admin/cartas/editor.php && git commit -m "feat(generador): editor de 2 paneles (admin/cartas/editor.php)"`

---

## Verificación final de la fase

- [ ] `php -l` sin errores en los 3 archivos.
- [ ] (Humano, tras `install/cartas.sql`) Menú muestra "Generador cartas PDF" → lista carga → "Nueva carta" abre el editor.
- [ ] En el editor: cargar desde ubicación trae secciones/ítems; agregar ítem libre con foto; editar/borrar; reordenar con ↑/↓; renombrar sección; toggle 1/2 columnas; mover sliders y ancho → el preview refleja los cambios; toggle noche/crema; "Generar PDF" abre el banner e imprime.
- [ ] Aislamiento: el diff no toca `carta/banner.php`, `admin/locations/*`, ni `products`.

## Notas

- Reordenar con **↑/↓** (fiable sin tests locales); el drag-and-drop del mockup se puede añadir después como pulido.
- El editor manda `carta_id` en los reorder (el API lo exige por seguridad).
- Mover ítem entre secciones: vía el **select de sección** en el modal de ítem.
