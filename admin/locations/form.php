<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('locations');

$id  = cleanInt($_GET['id'] ?? 0);
$loc = $id ? Database::fetch("SELECT * FROM ubicaciones WHERE id = ?", [$id]) : null;
if ($id && !$loc) { flashMessage('error', 'Ubicación no encontrada.'); redirect('/admin/locations/index.php'); }

// ¿Existe ya la columna de cierre manual? (tolerante si aún no se aplicó el SQL)
$hasCerrado = (bool) Database::fetch(
    "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ubicaciones' AND column_name = 'cerrado_manual'"
);

$isEdit = (bool)$loc;
$errors = [];
$data   = $loc ?? [
    'nombre' => '', 'slug' => '', 'descripcion' => '', 'color_header' => '#FCDA13',
    'sales_mode' => 'menu', 'whatsapp_number' => '', 'direccion' => '', 'maps_url' => '',
    'hora_apertura' => 18, 'hora_cierre' => 24, 'instagram' => '',
    'activa' => 1, 'cerrado_manual' => 0, 'es_principal' => 0, 'es_almacen' => 0, 'sort_order' => 0,
    'referencia' => '', 'serie_boleta' => '', 'serie_factura' => '', 'num_boleta' => 1, 'num_factura' => 1,
    'lat' => null, 'lng' => null, 'geocerca_radio' => 100, 'geocerca_activa' => 0, 'modo_marcaje' => 'tablet',
    'asistencia_token' => '',
];

