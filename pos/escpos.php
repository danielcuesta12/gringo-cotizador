<?php
/**
 * pos/escpos.php
 * Genera bytes ESC/POS para un pedido POS y los devuelve como base64.
 * Destino: Epson TM-m30 (80 mm, 48 chars/línea Font A) vía RawBT.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
requirePermission('pos_terminal');

$id = cleanInt($_GET['id'] ?? 0);
$p  = $id ? Database::fetch("SELECT * FROM pedidos WHERE id = ? AND origen = 'pos'", [$id]) : null;
if (!$p) { http_response_code(404); echo ''; exit; }

$items = json_decode($p['items_json'] ?? '[]', true) ?: [];

// ── Constantes ──────────────────────────────────────────────────────────────
define('ESC_INIT',     "\x1b\x40");
define('ESC_CENTER',   "\x1b\x61\x01");
define('ESC_LEFT',     "\x1b\x61\x00");
define('ESC_RIGHT',    "\x1b\x61\x02");
define('ESC_BOLD_ON',  "\x1b\x45\x01");
define('ESC_BOLD_OFF', "\x1b\x45\x00");
define('ESC_DBLHW',    "\x1d\x21\x11"); // double height + width
define('ESC_DBLH',     "\x1d\x21\x01"); // double height only
define('ESC_NORMAL',   "\x1d\x21\x00");
define('LINE_WIDTH',   48);

// ── Helpers de texto ────────────────────────────────────────────────────────

/**
 * Transliterar a ASCII para evitar caracteres corruptos en CP437/CP850.
 */
function asciiSafe(string $s): string {
    $r = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    return ($r !== false && $r !== '') ? $r : $s;
}

/**
 * Imprimir una línea de separador de 48 guiones.
 */
function separator(): string {
    return str_repeat('-', LINE_WIDTH) . "\n";
}

/**
 * Fila de dos columnas: texto izquierdo + precio derecho, ancho total 48.
 * Trunca el texto izquierdo si es necesario para que quepa el precio.
 */
function twoCol(string $left, string $right): string {
    $left  = asciiSafe($left);
    $right = asciiSafe($right);
    $rLen  = mb_strlen($right);
    $maxL  = LINE_WIDTH - $rLen - 1; // un espacio de separación
    if ($maxL < 1) $maxL = 1;
    if (mb_strlen($left) > $maxL) {
        $left = mb_substr($left, 0, $maxL - 1) . '.';
    }
    $pad = LINE_WIDTH - mb_strlen($left) - $rLen;
    return $left . str_repeat(' ', max(1, $pad)) . $right . "\n";
}

/**
 * Centrar una cadena en 48 chars.
 */
function centerLine(string $s): string {
    $s    = asciiSafe($s);
    $len  = mb_strlen($s);
    if ($len >= LINE_WIDTH) return $s . "\n";
    $pad  = (int)(floor((LINE_WIDTH - $len) / 2));
    return str_repeat(' ', $pad) . $s . "\n";
}

// ── Logo como imagen raster ESC/POS ─────────────────────────────────────────

