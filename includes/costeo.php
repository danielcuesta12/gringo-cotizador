<?php
// Matemática pura del costeo de recetas. Sin acceso a BD — testeable con aserciones.

if (!function_exists('foodCostCalc')) {
    /**
     * Food cost y margen de una porción.
     * @return array ['neto'=>precio sin IGV, 'fc'=>fracción 0..1, 'margen'=>fracción 0..1]
     */
    function foodCostCalc(float $costoPorcion, float $precioConIgv, float $igvPct): array
    {
        $neto = $precioConIgv / (1 + $igvPct / 100);
        if ($neto <= 0) return ['neto' => 0.0, 'fc' => 0.0, 'margen' => 0.0];
        return [
            'neto'   => $neto,
            'fc'     => $costoPorcion / $neto,
            'margen' => ($neto - $costoPorcion) / $neto,
        ];
    }
}

if (!function_exists('precioSugerido')) {
    /** Precio de venta (con IGV) para alcanzar un food cost objetivo (%). */
    function precioSugerido(float $costoPorcion, float $objetivoPct, float $igvPct): float
    {
        if ($objetivoPct <= 0) return 0.0;
        return ($costoPorcion / ($objetivoPct / 100)) * (1 + $igvPct / 100);
    }
}

if (!function_exists('subrecetaCostoUMCalc')) {
    /** Costo por unidad de medida de una subreceta = costo total de insumos / rendimiento. */
    function subrecetaCostoUMCalc(float $costoTotalInsumos, float $rendimiento): float
    {
        if ($rendimiento <= 0) return 0.0;
        return $costoTotalInsumos / $rendimiento;
    }
}

if (!function_exists('fcClase')) {
    /** Semáforo del food cost (fracción 0..1): ok ≤0.35, warn ≤0.42, bad resto. */
    function fcClase(float $fc): string
    {
        if ($fc <= 0.35) return 'ok';
        if ($fc <= 0.42) return 'warn';
        return 'bad';
    }
}

if (!function_exists('repartirConsumo')) {
    /**
     * Reparte el consumo de una receta entre insumos y subrecetas-con-stock.
     * Las subrecetas sin stock se explotan a insumos; las con stock se descuentan aparte.
     * @param array $componentes filas ['tipo'=>'insumo'|'subreceta','ref_id'=>int,'cantidad'=>float]
     * @param callable $subLoader fn(int $subId): ['lleva_stock'=>bool,'rendimiento'=>float,'items'=>[['insumo_id'=>int,'cantidad'=>float],...]]|null
     * @return array ['insumos'=>[insumo_id=>cantidad], 'subrecetas'=>[subreceta_id=>cantidad]]
     */
    function repartirConsumo(array $componentes, callable $subLoader): array
    {
        $insumos = [];
        $subs = [];
        foreach ($componentes as $c) {
            $cant = (float)($c['cantidad'] ?? 0);
            $ref  = (int)($c['ref_id'] ?? 0);
            $tipo = (($c['tipo'] ?? 'insumo') === 'subreceta') ? 'subreceta' : 'insumo';
            if ($cant <= 0 || $ref <= 0) continue;
            if ($tipo === 'insumo') {
                $insumos[$ref] = ($insumos[$ref] ?? 0) + $cant;
                continue;
            }
            $info = $subLoader($ref);
            if (!$info) continue;
            if (!empty($info['lleva_stock'])) {
                $subs[$ref] = ($subs[$ref] ?? 0) + $cant;
            } else {
                $rend = (float)($info['rendimiento'] ?? 0);
                if ($rend <= 0) continue;
                foreach (($info['items'] ?? []) as $it) {
                    $iid = (int)($it['insumo_id'] ?? 0);
                    if ($iid <= 0) continue;
                    $insumos[$iid] = ($insumos[$iid] ?? 0) + ((float)($it['cantidad'] ?? 0) / $rend) * $cant;
                }
            }
        }
        return ['insumos' => $insumos, 'subrecetas' => $subs];
    }
}
