<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Texto / select
    $modo = in_array($_POST['nubefact_modo'] ?? 'demo', ['demo', 'produccion'], true)
        ? $_POST['nubefact_modo'] : 'demo';

    setSetting('nubefact_url',           clean($_POST['nubefact_url'] ?? ''));
    setSetting('nubefact_token',         clean($_POST['nubefact_token'] ?? ''));
    setSetting('nubefact_modo',          $modo);

    $serieBol = clean($_POST['nubefact_serie_boleta'] ?? '');
    $serieFac = clean($_POST['nubefact_serie_factura'] ?? '');
    setSetting('nubefact_serie_boleta',  $serieBol !== '' ? $serieBol : 'B001');
    setSetting('nubefact_serie_factura', $serieFac !== '' ? $serieFac : 'F001');

    $numBol = cleanInt($_POST['nubefact_num_boleta'] ?? 1);
    $numFac = cleanInt($_POST['nubefact_num_factura'] ?? 1);
    setSetting('nubefact_num_boleta',  (string) ($numBol >= 1 ? $numBol : 1));
    setSetting('nubefact_num_factura', (string) ($numFac >= 1 ? $numFac : 1));

    $igvPct = cleanFloat($_POST['igv_pct'] ?? 18);
    setSetting('igv_pct',     (string) ($igvPct > 0 ? $igvPct : 18));
    setSetting('igv_incluido', isset($_POST['igv_incluido']) ? '1' : '0');

    // Consulta DNI/RUC (RENIEC/SUNAT) — token + endpoints (crudos, sin htmlspecialchars).
    setSetting('doc_api_token', trim((string)($_POST['doc_api_token'] ?? '')));
    $dniUrl = trim((string)($_POST['doc_api_dni_url'] ?? ''));
    $rucUrl = trim((string)($_POST['doc_api_ruc_url'] ?? ''));
    setSetting('doc_api_dni_url', $dniUrl !== '' ? $dniUrl : 'https://api.apis.net.pe/v2/reniec/dni?numero={n}');
    setSetting('doc_api_ruc_url', $rucUrl !== '' ? $rucUrl : 'https://api.apis.net.pe/v2/sunat/ruc?numero={n}');

    // ── Izipay (pagos en línea) ─────────────────────────────────────
    $izMode = strtoupper($_POST['izipay_mode'] ?? 'TEST');
    setSetting('izipay_mode', in_array($izMode, ['TEST', 'PROD'], true) ? $izMode : 'TEST');
    setSetting('izipay_shop_id',          trim((string)($_POST['izipay_shop_id'] ?? '')));
    setSetting('izipay_public_key_test',  trim((string)($_POST['izipay_public_key_test'] ?? '')));
    setSetting('izipay_public_key_prod',  trim((string)($_POST['izipay_public_key_prod'] ?? '')));
    $izJs = trim((string)($_POST['izipay_js_url'] ?? ''));
    if ($izJs !== '') setSetting('izipay_js_url', $izJs);
    // Secretas: solo-escritura → solo se guardan si llega un valor nuevo (no se borran al guardar).
    foreach (['izipay_rest_pass_test','izipay_rest_pass_prod','izipay_hmac_test','izipay_hmac_prod'] as $sk) {
        $v = trim((string)($_POST[$sk] ?? ''));
        if ($v !== '') setSetting($sk, $v);
    }

    flashMessage('success', 'Configuración de facturación guardada correctamente.');
    redirect('/admin/facturacion/index.php');
}

// Valores actuales
$nfUrl       = getSetting('nubefact_url', '');
$nfToken     = getSetting('nubefact_token', '');
$nfModo      = getSetting('nubefact_modo', 'demo');
$serieBol    = getSetting('nubefact_serie_boleta', 'B001');
$serieFac    = getSetting('nubefact_serie_factura', 'F001');
$numBol      = getSetting('nubefact_num_boleta', '1');
$numFac      = getSetting('nubefact_num_factura', '1');
$igvPct      = getSetting('igv_pct', '18');
$igvIncluido = getSetting('igv_incluido', '1');

