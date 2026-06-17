<?php
// KDS — historial de pedidos de hoy de una ubicación + stats.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }

$ubi  = cleanInt($_GET['ubicacion_id'] ?? 0);
$cols = "id, estado, nombre, tipo_entrega, items_json, created_at, total, origen, metodo_pago";
try {
    if ($ubi > 0) {
        $rows = Database::fetchAll(
            "SELECT $cols FROM pedidos WHERE DATE(created_at)=CURDATE() AND ubicacion_id=? ORDER BY created_at DESC",
            [$ubi]
        );
    } else {
        $rows = Database::fetchAll("SELECT $cols FROM pedidos WHERE DATE(created_at)=CURDATE() ORDER BY created_at DESC");
    }

    $pedidos = []; $tot = 0; $comp = 0; $canc = 0; $monto = 0.0;
    foreach ($rows as $r) {
        $tot++;
        if ($r['estado'] === 'listo' || $r['estado'] === 'entregado') { $comp++; $monto += (float)$r['total']; }
        elseif ($r['estado'] === 'cancelado') { $canc++; }

        $items = json_decode($r['items_json'] ?? '[]', true);
        if (!is_array($items)) $items = [];
        $resumen = implode(', ', array_map(fn($i) => (($i['qty'] ?? 1) . 'x ' . ($i['nombre'] ?? '')), $items));

        $pedidos[] = [
            'id'           => (int)$r['id'],
            'estado'       => $r['estado'],
            'cliente'      => $r['nombre'] ?: ('#' . str_pad((string)$r['id'], 3, '0', STR_PAD_LEFT)),
            'tipo_entrega' => $r['tipo_entrega'],
            'origen'       => $r['origen'] ?? 'carta',
            'resumen'      => $resumen,
            'created_at'   => $r['created_at'],
            'total'        => (float)$r['total'],
            'metodo_pago'  => $r['metodo_pago'] ?? null,
            'items'        => $items,
        ];
    }

    echo json_encode([
        'ok'      => true,
        'pedidos' => $pedidos,
        'stats'   => ['total_pedidos' => $tot, 'completados' => $comp, 'cancelados' => $canc, 'total_monto' => $monto],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => DEBUG_MODE ? $e->getMessage() : 'error']);
}
