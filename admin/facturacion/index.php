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

    $igvPct = cleanInt($_POST['igv_pct'] ?? 18);
    setSetting('igv_pct',     (string) ($igvPct > 0 ? $igvPct : 18));
    setSetting('igv_incluido', isset($_POST['igv_incluido']) ? '1' : '0');

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
          <input type="number" name="igv_pct" min="0" step="1"
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

<div style="margin-top:20px">
  <button type="submit" class="btn btn-primary">Guardar configuración</button>
</div>

</form>

<?php include __DIR__ . '/../layout-bottom.php'; ?>
