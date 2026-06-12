<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();

$type = clean($_GET['type'] ?? '');
$mes  = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');

// ──────────────────────────────────────────────
// Helper: stream CSV to browser and exit
// ──────────────────────────────────────────────
function csvOut(string $filename, array $headerRow, array $dataRows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // UTF-8 BOM so Excel (es-PE / Latam) renders tildes and ñ correctly
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, $headerRow, ';');
    foreach ($dataRows as $row) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}

// ──────────────────────────────────────────────
// type = cotizaciones
// ──────────────────────────────────────────────
if ($type === 'cotizaciones') {
    if (!can('quotes')) {
        flashMessage('error', 'Sin acceso');
        redirect('/admin/dashboard');
    }

    $rows = Database::fetchAll(
        "SELECT q.quote_number,
                COALESCE(c.name, '') AS cliente,
                q.status,
                q.event_type,
                q.event_date,
                q.num_people,
                q.total,
                q.created_at
         FROM   quotes q
         LEFT   JOIN clients c ON c.id = q.client_id
         WHERE  DATE_FORMAT(q.created_at, '%Y-%m') = ?
         ORDER  BY q.created_at DESC",
        [$mes]
    );

    $header = ['N° Cotización', 'Cliente', 'Estado', 'Tipo de evento', 'Fecha del evento', 'Personas', 'Total', 'Creada'];

    $data = array_map(function (array $r): array {
        return [
            $r['quote_number'],
            $r['cliente'],
            quoteStatusLabel($r['status']),
            $r['event_type'] ?? '',
            $r['event_date'] ? formatDate($r['event_date']) : '',
            $r['num_people'] !== null ? (int) $r['num_people'] : '',
            number_format((float) $r['total'], 2, '.', ''),
            formatDatetime($r['created_at']),
        ];
    }, $rows);

    csvOut('cotizaciones-' . $mes . '.csv', $header, $data);
}

// ──────────────────────────────────────────────
// type = operacion
// ──────────────────────────────────────────────
if ($type === 'operacion') {
    if (!can('pedidos') && !can('pos_terminal')) {
        flashMessage('error', 'Sin acceso');
        redirect('/admin/dashboard');
    }

    $header = ['# Pedido', 'Fecha', 'Origen', 'Ubicación', 'Método de pago', 'Comprobante', 'Cliente', 'Documento', 'Total'];

    try {
        $rows = Database::fetchAll(
            "SELECT p.id,
                    p.created_at,
                    p.origen,
                    COALESCE(u.nombre, '') AS ubi,
                    p.metodo_pago,
                    p.comprobante_tipo,
                    p.cliente_nombre,
                    p.cliente_documento,
                    p.total
             FROM   pedidos p
             LEFT   JOIN ubicaciones u ON u.id = p.ubicacion_id
             WHERE  p.estado <> 'cancelado'
               AND  DATE_FORMAT(p.created_at, '%Y-%m') = ?
             ORDER  BY p.id DESC",
            [$mes]
        );

        $data = array_map(function (array $r): array {
            $origenLabel = match ($r['origen'] ?? '') {
                'pos'   => 'POS',
                'carta' => 'Carta',
                default => $r['origen'] ?? '',
            };
            return [
                $r['id'],
                formatDatetime($r['created_at']),
                $origenLabel,
                $r['ubi'],
                $r['metodo_pago'] ?? '',
                $r['comprobante_tipo'] ?? '',
                $r['cliente_nombre'] ?? '',
                $r['cliente_documento'] ?? '',
                number_format((float) $r['total'], 2, '.', ''),
            ];
        }, $rows);
    } catch (\Throwable $e) {
        // Table may not exist on this instance — export empty file
        $data = [];
    }

    csvOut('operacion-' . $mes . '.csv', $header, $data);
}

// ──────────────────────────────────────────────
// type = consolidado
// ──────────────────────────────────────────────
if ($type === 'consolidado') {
    if (!isAdmin()) {
        flashMessage('error', 'Sin acceso');
        redirect('/admin/dashboard');
    }

    // Facturado eventos (cotizaciones aceptadas cuya fecha de evento cae en el mes)
    $rowEv = Database::fetch(
        "SELECT COALESCE(SUM(total), 0) AS total
         FROM   quotes
         WHERE  status = 'aceptada'
           AND  DATE_FORMAT(event_date, '%Y-%m') = ?",
        [$mes]
    );
    $facturadoEventos = (float) ($rowEv['total'] ?? 0);

    // Ventas operación (pedidos no cancelados creados en el mes)
    $ventasOp = 0.0;
    try {
        $rowOp = Database::fetch(
            "SELECT COALESCE(SUM(total), 0) AS total
             FROM   pedidos
             WHERE  estado <> 'cancelado'
               AND  DATE_FORMAT(created_at, '%Y-%m') = ?",
            [$mes]
        );
        $ventasOp = (float) ($rowOp['total'] ?? 0);
    } catch (\Throwable $e) {
        // Table may not exist — leave as 0
    }

    $totalNegocio = $facturadoEventos + $ventasOp;

    $header = ['Concepto', 'Monto'];
    $data   = [
        ['Periodo',                                   $mes],
        ['Eventos (cotizaciones aceptadas)',           number_format($facturadoEventos, 2, '.', '')],
        ['Operación (POS + Cartas)',                   number_format($ventasOp,         2, '.', '')],
        ['TOTAL NEGOCIO',                              number_format($totalNegocio,      2, '.', '')],
    ];

    csvOut('consolidado-' . $mes . '.csv', $header, $data);
}

// ──────────────────────────────────────────────
// Unknown type
// ──────────────────────────────────────────────
flashMessage('error', 'Tipo de exportación no válido');
redirect('/admin/dashboard');
