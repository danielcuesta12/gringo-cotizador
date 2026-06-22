<?php
require __DIR__ . '/../includes/costeo.php';

$fails = 0;
function check($label, $got, $exp, $eps = 0.0001) {
    global $fails;
    $ok = abs((float)$got - (float)$exp) < $eps;
    if (!$ok) { $fails++; echo "FAIL: $label — got " . var_export($got, true) . ", expected " . var_export($exp, true) . "\n"; }
    else { echo "ok: $label\n"; }
}

// Loader de prueba: sub 10 lleva stock (rend 4); sub 20 NO lleva stock (rend 2, insumo 3 x6); sub 30 rend 0
$loader = function($id) {
    if ($id === 10) return ['lleva_stock'=>true,  'rendimiento'=>4.0, 'items'=>[['insumo_id'=>1,'cantidad'=>8],['insumo_id'=>2,'cantidad'=>4]]];
    if ($id === 20) return ['lleva_stock'=>false, 'rendimiento'=>2.0, 'items'=>[['insumo_id'=>3,'cantidad'=>6]]];
    if ($id === 30) return ['lleva_stock'=>false, 'rendimiento'=>0.0, 'items'=>[['insumo_id'=>4,'cantidad'=>5]]];
    return null;
};

$comp = [
    ['tipo'=>'insumo',    'ref_id'=>1,  'cantidad'=>2],     // insumo directo
    ['tipo'=>'subreceta', 'ref_id'=>10, 'cantidad'=>1],     // con stock -> subrecetas[10]=1 (NO explota)
    ['tipo'=>'subreceta', 'ref_id'=>20, 'cantidad'=>0.5],   // sin stock -> insumo 3 += (6/2)*0.5 = 1.5
    ['tipo'=>'subreceta', 'ref_id'=>30, 'cantidad'=>1],     // rendimiento 0 -> se omite
];
$r = repartirConsumo($comp, $loader);

check('insumo 1 directo', $r['insumos'][1] ?? 0, 2);
check('insumo 3 explotado de sub20', $r['insumos'][3] ?? 0, 1.5);
check('insumo 2 NO aparece (sub10 lleva stock)', isset($r['insumos'][2]) ? 1 : 0, 0);
check('insumo 4 NO aparece (rend 0)', isset($r['insumos'][4]) ? 1 : 0, 0);
check('subreceta 10 con stock', $r['subrecetas'][10] ?? 0, 1);
check('subreceta 20 NO en subrecetas', isset($r['subrecetas'][20]) ? 1 : 0, 0);

// Acumulación: mismo insumo dos veces
$r2 = repartirConsumo([
    ['tipo'=>'insumo','ref_id'=>5,'cantidad'=>1],
    ['tipo'=>'insumo','ref_id'=>5,'cantidad'=>3],
], $loader);
check('acumula insumo 5', $r2['insumos'][5] ?? 0, 4);

echo $fails === 0 ? "\nALL OK\n" : "\n$fails FAIL(S)\n";
exit($fails === 0 ? 0 : 1);
