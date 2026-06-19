<?php
/**
 * Cuentas de mesa (Sub-build B) — lógica compartida: abrir cuenta, enviar comanda,
 * anular, total, estados de mesa y geocerca. Consumido por api/mozo.php.
 */

/** ¿Existen las tablas de cuentas? */
function cuentasListo(): bool {
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cuentas'");
    } catch (\Throwable $e) { return false; }
}

/** ¿Existe la tabla de pagos de mesa? (Sub-build C) */
function cuentaPagosListo(): bool {
    try {
        return (bool) Database::fetch(
            "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='cuenta_pagos'");
    } catch (\Throwable $e) { return false; }
}

/** Total de una línea de ítem (0 si anulado): (precio + suma de modificadores) * qty. */
function itemLineTotal(array $it): float {
    if (!empty($it['anulado'])) return 0.0;
    $qty  = max(1, (int)($it['qty'] ?? 1));
    $base = (float)($it['precio'] ?? 0);
    $mods = 0.0;
    foreach ((array)($it['modificadores'] ?? []) as $m) $mods += (float)($m['precio'] ?? 0);
    return ($base + $mods) * $qty;
}

/** Reparte $total en $n partes a 2 decimales; el resto de centavos va a la última. Suman exacto. */
function repartoCentavos(float $total, int $n): array {
    $n = max(1, $n);
    $cent  = (int) round($total * 100);
    $base  = intdiv($cent, $n);
    $resto = $cent - $base * $n;
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $c = $base + ($i === $n - 1 ? $resto : 0);
        $out[] = round($c / 100, 2);
    }
    return $out;
}

/** Distancia en metros entre dos coordenadas (haversine). */
function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $R = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** ¿El mozo está dentro de la geocerca del local? Kill-switch global + sin-coords = permite. */
function dentroGeocerca(int $ubicacionId, ?float $lat, ?float $lng): bool {
    if (getSetting('mozo_geocerca_activa', '1') !== '1') return true;
    $u = Database::fetch("SELECT lat, lng, geocerca_radio FROM ubicaciones WHERE id = ?", [$ubicacionId]);
    if (!$u || $u['lat'] === null || $u['lng'] === null) return true; // local sin coordenadas configuradas
    if ($lat === null || $lng === null) return false;                 // la app no entregó GPS
    $radio = (int)($u['geocerca_radio'] ?? 100) ?: 100;
    return haversineM((float)$u['lat'], (float)$u['lng'], $lat, $lng) <= $radio;
}

/** La cuenta abierta de una mesa, o null. */
function cuentaAbiertaDeMesa(int $mesaId): ?array {
    return Database::fetch("SELECT * FROM cuentas WHERE mesa_id = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1", [$mesaId]);
}

/** Abre (o reusa) la cuenta abierta de una mesa. Devuelve el id. */
function cuentaAbrir(int $mesaId, int $ubicacionId, ?int $empleadoId, int $numComensales): int {
    $ex = cuentaAbiertaDeMesa($mesaId);
    if ($ex) {
        if ($numComensales > 0 && (int)$ex['num_comensales'] === 0) {
            Database::execute("UPDATE cuentas SET num_comensales = ? WHERE id = ?", [$numComensales, (int)$ex['id']]);
        }
        return (int)$ex['id'];
    }
    return (int) Database::insert(
        "INSERT INTO cuentas (mesa_id, ubicacion_id, empleado_id, num_comensales) VALUES (?,?,?,?)",
        [$mesaId, $ubicacionId, $empleadoId, max(0, $numComensales)]);
}

/** Suma de ítems no-anulados de las comandas no-canceladas; cachea en cuentas.total. */
function cuentaTotalRecalc(int $cuentaId): float {
    $total = 0.0;
    foreach (Database::fetchAll("SELECT items_json FROM pedidos WHERE cuenta_id = ? AND estado <> 'cancelado'", [$cuentaId]) as $row) {
        $items = json_decode($row['items_json'] ?? '[]', true) ?: [];
        foreach ($items as $it) {
            $total += itemLineTotal($it);
        }
    }
    $total = round($total, 2);
    Database::execute("UPDATE cuentas SET total = ? WHERE id = ?", [$total, $cuentaId]);
    return $total;
}