function escposLogo(): string {
    if (!function_exists('imagecreatefrompng') && !function_exists('imagecreatefromjpeg')) {
        return '';
    }

    // Buscar fuente de imagen
    $rel = getSetting('company_logo', '');
    $src = '';
    if ($rel) {
        $candidate = rtrim(UPLOAD_PATH, '/') . '/' . ltrim($rel, '/');
        if (file_exists($candidate)) $src = $candidate;
    }
    if (!$src) {
        // Fallback: favicon
        $fav = rtrim(APP_PATH, '/') . '/assets/img/favicon-180.png';
        if (file_exists($fav)) $src = $fav;
    }
    if (!$src) return '';

    try {
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $im  = null;
        if ($ext === 'png' && function_exists('imagecreatefrompng')) {
            $im = @imagecreatefrompng($src);
        } elseif (in_array($ext, ['jpg','jpeg']) && function_exists('imagecreatefromjpeg')) {
            $im = @imagecreatefromjpeg($src);
        } elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $im = @imagecreatefromwebp($src);
        } elseif ($ext === 'gif' && function_exists('imagecreatefromgif')) {
            $im = @imagecreatefromgif($src);
        }
        if (!$im) return '';

        $origW = imagesx($im);
        $origH = imagesy($im);
        if ($origW <= 0 || $origH <= 0) { imagedestroy($im); return ''; }

        // Escalar a ancho múltiplo de 8, máx 384 px
        $targetW = 256; // moderado para que el base64 no sea enorme
        if ($origW < $targetW) $targetW = $origW;
        // Redondear al múltiplo de 8 más cercano hacia abajo
        $targetW = (int)(floor($targetW / 8) * 8);
        if ($targetW < 8) $targetW = 8;
        $targetH = (int)round($origH * $targetW / $origW);
        if ($targetH < 1) $targetH = 1;

        // Crear canvas blanco para compositing (alfa)
        $canvas = imagecreatetruecolor($targetW, $targetH);
        if (!$canvas) { imagedestroy($im); return ''; }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $im, 0, 0, 0, 0, $targetW, $targetH, $origW, $origH);
        imagedestroy($im);

        // Convertir a 1-bit: luminance < 128 → negro (bit=1)
        $widthBytes = (int)($targetW / 8);
        $data = '';
        for ($y = 0; $y < $targetH; $y++) {
            for ($bx = 0; $bx < $widthBytes; $bx++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x   = $bx * 8 + $bit;
                    $rgb = imagecolorat($canvas, $x, $y);
                    $r   = ($rgb >> 16) & 0xFF;
                    $g   = ($rgb >> 8)  & 0xFF;
                    $b   = $rgb & 0xFF;
                    // Luminance perceptual
                    $lum = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
                    if ($lum < 128) {
                        $byte |= (0x80 >> $bit); // MSB first
                    }
                }
                $data .= chr($byte);
            }
        }
        imagedestroy($canvas);

        // GS v 0 raster bit image
        $xL = $widthBytes & 0xFF;
        $xH = ($widthBytes >> 8) & 0xFF;
        $yL = $targetH & 0xFF;
        $yH = ($targetH >> 8) & 0xFF;
        $cmd = "\x1d\x76\x30\x00" . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $data;

        return ESC_CENTER . $cmd . "\n";

    } catch (\Throwable $e) {
        return '';
    }
}

// ── Construir bytes ESC/POS ──────────────────────────────────────────────────
$esc = '';
$esc .= ESC_INIT;

// 1. Logo (o nombre en grande si no hay)
$logoBytes = escposLogo();
if ($logoBytes !== '') {
    $esc .= $logoBytes;
} else {
    $esc .= ESC_CENTER . ESC_BOLD_ON . ESC_DBLHW;
    $esc .= asciiSafe(getSetting('company_name', 'El Gringo Burger Joint')) . "\n";
    $esc .= ESC_NORMAL . ESC_BOLD_OFF;
}

// 2. Info empresa (centrada, tamaño normal)
$esc .= ESC_CENTER;
$ruc  = getSetting('company_ruc', '');
$addr = getSetting('company_address', '');
$tel  = getSetting('company_phone', '');
if ($ruc)  $esc .= "RUC: " . asciiSafe($ruc)  . "\n";
if ($addr) $esc .= asciiSafe($addr) . "\n";
if ($tel)  $esc .= "Tel: " . asciiSafe($tel)   . "\n";

// 3. Separador
$esc .= ESC_LEFT;
$esc .= separator();

// 4. Datos del pedido
$numPad = str_pad((string)$id, 4, '0', STR_PAD_LEFT);
$esc .= "Pedido #" . $numPad . "\n";
$esc .= asciiSafe(formatDatetime($p['created_at'])) . "\n";

