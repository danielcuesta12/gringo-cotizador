<?php
// Verifica la firma del retorno del navegador (HMAC) y confirma el pago.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/izipay.php';
$izc     = izipayCfg();
$mode    = $izc['mode'];
$hmacKey = $izc['hmac'];   // secreta (solo-servidor)
$base    = rtrim(preg_replace('#/cotizador/?$#', '', APP_URL), '/');

$isBrowserRedirect = isset($_POST['kr-answer']);
if ($isBrowserRedirect) {
    $krAnswer = $_POST['kr-answer'] ?? '';
    $krHash   = $_POST['kr-hash']   ?? '';
} else {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
    $body     = json_decode(file_get_contents('php://input'), true) ?: [];
    $krAnswer = $body['kr-answer'] ?? '';
    $krHash   = $body['kr-hash']   ?? '';
}

$expectedHash = hash_hmac('sha256', $krAnswer, $hmacKey);
if (!hash_equals($expectedHash, $krHash)) {
    if ($isBrowserRedirect) { header('Location: ' . $base . '/?iz_err=1'); exit; }
    echo json_encode(['ok' => false, 'error' => 'Firma inválida']); exit;
}

$answer      = json_decode($krAnswer, true);
$transaction = $answer['transactions'][0] ?? null;

if ($transaction && ($transaction['detailedStatus'] ?? '') === 'AUTHORISED') {
    $orderId       = $answer['orderDetails']['orderId']          ?? '';
    $amount        = ($answer['orderDetails']['orderTotalAmount'] ?? 0) / 100;
    $cardLast4     = $transaction['transactionDetails']['cardDetails']['pan'] ?? '****';
    $transactionId = $transaction['uuid'] ?? '';
    if ($isBrowserRedirect) { header('Location: ' . $base . '/?iz_ok=1&iz_order=' . urlencode($orderId)); exit; }
    echo json_encode(['ok' => true, 'orderId' => $orderId, 'amount' => $amount, 'cardLast4' => $cardLast4, 'transactionId' => $transactionId]);
} else {
    $errorCode = $transaction['errorCode'] ?? ($transaction['detailedStatus'] ?? 'REJECTED');
    if ($isBrowserRedirect) { header('Location: ' . $base . '/?iz_err=1'); exit; }
    echo json_encode(['ok' => false, 'error' => 'Pago no autorizado (' . $errorCode . ')']);
}
