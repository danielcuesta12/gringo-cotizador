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

// ── Unknown action ────────────────────────────────────────────────────────────
rout(['ok' => false, 'error' => 'Acción desconocida']);