$docToken    = getSetting('doc_api_token', '');
$docDniUrl   = getSetting('doc_api_dni_url', 'https://api.apis.net.pe/v2/reniec/dni?numero={n}');
$docRucUrl   = getSetting('doc_api_ruc_url', 'https://api.apis.net.pe/v2/sunat/ruc?numero={n}');

// Izipay
$izMode      = getSetting('izipay_mode', 'TEST');
$izShop      = getSetting('izipay_shop_id', '');
$izPubT      = getSetting('izipay_public_key_test', '');
$izPubP      = getSetting('izipay_public_key_prod', '');
$izJsUrl     = getSetting('izipay_js_url', 'https://static.micuentaweb.pe/static/js/krypton-client/V4.0/stable/kr-payment-form.min.js');
$izHasPassT  = getSetting('izipay_rest_pass_test', '') !== '';
$izHasPassP  = getSetting('izipay_rest_pass_prod', '') !== '';
$izHasHmacT  = getSetting('izipay_hmac_test', '') !== '';
$izHasHmacP  = getSetting('izipay_hmac_prod', '') !== '';

$companyRuc   = getSetting('company_ruc', '');
$companyName  = getSetting('company_name', '');

$pageTitle  = 'Facturación electrónica';
$activePage = 'facturacion';
$extraHead  = '<style>
.page-header-left h1{display:inline-flex;align-items:center;gap:10px}
.card-title{display:inline-flex;align-items:center;gap:8px}
.emisor-box{display:flex;flex-wrap:wrap;gap:8px 28px;padding:14px 16px;background:var(--bg-page);border:1px solid var(--border);border-radius:var(--radius)}
.emisor-box .lbl{font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em}
.emisor-box .val{font-weight:600;color:var(--text-primary)}
</style>';
include __DIR__ . '/../layout-top.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1>Facturación electrónica</h1>
    <p>Integración con NubeFact (OSE / SUNAT) para boletas y facturas electrónicas</p>
  </div>
</div>

