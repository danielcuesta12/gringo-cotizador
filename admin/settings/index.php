<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
requireAdmin();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // ── Borrar datos de prueba (ventas) — destructivo, solo admin ──
    if (($_POST['accion'] ?? '') === 'reset_ventas') {
        if (($_POST['confirmacion'] ?? '') !== 'BORRAR VENTAS') {
            flashMessage('error', 'Escribe exactamente «BORRAR VENTAS» para confirmar. No se borró nada.');
            redirect('/admin/settings/index.php');
        }
        $pdo = Database::getInstance();
        try {
            $pdo->beginTransaction();
            // Movimientos de inventario (ventas/compras/ajustes de prueba) + stock a 0 (conserva stock_min)
            try { Database::execute("DELETE FROM inventario_movimientos"); } catch (\Throwable $e) {}
            try { Database::execute("UPDATE insumo_stock SET stock = 0"); } catch (\Throwable $e) {}
            // Pedidos (carta + POS, con sus comprobantes) + turnos de caja
            try { Database::execute("DELETE FROM pedidos"); } catch (\Throwable $e) {}
            try { Database::execute("DELETE FROM pos_turnos"); } catch (\Throwable $e) {}
            $pdo->commit();
            flashMessage('success', 'Datos de prueba borrados: pedidos, caja y movimientos de inventario. Stock en 0. Se conservaron insumos, recetas, productos, cotizaciones y configuración.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flashMessage('error', 'No se pudo completar el borrado.');
        }
        redirect('/admin/settings/index.php');
    }

    $fields = [
        'company_name','company_ruc','company_address',
        'company_phone','company_email','company_website',
        'quote_prefix','quote_validity_days','default_igv',
        'default_terms','default_observations','whatsapp_number',
        'pdf_primary_color','pdf_secondary_color',
        'mail_domain','mail_cotizaciones_replyto',
        'brand_primary','brand_secondary','brand_dark',
    ];

    foreach ($fields as $k) {
        $val = clean($_POST[$k] ?? '');
        setSetting($k, $val);
    }

    // POS: exigir nombre del pedido (checkbox)
    setSetting('pos_nombre_obligatorio', isset($_POST['pos_nombre_obligatorio']) ? '1' : '0');
    setSetting('mozo_geocerca_activa', isset($_POST['mozo_geocerca_activa']) ? '1' : '0');
    setSetting('mesa_umbral_naranja', (string) max(1, (int)($_POST['mesa_umbral_naranja'] ?? 20)));
    setSetting('mesa_umbral_rojo',    (string) max(1, (int)($_POST['mesa_umbral_rojo'] ?? 30)));

    // Logo activo (a o b)
    $activeLogoVal = in_array($_POST['active_logo'] ?? 'a', ['a','b']) ? $_POST['active_logo'] : 'a';
    setSetting('active_logo', $activeLogoVal);

    // Logo A
    if (!empty($_FILES['company_logo']['name'])) {
        $uploaded = uploadImage($_FILES['company_logo'], 'logos');
        if ($uploaded) {
            $old = getSetting('company_logo');
            if ($old && file_exists(UPLOAD_PATH . $old)) @unlink(UPLOAD_PATH . $old);
            setSetting('company_logo', $uploaded);
        } else {
            $errors[] = 'Error al subir Logo A. Usa JPG, PNG o WebP (máx. 2MB).';
        }
    }
    if (isset($_POST['remove_logo'])) {
        $old = getSetting('company_logo');
        if ($old && file_exists(UPLOAD_PATH . $old)) @unlink(UPLOAD_PATH . $old);
        setSetting('company_logo', '');
    }

    // Logo B
    if (!empty($_FILES['company_logo_b']['name'])) {
        $uploaded = uploadImage($_FILES['company_logo_b'], 'logos');
        if ($uploaded) {
            $old = getSetting('company_logo_b');
            if ($old && file_exists(UPLOAD_PATH . $old)) @unlink(UPLOAD_PATH . $old);
            setSetting('company_logo_b', $uploaded);
        } else {
            $errors[] = 'Error al subir Logo B. Usa JPG, PNG o WebP (máx. 2MB).';
        }
    }
    if (isset($_POST['remove_logo_b'])) {
        $old = getSetting('company_logo_b');
        if ($old && file_exists(UPLOAD_PATH . $old)) @unlink(UPLOAD_PATH . $old);
        setSetting('company_logo_b', '');
    }

    // Ícono de la app (favicon / PWA) — cuadrado, idealmente 512x512.
    if (!empty($_FILES['app_icon']['name'])) {
        $uploaded = uploadImage($_FILES['app_icon'], 'logos');
        if ($uploaded) {
            $old = getSetting('app_icon');
            if ($old && file_exists(UPLOAD_PATH . $old)) @unlink(UPLOAD_PATH . $old);
            setSetting('app_icon', $uploaded);
        } else {
            $errors[] = 'Error al subir el ícono. Usa JPG, PNG o WebP (máx. 2MB).';
        }
    }
    if (isset($_POST['remove_app_icon'])) {
        $old = getSetting('app_icon');
        if ($old && file_exists(UPLOAD_PATH . $old)) @unlink(UPLOAD_PATH . $old);
        setSetting('app_icon', '');
    }

    // Guardar cuentas bancarias
    Database::execute("DELETE FROM bank_accounts WHERE 1=1");
    $bankNames = isset($_POST['bank_name']) ? $_POST['bank_name'] : array();
    foreach ($bankNames as $i => $bname) {
        $bname = clean($bname);
        if (!$bname) continue;
        Database::insert(
            "INSERT INTO bank_accounts (bank_name, account_holder, tax_id, account_type, currency, account_number, cci, sort_order)
             VALUES (?,?,?,?,?,?,?,?)",
            array(
                $bname,
                clean(isset($_POST['account_holder'][$i]) ? $_POST['account_holder'][$i] : ''),
                clean(isset($_POST['tax_id'][$i])         ? $_POST['tax_id'][$i]         : ''),
                clean(isset($_POST['account_type'][$i])   ? $_POST['account_type'][$i]   : 'ahorros'),
                clean(isset($_POST['currency'][$i])       ? $_POST['currency'][$i]       : 'soles'),
                clean(isset($_POST['account_number'][$i]) ? $_POST['account_number'][$i] : ''),
                clean(isset($_POST['cci'][$i])            ? $_POST['cci'][$i]            : ''),
                $i
            )
        );
    }

    if (empty($errors)) {
        flashMessage('success', 'Configuración guardada correctamente.');
        redirect('/admin/settings/index.php');
    }
}