/** Detalle de la cuenta con sus comandas (rondas) e ítems. */
function cuentaDetalle(int $cuentaId, int $ubicacionId = 0): ?array {
    $c = Database::fetch(
        "SELECT cu.*, m.numero AS mesa_numero, e.nombre AS mozo_nombre FROM cuentas cu
         LEFT JOIN mesas m ON m.id = cu.mesa_id
         LEFT JOIN empleados e ON e.id = cu.empleado_id
         WHERE cu.id = ? AND (? = 0 OR cu.ubicacion_id = ?)", [$cuentaId, $ubicacionId, $ubicacionId]);
    if (!$c) return null;
    $comandas = [];
    $ronda = 0;
    foreach (Database::fetchAll("SELECT id, estado, items_json, created_at FROM pedidos WHERE cuenta_id = ? ORDER BY id", [$cuentaId]) as $p) {
        $ronda++;
        if ($p['estado'] === 'cancelado') continue;
        $comandas[] = [
            'pedido_id' => (int)$p['id'],
            'ronda'     => $ronda,
            'estado'    => $p['estado'],
            'creada_at' => $p['created_at'],
            'items'     => json_decode($p['items_json'] ?? '[]', true) ?: [],
        ];
    }
    return [
        'id' => (int)$c['id'], 'mesa_id' => (int)$c['mesa_id'], 'mesa_numero' => $c['mesa_numero'],
        'num_comensales' => (int)$c['num_comensales'], 'estado' => $c['estado'],
        'total' => (float)$c['total'], 'mozo_nombre' => $c['mozo_nombre'], 'abierta_at' => $c['abierta_at'],
        'comandas' => $comandas,
    ];
}

/** Crea una comanda (pedido origen='mesa') desde un borrador de ítems. */
function comandaEnviar(int $cuentaId, array $items, ?int $empleadoId, int $ubicacionId = 0): array {
    $c = Database::fetch("SELECT * FROM cuentas WHERE id = ? AND estado = 'abierta' AND (? = 0 OR ubicacion_id = ?)", [$cuentaId, $ubicacionId, $ubicacionId]);
    if (!$c) return ['ok' => false, 'error' => 'cuenta no abierta'];
    // Normalizar ítems (mismo formato que el POS)
    $clean = [];
    foreach ($items as $it) {
        $qty  = max(1, (int)($it['qty'] ?? 1));
        $base = (float)($it['precio'] ?? 0);
        $mods = [];
        foreach ((array)($it['modificadores'] ?? []) as $m) {
            $mods[] = ['nombre' => clean($m['nombre'] ?? ''), 'precio' => (float)($m['precio'] ?? 0)];
        }
        $nota = clean($it['nota'] ?? '');
        $clean[] = ['product_id' => (int)($it['product_id'] ?? $it['id'] ?? 0), 'qty' => $qty,
                    'nombre' => clean($it['nombre'] ?? ''), 'precio' => $base, 'modificadores' => $mods, 'nota' => $nota];
    }
    if (!$clean) return ['ok' => false, 'error' => 'borrador vacío'];
    // Total de la comanda
    $tot = 0.0;
    foreach ($clean as $it) {
        $msum = 0.0; foreach ($it['modificadores'] as $m) $msum += (float)$m['precio'];
        $tot += ($it['precio'] + $msum) * $it['qty'];
    }
    $pedidoId = (int) Database::insert(
        "INSERT INTO pedidos (ubicacion_id, nombre, tipo_entrega, items_json, total, estado, metodo_pago, origen, cuenta_id, mesa_id, aceptado_at)
         VALUES (?, ?, 'recojo', ?, ?, 'en_preparacion', 'whatsapp', 'mesa', ?, ?, NOW())",
        [(int)$c['ubicacion_id'], 'Mesa ' . (Database::fetch('SELECT numero FROM mesas WHERE id=?', [(int)$c['mesa_id']])['numero'] ?? ''),
         json_encode($clean, JSON_UNESCAPED_UNICODE), round($tot, 2), $cuentaId, (int)$c['mesa_id']]);
    $ronda = (int)(Database::fetch("SELECT COUNT(*) n FROM pedidos WHERE cuenta_id = ?", [$cuentaId])['n'] ?? 1);
    cuentaTotalRecalc($cuentaId);
    return ['ok' => true, 'pedido_id' => $pedidoId, 'ronda' => $ronda];
}

/** Anula un ítem (itemIdx) o una comanda completa (itemIdx=null). Solo antes de 'listo'. */
function cuentaAnular(int $cuentaId, int $pedidoId, ?int $itemIdx, string $motivo, ?int $empleadoId, int $ubicacionId = 0): array {
    $p = Database::fetch(
        "SELECT p.* FROM pedidos p JOIN cuentas c ON c.id = p.cuenta_id
         WHERE p.id = ? AND p.cuenta_id = ? AND (? = 0 OR c.ubicacion_id = ?)", [$pedidoId, $cuentaId, $ubicacionId, $ubicacionId]);
    if (!$p) return ['ok' => false, 'error' => 'comanda no encontrada'];
    if (!in_array($p['estado'], ['pendiente', 'en_preparacion'], true)) {
        return ['ok' => false, 'error' => 'no se puede anular: la cocina ya la marcó lista'];
    }
    $motivo = substr(trim($motivo), 0, 160) ?: 'Sin motivo';
    if ($itemIdx === null) {
        Database::execute("UPDATE pedidos SET estado = 'cancelado' WHERE id = ?", [$pedidoId]);
    } else {
        $items = json_decode($p['items_json'] ?? '[]', true) ?: [];
        if (!isset($items[$itemIdx])) return ['ok' => false, 'error' => 'ítem inexistente'];
        $items[$itemIdx]['anulado'] = true;
        $items[$itemIdx]['anul_motivo'] = $motivo;
        Database::execute("UPDATE pedidos SET items_json = ? WHERE id = ?", [json_encode($items, JSON_UNESCAPED_UNICODE), $pedidoId]);
    }
    Database::execute(
        "INSERT INTO cuenta_anulaciones (cuenta_id, pedido_id, item_idx, motivo, empleado_id) VALUES (?,?,?,?,?)",
        [$cuentaId, $pedidoId, $itemIdx, $motivo, $empleadoId]);
    cuentaTotalRecalc($cuentaId);
    return ['ok' => true];
}

/** Estados de mesa de un local: las que tienen cuenta abierta → 'ocupada' + monto. */
function mesaEstados(int $ubicacionId): array {
    $estados = []; $montos = []; $minutos = [];
    foreach (Database::fetchAll("SELECT mesa_id, total, TIMESTAMPDIFF(MINUTE, abierta_at, NOW()) AS mins FROM cuentas WHERE ubicacion_id = ? AND estado = 'abierta'", [$ubicacionId]) as $r) {
        $estados[(int)$r['mesa_id']] = 'ocupada';
        $montos[(int)$r['mesa_id']]  = (float)$r['total'];
        $minutos[(int)$r['mesa_id']] = max(0, (int)$r['mins']);
    }
    return ['estados' => $estados, 'montos' => $montos, 'minutos' => $minutos];
}
