<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
if (!isAdmin()) { echo json_encode(['ok' => false, 'error' => 'Sin permiso']); exit; }

$action = clean($_GET['action'] ?? '');
$desde  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : '';
$hasta  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : '';
$ubi    = cleanInt($_GET['ubicacion_id'] ?? 0);
$origen = in_array($_GET['origen'] ?? '', ['carta', 'pos'], true) ? $_GET['origen'] : '';
$turno  = cleanInt($_GET['turno_id'] ?? 0);

function rout(array $d): never { echo json_encode($d); exit; }

// ── action=pedidos ────────────────────────────────────────────────────────────
if ($action === 'pedidos') {
    try {
        if ($turno > 0) {
            $where  = "p.turno_id = ? AND p.estado <> 'cancelado'";
            $params = [$turno];
        } else {
            $where  = "p.estado <> 'cancelado'";
            $params = [];
            if ($desde !== '') { $where .= " AND DATE(p.created_at) >= ?"; $params[] = $desde; }
            if ($hasta !== '')  { $where .= " AND DATE(p.created_at) <= ?"; $params[] = $hasta; }
            if ($ubi > 0)       { $where .= " AND p.ubicacion_id = ?";      $params[] = $ubi; }
            if ($origen !== '')  { $where .= " AND p.origen = ?";            $params[] = $origen; }
        }

        $pedidos = Database::fetchAll(
            "SELECT p.id, p.created_at, p.origen,
                    COALESCE(u.nombre, '') AS ubicacion,
                    p.metodo_pago, p.estado, p.total, p.items_json,
                    COALESCE(NULLIF(p.cliente_nombre,''), NULLIF(p.nombre,''), p.notas_pos, '') AS cliente
             FROM pedidos p
             LEFT JOIN ubicaciones u ON u.id = p.ubicacion_id
             WHERE {$where}
             ORDER BY p.id DESC",
            $params
        );
        rout(['ok' => true, 'pedidos' => $pedidos]);
    } catch (Exception $e) {
        rout(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── action=categorias ─────────────────────────────────────────────────────────
if ($action === 'categorias') {
    try {
        // Build the same pedidos WHERE (items only)
        if ($turno > 0) {
            $where  = "turno_id = ? AND estado <> 'cancelado'";
            $params = [$turno];
        } else {
            $where  = "estado <> 'cancelado'";
            $params = [];
            if ($desde !== '') { $where .= " AND DATE(created_at) >= ?"; $params[] = $desde; }
            if ($hasta !== '')  { $where .= " AND DATE(created_at) <= ?"; $params[] = $hasta; }
            if ($ubi > 0)       { $where .= " AND ubicacion_id = ?";      $params[] = $ubi; }
            if ($origen !== '')  { $where .= " AND origen = ?";            $params[] = $origen; }
        }

        $rows = Database::fetchAll(
            "SELECT items_json FROM pedidos WHERE {$where}",
            $params
        );

        // Build product→category maps
        $prods     = Database::fetchAll(
            "SELECT p.id, p.name AS pnombre,
                    COALESCE(c.name, 'Sin categoría') AS cat
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id"
        );
        $catById   = [];  // int id  → string cat
        $catByName = [];  // lowercase name → string cat
        foreach ($prods as $pr) {
            $catById[(int)$pr['id']] = $pr['cat'];
            $catByName[mb_strtolower(trim($pr['pnombre']))] = $pr['cat'];
        }

        // Aggregation structures
        $cats       = [];  // [cat] => ['qty'=>int, 'monto'=>float, 'items'=>[nombre=>['qty','total']]]
        $totalQty   = 0;
        $totalMonto = 0.0;
        $mods            = [];  // [nombre] => ['qty'=>int, 'ingreso'=>float]
        $modTotalQty     = 0;
        $modTotalIngreso = 0.0;

        foreach ($rows as $row) {
            $items = json_decode($row['items_json'] ?? '[]', true);
            if (!is_array($items)) continue;

            foreach ($items as $it) {
                $pid    = (int)($it['id'] ?? $it['producto_id'] ?? 0);
                $nombre = trim($it['nombre'] ?? '?');
                $qty    = (int)($it['qty'] ?? 1);
                $total  = (float)($it['precio_total'] ?? $it['subtotal']
                          ?? (($it['precio'] ?? 0) * $qty));
                $cat    = $catById[$pid]
                       ?? ($catByName[mb_strtolower($nombre)] ?? 'Sin categoría');

                // Category aggregate
                if (!isset($cats[$cat])) {
                    $cats[$cat] = ['qty' => 0, 'monto' => 0.0, 'items' => []];
                }
                $cats[$cat]['qty']   += $qty;
                $cats[$cat]['monto'] += $total;
                $totalQty            += $qty;
                $totalMonto          += $total;

                // Item within category
                if (!isset($cats[$cat]['items'][$nombre])) {
                    $cats[$cat]['items'][$nombre] = ['qty' => 0, 'total' => 0.0];
                }
                $cats[$cat]['items'][$nombre]['qty']   += $qty;
                $cats[$cat]['items'][$nombre]['total'] += $total;

                // Modifiers
                foreach (($it['modificadores'] ?? []) as $mod) {
                    $mn = trim($mod['nombre'] ?? '');
                    if ($mn === '') continue;
                    $mp = (float)($mod['precio'] ?? $mod['precio_adicional'] ?? 0);
                    if (!isset($mods[$mn])) {
                        $mods[$mn] = ['qty' => 0, 'ingreso' => 0.0];
                    }
                    $mods[$mn]['qty']     += $qty;
                    $mods[$mn]['ingreso'] += $mp * $qty;
                    $modTotalQty          += $qty;
                    $modTotalIngreso      += $mp * $qty;
                }
            }
        }

        // Sort: categories by monto desc
        uasort($cats, fn($a, $b) => $b['monto'] <=> $a['monto']);

        // Build output array + sort items within each cat by qty desc
        $categoriasOut = [];
        foreach ($cats as $catNombre => $cData) {
            uasort($cData['items'], fn($a, $b) => $b['qty'] <=> $a['qty']);
            $itemsArr = [];
            foreach ($cData['items'] as $iNombre => $iData) {
                $itemsArr[] = ['nombre' => $iNombre, 'qty' => $iData['qty'], 'total' => $iData['total']];
            }
            $categoriasOut[] = [
                'categoria' => $catNombre,
                'qty'       => $cData['qty'],
                'monto'     => $cData['monto'],
                'items'     => $itemsArr,
            ];
        }

        // Sort mods by qty desc
        uasort($mods, fn($a, $b) => $b['qty'] <=> $a['qty']);
        $modsOut = [];
        foreach ($mods as $mn => $md) {
            $modsOut[] = ['nombre' => $mn, 'qty' => $md['qty'], 'ingreso' => $md['ingreso']];
        }

        rout([
            'ok'                => true,
            'categorias'        => $categoriasOut,
            'total_qty'         => $totalQty,
            'total_monto'       => $totalMonto,
            'modificadores'     => $modsOut,
            'mod_total_qty'     => $modTotalQty,
            'mod_total_ingreso' => $modTotalIngreso,
        ]);
    } catch (Exception $e) {
        rout(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── action=caja ───────────────────────────────────────────────────────────────
if ($action === 'caja') {
    try {
        $where  = '1=1';
        $params = [];
        if ($desde !== '') { $where .= " AND DATE(t.abierto_en) >= ?"; $params[] = $desde; }
        if ($hasta !== '')  { $where .= " AND DATE(t.abierto_en) <= ?"; $params[] = $hasta; }
        if ($ubi > 0)       { $where .= " AND t.ubicacion_id = ?";      $params[] = $ubi; }

        $turnos = Database::fetchAll(
            "SELECT t.*,
                    COALESCE(u.name,    '') AS cajero,
                    COALESCE(ub.nombre, '') AS ubicacion
             FROM pos_turnos t
             LEFT JOIN users       u  ON u.id  = t.usuario_id
             LEFT JOIN ubicaciones ub ON ub.id = t.ubicacion_id
             WHERE {$where}
             ORDER BY t.id DESC",
            $params
        );
        rout(['ok' => true, 'turnos' => $turnos]);
    } catch (Exception $e) {
        rout(['ok' => false, 'error' => $e->getMessage()]);
    }
}

// ── action=cotizaciones ───────────────────────────────────────────────────────
if ($action === 'cotizaciones') {
    try {
        $where = "1=1"; $params = [];
        if ($desde !== '') { $where .= " AND DATE(q.created_at) >= ?"; $params[] = $desde; }
        if ($hasta !== '')  { $where .= " AND DATE(q.created_at) <= ?"; $params[] = $hasta; }
        $rows = Database::fetchAll(
            "SELECT q.quote_number, COALESCE(c.name,'') cliente, q.status, q.event_type, q.event_date, q.num_people, q.total, q.created_at, q.origin
             FROM quotes q LEFT JOIN clients c ON c.id = q.client_id
             WHERE {$where} ORDER BY q.created_at DESC", $params);
        rout(['ok'=>true, 'cotizaciones'=>$rows]);
    } catch (Exception $e) { rout(['ok'=>false,'error'=>$e->getMessage()]); }
}

// ── action=consolidado ────────────────────────────────────────────────────────
if ($action === 'consolidado') {
    try {
        // Eventos = cotizaciones aceptadas cuya event_date cae en el rango
        $wE = "status='aceptada' AND event_date IS NOT NULL AND event_date<>''"; $pE = [];
        if ($desde !== '') { $wE .= " AND DATE(event_date) >= ?"; $pE[] = $desde; }
        if ($hasta !== '')  { $wE .= " AND DATE(event_date) <= ?"; $pE[] = $hasta; }
        $eventos = (float)(Database::fetch("SELECT COALESCE(SUM(total),0) s FROM quotes WHERE {$wE}", $pE)['s'] ?? 0);
        // Operación = pedidos no cancelados por created_at en el rango
        $op = 0.0;
        try {
            $wO = "estado<>'cancelado'"; $pO = [];
            if ($desde !== '') { $wO .= " AND DATE(created_at) >= ?"; $pO[] = $desde; }
            if ($hasta !== '')  { $wO .= " AND DATE(created_at) <= ?"; $pO[] = $hasta; }
            $op = (float)(Database::fetch("SELECT COALESCE(SUM(total),0) s FROM pedidos WHERE {$wO}", $pO)['s'] ?? 0);
        } catch (Exception $e2) { $op = 0.0; }

        // ── Detalle de eventos: cotizaciones aceptadas + eventos libres (con su nombre) ──
        $detalle = []; $libres = 0.0;
        $hasEvNombre = (bool) Database::fetch("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quotes' AND column_name='evento_nombre'");
        $selN = $hasEvNombre ? "q.evento_nombre" : "NULL";
        foreach (Database::fetchAll("SELECT q.quote_number, {$selN} AS evnombre, COALESCE(c.name,'') cliente, q.event_date fecha, q.total monto FROM quotes q LEFT JOIN clients c ON c.id=q.client_id WHERE q.{$wE} ORDER BY q.event_date", $pE) as $r) {
            $detalle[] = ['nombre'=>($r['evnombre'] ?: $r['quote_number']), 'tipo'=>'Cotización', 'fecha'=>$r['fecha'], 'cliente'=>$r['cliente'], 'monto'=>(float)$r['monto']];
        }
        try {
            $wA = "venta_real IS NOT NULL"; $pA = [];
            if ($desde !== '') { $wA .= " AND DATE(fecha) >= ?"; $pA[] = $desde; }
            if ($hasta !== '')  { $wA .= " AND DATE(fecha) <= ?"; $pA[] = $hasta; }
            foreach (Database::fetchAll("SELECT titulo, fecha, venta_real monto FROM agenda WHERE {$wA} ORDER BY fecha", $pA) as $r) {
                $libres += (float)$r['monto'];
                $detalle[] = ['nombre'=>$r['titulo'], 'tipo'=>'Evento libre', 'fecha'=>$r['fecha'], 'cliente'=>'', 'monto'=>(float)$r['monto']];
            }
        } catch (\Throwable $e3) {}
        try {
            $wL = "COALESCE(usa_pos,1)=0 AND venta_manual IS NOT NULL AND (quote_id IS NULL OR quote_id NOT IN (SELECT id FROM quotes WHERE status='aceptada'))"; $pL = [];
            if ($desde !== '') { $wL .= " AND DATE(fecha_inicio) >= ?"; $pL[] = $desde; }
            if ($hasta !== '')  { $wL .= " AND DATE(fecha_inicio) <= ?"; $pL[] = $hasta; }
            foreach (Database::fetchAll("SELECT nombre, fecha_inicio fecha, venta_manual monto FROM eventos WHERE {$wL} ORDER BY fecha_inicio", $pL) as $r) {
                $libres += (float)$r['monto'];
                $detalle[] = ['nombre'=>$r['nombre'], 'tipo'=>'Evento libre', 'fecha'=>$r['fecha'], 'cliente'=>'', 'monto'=>(float)$r['monto']];
            }
        } catch (\Throwable $e4) {}

        rout(['ok'=>true, 'eventos'=>$eventos, 'libres'=>$libres, 'operacion'=>$op, 'total'=>$eventos+$libres+$op, 'eventos_detalle'=>$detalle]);
    } catch (Exception $e) { rout(['ok'=>false,'error'=>$e->getMessage()]); }
}

// ── Unknown action ────────────────────────────────────────────────────────────
rout(['ok' => false, 'error' => 'Acción desconocida']);