// Cargar valores actuales
$cfg = [];
$rows = Database::fetchAll("SELECT `key`, `value` FROM company_settings");
foreach ($rows as $r) $cfg[$r['key']] = $r['value'];

// Cargar cuentas bancarias
$bankAccounts = Database::fetchAll("SELECT * FROM bank_accounts ORDER BY sort_order");

$pageTitle  = 'Configuración';
$activePage = 'settings';
$extraHead = '<style>
.page-header-left h1{display:inline-flex;align-items:center;gap:10px}
.page-header-left h1 .sec-ico{display:inline-flex;color:var(--text-secondary)}
.page-header-left h1 .sec-ico svg{width:22px;height:22px}
.card-title{display:inline-flex;align-items:center;gap:8px}
.card-title .sec-ico{display:inline-flex;color:var(--text-secondary)}
.card-title .sec-ico svg{width:17px;height:17px}
.btn .btn-ico{display:inline-flex;vertical-align:-2px;margin-right:5px}
.btn .btn-ico svg{width:15px;height:15px}
.upload-ico{display:inline-flex;vertical-align:-3px;margin-right:6px;color:var(--text-secondary)}
.upload-ico svg{width:16px;height:16px}
.bank-del svg{width:16px;height:16px}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg></span>Configuración</h1>
    <p>Datos de tu empresa, PDF, WhatsApp y valores por defecto</p>
  </div>
