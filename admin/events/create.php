<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requirePermission('events');

$errors = array();

// --- Modo agenda (evento sin venta) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['modo']) ? $_POST['modo'] : '') === 'agenda') {
    verifyCsrf();
    $titulo = clean($_POST['titulo'] ?? '');
    $fecha  = clean($_POST['fecha'] ?? '');
    $hora   = clean($_POST['hora'] ?? '');
    $lugar  = clean($_POST['lugar'] ?? '');
    $notas  = clean($_POST['notas'] ?? '');
    $bloquea = !empty($_POST['bloquea']) ? 1 : 0;
    $aid    = cleanInt($_POST['agenda_id'] ?? 0);
    if ($titulo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) { flashMessage('error','Título y fecha son obligatorios.'); redirect('/admin/events/create'); }
    if (isset($_POST['delete_agenda'])) { Database::execute("DELETE FROM agenda WHERE id=?", [$aid]); flashMessage('success','Evento de agenda eliminado.'); redirect('/admin/calendar'); }
    if ($aid > 0) { Database::execute("UPDATE agenda SET fecha=?,titulo=?,hora=?,lugar=?,notas=?,bloquea=? WHERE id=?", [$fecha,$titulo,$hora?:null,$lugar?:null,$notas?:null,$bloquea,$aid]); }
    else { Database::insert("INSERT INTO agenda (fecha,titulo,hora,lugar,notas,bloquea,created_by) VALUES (?,?,?,?,?,?,?)", [$fecha,$titulo,$hora?:null,$lugar?:null,$notas?:null,$bloquea, (int)(currentUser()['id'] ?? 0)]); }
    flashMessage('success','Evento de agenda guardado.');
    redirect('/admin/calendar');
}