// Genera un slug a partir de un texto
function slugify($txt) {
    $txt = strtolower(trim($txt));
    $txt = strtr($txt, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u']);
    $txt = preg_replace('/[^a-z0-9]+/', '-', $txt);
    return trim($txt, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'nombre'          => clean($_POST['nombre'] ?? ''),
        'slug'            => slugify($_POST['slug'] ?? ''),
        'descripcion'     => clean($_POST['descripcion'] ?? ''),
        'color_header'    => clean($_POST['color_header'] ?? '#FCDA13'),
        'sales_mode'      => in_array($_POST['sales_mode'] ?? '', ['menu','whatsapp','izipay','ambos']) ? $_POST['sales_mode'] : 'menu',
        'whatsapp_number' => preg_replace('/\D/', '', $_POST['whatsapp_number'] ?? ''),
        'direccion'       => clean($_POST['direccion'] ?? ''),
        'maps_url'        => clean($_POST['maps_url'] ?? ''),
        'hora_apertura'   => max(0, min(24, cleanInt($_POST['hora_apertura'] ?? 18))),
        'hora_cierre'     => max(0, min(24, cleanInt($_POST['hora_cierre'] ?? 24))),
        'instagram'       => ltrim(clean($_POST['instagram'] ?? ''), '@'),
        'activa'          => isset($_POST['activa']) ? 1 : 0,
        'cerrado_manual'  => isset($_POST['cerrado_manual']) ? 1 : 0,
        'es_principal'    => isset($_POST['es_principal']) ? 1 : 0,
        'es_almacen'      => isset($_POST['es_almacen']) ? 1 : 0,
        'sort_order'      => cleanInt($_POST['sort_order'] ?? 0),
        'referencia'      => clean($_POST['referencia'] ?? ''),
        'serie_boleta'    => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['serie_boleta'] ?? '')),
        'serie_factura'   => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['serie_factura'] ?? '')),
        'num_boleta'      => max(1, cleanInt($_POST['num_boleta'] ?? 1)),
        'num_factura'     => max(1, cleanInt($_POST['num_factura'] ?? 1)),
        'lat'             => ($_POST['lat'] ?? '') !== '' ? (float)$_POST['lat'] : null,
        'lng'             => ($_POST['lng'] ?? '') !== '' ? (float)$_POST['lng'] : null,
        'geocerca_radio'  => max(20, (int)($_POST['geocerca_radio'] ?? 100)),
        'geocerca_activa' => !empty($_POST['geocerca_activa']) ? 1 : 0,
        'modo_marcaje'    => in_array($_POST['modo_marcaje'] ?? '', ['tablet','celular']) ? $_POST['modo_marcaje'] : 'tablet',
    ];

    if (!$data['nombre']) $errors[] = 'El nombre es obligatorio.';
    if (!$data['slug'])   $data['slug'] = slugify($data['nombre']);
    if (!$data['slug'])   $errors[] = 'El slug (URL) es obligatorio.';

    // Slug único
    if (!$errors) {
        $dup = Database::fetch("SELECT id FROM ubicaciones WHERE slug = ? AND id <> ?", [$data['slug'], $id]);
        if ($dup) $errors[] = 'Ya existe una ubicación con ese slug. Usa otro.';
    }
    if ($data['sales_mode'] === 'whatsapp' && !$data['whatsapp_number']) {
        $errors[] = 'Para la modalidad WhatsApp necesitas un número de WhatsApp.';
    }
    if ($data['sales_mode'] === 'ambos') {
        if (!$data['whatsapp_number']) {
            $errors[] = 'El modo "Ambos" requiere un número de WhatsApp.';
        }
        require_once __DIR__ . '/../../includes/izipay.php';
        if (!izipayConfigured()) {
            $errors[] = 'El modo "Ambos" requiere que Izipay esté configurado (Facturación → Izipay).';
        }
    }

    if (empty($errors)) {
        // Solo una ubicación principal
        if ($data['es_principal']) {
            Database::execute("UPDATE ubicaciones SET es_principal = 0 WHERE id <> ?", [$id ?: 0]);
        }
        if ($isEdit) {
            $params = [$data['nombre'],$data['slug'],$data['descripcion'],$data['color_header'],$data['sales_mode'],$data['whatsapp_number'],$data['direccion'],$data['maps_url'],$data['hora_apertura'],$data['hora_cierre'],$data['instagram'],$data['activa'],$data['es_principal'],$data['sort_order']];
            if ($hasCerrado) { array_splice($params, 12, 0, [$data['cerrado_manual']]); }
            Database::execute(
                "UPDATE ubicaciones SET nombre=?,slug=?,descripcion=?,color_header=?,sales_mode=?,whatsapp_number=?,direccion=?,maps_url=?,hora_apertura=?,hora_cierre=?,instagram=?,activa=?" . ($hasCerrado ? ',cerrado_manual=?' : '') . ",es_principal=?,sort_order=? WHERE id=?",
                array_merge($params, [$id])
            );
            $savedId = $id;
            flashMessage('success', 'Ubicación actualizada.');
        } else {
            $cols = "nombre,slug,descripcion,color_header,sales_mode,whatsapp_number,direccion,maps_url,hora_apertura,hora_cierre,instagram,activa" . ($hasCerrado ? ',cerrado_manual' : '') . ",es_principal,sort_order";
            $vals = "?,?,?,?,?,?,?,?,?,?,?,?" . ($hasCerrado ? ',?' : '') . ",?,?";
            $params = [$data['nombre'],$data['slug'],$data['descripcion'],$data['color_header'],$data['sales_mode'],$data['whatsapp_number'],$data['direccion'],$data['maps_url'],$data['hora_apertura'],$data['hora_cierre'],$data['instagram'],$data['activa']];
            if ($hasCerrado) { $params[] = $data['cerrado_manual']; }
            $params[] = $data['es_principal']; $params[] = $data['sort_order'];
            $savedId = Database::insert("INSERT INTO ubicaciones ($cols) VALUES ($vals)", $params);
            flashMessage('success', 'Ubicación creada. Ahora agrégale ítems a su carta.');
        }
        // Campos multilocal (serie/correlativo/referencia) — UPDATE aparte y
        // tolerante por si la migración multilocal_facturacion.sql aún no se aplicó.
        if (!empty($savedId)) {
            try {
                Database::execute(
                    "UPDATE ubicaciones SET referencia=?, serie_boleta=?, serie_factura=?, num_boleta=?, num_factura=? WHERE id=?",
                    [
                        $data['referencia'],
                        $data['serie_boleta'] ?: null,
                        $data['serie_factura'] ?: null,
                        $data['num_boleta'],
                        $data['num_factura'],
                        $savedId,
                    ]
                );
            } catch (\Throwable $e) { /* columnas aún no creadas */ }
        }
        // Flag almacén central — UPDATE aparte y tolerante (migración 49)
        if (!empty($savedId)) {
            try {
                Database::execute("UPDATE ubicaciones SET es_almacen=? WHERE id=?", [$data['es_almacen'], $savedId]);
            } catch (\Throwable $e) { /* columna es_almacen aún no creada */ }
        }
        // Campos de asistencia — tolerante si la migración aún no se aplicó
        if (!empty($savedId)) {
            try {
                // asistencia_token: se genera una sola vez (COALESCE no lo sobreescribe)
                $token = generateToken(20);
                Database::execute(
                    "UPDATE ubicaciones SET lat=?, lng=?, geocerca_radio=?, geocerca_activa=?, modo_marcaje=?, asistencia_token=COALESCE(NULLIF(asistencia_token,''),?) WHERE id=?",
                    [
                        $data['lat'],
                        $data['lng'],
                        $data['geocerca_radio'],
                        $data['geocerca_activa'],
                        $data['modo_marcaje'],
                        $token,
                        $savedId,
                    ]
                );
            } catch (\Throwable $e) { /* columnas de asistencia aún no creadas */ }
        }
        redirect('/admin/locations/index.php');
    }
}

