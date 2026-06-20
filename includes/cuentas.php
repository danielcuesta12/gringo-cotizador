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
    $total  = (float)$c['total'];
    $descMonto = (float)($c['descuento_monto'] ?? 0);
    $montoCobrar = round(max(0, $total - $descMonto), 2);
    $pagado = 0.0;
    if (cuentaPagosListo()) {
        $pagado = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['s'] ?? 0);
    }
    return [
        'id' => (int)$c['id'], 'mesa_id' => (int)$c['mesa_id'], 'mesa_numero' => $c['mesa_numero'],
        'num_comensales' => (int)$c['num_comensales'], 'estado' => $c['estado'],
        'total' => $total, 'mozo_nombre' => $c['mozo_nombre'], 'abierta_at' => $c['abierta_at'],
        'precuenta_at' => $c['precuenta_at'] ?? null,
        'descuento_tipo' => $c['descuento_tipo'] ?? null,
        'descuento_valor' => (float)($c['descuento_valor'] ?? 0),
        'descuento_monto' => $descMonto,
        'monto_cobrar' => $montoCobrar,
        'pagado' => round($pagado, 2),
        'falta' => round(max(0, $montoCobrar - $pagado), 2),
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

/** Estados de mesa de un local. ocupada · precuenta (rosa) · por_cobrar (parcial).
 *  Tolerante a la migración 58 pendiente: si no está, no referencia precuenta_at/cuenta_pagos
 *  (el plano de Sub-build B sigue funcionando, solo sin los estados nuevos). */
function mesaEstados(int $ubicacionId): array {
    $estados = []; $montos = []; $minutos = [];
    $hasCobro = cuentaPagosListo(); // migración 58 = cuenta_pagos + cuentas.precuenta_at (se crean juntas)
    // ncom = comandas no canceladas: una cuenta abierta SIN contenido no pinta la mesa (se ve libre).
    $ncomSub = "(SELECT COUNT(*) FROM pedidos WHERE cuenta_id = cu.id AND estado <> 'cancelado')";
    $sel = $hasCobro
        ? "SELECT cu.mesa_id, cu.total, cu.precuenta_at, TIMESTAMPDIFF(MINUTE, cu.abierta_at, NOW()) AS mins,
                  COALESCE((SELECT SUM(monto) FROM cuenta_pagos WHERE cuenta_id = cu.id),0) AS pagado, $ncomSub AS ncom
           FROM cuentas cu WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'"
        : "SELECT cu.mesa_id, cu.total, NULL AS precuenta_at, TIMESTAMPDIFF(MINUTE, cu.abierta_at, NOW()) AS mins,
                  0 AS pagado, $ncomSub AS ncom
           FROM cuentas cu WHERE cu.ubicacion_id = ? AND cu.estado = 'abierta'";
    foreach (Database::fetchAll($sel, [$ubicacionId]) as $r) {
        $mid = (int)$r['mesa_id'];
        $pagado = (float)$r['pagado'];
        $ncom   = (int)$r['ncom'];
        if ($ncom === 0 && $pagado <= 0.001) continue; // cuenta abierta vacía → no pintar la mesa
        if ($pagado > 0.001)                $estado = 'por_cobrar';
        elseif (!empty($r['precuenta_at'])) $estado = 'precuenta';
        else                                $estado = 'ocupada';
        $estados[$mid] = $estado;
        $montos[$mid]  = (float)$r['total'];
        $minutos[$mid] = max(0, (int)$r['mins']);
    }
    return ['estados' => $estados, 'montos' => $montos, 'minutos' => $minutos];
}

/** Turnos de caja ABIERTOS en un local (para asignar el cobro de mesa al arqueo). */
function turnoAbiertoLocal(int $ubicacionId): array {
    $rows = Database::fetchAll(
        "SELECT t.id, t.abierto_en, COALESCE(u.name, u.email, CONCAT('Caja ', t.usuario_id)) AS usuario
         FROM pos_turnos t LEFT JOIN users u ON u.id = t.usuario_id
         WHERE t.ubicacion_id = ? AND t.estado = 'abierto' ORDER BY t.id DESC", [$ubicacionId]);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
    unset($r);
    return ['turnos' => $rows, 'count' => count($rows)];
}

/** Suma de pagos de mesa de un turno, por bucket (para el arqueo). */
function cuentaPagosArqueo(int $turnoId): array {
    $z = ['efectivo'=>0.0,'tarjeta'=>0.0,'qr'=>0.0,'otros'=>0.0,'total'=>0.0,'n'=>0];
    if (!cuentaPagosListo()) return $z;
    $r = Database::fetch(
        "SELECT COALESCE(SUM(CASE WHEN tipo='efectivo' THEN monto ELSE 0 END),0) ef,
                COALESCE(SUM(CASE WHEN tipo='tarjeta'  THEN monto ELSE 0 END),0) ta,
                COALESCE(SUM(CASE WHEN tipo='qr'       THEN monto ELSE 0 END),0) qr,
                COALESCE(SUM(CASE WHEN tipo NOT IN ('efectivo','tarjeta','qr') THEN monto ELSE 0 END),0) ot,
                COALESCE(SUM(monto),0) tot, COUNT(*) n
         FROM cuenta_pagos WHERE turno_id = ?", [$turnoId]);
    if (!$r) return $z;
    return [
        'efectivo'=>(float)$r['ef'], 'tarjeta'=>(float)$r['ta'], 'qr'=>(float)$r['qr'],
        'otros'=>(float)$r['ot'], 'total'=>(float)$r['tot'], 'n'=>(int)$r['n'],
    ];
}

/**
 * Cobra una o más PARTES de una cuenta (split + pago mixto). Transaccional.
 * El dinero va a cuenta_pagos (fuente de verdad); el comprobante (opcional) a un
 * pedido-comprobante por parte (reusa nubefactEmitir). Cierra la cuenta al pagar el total.
 */
function cuentaCobrar(int $cuentaId, int $ubicacionId, ?int $empleadoId, array $payload): array {
    $c = Database::fetch(
        "SELECT * FROM cuentas WHERE id = ? AND estado = 'abierta' AND (? = 0 OR ubicacion_id = ?)",
        [$cuentaId, $ubicacionId, $ubicacionId]);
    if (!$c) return ['ok' => false, 'error' => 'cuenta no abierta'];
    if (!cuentaPagosListo()) return ['ok' => false, 'error' => 'cobro de mesas no disponible (falta migración 58)'];
    $ubi = (int)$c['ubicacion_id'];
    $mesaId = (int)$c['mesa_id'];

    // 1) Resolver el turno de caja del local.
    $tl = turnoAbiertoLocal($ubi);
    if ($tl['count'] === 0) return ['ok' => false, 'sin_caja' => true, 'error' => 'No hay caja abierta en el local'];
    $turnoId = 0;
    if ($tl['count'] === 1) {
        $turnoId = (int)$tl['turnos'][0]['id'];
    } else {
        $want = (int)($payload['turno_id'] ?? 0);
        $ids = array_map(fn($t) => (int)$t['id'], $tl['turnos']);
        if ($want && in_array($want, $ids, true)) $turnoId = $want;
        else return ['ok' => false, 'multi_caja' => true, 'turnos' => $tl['turnos'], 'error' => 'Elige la caja'];
    }

    // 2) Consumo autoritativo + descuento.
    $consumo = cuentaTotalRecalc($cuentaId);
    $yaPagado = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['s'] ?? 0);
    $modo = in_array($payload['modo'] ?? '', ['todo','iguales','items','montos'], true) ? $payload['modo'] : 'todo';

    // Descuento: solo si aún no hay pagos (primera tanda) y no en modo items.
    $descTipo = $c['descuento_tipo']; $descValor = (float)$c['descuento_valor']; $descMonto = (float)$c['descuento_monto'];
    if ($yaPagado <= 0.001 && $modo !== 'items') {
        $dIn = (array)($payload['descuento'] ?? []);
        $dt = in_array($dIn['tipo'] ?? '', ['porcentaje','monto'], true) ? $dIn['tipo'] : null;
        $dv = (float)($dIn['valor'] ?? 0);
        $dm = 0.0;
        if ($dt === 'porcentaje') $dm = $consumo * min(100, max(0, $dv)) / 100;
        elseif ($dt === 'monto')  $dm = min($consumo, max(0, $dv));
        $descTipo = $dt; $descValor = $dv; $descMonto = round($dm, 2);
    }
    $montoCobrar = round(max(0, $consumo - $descMonto), 2);

    // 3) Validar partes y construir su monto + items de comprobante.
    $partesIn = (array)($payload['partes'] ?? []);
    if (!$partesIn) return ['ok' => false, 'error' => 'sin partes a cobrar'];
    // Mapa de ítems de la cuenta (para modo items): "pedidoId:idx" => item.
    $itemsMap = [];
    if ($modo === 'items') {
        foreach (Database::fetchAll("SELECT id, items_json FROM pedidos WHERE cuenta_id = ? AND estado <> 'cancelado'", [$cuentaId]) as $pp) {
            $arr = json_decode($pp['items_json'] ?? '[]', true) ?: [];
            foreach ($arr as $idx => $it) { $itemsMap[$pp['id'] . ':' . $idx] = $it; }
        }
    }
    $partes = [];
    $sumPartes = 0.0;
    foreach ($partesIn as $i => $pi) {
        $compItems = null;
        if ($modo === 'items') {
            $monto = 0.0; $compItems = [];
            foreach ((array)($pi['item_keys'] ?? []) as $k) {
                if (!isset($itemsMap[$k])) return ['ok' => false, 'error' => 'ítem inválido en la división'];
                $it = $itemsMap[$k];
                if (!empty($it['anulado'])) continue;
                $monto += itemLineTotal($it);
                $compItems[] = ['nombre' => (string)($it['nombre'] ?? 'Ítem'), 'qty' => max(1,(int)($it['qty'] ?? 1)),
                                'precio' => (float)($it['precio'] ?? 0), 'modificadores' => (array)($it['modificadores'] ?? [])];
            }
            $monto = round($monto, 2);
        } else {
            $monto = round((float)($pi['monto'] ?? 0), 2);
        }
        if ($monto <= 0) return ['ok' => false, 'error' => 'parte con monto inválido'];
        // pagos de la parte (mixto): suman el monto de la parte.
        $pagos = [];
        $sumPagos = 0.0;
        foreach ((array)($pi['pagos'] ?? []) as $pg) {
            $met = trim((string)($pg['metodo'] ?? ''));
            $mn  = round((float)($pg['monto'] ?? 0), 2);
            if ($met === '' || $mn <= 0) continue;
            $pagos[] = ['metodo' => $met, 'monto' => $mn];
            $sumPagos += $mn;
        }
        if (!$pagos) return ['ok' => false, 'error' => 'parte sin pagos'];
        if (abs(round($sumPagos, 2) - $monto) > 0.01) return ['ok' => false, 'error' => 'los pagos no suman el monto de la parte'];
        $comp = null;
        $cIn = $pi['comprobante'] ?? null;
        if (is_array($cIn)) {
            $ct = in_array($cIn['tipo'] ?? '', ['ticket','boleta','factura'], true) ? $cIn['tipo'] : 'ticket';
            $comp = [
                'tipo' => $ct,
                'cliente_tipo' => in_array($cIn['cliente_tipo'] ?? '', ['nombre','dni','ruc'], true) ? $cIn['cliente_tipo'] : null,
                'cliente_nombre' => clean($cIn['cliente_nombre'] ?? ''),
                'cliente_documento' => preg_replace('/[^0-9A-Za-z]/', '', (string)($cIn['cliente_documento'] ?? '')),
                'cliente_razon_social' => clean($cIn['cliente_razon_social'] ?? ''),
                'cliente_email' => cleanEmail($cIn['cliente_email'] ?? ''),
                'items' => $compItems, // null salvo modo items
            ];
        }
        $partes[] = ['num' => $i + 1, 'monto' => $monto, 'pagos' => $pagos, 'comp' => $comp];
        $sumPartes += $monto;
    }
    $sumPartes = round($sumPartes, 2);

    // 4) No sobrepagar.
    if (round($yaPagado + $sumPartes, 2) > $montoCobrar + 0.01) {
        return ['ok' => false, 'error' => 'el cobro supera el saldo de la cuenta'];
    }

    // 5) Transacción: descuento + pagos + comprobantes (+ cierre si se completa).
    $pdo = Database::getInstance();
    $emitDespues = []; // [pedidoId, parteNum, tipo]
    $comprobantes = [];
    try {
        $pdo->beginTransaction();

        if ($yaPagado <= 0.001) {
            Database::execute("UPDATE cuentas SET descuento_tipo = ?, descuento_valor = ?, descuento_monto = ? WHERE id = ?",
                [$descTipo, $descValor, $descMonto, $cuentaId]);
        }

        $parteBase = (int)(Database::fetch("SELECT COALESCE(MAX(parte_num),0) m FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['m'] ?? 0);

        foreach ($partes as $k => $pt) {
            $parteNum = $parteBase + $k + 1;
            // Comprobante (opcional): pedido-comprobante por parte.
            $compPid = null;
            if ($pt['comp']) {
                $cm = $pt['comp'];
                $compItems = $cm['items'];
                if (!is_array($compItems) || !$compItems) {
                    $compItems = [['nombre' => 'Consumo en salón', 'qty' => 1, 'precio' => $pt['monto'], 'modificadores' => []]];
                }
                $nombrePed = 'Mesa ' . ($c['mesa_id'] ? (Database::fetch('SELECT numero FROM mesas WHERE id=?', [$mesaId])['numero'] ?? '') : '') . ' · parte ' . $parteNum;
                $compPid = (int) Database::insert(
                    "INSERT INTO pedidos (ubicacion_id, nombre, tipo_entrega, items_json, total, estado, metodo_pago, origen, cuenta_id, mesa_id,
                        comprobante_tipo, cliente_tipo, cliente_nombre, cliente_documento, cliente_razon_social, cliente_email, aceptado_at, completado_at, horario)
                     VALUES (?,?, 'recojo', ?, ?, 'entregado', 'mesa', 'mesa', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 'En salón')",
                    [$ubi, $nombrePed, json_encode($compItems, JSON_UNESCAPED_UNICODE), $pt['monto'], $cuentaId, $mesaId,
                     $cm['tipo'], $cm['cliente_tipo'], ($cm['cliente_nombre'] ?: null), ($cm['cliente_documento'] ?: null),
                     ($cm['cliente_razon_social'] ?: null), ($cm['cliente_email'] ?: null)]);
                if (in_array($cm['tipo'], ['boleta','factura'], true)) $emitDespues[] = ['pid' => $compPid, 'parte' => $parteNum, 'tipo' => $cm['tipo']];
            }
            // Pagos (mixto) → cuenta_pagos.
            foreach ($pt['pagos'] as $pg) {
                $tipoRow = Database::fetch("SELECT tipo FROM pos_metodos_pago WHERE nombre = ? LIMIT 1", [$pg['metodo']]);
                $tipo = $tipoRow['tipo'] ?? 'otros';
                Database::insert(
                    "INSERT INTO cuenta_pagos (cuenta_id, ubicacion_id, turno_id, parte_num, metodo_pago, tipo, monto, empleado_id, comprobante_pedido_id)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$cuentaId, $ubi, $turnoId, $parteNum, $pg['metodo'], $tipo, $pg['monto'], $empleadoId, $compPid]);
            }
        }

        // ¿Quedó pagada por completo?
        $pagadoTot = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['s'] ?? 0);
        $cerrada = round($pagadoTot, 2) >= $montoCobrar - 0.01;
        if ($cerrada) {
            Database::execute("UPDATE cuentas SET estado = 'cerrada', cobrada_at = NOW(), cerrada_at = NOW() WHERE id = ?", [$cuentaId]);
            // Trazabilidad: asignar turno a las comandas (NO entran al arqueo por el guard origen='mesa').
            Database::execute("UPDATE pedidos SET turno_id = ? WHERE cuenta_id = ? AND origen = 'mesa' AND estado <> 'cancelado' AND turno_id IS NULL", [$turnoId, $cuentaId]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'no se pudo registrar el cobro'];
    }

    // 6) Emitir comprobantes electrónicos FUERA de la transacción (red; nunca rompe el cobro).
    foreach ($emitDespues as $em) {
        $est = 'pendiente'; $serie = ''; $num = 0; $err = '';
        if (function_exists('nubefactConfigurado') && function_exists('nubefactEmitir') && nubefactConfigurado()) {
            $r = nubefactEmitir($em['pid']);
            $est = $r['estado'] ?? (!empty($r['ok']) ? 'emitido' : 'error');
            $serie = $r['serie'] ?? ''; $num = (int)($r['numero'] ?? 0); $err = $r['error'] ?? '';
        }
        $comprobantes[] = ['parte'=>$em['parte'],'tipo'=>$em['tipo'],'estado'=>$est,'serie'=>$serie,'numero'=>$num,'error'=>$err,'pedido_id'=>$em['pid']];
    }

    $pagadoTot = (float)(Database::fetch("SELECT COALESCE(SUM(monto),0) s FROM cuenta_pagos WHERE cuenta_id = ?", [$cuentaId])['s'] ?? 0);
    return [
        'ok' => true,
        'cerrada' => round($pagadoTot, 2) >= $montoCobrar - 0.01,
        'pagado' => round($pagadoTot, 2),
        'falta' => round(max(0, $montoCobrar - $pagadoTot), 2),
        'comprobantes' => $comprobantes,
    ];
}

/** Comandas (origen='mesa') en estado 'listo' de las cuentas abiertas de un mozo. Para el aviso de "Listo". */
function comandasListas(int $ubicacionId, int $empleadoId): array {
    if ($empleadoId <= 0) return [];
    $out = [];
    foreach (Database::fetchAll(
        "SELECT p.id AS pedido_id, m.numero AS mesa, p.items_json
         FROM pedidos p
         JOIN cuentas c ON c.id = p.cuenta_id
         LEFT JOIN mesas m ON m.id = p.mesa_id
         WHERE c.ubicacion_id = ? AND c.empleado_id = ? AND c.estado = 'abierta'
           AND p.origen = 'mesa' AND p.estado = 'listo'
         ORDER BY p.id", [$ubicacionId, $empleadoId]) as $r) {
        $items = json_decode($r['items_json'] ?? '[]', true) ?: [];
        $nombres = []; $total = 0;
        foreach ($items as $it) {
            if (!empty($it['anulado'])) continue;
            $total++;
            if (count($nombres) < 3) $nombres[] = (int)($it['qty'] ?? 1) . '× ' . (string)($it['nombre'] ?? 'Ítem');
        }
        $resumen = implode(' · ', $nombres);
        if ($total > count($nombres)) $resumen .= ' …';
        $out[] = ['pedido_id' => (int)$r['pedido_id'], 'mesa' => (string)($r['mesa'] ?? ''), 'resumen' => $resumen];
    }
    return $out;
}