// --- Cargar agenda para edición (?agenda=<id>) ---
$editAgenda = null;
$agendaIdGet = cleanInt($_GET['agenda'] ?? 0);
if ($agendaIdGet > 0) {
    $editAgenda = Database::fetch("SELECT id, fecha, titulo, hora, lugar, notas, bloquea FROM agenda WHERE id=?", array($agendaIdGet));
    if (!$editAgenda) { flashMessage('error','Evento de agenda no encontrado.'); redirect('/admin/calendar'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $clientId     = cleanInt(isset($_POST['client_id'])       ? $_POST['client_id']       : 0);
    $eventType    = clean(isset($_POST['event_type'])         ? $_POST['event_type']       : '');
    $eventDate    = clean(isset($_POST['event_date'])         ? $_POST['event_date']       : '');
    $eventTime    = clean(isset($_POST['event_time'])         ? $_POST['event_time']       : '');
    $eventDur     = clean(isset($_POST['event_duration'])     ? $_POST['event_duration']   : '');
    $eventLoc     = clean(isset($_POST['event_location'])     ? $_POST['event_location']   : '');
    $numPeople    = cleanInt(isset($_POST['num_people'])      ? $_POST['num_people']       : 0);
    $igvType      = in_array(isset($_POST['igv_type'])?$_POST['igv_type']:'', array('none','10.5','18')) ? $_POST['igv_type'] : 'none';
    $discPct      = min(100, max(0, cleanFloat(isset($_POST['discount_pct'])  ? $_POST['discount_pct']  : 0)));
    $extrasAmt    = max(0, cleanFloat(isset($_POST['extras_amount'])          ? $_POST['extras_amount'] : 0));
    $extrasDet    = clean(isset($_POST['extras_detail'])      ? $_POST['extras_detail']    : '');
    $observations = clean(isset($_POST['observations'])       ? $_POST['observations']     : '');
    $terms        = clean(isset($_POST['terms'])              ? $_POST['terms']            : '');
    $validUntil   = clean(isset($_POST['valid_until'])        ? $_POST['valid_until']      : '');

    if (!$clientId) $errors[] = 'Selecciona un cliente.';
    if (!$eventDate) $errors[] = 'La fecha del evento es obligatoria.';

    $items = array();
    $rawItems = isset($_POST['items']) ? $_POST['items'] : array();
    foreach ($rawItems as $item) {
        $name = clean(isset($item['name']) ? $item['name'] : '');
        if (!$name) continue;
        $unitPrice = max(0, cleanFloat(isset($item['unit_price'])   ? $item['unit_price']   : 0));
        $quantity  = max(0.01, cleanFloat(isset($item['quantity'])  ? $item['quantity']     : 1));
        $discItem  = min(100, max(0, cleanFloat(isset($item['discount_pct']) ? $item['discount_pct'] : 0)));
        $priceMode = in_array(isset($item['price_mode'])?$item['price_mode']:'', array('per_person','per_event','custom')) ? $item['price_mode'] : 'custom';
        $subtotal  = $unitPrice * $quantity * (1 - $discItem / 100);
        $items[] = array(
            'product_id'   => cleanInt(isset($item['product_id']) ? $item['product_id'] : 0),
            'name'         => $name,
            'description'  => clean(isset($item['description']) ? $item['description'] : ''),
            'price_mode'   => $priceMode,
            'unit_price'   => $unitPrice,
            'quantity'     => $quantity,
            'discount_pct' => $discItem,
            'subtotal'     => $subtotal,
        );
    }
    if (empty($items)) $errors[] = 'Agrega al menos un producto o servicio.';

    if (empty($errors)) {
        $subtotal  = array_sum(array_column($items, 'subtotal'));
        $discAmt   = $subtotal * ($discPct / 100);
        $base      = $subtotal - $discAmt + $extrasAmt;
        $igvRate   = $igvType === '18' ? 0.18 : ($igvType === '10.5' ? 0.105 : 0);
        $igvAmt    = $base * $igvRate;
        $total     = $base + $igvAmt;
        $perPerson = $numPeople > 0 ? $total / $numPeople : 0;

        $prefix = 'EV';
        $year   = date('Y');
        $last   = Database::fetch(
            "SELECT quote_number FROM quotes WHERE quote_number LIKE ? ORDER BY id DESC LIMIT 1",
            array($prefix . '-' . $year . '-%')
        );
        $num         = $last ? ((int)end(explode('-', $last['quote_number'])) + 1) : 1;
        $quoteNumber = sprintf('%s-%s-%04d', $prefix, $year, $num);

        $quoteId = Database::insert(
            "INSERT INTO quotes (quote_number, user_id, client_id, status, origin,
             event_type, event_date, event_time, event_duration, event_location,
             num_people, valid_until, igv_type, igv_amount,
             discount_pct, discount_amount, extras_amount, extras_detail,
             subtotal, total, price_per_person, observations, terms,
             public_token, accepted_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())",
            array($quoteNumber, $_SESSION['user_id'], $clientId, 'aceptada', 'event',
             $eventType, $eventDate, $eventTime, $eventDur, $eventLoc,
             $numPeople, $validUntil, $igvType, $igvAmt,
             $discPct, $discAmt, $extrasAmt, $extrasDet,
             $subtotal, $total, $perPerson, $observations, $terms,
             generateToken(24))
        );

        foreach ($items as $i => $item) {
            Database::insert(
                "INSERT INTO quote_items (quote_id,product_id,name,description,price_mode,unit_price,quantity,discount_pct,subtotal,sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                array($quoteId, $item['product_id'], $item['name'], $item['description'],
                 $item['price_mode'], $item['unit_price'], $item['quantity'],
                 $item['discount_pct'], $item['subtotal'], $i)
            );
        }

        Database::insert(
            "INSERT INTO quote_status_log (quote_id,user_id,from_status,to_status,note) VALUES (?,?,?,?,?)",
            array($quoteId, $_SESSION['user_id'], '', 'aceptada', 'Evento creado directamente')
        );

        flashMessage('success', 'Evento ' . $quoteNumber . ' creado correctamente.');
        redirect('/quotes/edit.php?id=' . $quoteId);
    }
}

$rawTypes   = getSetting('event_types', 'Corporativo,Boda,Cumpleaños,Social / Familiar,Feria gastronómica,Food truck,Otro');
$eventTypes = array_filter(array_map('trim', explode(',', $rawTypes)));
$categories = Database::fetchAll("SELECT id, name FROM categories WHERE active=1 ORDER BY name");
$defTerms   = getSetting('default_terms', '');
$defObs     = getSetting('default_observations', '');