</div>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-error">✗ <?= $e ?></div>
<?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
<?= csrfField() ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

  <!-- COLUMNA IZQUIERDA -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Datos de la empresa -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M9 6h.01M15 6h.01M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/></svg></span>Datos de la empresa</span></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-required">Nombre de la empresa</label>
          <input type="text" name="company_name"
                 value="<?= clean($cfg['company_name'] ?? '') ?>"
                 placeholder="El Gringo Burger Joint">
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>RUC</label>
            <input type="text" name="company_ruc"
                   value="<?= clean($cfg['company_ruc'] ?? '') ?>"
                   placeholder="20xxxxxxxxx" maxlength="11">
          </div>
          <div class="form-group">
            <label>Teléfono / WhatsApp</label>
            <input type="tel" name="company_phone"
                   value="<?= clean($cfg['company_phone'] ?? '') ?>"
                   placeholder="9XX XXX XXX">
          </div>
        </div>
        <div class="form-group">
          <label>Dirección</label>
          <input type="text" name="company_address"
                 value="<?= clean($cfg['company_address'] ?? '') ?>"
                 placeholder="Av. Javier Prado 1234, San Isidro, Lima">
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="company_email"
                   value="<?= clean($cfg['company_email'] ?? '') ?>"
                   placeholder="contacto@elgringo.pe">
          </div>
          <div class="form-group">
            <label>Sitio web</label>
            <input type="text" name="company_website"
                   value="<?= clean($cfg['company_website'] ?? '') ?>"
                   placeholder="www.elgringo.pe">
          </div>
        </div>
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Dominio de correo</label>
            <input type="text" name="mail_domain"
                   value="<?= clean($cfg['mail_domain'] ?? '') ?>"
                   placeholder="elgringo.pe">
            <div class="form-hint">Desde aquí salen los correos: cotizaciones@, reservas@, comprobantes@<strong>{dominio}</strong>. Esos buzones deben existir en el cPanel.</div>
          </div>
          <div class="form-group">
            <label>Responder-a (cotizaciones)</label>
            <input type="text" name="mail_cotizaciones_replyto"
                   value="<?= clean($cfg['mail_cotizaciones_replyto'] ?? '') ?>"
                   placeholder="daniel@..., eventos@...">
            <div class="form-hint">A dónde llegan las respuestas de los correos de cotización (separa con coma).</div>
          </div>
        </div>
      </div>
    </div>

    <!-- WhatsApp -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span>WhatsApp</span></div>
      <div class="card-body">
        <div class="form-group">
          <label>Número de WhatsApp (con código de país)</label>
          <input type="text" name="whatsapp_number"
                 value="<?= clean($cfg['whatsapp_number'] ?? '') ?>"
                 placeholder="51987654321">
          <div class="form-hint">Formato: código de país + número sin espacios. Ejemplo: <strong>51987654321</strong> para Perú</div>
        </div>
        <?php
        $waNum = preg_replace('/\D/', '', $cfg['whatsapp_number'] ?? '');
        if ($waNum): ?>
        <a href="https://wa.me/<?= $waNum ?>" target="_blank" class="btn btn-success btn-sm">
          <span class="btn-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span>Probar enlace de WhatsApp
        </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Cuentas bancarias -->
    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
        <span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></span>Números de cuenta bancaria</span>
      </div>
      <div class="card-body">
        <div id="bankAccountsContainer">
          <?php foreach ($bankAccounts as $i => $ba): ?>
          <div class="bank-entry" style="border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:12px;position:relative">
            <button type="button" onclick="removeBankEntry(this)" class="bank-del" aria-label="Eliminar cuenta"
                    style="position:absolute;top:10px;right:10px;background:none;border:none;cursor:pointer;color:var(--text-muted);display:inline-flex;padding:0"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
              <div class="form-group" style="margin:0">
                <label>Banco</label>
                <input type="text" name="bank_name[]"
                       value="<?= clean($ba['bank_name']) ?>" placeholder="BCP, Interbank...">
              </div>
              <div class="form-group" style="margin:0">
                <label>Tipo de cuenta</label>
                <select name="account_type[]">
                  <option value="ahorros"   <?= $ba['account_type']==='ahorros'   ?'selected':'' ?>>Ahorros</option>
                  <option value="corriente" <?= $ba['account_type']==='corriente' ?'selected':'' ?>>Corriente</option>
                </select>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
              <div class="form-group" style="margin:0">
                <label>Nombre del titular</label>
                <input type="text" name="account_holder[]"
                       value="<?= clean($ba['account_holder'] ?? '') ?>" placeholder="Smash Peru SAC">
              </div>
              <div class="form-group" style="margin:0">
                <label>RUC / DNI</label>
                <input type="text" name="tax_id[]"
                       value="<?= clean($ba['tax_id'] ?? '') ?>" placeholder="20612345678" inputmode="numeric">
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div class="form-group" style="margin:0">
                <label>Número de cuenta</label>
                <input type="text" name="account_number[]"
                       value="<?= clean($ba['account_number']) ?>" placeholder="XXX-XXXXXXXXX-X-XX">
              </div>
              <div class="form-group" style="margin:0">
                <label>CCI</label>
                <input type="text" name="cci[]"
                       value="<?= clean($ba['cci']) ?>" placeholder="00XXXXXXXXXXXXXXXXXXXXX">
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($bankAccounts)): ?>
          <div id="emptyBankMsg" style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">
            Sin cuentas configuradas. Agrega una abajo.
          </div>
          <?php endif; ?>
        </div>
        <button type="button" onclick="addBankEntry()" class="btn btn-ghost btn-sm" style="margin-top:4px">
          <span class="btn-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></span>Agregar cuenta
        </button>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-top:1px solid var(--border)">
        <label style="font-size:13px;font-weight:600">Mostrar en el PDF de cotización</label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="show_bank_accounts" value="1"
                 <?= ($cfg['show_bank_accounts'] ?? '1') === '1' ? 'checked' : '' ?>
                 style="width:18px;height:18px;accent-color:var(--red)">
          <span style="font-size:13px;color:var(--text-muted)">Visible</span>
        </label>
      </div>
    </div>

    <!-- Términos por defecto -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></span>Textos por defecto</span></div>
      <div class="card-body">
        <div class="form-group">
          <label>Términos y condiciones por defecto</label>
          <textarea name="default_terms" rows="7"><?= clean($cfg['default_terms'] ?? '') ?></textarea>
          <div class="form-hint">Se precarga en cada nueva cotización (editable antes de guardar)</div>
        </div>
        <div class="form-group">
          <label>Observaciones por defecto</label>
          <textarea name="default_observations" rows="3"><?= clean($cfg['default_observations'] ?? '') ?></textarea>
        </div>
      </div>
    </div>

  </div>

  <!-- COLUMNA DERECHA -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Logos -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/></svg></span>Logos</span></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:16px">
        <div class="form-hint" style="margin:0">Sube dos versiones del logo. Elige cuál se muestra en el PDF según el color de fondo.</div>

        <!-- Logo A -->
        <div style="border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
          <div style="padding:10px 14px;background:#fafafa;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <span style="font-size:13px;font-weight:600">Logo A</span>
            <?php if ($cfg['company_logo'] ?? ''): ?>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:#dc2626">
              <input type="checkbox" name="remove_logo" value="1" style="accent-color:#dc2626">
              Eliminar
            </label>
            <?php endif; ?>
          </div>
          <div style="padding:12px 14px">
            <?php if ($cfg['company_logo'] ?? ''): ?>
            <div style="background:#1a1a1a;border-radius:8px;padding:12px;text-align:center;margin-bottom:10px">
              <img src="<?= UPLOAD_URL . clean($cfg['company_logo']) ?>"
                   style="max-height:60px;max-width:180px;object-fit:contain" id="logoAPreview">
            </div>
            <?php else: ?>
            <div style="background:#1a1a1a;border-radius:8px;padding:12px;text-align:center;margin-bottom:10px;min-height:50px" id="logoABg">
              <img id="logoAPreview" src="" style="max-height:60px;max-width:180px;object-fit:contain;display:none">
              <span id="logoAEmpty" style="font-size:12px;color:#666">Sin logo</span>
            </div>
            <?php endif; ?>
            <label class="img-upload-box" for="logoInputA" style="padding:10px">
              <div style="font-size:13px;font-weight:600;color:var(--text-secondary)"><span class="upload-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></span>Subir Logo A</div>
              <div style="font-size:11px;color:var(--text-muted)">PNG transparente · Máx. 2MB</div>
              <input type="file" id="logoInputA" name="company_logo" accept="image/*" style="display:none"
                     onchange="previewLogo(this,'logoAPreview','logoAEmpty')">
            </label>
          </div>
        </div>

        <!-- Logo B -->
        <div style="border:1.5px solid var(--border);border-radius:10px;overflow:hidden">
          <div style="padding:10px 14px;background:#fafafa;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <span style="font-size:13px;font-weight:600">Logo B</span>
            <?php if ($cfg['company_logo_b'] ?? ''): ?>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:#dc2626">
              <input type="checkbox" name="remove_logo_b" value="1" style="accent-color:#dc2626">
              Eliminar
            </label>
            <?php endif; ?>
          </div>
          <div style="padding:12px 14px">
            <?php if ($cfg['company_logo_b'] ?? ''): ?>
            <div style="background:#f5f5f0;border-radius:8px;padding:12px;text-align:center;margin-bottom:10px">
              <img src="<?= UPLOAD_URL . clean($cfg['company_logo_b']) ?>"
                   style="max-height:60px;max-width:180px;object-fit:contain" id="logoBPreview">
            </div>
            <?php else: ?>
            <div style="background:#f5f5f0;border-radius:8px;padding:12px;text-align:center;margin-bottom:10px;min-height:50px">
              <img id="logoBPreview" src="" style="max-height:60px;max-width:180px;object-fit:contain;display:none">
              <span id="logoBEmpty" style="font-size:12px;color:#aaa">Sin logo</span>
            </div>
            <?php endif; ?>
            <label class="img-upload-box" for="logoInputB" style="padding:10px">
              <div style="font-size:13px;font-weight:600;color:var(--text-secondary)"><span class="upload-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></span>Subir Logo B</div>
              <div style="font-size:11px;color:var(--text-muted)">PNG transparente · Máx. 2MB</div>
              <input type="file" id="logoInputB" name="company_logo_b" accept="image/*" style="display:none"
                     onchange="previewLogo(this,'logoBPreview','logoBEmpty')">
            </label>
          </div>
        </div>

        <!-- Ícono de la app (favicon / PWA) -->
        <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden">
          <div style="padding:10px 14px;background:#fafafa;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
            <span style="font-size:13px;font-weight:600">Ícono de la app (favicon / PWA)</span>
            <?php if ($cfg['app_icon'] ?? ''): ?>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:#dc2626">
              <input type="checkbox" name="remove_app_icon" value="1" style="accent-color:#dc2626"> Eliminar
            </label>
            <?php endif; ?>
          </div>
          <div style="padding:12px 14px">
            <?php if ($cfg['app_icon'] ?? ''): ?>
            <div style="background:#f5f5f0;border-radius:8px;padding:12px;text-align:center;margin-bottom:10px">
              <img src="<?= UPLOAD_URL . clean($cfg['app_icon']) ?>" style="max-height:60px;max-width:60px;object-fit:contain;border-radius:10px" id="iconPreview">
            </div>
            <?php else: ?>
            <div style="background:#f5f5f0;border-radius:8px;padding:12px;text-align:center;margin-bottom:10px;min-height:50px">
              <img id="iconPreview" src="" style="max-height:60px;max-width:60px;object-fit:contain;display:none">
              <span id="iconEmpty" style="font-size:12px;color:#aaa">Usa el ícono por defecto</span>
            </div>
            <?php endif; ?>
            <label class="img-upload-box" for="iconInput" style="padding:10px">
              <div style="font-size:13px;font-weight:600;color:var(--text-secondary)"><span class="upload-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg></span>Subir ícono</div>
              <div style="font-size:11px;color:var(--text-muted)">Cuadrado (512×512) · PNG · Máx. 2MB · favicon + ícono al instalar</div>
              <input type="file" id="iconInput" name="app_icon" accept="image/*" style="display:none"
                     onchange="previewLogo(this,'iconPreview','iconEmpty')">
            </label>
          </div>
        </div>

        <!-- Selector de logo activo -->
        <div class="form-group" style="margin:0">
          <label style="font-weight:600">Logo activo en el PDF</label>
          <div style="display:flex;gap:10px;margin-top:6px">
            <label style="flex:1;cursor:pointer">
              <input type="radio" name="active_logo" value="a"
                     <?= (($cfg['active_logo'] ?? 'a') === 'a') ? 'checked' : '' ?>
                     style="display:none">
              <div class="logo-opt" id="logoOptA"
                   style="border:2px solid;border-radius:10px;padding:12px;text-align:center;transition:.15s">
                <div style="font-size:22px">A</div>
                <div style="font-size:12px;font-weight:600;margin-top:4px">Logo A</div>
              </div>
            </label>
            <label style="flex:1;cursor:pointer">
              <input type="radio" name="active_logo" value="b"
                     <?= (($cfg['active_logo'] ?? 'a') === 'b') ? 'checked' : '' ?>
                     style="display:none">
              <div class="logo-opt" id="logoOptB"
                   style="border:2px solid;border-radius:10px;padding:12px;text-align:center;transition:.15s">
                <div style="font-size:22px">B</div>
                <div style="font-size:12px;font-weight:600;margin-top:4px">Logo B</div>
              </div>
            </label>
          </div>
        </div>

      </div>
    </div>

    <!-- Punto de venta (POS) -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg></span>Punto de venta (POS)</span></div>
      <div class="card-body">
        <label class="toggle-wrap" style="cursor:pointer;display:flex;align-items:center;gap:10px">
          <input type="checkbox" name="pos_nombre_obligatorio" value="1" <?= ($cfg['pos_nombre_obligatorio'] ?? '0')==='1' ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--brand)">
          <span>
            <span class="toggle-label" style="font-weight:700">Exigir nombre del pedido al cobrar</span>
            <span style="display:block;font-size:12px;color:var(--text-muted);margin-top:2px">Si está activo, el cajero debe ponerle un nombre a cada pedido (o tener nombre/razón en boleta/factura). Sirve para cantar pedidos por nombre. Apágalo en horas pico si frena la caja.</span>
          </span>
        </label>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-top:10px">
          <input type="checkbox" name="mozo_geocerca_activa" value="1" <?= getSetting('mozo_geocerca_activa','1')==='1'?'checked':'' ?> style="width:18px;height:18px">
          <span>Geocerca del mozo (solo puede tomar pedidos dentro del local) — apágalo si el GPS falla</span>
        </label>
        <div style="margin-top:14px">
          <div style="font-weight:700;margin-bottom:6px">Tiempo de mesa (color del borde en el plano)</div>
          <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;font-size:13px">
            <label style="display:flex;align-items:center;gap:6px">🟠 Naranja desde
              <input type="number" name="mesa_umbral_naranja" min="1" value="<?= (int)getSetting('mesa_umbral_naranja','20') ?>" style="width:64px;padding:6px;border:1.5px solid var(--border,#ddd);border-radius:7px"> min</label>
            <label style="display:flex;align-items:center;gap:6px">🔴 Rojo desde
              <input type="number" name="mesa_umbral_rojo" min="1" value="<?= (int)getSetting('mesa_umbral_rojo','30') ?>" style="width:64px;padding:6px;border:1.5px solid var(--border,#ddd);border-radius:7px"> min</label>
          </div>
          <span style="display:block;font-size:12px;color:var(--text-muted);margin-top:5px">Verde antes del naranja. Por defecto: naranja a los 20 min, rojo a los 30.</span>
        </div>
      </div>
    </div>

    <!-- Configuración de cotizaciones -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 2H8a2 2 0 0 0-2 2v18l3-2 3 2 3-2 3 2V4a2 2 0 0 0-2-2Z"/><path d="M9 7h6M9 11h6"/></svg></span>Cotizaciones</span></div>
      <div class="card-body">
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Prefijo del número</label>
            <input type="text" name="quote_prefix"
                   value="<?= clean($cfg['quote_prefix'] ?? 'EG') ?>"
                   placeholder="EG" maxlength="5">
            <div class="form-hint">Ej: EG → EG-2024-0001</div>
          </div>
          <div class="form-group">
            <label>Vigencia por defecto (días)</label>
            <input type="number" name="quote_validity_days"
                   value="<?= clean($cfg['quote_validity_days'] ?? '15') ?>"
                   min="1" max="365">
          </div>
        </div>
        <div class="form-group">
          <label>IGV por defecto</label>
          <select name="default_igv">
            <option value="none"  <?= ($cfg['default_igv']??'none')==='none'  ?'selected':'' ?>>Sin IGV</option>
            <option value="10.5"  <?= ($cfg['default_igv']??'')==='10.5'      ?'selected':'' ?>>IGV 10.5%</option>
            <option value="18"    <?= ($cfg['default_igv']??'')==='18'        ?'selected':'' ?>>IGV 18%</option>
          </select>
          <div class="form-hint">Se puede cambiar en cada cotización individual</div>
        </div>
      </div>
    </div>

    <!-- Colores del PDF -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg></span>Colores del PDF</span></div>
      <div class="card-body">
        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Color de fondo</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="color" name="pdf_primary_color"
                     value="<?= clean($cfg['pdf_primary_color'] ?? '#C8102E') ?>"
                     style="width:44px;height:40px;padding:2px;cursor:pointer;border-radius:6px"
                     oninput="syncText('color1Text',this.value);updatePreview()">
              <input type="text" id="color1Text"
                     value="<?= clean($cfg['pdf_primary_color'] ?? '#C8102E') ?>"
                     style="flex:1;font-family:monospace" maxlength="7"
                     oninput="syncPicker('[name=pdf_primary_color]',this.value);updatePreview()">
            </div>
            <div class="form-hint">Header, cabecera de tabla, total final</div>
          </div>
          <div class="form-group">
            <label>Color de texto en cajas</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="color" name="pdf_secondary_color"
                     value="<?= clean($cfg['pdf_secondary_color'] ?? '#ffffff') ?>"
                     style="width:44px;height:40px;padding:2px;cursor:pointer;border-radius:6px"
                     oninput="syncText('color2Text',this.value);updatePreview()">
              <input type="text" id="color2Text"
                     value="<?= clean($cfg['pdf_secondary_color'] ?? '#ffffff') ?>"
                     style="flex:1;font-family:monospace" maxlength="7"
                     oninput="syncPicker('[name=pdf_secondary_color]',this.value);updatePreview()">
            </div>
            <div class="form-hint">Texto dentro del header, tabla y total (ej: blanco #ffffff)</div>
          </div>
        </div>

        <!-- Preview mini del PDF -->
        <div style="border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-top:12px">
          <div id="pdfPreviewHeader" style="padding:12px 16px;display:flex;justify-content:space-between;align-items:center">
            <strong id="pdfPreviewName"><?= clean($cfg['company_name'] ?? 'El Gringo Burger Joint') ?></strong>
            <span style="font-size:13px;opacity:.85">EG-2026-0001</span>
          </div>
          <div style="padding:12px 16px;background:#fff;font-size:12px;color:#666">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px">
              <span>Subtotal</span><span>S/ 500.00</span>
            </div>
            <div id="pdfPreviewTotal" style="display:flex;justify-content:space-between;padding:9px 12px;border-radius:7px;font-weight:700">
              <span>TOTAL</span><span>S/ 500.00</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Colores de la app (white-label) -->
    <div class="card">
      <div class="card-header"><span class="card-title"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg></span>Colores de la app</span></div>
      <div class="card-body">
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px">Colores de marca de la carta, POS y panel. <strong>Déjalos vacíos para usar los colores por defecto.</strong></p>
        <?php
          $brandRows = [
            ['brand_primary',   'Color primario',   '#FFDF00', 'Botones, acentos, resaltados'],
            ['brand_secondary', 'Color secundario', '#FFBBC8', 'Rosa de marca'],
            ['brand_dark',      'Color oscuro',     '#1E1E1E', 'Texto / cabeceras'],
          ];
          foreach ($brandRows as [$k, $lbl, $def, $hint]):
            $cur = clean($cfg[$k] ?? '');
        ?>
        <div class="form-group">
          <label><?= $lbl ?></label>
          <div style="display:flex;gap:8px;align-items:center">
            <input type="color" value="<?= $cur ?: $def ?>" oninput="this.nextElementSibling.value=this.value.toUpperCase()" style="width:46px;height:40px;padding:3px;border:1px solid var(--border);border-radius:8px;cursor:pointer">
            <input type="text" name="<?= $k ?>" value="<?= $cur ?>" placeholder="<?= $def ?> (default)" style="flex:1" maxlength="7">
          </div>
          <div class="form-hint"><?= $hint ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Guardar -->
    <button type="submit" class="btn btn-primary btn-lg btn-block">
      <span class="btn-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg></span>Guardar configuración
    </button>

  </div>

</div>
</form>

<!-- Zona de peligro: borrar datos de prueba (ventas) -->
<div class="card" style="max-width:760px;border:1px solid #f3b4b4;background:#fff7f7">
  <div class="card-header"><span class="card-title" style="color:#c0392b"><span class="sec-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/><path d="M12 9v4M12 17h.01"/></svg></span>Zona de peligro</span></div>
  <div class="card-body">
    <p style="margin:0 0 6px;font-weight:700">Borrar datos de prueba (ventas)</p>
    <p style="margin:0 0 14px;font-size:13px;color:var(--text-secondary);line-height:1.55">
      Borra <strong>todos los pedidos</strong> (carta + POS, con sus comprobantes), los <strong>turnos/arqueos de caja</strong>, los <strong>movimientos de inventario</strong> y pone el <strong>stock en 0</strong>.
      <br><strong>Se conservan:</strong> insumos, recetas, productos, categorías, ubicaciones, usuarios, cotizaciones/eventos/reservas, gastos y toda la configuración (Izipay, SUNAT/NubeFact, series). <strong>Es permanente.</strong>
    </p>
    <form method="post" onsubmit="return confirm('¿Borrar TODOS los pedidos, caja y movimientos de inventario? Se conserva todo lo demás. No se puede deshacer.');">
      <?= csrfField() ?>
      <input type="hidden" name="accion" value="reset_ventas">
      <div class="form-group" style="max-width:320px">
        <label>Para confirmar, escribe <code>BORRAR VENTAS</code></label>
        <input type="text" name="confirmacion" placeholder="BORRAR VENTAS" autocomplete="off" oninput="document.getElementById('btn-reset').disabled = (this.value !== 'BORRAR VENTAS');">
      </div>
      <button type="submit" id="btn-reset" class="btn btn-danger" disabled style="background:#c0392b;border-color:#c0392b;color:#fff">Borrar datos de prueba</button>
    </form>
  </div>
</div>

<script>
function syncText(id, val)      { var el=document.getElementById(id); if(el) el.value=val; }
function syncPicker(sel, val)   { var el=document.querySelector(sel); if(el && /^#[0-9a-fA-F]{6}$/.test(val)) el.value=val; }

// Cuentas bancarias
function addBankEntry() {
  var empty = document.getElementById('emptyBankMsg');
  if (empty) empty.style.display = 'none';
  var div = document.createElement('div');
  div.className = 'bank-entry';
  div.style.cssText = 'border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:12px;position:relative';
  div.innerHTML = '<button type="button" onclick="removeBankEntry(this)" class="bank-del" aria-label="Eliminar cuenta" style="position:absolute;top:10px;right:10px;background:none;border:none;cursor:pointer;color:var(--text-muted);display:inline-flex;padding:0"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg></button>'
    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">'
    + '<div class="form-group" style="margin:0"><label>Banco</label><input type="text" name="bank_name[]" placeholder="BCP, Interbank..."></div>'
    + '<div class="form-group" style="margin:0"><label>Tipo de cuenta</label><select name="account_type[]"><option value="ahorros">Ahorros</option><option value="corriente">Corriente</option></select></div>'
    + '</div>'
    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">'
    + '<div class="form-group" style="margin:0"><label>Nombre del titular</label><input type="text" name="account_holder[]" placeholder="Smash Peru SAC"></div>'
    + '<div class="form-group" style="margin:0"><label>RUC / DNI</label><input type="text" name="tax_id[]" placeholder="20612345678" inputmode="numeric"></div>'
    + '</div>'
    + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">'
    + '<div class="form-group" style="margin:0"><label>Número de cuenta</label><input type="text" name="account_number[]" placeholder="XXX-XXXXXXXXX-X-XX"></div>'
    + '<div class="form-group" style="margin:0"><label>CCI</label><input type="text" name="cci[]" placeholder="00XXXXXXXXXXXXXXXXXXXXX"></div>'
    + '</div>';
  document.getElementById('bankAccountsContainer').appendChild(div);
}
function removeBankEntry(btn) {
  btn.closest('.bank-entry').remove();
}

function previewLogo(input, previewId, emptyId) {
  var file = input.files[0];
  if (!file) return;
  var reader = new FileReader();
  reader.onload = function(e) {
    var img = document.getElementById(previewId);
    if (img) { img.src = e.target.result; img.style.display = 'block'; }
    var empty = document.getElementById(emptyId);
    if (empty) empty.style.display = 'none';
  };
  reader.readAsDataURL(file);
}

function updateLogoSelector() {
  var aChecked = document.querySelector('[name="active_logo"][value="a"]').checked;
  var optA = document.getElementById('logoOptA');
  var optB = document.getElementById('logoOptB');
  optA.style.borderColor = aChecked ? 'var(--red)' : 'var(--border)';
  optA.style.background  = aChecked ? 'var(--red-light)' : '#fff';
  optA.style.color       = aChecked ? 'var(--red)' : 'var(--text-secondary)';
  optB.style.borderColor = !aChecked ? 'var(--red)' : 'var(--border)';
  optB.style.background  = !aChecked ? 'var(--red-light)' : '#fff';
  optB.style.color       = !aChecked ? 'var(--red)' : 'var(--text-secondary)';
}

document.querySelectorAll('[name="active_logo"]').forEach(function(r) {
  r.addEventListener('change', updateLogoSelector);
  // Hacer clickeable el div completo
  r.parentElement.querySelector('.logo-opt').addEventListener('click', function() {
    r.checked = true;
    updateLogoSelector();
  });
});
updateLogoSelector();

function updatePreview() {
  var c1 = document.querySelector('[name="pdf_primary_color"]').value;
  var c2 = document.querySelector('[name="pdf_secondary_color"]').value;
  var hdr = document.getElementById('pdfPreviewHeader');
  var tot = document.getElementById('pdfPreviewTotal');
  hdr.style.background = c1;
  hdr.style.color      = c2;
  tot.style.background = c1;
  tot.style.color      = c2;
}
updatePreview();
</script>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