<form method="post">
<?= csrfField() ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

  <!-- COLUMNA IZQUIERDA -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Conexión NubeFact -->
    <div class="card">
      <div class="card-header"><span class="card-title">Conexión con NubeFact</span></div>
      <div class="card-body">

        <div class="form-group">
          <label class="form-required">URL de la API</label>
          <input type="text" name="nubefact_url"
                 value="<?= clean($nfUrl) ?>"
                 placeholder="https://api.nubefact.com/api/v1/xxxxxxxx">
          <div class="form-hint">Cópiala desde NubeFact &rarr; <strong>Api Integración</strong> (campo &laquo;url&raquo;).</div>
        </div>

        <div class="form-group">
          <label class="form-required">Token</label>
          <input type="password" name="nubefact_token"
                 value="<?= clean($nfToken) ?>"
                 placeholder="Token de Api Integración" autocomplete="off">
          <div class="form-hint">El token secreto de la misma pantalla. No lo compartas.</div>
        </div>

        <div class="form-group">
          <label>Modo</label>
          <select name="nubefact_modo">
            <option value="demo"       <?= $nfModo === 'demo' ? 'selected' : '' ?>>Demo (pruebas)</option>
            <option value="produccion" <?= $nfModo === 'produccion' ? 'selected' : '' ?>>Producción (SUNAT real)</option>
          </select>
        </div>

        <?php if ($nfModo === 'demo'): ?>
        <div class="alert alert-warning" style="margin:0">
          <strong>Modo DEMO activo.</strong> Los comprobantes generados en demo
          <strong>NO se envían a SUNAT real</strong> y no tienen validez tributaria.
          Úsalo solo para pruebas. Cambia a <em>Producción</em> cuando vayas a emitir de verdad.
        </div>
        <?php else: ?>
        <div class="alert alert-error" style="margin:0">
          <strong>Modo PRODUCCIÓN activo.</strong> Los comprobantes
          <strong>SÍ se envían a SUNAT</strong> y tienen efecto legal. Verifica las series y correlativos.
        </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- Emisor -->
    <div class="card">
      <div class="card-header"><span class="card-title">Emisor</span></div>
      <div class="card-body">
        <div class="emisor-box">
          <div>
            <div class="lbl">Razón social</div>
            <div class="val"><?= $companyName !== '' ? clean($companyName) : '— sin definir —' ?></div>
          </div>
          <div>
            <div class="lbl">RUC</div>
            <div class="val"><?= $companyRuc !== '' ? clean($companyRuc) : '— sin definir —' ?></div>
          </div>
        </div>
        <div class="form-hint" style="margin-top:10px">
          El emisor real de los comprobantes es la cuenta configurada en NubeFact.
          Estos datos provienen de <a href="<?= APP_URL ?>/admin/settings/index.php">Configuración</a>.
        </div>
      </div>
    </div>

    <!-- Consulta DNI / RUC -->
    <div class="card">
      <div class="card-header"><span class="card-title">Consulta DNI / RUC (RENIEC · SUNAT)</span></div>
      <div class="card-body">

        <div class="form-group">
          <label>Token de <a href="https://apis.net.pe" target="_blank" rel="noopener">apis.net.pe</a></label>
          <input type="password" name="doc_api_token"
                 value="<?= clean($docToken) ?>"
                 placeholder="Token de tu cuenta apis.net.pe" autocomplete="off">
          <div class="form-hint">
            Crea una cuenta en apis.net.pe, genera un token y pégalo aquí. Con esto el POS
            autocompleta el nombre al escribir el DNI/RUC en el cobro. Las consultas de DNI
            tienen costo según tu plan del proveedor.
          </div>
        </div>

        <details>
          <summary style="cursor:pointer;font-size:13px;color:var(--text-muted)">Endpoints (avanzado)</summary>
          <div class="form-group" style="margin-top:12px">
            <label>URL consulta DNI</label>
            <input type="text" name="doc_api_dni_url" value="<?= clean($docDniUrl) ?>">
          </div>
          <div class="form-group">
            <label>URL consulta RUC</label>
            <input type="text" name="doc_api_ruc_url" value="<?= clean($docRucUrl) ?>">
          </div>
          <div class="form-hint">Usa <code>{n}</code> donde va el número. Cámbialos solo si usas otro proveedor.</div>
        </details>

      </div>
    </div>

  </div>

  <!-- COLUMNA DERECHA -->
  <div style="display:flex;flex-direction:column;gap:20px">

    <!-- Series y correlativos -->
    <div class="card">
      <div class="card-header"><span class="card-title">Series y correlativos</span></div>
      <div class="card-body">

        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Serie de boletas</label>
            <input type="text" name="nubefact_serie_boleta"
                   value="<?= clean($serieBol) ?>" placeholder="B001" maxlength="10">
          </div>
          <div class="form-group">
            <label>Siguiente número (boleta)</label>
            <input type="number" name="nubefact_num_boleta" min="1" step="1"
                   value="<?= clean($numBol) ?>">
          </div>
        </div>

        <div class="form-row form-row-2">
          <div class="form-group">
            <label>Serie de facturas</label>
            <input type="text" name="nubefact_serie_factura"
                   value="<?= clean($serieFac) ?>" placeholder="F001" maxlength="10">
          </div>
          <div class="form-group">
            <label>Siguiente número (factura)</label>
            <input type="number" name="nubefact_num_factura" min="1" step="1"
                   value="<?= clean($numFac) ?>">
          </div>
        </div>

        <div class="form-hint">
          El <strong>siguiente número</strong> es el correlativo que se usará en la próxima emisión de cada serie.
          El sistema lo avanza automáticamente tras cada comprobante aceptado. Edítalo solo si necesitas arrancar en otro número.
        </div>

      </div>
    </div>

    <!-- IGV -->
    <div class="card">
      <div class="card-header"><span class="card-title">IGV</span></div>
      <div class="card-body">

        <div class="form-group">
          <label>Porcentaje de IGV (%)</label>
          <input type="number" name="igv_pct" min="0" max="18" step="0.01" inputmode="decimal"
                 value="<?= clean($igvPct) ?>">
        </div>

        <div class="form-group">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="igv_incluido" value="1" <?= $igvIncluido === '1' ? 'checked' : '' ?>>
            Los precios ya incluyen IGV
          </label>
          <div class="form-hint">
            Marcado: el precio cobrado es con IGV (modo recomendado para esta operación).
            El total del pedido es el monto autoritativo y se desagrega el IGV al emitir.
          </div>
        </div>

      </div>
    </div>

  </div>