$pageTitle  = 'Nuevo evento';
$activePage = 'event-new';
$extraHead  = '
<link rel="stylesheet" href="' . APP_URL . '/assets/css/quoter.css?v=' . (@filemtime(__DIR__ . '/../../assets/css/quoter.css') ?: time()) . '">
<style>
.event-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(124,58,237,.1);color:#7c3aed;border:1px solid rgba(124,58,237,.25);border-radius:20px;padding:4px 12px;font-size:13px;font-weight:600;margin-bottom:16px}
.event-badge svg{width:15px;height:15px}
.card-title{display:inline-flex;align-items:center;gap:8px}
.card-title .sec-ico{display:inline-flex;color:var(--text-secondary)}
.card-title .sec-ico svg{width:17px;height:17px}
.mode-switch{display:inline-flex;background:var(--bg-input,#f4f4f5);border:1px solid var(--border);border-radius:10px;padding:3px;gap:3px;margin-bottom:16px}
.mode-switch button{display:inline-flex;align-items:center;gap:6px;border:none;background:transparent;color:var(--text-secondary);font-size:13px;font-weight:600;padding:7px 16px;border-radius:7px;cursor:pointer;transition:all .12s}
.mode-switch button svg{width:15px;height:15px}
.mode-switch button.active{background:#fff;color:#7c3aed;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.agenda-form-grid{max-width:560px}
</style>';

include __DIR__ . '/../layout-top.php';
?>

<?php $isAgendaEdit = $editAgenda !== null; ?>
<div class="breadcrumb">
  <a href="<?php echo APP_URL; ?>/admin/dashboard">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span class="breadcrumb-current"><?= $isAgendaEdit ? 'Editar agenda' : 'Nuevo evento' ?></span>
</div>

<!-- Selector de modo -->
<div class="mode-switch" role="group" aria-label="Tipo de registro">
  <button type="button" id="modeBtnVenta" class="<?= $isAgendaEdit ? '' : 'active' ?>" onclick="setMode('venta')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 2H8a2 2 0 0 0-2 2v18l3-2 3 2 3-2 3 2V4a2 2 0 0 0-2-2Z"/><path d="M9 7h6M9 11h6"/></svg>
    Con venta
  </button>
  <button type="button" id="modeBtnAgenda" class="<?= $isAgendaEdit ? 'active' : '' ?>" onclick="setMode('agenda')">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
    Solo agenda
  </button>
</div>

<div class="event-badge" id="badgeVenta" style="<?= $isAgendaEdit ? 'display:none' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg> Evento directo — se registra como aceptado inmediatamente</div>
<div class="event-badge" id="badgeAgenda" style="background:rgba(249,115,22,.1);color:#c2410c;border-color:rgba(249,115,22,.25);<?= $isAgendaEdit ? '' : 'display:none' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg> Solo agenda — no afecta ventas ni facturación (bloquea el día solo si lo marcas)</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-error">&#10007; <?php echo htmlspecialchars($e); ?></div>
<?php endforeach; ?>

<!-- ============ FORM AGENDA (solo disponibilidad) ============ -->
<form method="post" id="agendaForm" style="<?= $isAgendaEdit ? '' : 'display:none' ?>">
<?= csrfField() ?>
<input type="hidden" name="modo" value="agenda">
<input type="hidden" name="agenda_id" value="<?= $isAgendaEdit ? (int)$editAgenda['id'] : 0 ?>">

<div class="card agenda-form-grid">
  <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span><?= $isAgendaEdit ? 'Editar agenda' : 'Evento de agenda' ?></span></div>
  <div class="card-body">
    <div class="form-group">
      <label class="form-required">Título</label>
      <input type="text" name="titulo" required maxlength="160" placeholder="Ej: Reservado · día no disponible"
             value="<?= $isAgendaEdit ? clean($editAgenda['titulo']) : '' ?>">
    </div>
    <div class="form-row form-row-2">
      <div class="form-group">
        <label class="form-required">Fecha</label>
        <input type="date" name="fecha" required min="<?= date('Y-m-d') ?>"
               value="<?= $isAgendaEdit ? clean($editAgenda['fecha']) : '' ?>">
      </div>
      <div class="form-group">
        <label>Hora</label>
        <input type="time" name="hora" value="<?= $isAgendaEdit ? clean($editAgenda['hora']) : '' ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Lugar</label>
      <input type="text" name="lugar" maxlength="255" placeholder="Local, dirección..."
             value="<?= $isAgendaEdit ? clean($editAgenda['lugar']) : '' ?>">
    </div>
    <div class="form-group">
      <label>Notas</label>
      <textarea name="notas" rows="3" placeholder="Detalles internos..."><?= $isAgendaEdit ? clean($editAgenda['notas']) : '' ?></textarea>
    </div>

    <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1px solid #fecaca;background:#fef2f2;border-radius:10px;cursor:pointer;margin-top:4px">
      <input type="checkbox" name="bloquea" value="1" style="margin-top:2px;accent-color:#dc2626;width:16px;height:16px;flex-shrink:0"
             <?= ($isAgendaEdit && !empty($editAgenda['bloquea'])) ? 'checked' : '' ?>>
      <span style="display:flex;flex-direction:column;gap:2px">
        <span style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#dc2626">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Bloquear el día (marcarlo como NO disponible)
        </span>
        <span style="font-size:11.5px;color:var(--text-muted)">Si lo dejas sin marcar, es solo un evento; el día sigue disponible para más eventos.</span>
      </span>
    </label>

    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary btn-lg" style="background:#f97316;border-color:#f97316;color:#fff;gap:8px;flex:1">
        <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        Guardar agenda
      </button>
      <?php if ($isAgendaEdit): ?>
      <button type="submit" name="delete_agenda" value="1" class="btn btn-lg" style="background:#fee2e2;border-color:#fecaca;color:#dc2626"
              onclick="return confirm('¿Eliminar este evento de agenda?')">
        Eliminar
      </button>
      <?php endif; ?>
    </div>
    <a href="<?= APP_URL ?>/admin/calendar" class="btn btn-ghost btn-block" style="margin-top:8px">Cancelar</a>
  </div>
</div>
</form>

<!-- ============ FORM VENTA (evento con cotización) ============ -->
<form method="post" id="quoteForm" style="<?= $isAgendaEdit ? 'display:none' : '' ?>">
<?= csrfField() ?>
<input type="hidden" name="modo" value="venta">
<input type="hidden" name="origin" value="event">

<div class="quoter-grid">

  <div class="quoter-left">

    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>Datos del evento</span></div>
      <div class="card-body">

        <div class="form-group">
          <label class="form-required">Cliente</label>
          <div style="display:flex;gap:8px">
            <div style="position:relative;flex:1">
              <span class="search-icon" style="top:50%;transform:translateY(-50%);display:flex"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>
              <input type="text" id="clientSearch" placeholder="Buscar cliente por nombre o RUC..."
                     autocomplete="off" style="padding-left:36px">
              <div id="clientDropdown" class="search-dropdown" style="display:none"></div>
            </div>
            <a href="<?= APP_URL ?>/admin/clients/form?back=<?= urlencode(APP_URL . '/admin/events/create') ?>"
               class="btn btn-ghost btn-sm" style="white-space:nowrap">+ Cliente</a>
          </div>
          <input type="hidden" name="client_id" id="clientId" value="0">
          <div id="clientSelected" class="client-chip" style="display:none"></div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label class="form-required">Tipo de evento</label>
            <select name="event_type" required>
              <option value="">Seleccionar...</option>
              <?php foreach ($eventTypes as $et): ?>
              <option value="<?= clean($et) ?>"><?= clean($et) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Fecha del evento</label>
            <input type="date" name="event_date" min="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label>Hora de inicio</label>
            <input type="time" name="event_time">
          </div>
          <div class="form-group">
            <label>Duracion</label>
            <input type="text" name="event_duration" placeholder="Ej: 3 horas">
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Lugar del evento</label>
            <input type="text" name="event_location" placeholder="Local, direccion...">
          </div>
          <div class="form-group">
            <label>N&ordm; de personas</label>
            <input type="number" name="num_people" id="num_people" min="0" placeholder="0">
            <div class="form-hint">Para calcular precio por persona</div>
          </div>
        </div>

      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg></span>Productos</span>
        <span id="itemCount" style="font-size:13px;color:var(--text-muted)">0 items</span>
      </div>
      <div class="card-body">
        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
          <div style="position:relative;flex:1;min-width:200px">
            <span class="search-icon" style="top:50%;transform:translateY(-50%);display:flex"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></span>
            <input type="text" id="productSearch" placeholder="Buscar producto..."
                   autocomplete="off" style="padding-left:36px">
            <div id="productDropdown" class="search-dropdown" style="display:none"></div>
          </div>
          <select id="catFilter" style="width:auto;padding:9px 12px">
            <option value="">Todas las categorias</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>"><?= clean($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" onclick="addManualItem()" class="btn btn-ghost btn-sm">+ Manual</button>
        </div>

        <div class="items-header">
          <span style="flex:3">Producto</span>
          <span style="flex:1.2;text-align:center">Modo</span>
          <span style="flex:1.1;text-align:right">Precio unit.</span>
          <span style="flex:.9;text-align:center">Cant.</span>
          <span style="flex:.8;text-align:center">Desc.%</span>
          <span style="flex:1.1;text-align:right">Subtotal</span>
          <span style="width:32px"></span>
        </div>
        <div id="quoteItemsContainer"></div>
        <div id="emptyItems" style="text-align:center;padding:24px;color:var(--text-muted);font-size:14px">
          Busca o agrega productos arriba
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg></span>Notas</span></div>
      <div class="card-body">
        <div class="form-group">
          <label>Observaciones</label>
          <textarea name="observations" rows="3" placeholder="Detalles especiales..."><?= clean($defObs) ?></textarea>
        </div>
        <div class="form-group">
          <label>Terminos y condiciones</label>
          <textarea name="terms" rows="4"><?= clean($defTerms) ?></textarea>
        </div>
      </div>
    </div>

  </div>

  <div class="quoter-right">
    <div class="card totals-card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 2H8a2 2 0 0 0-2 2v18l3-2 3 2 3-2 3 2V4a2 2 0 0 0-2-2Z"/><path d="M9 7h6M9 11h6"/></svg></span>Resumen</span></div>
      <div class="card-body">

        <div class="form-group">
          <label>IGV</label>
          <select name="igv_type" id="igv_type" class="igv-select">
            <option value="none">Sin IGV</option>
            <option value="10.5">IGV 10.5%</option>
            <option value="18">IGV 18%</option>
          </select>
        </div>

        <div class="form-group">
          <label>Descuento global</label>
          <div style="position:relative">
            <input type="number" name="discount_pct" id="discount_pct" value="0" min="0" max="100" step="any">
            <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px">%</span>
          </div>
        </div>

        <div class="form-group">
          <label>Costos adicionales (S/)</label>
          <input type="number" name="extras_amount" id="extras_amount" value="0" min="0" step="any">
          <input type="text" name="extras_detail" id="extras_detail"
                 placeholder="Detalle (movilidad, personal extra...)" style="margin-top:6px">
        </div>

        <div class="totals-table">
          <div class="total-row"><span>Subtotal</span><span id="total-subtotal">S/ 0.00</span></div>
          <div class="total-row" id="row-discount" style="display:none;color:#dc2626"><span>Descuento</span><span id="total-discount"></span></div>
          <div class="total-row" id="row-extras" style="display:none;color:#16a34a"><span>Adicionales</span><span id="total-extras"></span></div>
          <div class="total-row" id="row-igv" style="display:none"><span id="total-igv-label">IGV</span><span id="total-igv"></span></div>
        </div>

        <div class="total-final"><span>TOTAL</span><span id="total-final">S/ 0.00</span></div>
        <div class="total-per-person" id="row-per-person" style="display:none"><span id="total-per-person"></span></div>

        <input type="hidden" name="valid_until" value="<?= date('Y-m-d', strtotime('+' . getSetting('quote_validity_days','15') . ' days')) ?>">
        <input type="hidden" id="calc_subtotal"     name="calc_subtotal">
        <input type="hidden" id="calc_discount_amt" name="calc_discount_amt">
        <input type="hidden" id="calc_igv_amount"   name="calc_igv_amount">
        <input type="hidden" id="calc_total"        name="calc_total">
        <input type="hidden" id="calc_per_person"   name="calc_per_person">

        <button type="submit" class="btn btn-primary btn-lg btn-block"
                style="margin-top:16px;background:#7c3aed;border-color:#7c3aed;color:#fff;gap:8px">
          <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/></svg>
          Guardar evento
        </button>
        <a href="<?= APP_URL ?>/admin/dashboard" class="btn btn-ghost btn-block" style="margin-top:8px">Cancelar</a>

      </div>
    </div>
  </div>

</div>
</form>

<template id="itemRowTemplate">
  <div class="item-row" data-idx="__IDX__">
    <div class="item-name-col">
      <strong class="item-name-text">__NAME__</strong>
      <input type="hidden" name="items[__IDX__][product_id]" value="__PRODUCT_ID__">
      <input type="hidden" name="items[__IDX__][name]"       value="__NAME__">
      <input type="hidden" name="items[__IDX__][description]" value="__DESC__">
      <button type="button" class="btn-del" onclick="removeItem(this)" title="Quitar" style="display:none">✕</button>
    </div>
    <div class="item-mode-col item-field">
      <span class="item-field-label">Modo</span>
      <select name="items[__IDX__][price_mode]" data-field="price_mode"
              data-per-person="__PRICE_PP__" data-per-event="__PRICE_PE__" class="input-sm">
        <option value="per_person">x persona</option>
        <option value="per_event">x evento</option>
        <option value="custom">Libre</option>
      </select>
    </div>
    <div class="item-price-col item-field">
      <span class="item-field-label">Precio</span>
      <input type="text" inputmode="decimal" name="items[__IDX__][unit_price]"
             data-field="unit_price" value="__PRICE_PP__" placeholder="0.00"
             class="input-sm text-right" readonly>
    </div>
    <div class="item-qty-col item-field">
      <span class="item-field-label">Cant.</span>
      <input type="text" inputmode="decimal" name="items[__IDX__][quantity]"
             data-field="quantity" value="" placeholder="1" class="input-sm text-center">
    </div>
    <div class="item-disc-col item-field">
      <span class="item-field-label">Desc.%</span>
      <div style="position:relative">
        <input type="text" inputmode="decimal" name="items[__IDX__][discount_pct]"
               data-field="discount_pct" value="" placeholder="0" class="input-sm text-center">
        <span class="pct-symbol">%</span>
      </div>
    </div>
    <div class="item-sub-col item-field">
      <span class="item-field-label">Subtotal</span>
      <span data-field="subtotal" class="subtotal-display">S/ 0.00</span>
    </div>
    <div class="item-del-col">
      <button type="button" class="btn-del" onclick="removeItem(this)" title="Quitar">✕</button>
    </div>
  </div>
</template>

<?php
$extraScripts = '
<script>
const API_URL    = "' . APP_URL . '/api/quotes.php";
const CSRF_TOKEN = "' . csrfToken() . '";
function setMode(m){
  var venta = m === "venta";
  document.getElementById("quoteForm").style.display  = venta ? "" : "none";
  document.getElementById("agendaForm").style.display = venta ? "none" : "";
  document.getElementById("badgeVenta").style.display  = venta ? "" : "none";
  document.getElementById("badgeAgenda").style.display = venta ? "none" : "";
  document.getElementById("modeBtnVenta").classList.toggle("active", venta);
  document.getElementById("modeBtnAgenda").classList.toggle("active", !venta);
}
</script>
<script src="' . APP_URL . '/assets/js/quoter.js"></script>
';
include __DIR__ . '/../layout-bottom.php';
?>
