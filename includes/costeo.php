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
