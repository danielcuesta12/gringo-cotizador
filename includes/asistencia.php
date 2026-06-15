<?php
if (!function_exists('asistenciaResumen')) {
    /**
     * @param array $rows filas con ['empleado_id','nombre','tipo','marcada_at'] ordenadas por empleado y marcada_at ASC
     * @return array empleado_id => ['nombre','segundos','incompletas']
     */
    function asistenciaResumen(array $rows): array {
        $acc = []; $abierta = [];
        $TOPE = 16 * 3600; // 16h máximo por turno
        foreach ($rows as $r) {
            $eid = $r['empleado_id'];
            if (!isset($acc[$eid])) $acc[$eid] = ['nombre'=>$r['nombre'] ?? '', 'segundos'=>0, 'incompletas'=>0];
            $ts = strtotime($r['marcada_at']);
            if ($r['tipo'] === 'entrada') {
                if (isset($abierta[$eid])) $acc[$eid]['incompletas']++; // entrada previa sin cerrar
                $abierta[$eid] = $ts;
            } else { // salida
                if (isset($abierta[$eid])) {
                    $dur = $ts - $abierta[$eid];
                    if ($dur >= 0 && $dur <= $TOPE) { $acc[$eid]['segundos'] += $dur; }
                    else { $acc[$eid]['incompletas']++; } // turno inverosímil → no sumar
                    unset($abierta[$eid]);
                } else { $acc[$eid]['incompletas']++; } // salida sin entrada
            }
        }
        foreach ($abierta as $eid => $_v) $acc[$eid]['incompletas']++; // entradas abiertas al final
        return $acc;
    }
}