// Cliente
$tipoDoc = $p['cliente_tipo'] ?? '';
$numDoc  = $p['cliente_documento'] ?? '';
$nombre  = $p['cliente_razon_social'] ?: ($p['cliente_nombre'] ?? '');
if ($numDoc || $nombre) {
    $docLabel = ($tipoDoc === 'ruc') ? 'RUC' : (($tipoDoc === 'dni') ? 'DNI' : 'Doc');
    if ($numDoc)  $esc .= $docLabel . ': ' . asciiSafe($numDoc) . "\n";
    if ($nombre)  $esc .= asciiSafe($nombre) . "\n";
}

// Comprobante
$compLabels = ['ticket' => 'Ticket', 'boleta' => 'Boleta', 'factura' => 'Factura'];
$compLabel  = $compLabels[$p['comprobante_tipo'] ?? 'ticket'] ?? 'Ticket';
$esc .= $compLabel . "\n";

// 5. Separador
$esc .= separator();

// 6. Ítems
foreach ($items as $it) {
    $qty     = (int)($it['qty'] ?? 1);
    $base    = (float)($it['precio'] ?? 0);
    $modsArr = (array)($it['modificadores'] ?? []);
    $modsSum = 0;
    foreach ($modsArr as $m) { $modsSum += (float)($m['precio'] ?? 0); }
    $lineUnit = $base + $modsSum;
    $lineTot  = $lineUnit * $qty;

    // Descuento por ítem
    $dt = $it['desc_tipo'] ?? null;
    $dv = (float)($it['desc_valor'] ?? 0);
    $discAmt = 0;
    if ($dt === 'porcentaje' && $dv > 0) {
        $discAmt = $lineTot * min(100, max(0, $dv)) / 100;
        $lineTot -= $discAmt;
    } elseif ($dt === 'monto' && $dv > 0) {
        $discAmt = min($lineTot, max(0, $dv));
        $lineTot -= $discAmt;
    }

    $nombre = $qty . 'x ' . ($it['nombre'] ?? '');
    $esc .= twoCol($nombre, formatMoney($lineTot));

    // Modificadores
    foreach ($modsArr as $m) {
        $mNombre = '  + ' . ($m['nombre'] ?? '');
        $esc .= asciiSafe($mNombre) . "\n";
    }

    // Nota del ítem
    $nota = trim($it['nota'] ?? '');
    if ($nota) {
        $esc .= asciiSafe('  ' . $nota) . "\n";
    }

    // Descuento por ítem
    if ($discAmt > 0) {
        $esc .= asciiSafe('  Desc. -' . formatMoney($discAmt)) . "\n";
    }
}

// 7. Separador
$esc .= separator();

// 8. Descuento global
$descMonto = (float)($p['descuento_monto'] ?? 0);
if ($descMonto > 0) {
    $esc .= twoCol('Descuento', '-' . formatMoney($descMonto));
}

// 9. TOTAL (bold + double height+width)
$totalStr = formatMoney((float)$p['total']);
$esc .= ESC_BOLD_ON . ESC_DBLHW;
$esc .= twoCol('TOTAL', $totalStr);
$esc .= ESC_NORMAL . ESC_BOLD_OFF;

// 10. Método de pago y notas
$metodo = asciiSafe($p['metodo_pago'] ?? '');
if ($metodo) {
    $esc .= 'Pago: ' . $metodo . "\n";
}
$notas = trim($p['notas_pos'] ?? '');
if ($notas) {
    $esc .= separator();
    // Wrap notas en líneas de LINE_WIDTH
    $notasAscii = asciiSafe($notas);
    $wrapped    = wordwrap($notasAscii, LINE_WIDTH, "\n", true);
    $esc .= $wrapped . "\n";
}

// 11. Feed + footer centrado
$esc .= "\x1b\x64\x01"; // feed 1 línea
$esc .= ESC_CENTER;
$esc .= asciiSafe('Gracias por tu compra!') . "\n";
$esc .= asciiSafe('@elgringoburger') . "\n";

// 12. Corte (feed 4 + corte parcial)
$esc .= "\x1b\x64\x04" . "\x1d\x56\x42\x00";

// Devolver como base64
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo base64_encode($esc);
exit;
