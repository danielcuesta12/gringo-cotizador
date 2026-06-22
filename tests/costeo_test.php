<?php
require __DIR__ . '/../includes/costeo.php';

$fails = 0;
function check($label, $got, $exp, $eps = 0.0001) {
    global $fails;
    $ok = is_string($exp) ? ($got === $exp) : (abs($got - $exp) < $eps);
    if (!$ok) { $fails++; echo "FAIL: $label — got " . var_export($got, true) . ", expected " . var_export($exp, true) . "\n"; }
    else { echo "ok: $label\n"; }
}

// foodCostCalc: costo 3.50, precio con IGV 11.80, IGV 18 -> neto 10.00, fc 0.35, margen 0.65
$r = foodCostCalc(3.50, 11.80, 18);
check('fc.neto', $r['neto'], 10.00);
check('fc.fc', $r['fc'], 0.35);
check('fc.margen', $r['margen'], 0.65);

// precio <= 0 / neto 0 -> ceros, sin división por cero
$z = foodCostCalc(3.50, 0, 18);
check('fc.zero.neto', $z['neto'], 0.0);
check('fc.zero.fc', $z['fc'], 0.0);
check('fc.zero.margen', $z['margen'], 0.0);

// precioSugerido: costo 3.50, objetivo 35%, IGV 18 -> (3.5/0.35)*1.18 = 11.80
check('precioSugerido', precioSugerido(3.50, 35, 18), 11.80);
check('precioSugerido.cero', precioSugerido(3.50, 0, 18), 0.0);

// subrecetaCostoUMCalc: total 20 / rendimiento 5 = 4 ; rendimiento 0 -> 0
check('subUM', subrecetaCostoUMCalc(20, 5), 4.0);
check('subUM.cero', subrecetaCostoUMCalc(20, 0), 0.0);

// fcClase: 0.35 ok, 0.42 warn, 0.50 bad
check('fcClase.ok', fcClase(0.35), 'ok');
check('fcClase.warn', fcClase(0.42), 'warn');
check('fcClase.bad', fcClase(0.50), 'bad');

echo $fails === 0 ? "\nALL OK\n" : "\n$fails FAIL(S)\n";
exit($fails === 0 ? 0 : 1);