$pageTitle  = $isEdit ? 'Editar ubicación' : 'Nueva ubicación';
$activePage = 'locations';
include __DIR__ . '/../layout-top.php';
?>

<div class="breadcrumb">
  <a href="<?= APP_URL ?>/admin/locations/index.php">Ubicaciones</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= $isEdit ? 'Editar' : 'Nueva' ?></span>
</div>

<div class="page-header">
  <div class="page-header-left"><h1><?= $pageTitle ?></h1></div>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-error">✗ <?= $e ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:620px">
  <div class="card-body">
    <form method="post">
      <?= csrfField() ?>

      <div class="form-row form-row-2">
        <div class="form-group">
          <label class="form-required">Nombre</label>
          <input type="text" name="nombre" value="<?= clean($data['nombre']) ?>"
                 placeholder="Ej: El Gringo Food Truck" required autofocus>
        </div>
        <div class="form-group">
          <label>Slug (URL)</label>
          <input type="text" name="slug" value="<?= clean($data['slug']) ?>" placeholder="se genera del nombre">
          <div class="form-hint">La carta vivirá en <code>/<?= clean($data['slug'] ?: 'slug') ?></code> y el menú en <code>/<?= clean($data['slug'] ?: 'slug') ?>/menu</code></div>
        </div>
      </div>

      <div class="form-group">
        <label>Descripción <small style="font-weight:400;color:var(--text-muted)">(opcional)</small></label>
        <input type="text" name="descripcion" value="<?= clean($data['descripcion']) ?>" placeholder="Ej: Hamburguesas a la parrilla en el malecón">
      </div>

      <div class="form-row form-row-2">
        <div class="form-group">
          <label>Modalidad de venta</label>
          <select name="sales_mode">
            <option value="menu"     <?= $data['sales_mode']==='menu'?'selected':'' ?>>Solo menú (sin venta)</option>
            <option value="whatsapp" <?= $data['sales_mode']==='whatsapp'?'selected':'' ?>>Pedido por WhatsApp</option>
            <option value="izipay"   <?= $data['sales_mode']==='izipay'?'selected':'' ?>>Pago con Izipay</option>
            <option value="ambos"    <?= $data['sales_mode']==='ambos'?'selected':'' ?>>Ambos (WhatsApp + Izipay)</option>
          </select>
          <div class="form-hint">Define cómo se vende en la carta de esta ubicación</div>
        </div>
        <div class="form-group">
          <label>Número de WhatsApp <small style="font-weight:400;color:var(--text-muted)">(para modalidad WhatsApp)</small></label>
          <input type="text" name="whatsapp_number" value="<?= clean($data['whatsapp_number']) ?>" placeholder="51999888777" inputmode="numeric">
          <div class="form-hint">Con código de país, sin +</div>
        </div>
      </div>

      <div style="margin:22px 0 6px;padding-top:18px;border-top:1px solid var(--border)">
        <strong style="font-size:14px">Control de asistencia</strong>
        <div class="form-hint" style="margin-top:2px">Configura cómo marcan asistencia los colaboradores de este local.</div>
      </div>

      <div class="form-group">
        <label>Modo de marcaje de asistencia</label>
        <select name="modo_marcaje">
          <option value="tablet"  <?= ($data['modo_marcaje'] ?? 'tablet')==='tablet'?'selected':'' ?>>Tablet/equipo fijo en el local</option>
          <option value="celular" <?= ($data['modo_marcaje'] ?? 'tablet')==='celular'?'selected':'' ?>>Celular propio (food truck / delivery)</option>
        </select>
      </div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label>Latitud</label>
          <input type="text" name="lat" id="loc-lat" value="<?= isset($data['lat']) && $data['lat'] !== null ? clean((string)$data['lat']) : '' ?>">
        </div>
        <div class="form-group">
          <label>Longitud</label>
          <input type="text" name="lng" id="loc-lng" value="<?= isset($data['lng']) && $data['lng'] !== null ? clean((string)$data['lng']) : '' ?>">
        </div>
      </div>
      <button type="button" class="btn btn-secondary" onclick="capturarUbicacion()">📍 Capturar mi ubicación actual</button>
      <div class="form-row form-row-2" style="margin-top:12px">
        <div class="form-group">
          <label>Radio de geocerca (metros)</label>
          <input type="number" name="geocerca_radio" min="20" value="<?= (int)($data['geocerca_radio'] ?? 100) ?>">
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

        <?php if (!empty($data['slug']) && !empty($data['asistencia_token'])):
            $marcarUrl = APP_URL . '/asistencia/marcar.php?u=' . rawurlencode($data['slug']) . '&t=' . rawurlencode($data['asistencia_token']);
        ?>
        <div class="form-group" style="background:#fafafa;border:1px solid #eee;border-radius:10px;padding:14px">
          <label>Enlace de marcaje de asistencia (compártelo o pégalo en la tablet del local)</label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="text" id="marcar-url" readonly value="<?= htmlspecialchars($marcarUrl, ENT_QUOTES) ?>" onclick="this.select()" style="flex:1">
            <button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText(document.getElementById('marcar-url').value);this.textContent='Copiado'">Copiar</button>
          </div>
          <div id="marcar-qr" style="margin-top:12px"></div>
          <div class="form-hint">El token es secreto: solo compártelo con tu equipo. La página pide selfie + ubicación.</div>
        </div>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
        (function(){ if(window.QRCode){ try{ new QRCode(document.getElementById('marcar-qr'), {text: document.getElementById('marcar-url').value, width:148, height:148}); }catch(e){} } })();
        </script>
        <?php endif; ?>

      <div style="margin:22px 0 6px;padding-top:18px;border-top:1px solid var(--border)"></div>

      <div class="form-group">
        <label>Referencia / zona <small style="font-weight:400;color:var(--text-muted)">(la ve el cliente en el selector de tienda)</small></label>
        <input type="text" name="referencia" value="<?= clean($data['referencia'] ?? '') ?>" placeholder="Ej: Lince / San Isidro">
      </div>

      <div class="form-row form-row-2">
        <div class="form-group">
          <label>Dirección <small style="font-weight:400;color:var(--text-muted)">(opcional)</small></label>
          <input type="text" name="direccion" value="<?= clean($data['direccion']) ?>" placeholder="Av. ...">
        </div>
        <div class="form-group">
          <label>Link de Google Maps <small style="font-weight:400;color:var(--text-muted)">(opcional)</small></label>
          <input type="text" name="maps_url" value="<?= clean($data['maps_url']) ?>" placeholder="https://maps.app.goo.gl/...">
        </div>
      </div>

      <div class="form-row form-row-3">
        <div class="form-group">
          <label>Hora de apertura</label>
          <input type="number" name="hora_apertura" value="<?= (int)$data['hora_apertura'] ?>" min="0" max="24" step="1">
          <div class="form-hint">Hora (0–24). Ej: 18 = 6pm</div>
        </div>
        <div class="form-group">
          <label>Hora de cierre</label>
          <input type="number" name="hora_cierre" value="<?= (int)$data['hora_cierre'] ?>" min="0" max="24" step="1">
          <div class="form-hint">24 = medianoche</div>
        </div>
        <div class="form-group">
          <label>Instagram <small style="font-weight:400;color:var(--text-muted)">(usuario)</small></label>
          <input type="text" name="instagram" value="<?= clean($data['instagram']) ?>" placeholder="elgringoburger">
          <div class="form-hint">Sin @, solo el usuario</div>
        </div>
      </div>

      <div style="margin:22px 0 6px;padding-top:18px;border-top:1px solid var(--border)">
        <strong style="font-size:14px">Facturación electrónica de este local</strong>
        <div class="form-hint" style="margin-top:2px">Serie y correlativo propios para que dos locales nunca choquen en SUNAT. Déjalo vacío para usar la serie global de Facturación.</div>
      </div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label>Serie de boletas</label>
          <input type="text" name="serie_boleta" value="<?= clean($data['serie_boleta'] ?? '') ?>" placeholder="B001" maxlength="10" style="text-transform:uppercase">
        </div>
        <div class="form-group">
          <label>Siguiente Nº (boleta)</label>
          <input type="number" name="num_boleta" value="<?= (int)($data['num_boleta'] ?? 1) ?>" min="1" step="1">
        </div>
      </div>
      <div class="form-row form-row-2">
        <div class="form-group">
          <label>Serie de facturas</label>
          <input type="text" name="serie_factura" value="<?= clean($data['serie_factura'] ?? '') ?>" placeholder="F001" maxlength="10" style="text-transform:uppercase">
        </div>
        <div class="form-group">
          <label>Siguiente Nº (factura)</label>
          <input type="number" name="num_factura" value="<?= (int)($data['num_factura'] ?? 1) ?>" min="1" step="1">
        </div>
      </div>

      <div class="form-row form-row-3">
        <div class="form-group">
          <label>Color de cabecera</label>
          <input type="color" name="color_header" value="<?= clean($data['color_header'] ?: '#FCDA13') ?>" style="height:44px;padding:4px;cursor:pointer">
        </div>
        <div class="form-group">
          <label>Orden</label>
          <input type="number" name="sort_order" value="<?= (int)$data['sort_order'] ?>" min="0" step="1">
        </div>
        <div class="form-group">
          <label>Opciones</label>
          <div style="padding-top:6px;display:flex;flex-direction:column;gap:8px">
            <label class="toggle-wrap" style="cursor:pointer">
              <input type="checkbox" name="activa" value="1" <?= $data['activa']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)">
              <span class="toggle-label">Activa</span>
            </label>
            <label class="toggle-wrap" style="cursor:pointer">
              <input type="checkbox" name="es_principal" value="1" <?= $data['es_principal']?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)">
              <span class="toggle-label">Principal</span>
            </label>
            <label class="toggle-wrap" style="cursor:pointer" title="Solo guarda y despacha; no vende">
              <input type="checkbox" name="es_almacen" value="1" <?= !empty($data['es_almacen'])?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--brand)">
              <span class="toggle-label">Almacén central</span>
            </label>
          </div>
          <div class="form-hint" style="margin-top:6px">Si marcas <strong>Almacén central</strong>, desmarca <strong>Activa</strong>: el almacén no vende, solo guarda y despacha insumos.</div>
        </div>

        <div class="form-group" style="margin-top:4px">
          <label>Estado de la tienda</label>
          <label class="toggle-wrap" style="cursor:pointer;display:flex;align-items:center;gap:9px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:<?= !empty($data['cerrado_manual']) ? 'rgba(220,38,38,.06)' : 'transparent' ?>">
            <input type="checkbox" name="cerrado_manual" value="1" <?= !empty($data['cerrado_manual'])?'checked':'' ?> style="width:18px;height:18px;accent-color:var(--danger)">
            <span>
              <span class="toggle-label" style="font-weight:700">Cerrar tienda (cierre manual)</span>
              <span style="display:block;font-size:12px;color:var(--text-muted);margin-top:2px">Mientras esté activo, la carta sale <strong>Cerrada</strong> sin importar el horario y no se pueden hacer pedidos.</span>
            </span>
          </label>
          <?php if (!$hasCerrado): ?>
            <div class="form-hint" style="color:var(--danger);margin-top:6px">Falta aplicar <code>install/ubicaciones_cerrado.sql</code> para que este interruptor funcione.</div>
          <?php endif; ?>
        </div>
      </div>

      <div style="display:flex;gap:12px;margin-top:8px">
        <button type="submit" class="btn btn-primary" style="gap:6px">
          <?php if ($isEdit): ?>
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>Guardar cambios
          <?php else: ?>
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Crear ubicación
          <?php endif; ?>
        </button>
        <a href="<?= APP_URL ?>/admin/locations/index.php" class="btn btn-ghost">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
