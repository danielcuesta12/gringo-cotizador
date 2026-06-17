<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/nubefact.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');
function pout($d){ echo json_encode($d); exit; }

$action = clean($_GET['action'] ?? $_POST['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$writes = ['abrir_turno','cerrar_turno','registrar_venta','fav_set','fav_clear','enviar_recibo','emitir','aceptar_pedido','atender_pedido'];
if (in_array($action, $writes, true)) { if (!$isPost) pout(['ok'=>false,'error'=>'Método']); verifyCsrf(); }
$uid = (int)(currentUser()['id'] ?? 0);

switch ($action) {

// Productos disponibles de una ubicación (para la grilla), agrupados por categoría
case 'productos':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    $rows = Database::fetchAll(
        "SELECT p.id, p.name AS nombre, p.image AS foto, c.name AS categoria, lp.price AS precio
         FROM location_products lp
         JOIN products p ON p.id = lp.product_id AND p.active = 1
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE lp.location_id = ? AND lp.available = 1
         ORDER BY c.sort_order, c.name, lp.sort_order, p.sort_order, p.name", [$ubi]);
    pout(['ok'=>true,'data'=>$rows]);

// Métodos de pago activos
case 'metodos':
    pout(['ok'=>true,'data'=>Database::fetchAll("SELECT id,nombre,tipo FROM pos_metodos_pago WHERE activo=1 ORDER BY orden,id")]);

// Turno abierto del cajero en esa ubicación (o null)
case 'turno_actual':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    $t = Database::fetch("SELECT * FROM pos_turnos WHERE usuario_id=? AND ubicacion_id=? AND estado='abierto' ORDER BY id DESC LIMIT 1", [$uid,$ubi]);
    pout(['ok'=>true,'turno'=>$t]);

case 'historial_turno':
    $tid = cleanInt($_GET['turno_id'] ?? 0);
    if (!$tid) pout(['ok'=>true,'ventas'=>[]]);
    // Incluye ventas POS y pedidos de carta atendidos en este turno (turno_id).
    $ventas = Database::fetchAll(
        "SELECT id, total, metodo_pago, estado, created_at, origen, comprobante_tipo,
                comprobante_estado, comprobante_serie, comprobante_numero, comprobante_pdf
         FROM pedidos
         WHERE turno_id=?
         ORDER BY id DESC", [$tid]);
    pout(['ok'=>true,'ventas'=>$ventas]);

case 'consultar_doc':
    require_once __DIR__ . '/../includes/consulta_doc.php';
    $tipoDoc = clean($_GET['tipo'] ?? '');
    $numDoc  = preg_replace('/\D/', '', (string)($_GET['numero'] ?? ''));
    pout(consultarDocumento($tipoDoc, $numDoc));

// Bandeja de pedidos de la carta para el local del cajero (pendientes de atender).
case 'pedidos_carta':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    if (!$ubi) pout(['ok'=>true,'pedidos'=>[]]);
    $cols = "id, nombre, telefono, tipo_entrega, total, estado, metodo_pago, items_json, created_at, comprobante_tipo, cliente_documento, cliente_nombre, cliente_razon_social";
    try {
        $rows = Database::fetchAll(
            "SELECT $cols, cliente_email FROM pedidos
             WHERE origen='carta' AND ubicacion_id=? AND turno_id IS NULL AND estado <> 'cancelado'
             ORDER BY id DESC LIMIT 50", [$ubi]);
    } catch (\Throwable $e) {
        $rows = Database::fetchAll(
            "SELECT $cols FROM pedidos
             WHERE origen='carta' AND ubicacion_id=? AND turno_id IS NULL AND estado <> 'cancelado'
             ORDER BY id DESC LIMIT 50", [$ubi]);
    }
    pout(['ok'=>true,'pedidos'=>$rows]);

// Aceptar un pedido de WhatsApp (pendiente) → entra a cocina (KDS).
case 'aceptar_pedido':
    $pid = cleanInt($_POST['pedido_id'] ?? 0);
    $row = Database::fetch("SELECT id, estado FROM pedidos WHERE id=? AND origen='carta'", [$pid]);
    if (!$row) pout(['ok'=>false,'error'=>'Pedido no encontrado']);
    if ($row['estado'] === 'pendiente') {
        Database::execute("UPDATE pedidos SET estado='en_preparacion', aceptado_at=NOW() WHERE id=?", [$pid]);
    }
    pout(['ok'=>true]);

// Atender fiscalmente un pedido de carta: guarda comprobante+cliente, lo marca
// atendido en el turno (turno_id) y, si es boleta/factura, lo emite.
case 'atender_pedido':
    require_once __DIR__ . '/../includes/nubefact.php';
    $pid = cleanInt($_POST['pedido_id'] ?? 0);
    $tid = cleanInt($_POST['turno_id'] ?? 0);
    $row = Database::fetch("SELECT id FROM pedidos WHERE id=? AND origen='carta'", [$pid]);
    if (!$row) pout(['ok'=>false,'error'=>'Pedido no encontrado']);
    $compro = clean($_POST['comprobante_tipo'] ?? 'ticket');
    if (!in_array($compro, ['ticket','boleta','factura'], true)) $compro = 'ticket';
    $cDoc   = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['cliente_documento'] ?? ''));
    $cNom   = clean($_POST['cliente_nombre'] ?? '');
    $cEmail = cleanEmail($_POST['cliente_email'] ?? '');
    $cTipo   = $compro === 'factura' ? 'ruc' : ($cDoc !== '' ? 'dni' : null);
    $cNombre = $compro === 'factura' ? null : ($cNom ?: null);
    $cRazon  = $compro === 'factura' ? ($cNom ?: null) : null;
    try {
        Database::execute(
            "UPDATE pedidos SET comprobante_tipo=?, cliente_tipo=?, cliente_documento=?, cliente_nombre=?, cliente_razon_social=?, cliente_email=?, turno_id=? WHERE id=?",
            [$compro, $cTipo, ($cDoc ?: null), $cNombre, $cRazon, ($cEmail ?: null), ($tid ?: null), $pid]);
    } catch (\Throwable $e) {
        Database::execute(
            "UPDATE pedidos SET comprobante_tipo=?, cliente_tipo=?, cliente_documento=?, cliente_nombre=?, cliente_razon_social=?, turno_id=? WHERE id=?",
            [$compro, $cTipo, ($cDoc ?: null), $cNombre, $cRazon, ($tid ?: null), $pid]);
    }
    $comp = null;
    if (in_array($compro, ['boleta','factura'], true) && nubefactConfigurado()) {
        $r = nubefactEmitir($pid);
        $comp = [
            'tipo'   => $compro,
            'estado' => $r['estado'] ?? ($r['ok'] ? 'emitido' : 'error'),
            'serie'  => $r['serie'] ?? '',
            'numero' => $r['numero'] ?? 0,
            'error'  => $r['error'] ?? '',
        ];
    }
    pout(['ok'=>true,'comprobante'=>$comp]);

case 'clientes_buscar':
    $q = trim((string)($_GET['q'] ?? ''));
    $where  = "origen='pos' AND cliente_documento IS NOT NULL AND cliente_documento <> '' AND estado <> 'cancelado'";
    $params = [];
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where .= " AND (cliente_documento LIKE ? OR cliente_nombre LIKE ? OR cliente_razon_social LIKE ?)";
        $params = [$like, $like, $like];
    }
    $base = "SELECT cliente_documento AS doc,
                    COALESCE(MAX(NULLIF(cliente_razon_social,'')), MAX(NULLIF(cliente_nombre,''))) AS nombre,
                    MAX(cliente_tipo) AS tipo, %EMAIL%
                    COUNT(*) AS n, COALESCE(SUM(total),0) AS total
             FROM pedidos WHERE $where
             GROUP BY cliente_documento
             ORDER BY MAX(created_at) DESC LIMIT 50";
    try {
        $rows = Database::fetchAll(str_replace('%EMAIL%', "MAX(NULLIF(cliente_email,'')) AS email,", $base), $params);
    } catch (\Throwable $e) {
        // La columna cliente_email aún no existe (migración pendiente): seguir sin email.
        $rows = Database::fetchAll(str_replace('%EMAIL%', "NULL AS email,", $base), $params);
    }
    pout(['ok'=>true,'clientes'=>$rows]);

case 'abrir_turno':
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $monto = cleanFloat($_POST['monto_inicial'] ?? 0);
    if (!$ubi) pout(['ok'=>false,'error'=>'Ubicación']);
    $ya = Database::fetch("SELECT id FROM pos_turnos WHERE usuario_id=? AND ubicacion_id=? AND estado='abierto'", [$uid,$ubi]);
    if ($ya) pout(['ok'=>true,'id'=>(int)$ya['id']]);
    $id = Database::insert("INSERT INTO pos_turnos (usuario_id,ubicacion_id,monto_inicial) VALUES (?,?,?)", [$uid,$ubi,$monto]);
    pout(['ok'=>true,'id'=>$id]);

case 'cerrar_turno':
    $tid      = cleanInt($_POST['turno_id'] ?? 0);
    $cajaReal = cleanFloat($_POST['caja_real'] ?? 0);
    $ingreso  = max(0, cleanFloat($_POST['ingreso_efectivo'] ?? 0));
    $gastosIn = json_decode($_POST['gastos'] ?? '[]', true);
    $gastos = []; $gastosTot = 0;
    if (is_array($gastosIn)) {
        foreach ($gastosIn as $g) {
            if (!is_array($g)) continue;
            $concepto = mb_substr(trim(strip_tags((string)($g['concepto'] ?? ''))), 0, 60);
            $monto    = max(0, (float)($g['monto'] ?? 0));
            if ($monto <= 0 && $concepto === '') continue;
            $gastos[] = ['concepto' => $concepto, 'monto' => $monto];
            $gastosTot += $monto;
            if (count($gastos) >= 20) break;
        }
    }
    $t = Database::fetch("SELECT * FROM pos_turnos WHERE id=? AND usuario_id=? AND estado='abierto'", [$tid,$uid]);
    if (!$t) pout(['ok'=>false,'error'=>'Turno no encontrado']);
    $ag = Database::fetch(
        "SELECT COUNT(*) n, COALESCE(SUM(total),0) tot,
                COALESCE(SUM(CASE WHEN m.tipo='efectivo' THEN p.total ELSE 0 END),0) ef,
                COALESCE(SUM(CASE WHEN m.tipo='tarjeta'  THEN p.total ELSE 0 END),0) ta,
                COALESCE(SUM(CASE WHEN m.tipo='qr'       THEN p.total ELSE 0 END),0) qr,
                COALESCE(SUM(CASE WHEN m.tipo NOT IN ('efectivo','tarjeta','qr') OR m.tipo IS NULL THEN p.total ELSE 0 END),0) ot
         FROM pedidos p LEFT JOIN pos_metodos_pago m ON m.nombre = p.metodo_pago
         WHERE p.turno_id = ? AND p.estado <> 'cancelado'", [$tid]);
    $cajaEsperada = (float)$t['monto_inicial'] + $ingreso + (float)$ag['ef'] - $gastosTot;
    $diferencia   = $cajaEsperada - $cajaReal;
    Database::execute(
        "UPDATE pos_turnos SET estado='cerrado', cerrado_en=NOW(),
            ingreso_efectivo=?, gastos_total=?, gastos_json=?,
            monto_final=?, caja_esperada=?, caja_real=?, diferencia=?,
            total_pedidos=?, total_ventas=?, total_efectivo=?, total_tarjeta=?, total_qr=?, total_otros=? WHERE id=?",
        [$ingreso, $gastosTot, ($gastos ? json_encode($gastos, JSON_UNESCAPED_UNICODE) : null),
         $cajaReal, $cajaEsperada, $cajaReal, $diferencia,
         (int)$ag['n'], $ag['tot'], $ag['ef'], $ag['ta'], $ag['qr'], $ag['ot'], $tid]);
    pout(['ok'=>true]);

case 'registrar_venta':
    $ubi   = cleanInt($_POST['ubicacion_id'] ?? 0);
    $tid   = cleanInt($_POST['turno_id'] ?? 0);
    $metodo= clean($_POST['metodo_pago'] ?? 'Efectivo');
    $total = cleanFloat($_POST['total'] ?? 0);
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!$ubi || !$tid || !is_array($items) || !count($items)) pout(['ok'=>false,'error'=>'Datos incompletos']);
    $t = Database::fetch("SELECT id FROM pos_turnos WHERE id=? AND usuario_id=? AND estado='abierto'", [$tid,$uid]);
    if (!$t) pout(['ok'=>false,'error'=>'Caja cerrada']);
    $clean = [];
    $subtotal = 0.0;
    foreach ($items as $it) {
        $qty   = max(1, (int)($it['qty'] ?? 1));
        $base  = (float)($it['precio'] ?? 0);
        $mods  = [];
        $modsSum = 0.0;
        foreach ((array)($it['modificadores'] ?? []) as $m) {
            $mp = (float)($m['precio'] ?? 0);
            $mods[] = ['nombre' => clean($m['nombre'] ?? ''), 'precio' => $mp];
            $modsSum += $mp;
        }
        $nota = clean($it['nota'] ?? '');
        if ($nota !== '') $mods[] = ['nombre' => 'Nota: ' . $nota, 'precio' => 0];
        $lineUnit = $base + $modsSum;
        $lineTot  = $lineUnit * $qty;
        $dt = in_array($it['desc_tipo'] ?? '', ['porcentaje','monto'], true) ? $it['desc_tipo'] : null;
        $dv = (float)($it['desc_valor'] ?? 0);
        if ($dt === 'porcentaje') $lineTot -= $lineTot * min(100, max(0, $dv)) / 100;
        elseif ($dt === 'monto')  $lineTot -= min($lineTot, max(0, $dv));
        $subtotal += $lineTot;
        $clean[] = ['product_id'=>(int)($it['id'] ?? $it['product_id'] ?? 0), 'qty'=>$qty, 'nombre'=>clean($it['nombre'] ?? ''), 'precio'=>$base,
                    'modificadores'=>$mods, 'nota'=>$nota, 'desc_tipo'=>$dt, 'desc_valor'=>$dv];
    }
    $gdt = in_array($_POST['descuento_tipo'] ?? '', ['porcentaje','monto'], true) ? $_POST['descuento_tipo'] : null;
    $gdv = cleanFloat($_POST['descuento_valor'] ?? 0);
    $gMonto = 0.0;
    if ($gdt === 'porcentaje') $gMonto = $subtotal * min(100, max(0, $gdv)) / 100;
    elseif ($gdt === 'monto')  $gMonto = min($subtotal, max(0, $gdv));
    $total = max(0, $subtotal - $gMonto);
    $cTipo = in_array($_POST['cliente_tipo'] ?? '', ['nombre','dni','ruc'], true) ? $_POST['cliente_tipo'] : null;
    $cNom  = clean($_POST['cliente_nombre'] ?? '');
    $cDoc  = preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['cliente_documento'] ?? ''));
    $cRaz  = clean($_POST['cliente_razon_social'] ?? '');
    $cEmail = cleanEmail($_POST['cliente_email'] ?? '');
    $compro = clean($_POST['comprobante_tipo'] ?? 'ticket');
    if (!in_array($compro, ['ticket','boleta','factura'], true)) $compro = 'ticket';
    $notas = clean($_POST['notas_pos'] ?? '');
    // Nombre que sale en el KDS: nombre del pedido (manda) → nombre/razón del documento → vacío (el KDS muestra el número).
    $nombrePedido = clean($_POST['nombre_pedido'] ?? '');
    $nombre = $nombrePedido ?: ($cNom ?: '');
    $tipoRow = Database::fetch("SELECT tipo FROM pos_metodos_pago WHERE nombre = ? LIMIT 1", [$metodo]);
    $tipo    = $tipoRow['tipo'] ?? 'otros';
    $bucket  = ['efectivo'=>'total_efectivo','tarjeta'=>'total_tarjeta','qr'=>'total_qr'][$tipo] ?? 'total_otros';
    $pid = Database::insert(
        "INSERT INTO pedidos (ubicacion_id, nombre, tipo_entrega, items_json, total, estado, metodo_pago, origen, turno_id,
            comprobante_tipo, descuento_tipo, descuento_valor, descuento_monto,
            cliente_tipo, cliente_nombre, cliente_documento, cliente_razon_social, cliente_email, notas_pos, aceptado_at, horario)
         VALUES (?,?, 'recojo', ?, ?, 'en_preparacion', ?, 'pos', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'En salón')",
        [$ubi, $nombre, json_encode($clean, JSON_UNESCAPED_UNICODE), $total, $metodo, $tid,
         $compro, $gdt, $gdv, $gMonto, $cTipo, ($cNom ?: null), ($cDoc ?: null), ($cRaz ?: null), ($cEmail ?: null), ($notas ?: null)]);
    Database::execute("UPDATE pos_turnos SET total_ventas=total_ventas+?, total_pedidos=total_pedidos+1, $bucket=$bucket+? WHERE id=?", [$total, $total, $tid]);

    // Auto-emisión del comprobante electrónico (boleta/factura) si NubeFact está configurado.
    $comp = null;
    if (in_array($compro, ['boleta','factura'], true) && nubefactConfigurado()) {
        $r = nubefactEmitir($pid);
        $comp = [
            'tipo'   => $compro,
            'estado' => $r['estado'] ?? ($r['ok'] ? 'emitido' : 'error'),
            'serie'  => $r['serie'] ?? '',
            'numero' => $r['numero'] ?? 0,
            'error'  => $r['error'] ?? '',
        ];
    }
    pout(['ok'=>true,'id'=>$pid,'total'=>$total,'comprobante'=>$comp]);

case 'emitir':
    $pid = cleanInt($_POST['pedido_id'] ?? 0);
    if (!$pid) pout(['ok'=>false,'error'=>'Pedido inválido']);
    $own = Database::fetch("SELECT id FROM pedidos WHERE id=?", [$pid]);
    if (!$own) pout(['ok'=>false,'error'=>'Pedido no encontrado']);
    if (!nubefactConfigurado()) pout(['ok'=>false,'error'=>'NubeFact no está configurado']);
    $r = nubefactEmitir($pid);
    pout([
        'ok'     => (bool)($r['ok'] ?? false),
        'estado' => $r['estado'] ?? '',
        'serie'  => $r['serie'] ?? '',
        'numero' => $r['numero'] ?? 0,
        'error'  => $r['error'] ?? '',
    ]);

case 'producto_mods':
    $pid = cleanInt($_GET['producto_id'] ?? 0);
    $grupos = [];
    try {
        $grupos = Database::fetchAll(
            "SELECT g.id, g.nombre, g.tipo, g.max_opciones, g.requerido
             FROM grupos_modificadores g
             JOIN product_modifier_groups pmg ON pmg.grupo_id = g.id
             WHERE pmg.product_id = ? AND g.activo = 1
             ORDER BY pmg.orden, g.orden, g.id", [$pid]);
        foreach ($grupos as &$g) {
            $g['modificadores'] = Database::fetchAll(
                "SELECT id, nombre, precio_adicional FROM modificadores WHERE grupo_id = ? AND activo = 1 ORDER BY orden, id", [(int)$g['id']]);
        }
        unset($g);
    } catch (Exception $e) { $grupos = []; }
    pout(['ok'=>true,'grupos'=>$grupos]);

case 'favoritos':
    $ubi = cleanInt($_GET['ubicacion_id'] ?? 0);
    pout(['ok'=>true,'data'=>Database::fetchAll(
        "SELECT f.id, f.producto_id, f.posicion, p.name AS nombre, p.image AS foto
         FROM pos_favoritos f JOIN products p ON p.id = f.producto_id AND p.active = 1
         WHERE f.ubicacion_id = ? ORDER BY f.posicion, f.id", [$ubi])]);

case 'fav_set':
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $prod = cleanInt($_POST['producto_id'] ?? 0);
    $pos = cleanInt($_POST['posicion'] ?? 0);
    if (!$ubi || !$prod) pout(['ok'=>false,'error'=>'Datos']);
    Database::execute("DELETE FROM pos_favoritos WHERE ubicacion_id=? AND posicion=?", [$ubi,$pos]);
    $id = Database::insert("INSERT INTO pos_favoritos (ubicacion_id,producto_id,posicion) VALUES (?,?,?)", [$ubi,$prod,$pos]);
    pout(['ok'=>true,'id'=>$id]);

case 'fav_clear':
    $ubi = cleanInt($_POST['ubicacion_id'] ?? 0);
    $pos = cleanInt($_POST['posicion'] ?? 0);
    Database::execute("DELETE FROM pos_favoritos WHERE ubicacion_id=? AND posicion=?", [$ubi,$pos]);
    pout(['ok'=>true]);

case 'enviar_recibo':
    $pid   = cleanInt($_POST['pedido_id'] ?? 0);
    $email = cleanEmail($_POST['email'] ?? '');
    if (!$pid || !$email) pout(['ok'=>false,'error'=>'Correo inválido']);
    $p = Database::fetch("SELECT * FROM pedidos WHERE id=?", [$pid]);
    if (!$p) pout(['ok'=>false,'error'=>'Pedido no encontrado']);
    $items = json_decode($p['items_json'] ?? '[]', true) ?: [];
    $emp = getSetting('company_name', 'Mi Restaurante');
    $rows = '';
    foreach ($items as $it) {
        $qty = (int)($it['qty'] ?? 1);
        $base = (float)($it['precio'] ?? 0);
        $modsSum = 0; $modsHtml = '';
        foreach ((array)($it['modificadores'] ?? []) as $m) {
            $mp = (float)($m['precio'] ?? 0); $modsSum += $mp;
            $modsHtml .= '<div style="font-size:12px;color:#888">' . clean($m['nombre'] ?? '') . '</div>';
        }
        $unit = $base + $modsSum; $lineTot = $unit * $qty;
        $dt = $it['desc_tipo'] ?? null; $dv = (float)($it['desc_valor'] ?? 0);
        if ($dt === 'porcentaje') $lineTot -= $lineTot * min(100,max(0,$dv))/100;
        elseif ($dt === 'monto')  $lineTot -= min($lineTot,max(0,$dv));
        $rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #eee"><strong>' . $qty . 'x ' . clean($it['nombre'] ?? '') . '</strong>' . $modsHtml . '</td><td style="padding:6px 0;border-bottom:1px solid #eee;text-align:right;white-space:nowrap">' . formatMoney($lineTot) . '</td></tr>';
    }
    $num = str_pad((string)$p['id'], 4, '0', STR_PAD_LEFT);
    // Si el comprobante electrónico ya está emitido, usamos su título y serie oficiales.
    $compEmitido = in_array($p['comprobante_tipo'], ['boleta','factura'], true) && ($p['comprobante_estado'] ?? '') === 'emitido';
    if ($compEmitido) {
        $serieNum  = ($p['comprobante_serie'] ?? '') . '-' . str_pad((string)($p['comprobante_numero'] ?? ''), 6, '0', STR_PAD_LEFT);
        $compLabel = ($p['comprobante_tipo'] === 'factura' ? 'Factura Electrónica' : 'Boleta de Venta Electrónica') . ' ' . $serieNum;
        $subject   = $compLabel . ' — ' . $emp;
    } else {
        $compLabel = ['ticket'=>'Ticket','boleta'=>'Boleta','factura'=>'Factura'][$p['comprobante_tipo']] ?? 'Ticket';
        $subject   = 'Tu comprobante — ' . $emp . ' · Pedido #' . $num;
    }
    $brandHex = brandPrimaryHex('#FFDF00');
    // Botón al PDF oficial de SUNAT (solo si está emitido y hay enlace).
    $pdfBtn = '';
    if ($compEmitido && !empty($p['comprobante_pdf'])) {
        $pdfBtn = '<div style="margin:22px 0 0;text-align:center">'
            . '<a href="' . htmlspecialchars($p['comprobante_pdf'], ENT_QUOTES) . '" '
            . 'style="display:inline-block;background:' . $brandHex . ';color:#1E1E1E;font-weight:800;font-size:14px;'
            . 'text-decoration:none;padding:13px 26px;border-radius:10px">Descargar comprobante (PDF SUNAT)</a></div>';
    }
    $descHtml = ((float)($p['descuento_monto'] ?? 0) > 0)
        ? '<tr><td style="padding:4px 0;color:#666">Descuento</td><td style="padding:4px 0;text-align:right;color:#666">- ' . formatMoney((float)$p['descuento_monto']) . '</td></tr>' : '';
    $bodyHtml = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f0f0f0;font-family:-apple-system,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f0;padding:20px 0"><tr><td align="center">
<table width="100%" style="max-width:520px;background:#fff;border-radius:12px;overflow:hidden">
  <tr><td style="background:#1A1A1A;padding:22px 26px">
    <p style="margin:0;font-size:20px;font-weight:800;color:' . $brandHex . '">' . clean($emp) . '</p>
    <p style="margin:4px 0 0;font-size:12px;color:rgba(255,255,255,.7)">' . $compLabel . ' · Pedido #' . $num . '</p>
  </td></tr>
  <tr><td style="padding:26px">
    <p style="margin:0 0 16px;font-size:15px;color:#1a1a1a;line-height:1.6">¡Gracias por tu compra en ' . clean($emp) . '!<br>Aquí está el detalle de tu pedido.</p>
    <table width="100%" style="font-size:14px;color:#1a1a1a;margin-bottom:8px">' . $rows . '</table>
    <table width="100%" style="font-size:14px;margin-top:8px">' . $descHtml . '
      <tr><td style="padding:8px 0;font-size:18px;font-weight:800">TOTAL</td><td style="padding:8px 0;text-align:right;font-size:18px;font-weight:800">' . formatMoney((float)$p['total']) . '</td></tr>
      <tr><td style="padding:2px 0;color:#666">Pago</td><td style="padding:2px 0;text-align:right;color:#666">' . clean($p['metodo_pago']) . '</td></tr>
    </table>' . $pdfBtn . '
    <p style="margin:22px 0 0;font-size:12px;color:#999;line-height:1.5">Este es un correo automático, por favor no respondas a esta dirección.<br>' . clean($emp) . ' · Lima, Perú</p>
  </td></tr>
</table></td></tr></table></body></html>';
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode($emp) . "?= <" . mailFrom('comprobantes') . ">\r\n";
    $headers .= "X-Mailer: POS/1.0\r\n";
    $ok = @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $bodyHtml, $headers);
    pout(['ok' => (bool)$ok, 'error' => $ok ? '' : 'No se pudo enviar']);

case 'monitor':
    if (!isAdmin()) pout(['ok'=>false,'error'=>'Sin permisos']);
    $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha'] ?? '')) ? $_GET['fecha'] : date('Y-m-d');
    $ubi   = cleanInt($_GET['ubicacion_id'] ?? 0);
    $w = "p.origen='pos' AND p.estado <> 'cancelado' AND DATE(p.created_at) = ?";
    $params = [$fecha];
    if ($ubi) { $w .= " AND p.ubicacion_id = ?"; $params[] = $ubi; }
    $tot = Database::fetch("SELECT COUNT(*) n, COALESCE(SUM(p.total),0) t FROM pedidos p WHERE $w", $params);
    $metodos = Database::fetchAll("SELECT p.metodo_pago nombre, COUNT(*) n, COALESCE(SUM(p.total),0) t FROM pedidos p WHERE $w GROUP BY p.metodo_pago ORDER BY t DESC", $params);
    $ubis = Database::fetchAll("SELECT COALESCE(u.nombre,'—') nombre, COUNT(*) n, COALESCE(SUM(p.total),0) t FROM pedidos p LEFT JOIN ubicaciones u ON u.id = p.ubicacion_id WHERE $w GROUP BY p.ubicacion_id ORDER BY t DESC", $params);
    $recientes = Database::fetchAll("SELECT p.id, p.total, p.metodo_pago, p.created_at, COALESCE(u.nombre,'—') ubi FROM pedidos p LEFT JOIN ubicaciones u ON u.id = p.ubicacion_id WHERE $w ORDER BY p.id DESC LIMIT 40", $params);
    $porHora = Database::fetchAll("SELECT HOUR(p.created_at) h, COUNT(*) n, COALESCE(SUM(p.total),0) t FROM pedidos p WHERE $w GROUP BY HOUR(p.created_at) ORDER BY h", $params);
    $compFecha = date('Y-m-d', strtotime($fecha . ' -7 days'));
    $wc = "p.origen='pos' AND p.estado <> 'cancelado' AND DATE(p.created_at) = ?";
    $pc = [$compFecha];
    if ($ubi) { $wc .= " AND p.ubicacion_id = ?"; $pc[] = $ubi; }
    $compTot  = Database::fetch("SELECT COUNT(*) n, COALESCE(SUM(p.total),0) t FROM pedidos p WHERE $wc", $pc);
    $compHora = Database::fetchAll("SELECT HOUR(p.created_at) h, COUNT(*) n, COALESCE(SUM(p.total),0) t FROM pedidos p WHERE $wc GROUP BY HOUR(p.created_at) ORDER BY h", $pc);
    pout(['ok'=>true, 'fecha'=>$fecha, 'total'=>$tot, 'metodos'=>$metodos, 'ubicaciones'=>$ubis, 'recientes'=>$recientes,
          'por_hora'=>$porHora, 'comp'=>['fecha'=>$compFecha, 'total'=>$compTot, 'por_hora'=>$compHora]]);

default:
    pout(['ok'=>false,'error'=>'Acción no válida']);
}
