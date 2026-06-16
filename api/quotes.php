<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$action = clean($_GET['action'] ?? $_POST['action'] ?? '');

switch ($action) {

    // Nombrar / marcar atendida una cotización de evento (desde el calendario o salida a evento)
    case 'set_evento':
        verifyCsrf();
        if (!can('calendar') && !can('events') && !can('quotes') && !can('inv_evento')) {
            echo json_encode(['ok' => false, 'error' => 'Sin permisos']); break;
        }
        $id       = cleanInt($_POST['id'] ?? 0);
        $nombre   = clean($_POST['evento_nombre'] ?? '');
        $atendido = !empty($_POST['evento_atendido']) ? 1 : 0;
        if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'ID inválido']); break; }
        try {
            Database::execute("UPDATE quotes SET evento_nombre=?, evento_atendido=? WHERE id=?", [$nombre ?: null, $atendido, $id]);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Falta aplicar install/50_quotes_evento.sql']);
        }
        break;

    // Renombrar / marcar atendido un evento libre (agenda) — desde el calendario
    case 'set_agenda':
        verifyCsrf();
        if (!can('calendar') && !can('events') && !can('quotes') && !can('inv_evento')) {
            echo json_encode(['ok' => false, 'error' => 'Sin permisos']); break;
        }
        $id       = cleanInt($_POST['id'] ?? 0);
        $titulo   = clean($_POST['titulo'] ?? '');
        $atendido = !empty($_POST['atendido']) ? 1 : 0;
        if ($id <= 0 || $titulo === '') { echo json_encode(['ok' => false, 'error' => 'Falta el nombre']); break; }
        $agOk = (bool) Database::fetch("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='agenda' AND column_name='atendido'");
        try {
            if ($agOk) Database::execute("UPDATE agenda SET titulo=?, atendido=? WHERE id=?", [$titulo, $atendido, $id]);
            else       Database::execute("UPDATE agenda SET titulo=? WHERE id=?", [$titulo, $id]);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'No se pudo guardar']);
        }
        break;

    // Buscar productos activos
    case 'search_products':
        $q    = clean($_GET['q'] ?? '');
        $cat  = cleanInt($_GET['cat'] ?? 0);
        $w    = ['p.active = 1'];
        $p    = [];
        if ($q)   { $w[] = '(p.name LIKE ? OR p.description LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
        if ($cat) { $w[] = 'p.category_id = ?'; $p[] = $cat; }
        $where = implode(' AND ', $w);
        $rows  = Database::fetchAll(
            "SELECT p.id, p.name, p.description, p.price_per_person, p.price_per_event,
                    p.image, c.name as category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE $where
             ORDER BY c.sort_order, p.sort_order, p.name
             LIMIT 30",
            $p
        );
        echo json_encode(['ok' => true, 'data' => $rows]);
        break;

    // Búsqueda de clientes (para select2 / autocomplete)
    case 'search_clients':
        $q   = clean($_GET['q'] ?? '');
        $p   = [];
        $w   = ['active = 1'];
        if ($q) { $w[] = '(name LIKE ? OR ruc_dni LIKE ? OR email LIKE ?)'; $s = "%$q%"; $p = [$s,$s,$s]; }
        $where = implode(' AND ', $w);
        $rows  = Database::fetchAll(
            "SELECT id, name, type, ruc_dni, email, phone FROM clients WHERE $where ORDER BY name LIMIT 20",
            $p
        );
        echo json_encode(['ok' => true, 'data' => $rows]);
        break;

    // Obtener categorías activas
    case 'categories':
        $rows = Database::fetchAll(
            "SELECT id, name FROM categories WHERE active = 1 ORDER BY sort_order, name"
        );
        echo json_encode(['ok' => true, 'data' => $rows]);
        break;

    // Obtener un cliente por ID
    case 'get_client':
        $id  = cleanInt($_GET['id'] ?? 0);
        $row = Database::fetch("SELECT * FROM clients WHERE id = ? AND active = 1", [$id]);
        echo json_encode(['ok' => (bool)$row, 'data' => $row]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Acción no válida']);
}
