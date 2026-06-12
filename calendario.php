<?php
// ============================================================
// calendario.php — Feed iCalendar (ICS) público gateado por token
// Suscribible desde el celular (Apple / Google). Se auto-actualiza.
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$token = $_GET['token'] ?? '';
$real  = getSetting('ics_token', '');
if ($real === '' || $token === '' || !hash_equals($real, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Acceso no autorizado';
    exit;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="elgringo.ics"');

// ------------------------------------------------------------
// Helpers ICS
// ------------------------------------------------------------

// Escapa texto para un valor de propiedad ICS (RFC 5545).
function icsEsc(string $s): string
{
    // Normaliza saltos de línea a \n y elimina otros caracteres de control.
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace("\n", '\\n', $s);
    $s = str_replace(',', '\\,', $s);
    $s = str_replace(';', '\\;', $s);
    return $s;
}

// Plegado de líneas: máximo 73 octetos por línea, continuación con CRLF + espacio.
function icsFold(string $line): string
{
    $out   = '';
    $chunk = '';
    $len   = 0;
    $n     = strlen($line); // por octetos
    for ($i = 0; $i < $n; $i++) {
        // No partir en medio de una secuencia UTF-8 multibyte.
        $c   = $line[$i];
        $ord = ord($c);
        $seq = $c;
        if ($ord >= 0xF0) { $seq .= substr($line, $i + 1, 3); $i += 3; }
        elseif ($ord >= 0xE0) { $seq .= substr($line, $i + 1, 2); $i += 2; }
        elseif ($ord >= 0xC0) { $seq .= substr($line, $i + 1, 1); $i += 1; }
        $slen = strlen($seq);
        if ($len + $slen > 73) {
            $out  .= $chunk . "\r\n ";
            $chunk = $seq;
            $len   = 1 + $slen; // el espacio inicial cuenta
        } else {
            $chunk .= $seq;
            $len   += $slen;
        }
    }
    $out .= $chunk;
    return $out;
}

// Emite una propiedad (plegada) al buffer con CRLF final.
function icsLine(array &$buf, string $line): void
{
    $buf[] = icsFold($line);
}

// Fecha+hora local America/Lima (UTC-5, sin DST) -> instante UTC en formato ICS.
// Se interpreta la hora EXPLÍCITAMENTE en America/Lima (independiente del
// timezone por defecto del runtime, que en esta app es Lima) y se convierte a UTC.
// $addHours permite calcular DTEND (p.ej. +2h) sobre la misma base.
function icsUtc(string $fecha, string $hora, int $addHours = 0): string
{
    static $lima = null, $utc = null;
    if ($lima === null) { $lima = new DateTimeZone('America/Lima'); $utc = new DateTimeZone('UTC'); }
    $dt = new DateTime($fecha . ' ' . $hora, $lima);
    if ($addHours !== 0) $dt->modify(($addHours >= 0 ? '+' : '') . $addHours . ' hours');
    $dt->setTimezone($utc);
    return $dt->format('Ymd\THis\Z');
}

// Fecha local -> VALUE=DATE (YYYYMMDD).
function icsDate(string $fecha): string
{
    return date('Ymd', strtotime($fecha));
}

// Siguiente día (DTEND exclusivo en eventos de día completo).
function icsNextDay(string $fecha): string
{
    return date('Ymd', strtotime($fecha . ' +1 day'));
}

$DTSTAMP = gmdate('Ymd\THis\Z');

// ------------------------------------------------------------
// Datos
// ------------------------------------------------------------

try {
    $quotes = Database::fetchAll(
        "SELECT q.id, q.quote_number, q.status, q.origin,
                q.event_date, q.event_type, q.event_time,
                q.event_location, q.num_people, q.total,
                c.name AS cliente
         FROM quotes q
         JOIN clients c ON c.id = q.client_id
         WHERE q.status IN ('enviada','aceptada')
           AND q.event_date IS NOT NULL AND q.event_date <> ''
         ORDER BY q.event_date ASC"
    );
} catch (Exception $e) {
    $quotes = [];
}

try {
    $agenda = Database::fetchAll(
        "SELECT id, fecha, fecha_fin, titulo, hora, hora_fin, lugar, notas
         FROM agenda ORDER BY fecha ASC"
    );
} catch (Exception $e) {
    $agenda = [];
}

// ------------------------------------------------------------
// Construcción del calendario
// ------------------------------------------------------------

$mid = mb_convert_encoding('&#183;', 'UTF-8', 'HTML-ENTITIES'); // ·

$buf = [];
$buf[] = 'BEGIN:VCALENDAR';
$buf[] = 'VERSION:2.0';
$buf[] = 'PRODID:-//El Gringo//Calendario//ES';
$buf[] = 'CALSCALE:GREGORIAN';
$buf[] = 'METHOD:PUBLISH';
$buf[] = 'X-WR-CALNAME:El Gringo ' . $mid . ' Eventos';
$buf[] = 'NAME:El Gringo ' . $mid . ' Eventos';
$buf[] = 'X-WR-TIMEZONE:America/Lima';
$buf[] = 'X-PUBLISHED-TTL:PT1H';
$buf[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';

// ---- Cotizaciones / eventos directos ----
foreach ($quotes as $q) {
    $fecha = trim((string)$q['event_date']);
    if ($fecha === '') continue;

    $cliente   = (string)($q['cliente'] ?? '');
    $eventType = trim((string)($q['event_type'] ?? ''));
    $people    = (int)($q['num_people'] ?? 0);
    $hora      = trim((string)($q['event_time'] ?? ''));
    $lugar     = trim((string)($q['event_location'] ?? ''));
    $total     = (float)($q['total'] ?? 0);

    // SUMMARY
    $summary = $cliente;
    if ($eventType !== '') $summary .= ' ' . $mid . ' ' . $eventType;
    if ($people > 0)       $summary .= ' ' . $mid . ' ' . $people . ' pers';

    // Estado legible
    if ($q['origin'] === 'event') $estado = 'Evento';
    elseif ($q['status'] === 'aceptada') $estado = 'Aceptada';
    else $estado = 'Enviada';

    // DESCRIPTION
    $descParts = [];
    if (!empty($q['quote_number'])) $descParts[] = (string)$q['quote_number'];
    $descParts[] = 'Estado: ' . $estado;
    $descParts[] = 'Total: ' . formatMoney($total);
    $desc = implode("\n", $descParts);

    $buf[] = 'BEGIN:VEVENT';
    icsLine($buf, 'UID:quote-' . (int)$q['id'] . '@elgringo.pe');
    icsLine($buf, 'DTSTAMP:' . $DTSTAMP);
    if ($hora !== '' && preg_match('/\d{1,2}:\d{2}/', $hora, $hm)) {
        $hhmm = $hm[0];
        icsLine($buf, 'DTSTART:' . icsUtc($fecha, $hhmm));
        // DTEND = +2 horas (los eventos no tienen hora de fin)
        icsLine($buf, 'DTEND:' . icsUtc($fecha, $hhmm, 2));
    } else {
        icsLine($buf, 'DTSTART;VALUE=DATE:' . icsDate($fecha));
        icsLine($buf, 'DTEND;VALUE=DATE:' . icsNextDay($fecha));
    }
    icsLine($buf, 'SUMMARY:' . icsEsc($summary));
    if ($lugar !== '') icsLine($buf, 'LOCATION:' . icsEsc($lugar));
    icsLine($buf, 'DESCRIPTION:' . icsEsc($desc));
    $buf[] = 'END:VEVENT';
}

// ---- Agenda (eventos sin venta) ----
foreach ($agenda as $a) {
    $fecha = trim((string)($a['fecha'] ?? ''));
    if ($fecha === '') continue;
    $fechaFin = trim((string)($a['fecha_fin'] ?? ''));
    if ($fechaFin === '') $fechaFin = $fecha;
    if ($fechaFin < $fecha) $fechaFin = $fecha;

    $titulo  = (string)($a['titulo'] ?? '');
    $lugar   = trim((string)($a['lugar'] ?? ''));
    $notas   = trim((string)($a['notas'] ?? ''));
    $hora    = trim((string)($a['hora'] ?? ''));
    $horaFin = trim((string)($a['hora_fin'] ?? ''));

    $descBase = ($notas !== '' ? $notas . ' ' : '') . '(Agenda ' . $mid . ' sin venta)';

    if ($hora !== '' && preg_match('/\d{1,2}:\d{2}/', $hora, $hm)) {
        // Un VEVENT por cada día del rango.
        $hhmm = $hm[0];
        $hasEnd = ($horaFin !== '' && preg_match('/\d{1,2}:\d{2}/', $horaFin, $em));
        $endHHMM = $hasEnd ? $em[0] : '';

        $d = $fecha;
        while ($d <= $fechaFin) {
            $ymd = icsDate($d);
            $buf[] = 'BEGIN:VEVENT';
            icsLine($buf, 'UID:agenda-' . (int)$a['id'] . '-' . $ymd . '@elgringo.pe');
            icsLine($buf, 'DTSTAMP:' . $DTSTAMP);
            icsLine($buf, 'DTSTART:' . icsUtc($d, $hhmm));
            if ($hasEnd) {
                icsLine($buf, 'DTEND:' . icsUtc($d, $endHHMM));
            } else {
                icsLine($buf, 'DTEND:' . icsUtc($d, $hhmm, 2));
            }
            icsLine($buf, 'SUMMARY:' . icsEsc($titulo));
            if ($lugar !== '') icsLine($buf, 'LOCATION:' . icsEsc($lugar));
            icsLine($buf, 'DESCRIPTION:' . icsEsc($descBase));
            $buf[] = 'END:VEVENT';
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
    } else {
        // Un único VEVENT de día completo abarcando el rango.
        $buf[] = 'BEGIN:VEVENT';
        icsLine($buf, 'UID:agenda-' . (int)$a['id'] . '-' . icsDate($fecha) . '@elgringo.pe');
        icsLine($buf, 'DTSTAMP:' . $DTSTAMP);
        icsLine($buf, 'DTSTART;VALUE=DATE:' . icsDate($fecha));
        icsLine($buf, 'DTEND;VALUE=DATE:' . icsNextDay($fechaFin));
        icsLine($buf, 'SUMMARY:' . icsEsc($titulo));
        if ($lugar !== '') icsLine($buf, 'LOCATION:' . icsEsc($lugar));
        icsLine($buf, 'DESCRIPTION:' . icsEsc($descBase));
        $buf[] = 'END:VEVENT';
    }
}

$buf[] = 'END:VCALENDAR';

echo implode("\r\n", $buf) . "\r\n";
