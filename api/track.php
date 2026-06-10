<?php
// Ingesta de eventos de analítica de páginas públicas. Anónimo, fire-and-forget.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$EVENTS = ['page_view','link_click','product_view','add_to_cart','checkout_open','order_placed','search'];
$PAGES  = ['landing','carta','menu','solicitud'];

$event = $body['event'] ?? '';
if (!in_array($event, $EVENTS, true)) { http_response_code(204); exit; }

$page    = in_array($body['page'] ?? '', $PAGES, true) ? $body['page'] : null;
$ubi     = (int)($body['ubicacion_id'] ?? 0) ?: null;
$src     = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $body['src'] ?? ''), 0, 60) ?: null;
$session = substr(preg_replace('/[^a-zA-Z0-9]/', '', $body['sid'] ?? ''), 0, 40) ?: null;
$ref     = isset($body['ref']) ? substr(parse_url($body['ref'], PHP_URL_HOST) ?? '', 0, 255) : null;

$ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
$device  = preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $ua) ? 'mobile' : 'desktop';

// meta limitada (máx 1KB serializada)
$meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
$metaJson = $meta ? substr(json_encode($meta, JSON_UNESCAPED_UNICODE), 0, 1000) : null;

try {
    Database::insert(
        "INSERT INTO analytics_events (event_type,page,ubicacion_id,src,referrer,device,session_id,meta_json) VALUES (?,?,?,?,?,?,?,?)",
        [$event, $page, $ubi, $src, $ref, $device, $session, $metaJson]
    );
} catch (Exception $e) { /* tabla no creada aún: ignorar */ }

http_response_code(204);