</div>

<!-- Izipay (ancho completo) -->
<div class="card" style="margin-top:20px">
  <div class="card-header"><span class="card-title">Pagos en línea (Izipay)</span></div>
  <div class="card-body">
    <div class="form-row form-row-2">
      <div class="form-group">
        <label>Modo</label>
        <select name="izipay_mode">
          <option value="TEST" <?= $izMode==='TEST'?'selected':'' ?>>TEST (pruebas)</option>
          <option value="PROD" <?= $izMode==='PROD'?'selected':'' ?>>PRODUCCIÓN (cobros reales)</option>
        </select>
      </div>
      <div class="form-group">
        <label>Shop ID</label>
        <input type="text" name="izipay_shop_id" value="<?= clean($izShop) ?>" placeholder="12345678" autocomplete="off">
      </div>
    </div>

    <div class="form-row form-row-2">
      <div class="form-group">
        <label>Clave pública TEST</label>
        <input type="text" name="izipay_public_key_test" value="<?= clean($izPubT) ?>" placeholder="testpublickey_..." autocomplete="off">
      </div>
      <div class="form-group">
        <label>Clave pública PRODUCCIÓN</label>
        <input type="text" name="izipay_public_key_prod" value="<?= clean($izPubP) ?>" placeholder="publickey_..." autocomplete="off">
      </div>
    </div>

    <div class="form-row form-row-2">
      <div class="form-group">
        <label>Contraseña REST TEST <span style="color:#16a34a;font-size:11px"><?= $izHasPassT ? '· configurada' : '' ?></span></label>
        <input type="password" name="izipay_rest_pass_test" value="" placeholder="<?= $izHasPassT ? '•••••••• (dejar vacío para no cambiar)' : 'testpassword_...' ?>" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label>Contraseña REST PRODUCCIÓN <span style="color:#16a34a;font-size:11px"><?= $izHasPassP ? '· configurada' : '' ?></span></label>
        <input type="password" name="izipay_rest_pass_prod" value="" placeholder="<?= $izHasPassP ? '•••••••• (dejar vacío para no cambiar)' : 'prodpassword_...' ?>" autocomplete="new-password">
      </div>
    </div>

    <div class="form-row form-row-2">
      <div class="form-group">
        <label>Clave HMAC TEST <span style="color:#16a34a;font-size:11px"><?= $izHasHmacT ? '· configurada' : '' ?></span></label>
        <input type="password" name="izipay_hmac_test" value="" placeholder="<?= $izHasHmacT ? '•••••••• (dejar vacío para no cambiar)' : 'testhmac_...' ?>" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label>Clave HMAC PRODUCCIÓN <span style="color:#16a34a;font-size:11px"><?= $izHasHmacP ? '· configurada' : '' ?></span></label>
        <input type="password" name="izipay_hmac_prod" value="" placeholder="<?= $izHasHmacP ? '•••••••• (dejar vacío para no cambiar)' : 'prodhmac_...' ?>" autocomplete="new-password">
      </div>
    </div>

    <details>
      <summary style="cursor:pointer;font-size:13px;color:var(--text-muted)">Avanzado (URLs)</summary>
      <div class="form-group" style="margin-top:12px">
        <label>JS del formulario (Krypton)</label>
        <input type="text" name="izipay_js_url" value="<?= clean($izJsUrl) ?>">
      </div>
    </details>

    <div class="form-hint" style="margin-top:6px">Las contraseñas REST y HMAC son <strong>secretas</strong>: se guardan en la BD, <strong>nunca se muestran</strong> y solo se usan en el servidor (jamás llegan al navegador). Déjalas vacías para conservar las actuales. Si configuras todo aquí, ya no necesitas el <code>.env</code> de Izipay (que queda como respaldo).</div>
  </div>
</div>

<div style="margin-top:20px">
  <button type="submit" class="btn btn-primary">Guardar configuración</button>
</div>

</form>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
