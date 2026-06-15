<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (!empty($in['website'])) { echo json_encode(['ok'=>false]); exit; }

$token = clean($in['token'] ?? '');
$empId = cleanInt($in['empleado_id'] ?? 0);
$tipo  = in_array($in['tipo'] ?? '', ['entrada','salida']) ? $in['tipo'] : '';
$ubi = Database::fetch("SELECT * FROM ubicaciones WHERE id=? AND asistencia_token=? AND activa=1", [cleanInt($in['ubicacion_id'] ?? 0), $token]);
if (!$ubi || $token === '' || !$tipo) { echo json_encode(['ok'=>false,'error'=>'Datos inválidos']); exit; }

$emp = Database::fetch("SELECT * FROM empleados WHERE id=? AND ubicacion_id=? AND activo=1", [$empId, $ubi['id']]);
if (!$emp) { echo json_encode(['ok'=>false,'error'=>'Empleado no válido']); exit; }

if (!empty($emp['pin_hash'])) {
    $pin = preg_replace('/\D/', '', $in['pin'] ?? '');
    if (!password_verify($pin, $emp['pin_hash'])) { echo json_encode(['ok'=>false,'error'=>'PIN incorrecto']); exit; }
}

$fotoRel = null;
$b64 = $in['foto'] ?? '';
if (preg_match('#^data:image/\w+;base64,#', $b64)) {
    $bin = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $b64));
    if ($bin !== false && strlen($bin) < 3000000) {
        $name = 'asistencia/' . date('Ymd') . '_' . $empId . '_' . bin2hex(random_bytes(4)) . '.jpg';
        @mkdir(UPLOAD_PATH . 'asistencia', 0775, true);
        if (file_put_contents(UPLOAD_PATH . $name, $bin) !== false) $fotoRel = $name;
    }
}

$lat = isset($in['lat']) && $in['lat'] !== '' && $in['lat'] !== null ? (float)$in['lat'] : null;
$lng = isset($in['lng']) && $in['lng'] !== '' && $in['lng'] !== null ? (float)$in['lng'] : null;
$ubiLat = ($ubi['lat'] !== null && $ubi['lat'] !== '') ? (float)$ubi['lat'] : null;
$ubiLng = ($ubi['lng'] !== null && $ubi['lng'] !== '') ? (float)$ubi['lng'] : null;
$dist = null; $dentro = 1;
if (!empty($ubi['geocerca_activa'])) {
    if ($lat !== null && $lng !== null && $ubiLat !== null && $ubiLng !== null) {
        $R = 6371000; $dLat = deg2rad($lat - $ubiLat); $dLng = deg2rad($lng - $ubiLng);
        $a = sin($dLat/2)**2 + cos(deg2rad($ubiLat))*cos(deg2rad($lat))*sin($dLng/2)**2;
        $dist = (int) round($R * 2 * atan2(sqrt($a), sqrt(1-$a)));
        $dentro = $dist <= (int)$ubi['geocerca_radio'] ? 1 : 0;
    } else {
        $dentro = 0; // geocerca activa pero faltan coordenadas → rojo para revisar
    }
}

$fuente = in_array($ubi['modo_marcaje'] ?? '', ['tablet','celular']) ? $ubi['modo_marcaje'] : 'tablet';

Database::insert(
  "INSERT INTO asistencia_marcas (empleado_id,ubicacion_id,tipo,foto,lat,lng,distancia_m,dentro_geocerca,fuente,origen,marcada_at)
   VALUES (?,?,?,?,?,?,?,?,?, 'app', NOW())",
  [$empId, $ubi['id'], $tipo, $fotoRel, $lat, $lng, $dist, $dentro, $fuente]
);
echo json_encode(['ok'=>true, 'tipo'=>$tipo, 'dentro'=>$dentro]);
