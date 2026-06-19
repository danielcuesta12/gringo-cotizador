<?php
/**
 * pos/escpos_build.php — builder ESC/POS reutilizable y SIN auth/echo.
 * Lo usa api/mozo.php (sesión de mozo) para imprimir la PRECUENTA no fiscal.
 * (El comprobante fiscal sigue saliendo por pos/escpos.php, gateado a pos_terminal.)
 */

if (!defined('EB_ESC_INIT')) {
    define('EB_ESC_INIT',     "\x1b\x40");
    define('EB_ESC_CENTER',   "\x1b\x61\x01");
    define('EB_ESC_LEFT',     "\x1b\x61\x00");
    define('EB_ESC_BOLD_ON',  "\x1b\x45\x01");
    define('EB_ESC_BOLD_OFF', "\x1b\x45\x00");
    define('EB_ESC_DBLHW',    "\x1d\x21\x11");
    define('EB_ESC_NORMAL',   "\x1d\x21\x00");
    define('EB_LINE_WIDTH',   48);
}

if (!function_exists('ebAsciiSafe')) {
    function ebAsciiSafe(string $s): string {
        $r = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        return ($r !== false && $r !== '') ? $r : $s;
    }
    function ebSeparator(): string { return str_repeat('-', EB_LINE_WIDTH) . "\n"; }
    function ebCenter(string $s): string {
        $s = ebAsciiSafe($s); $len = mb_strlen($s);
        if ($len >= EB_LINE_WIDTH) return $s . "\n";
        return str_repeat(' ', (int)floor((EB_LINE_WIDTH - $len) / 2)) . $s . "\n";
    }
    function ebTwoCol(string $left, string $right): string {
        $left = ebAsciiSafe($left); $right = ebAsciiSafe($right);
        $rLen = mb_strlen($right); $maxL = max(1, EB_LINE_WIDTH - $rLen - 1);
        if (mb_strlen($left) > $maxL) $left = mb_substr($left, 0, $maxL - 1) . '.';
        $pad = EB_LINE_WIDTH - mb_strlen($left) - $rLen;
        return $left . str_repeat(' ', max(1, $pad)) . $right . "\n";
    }
}

/** Bytes ESC/POS de la PRECUENTA (no fiscal) a partir del detalle de la cuenta. */
function escposPrecuentaBytes(array $cuenta): string {
    $empresa = getSetting('company_name', 'El Gringo');
    $out  = EB_ESC_INIT;
    $out .= EB_ESC_CENTER . EB_ESC_BOLD_ON . EB_ESC_DBLHW . ebCenter('PRE-CUENTA') . EB_ESC_NORMAL . EB_ESC_BOLD_OFF;
    $out .= EB_ESC_CENTER . ebCenter(ebAsciiSafe($empresa));
    $out .= EB_ESC_LEFT . ebSeparator();
    $out .= 'Mesa: ' . ebAsciiSafe((string)($cuenta['mesa_numero'] ?? '')) . "\n";
    if (!empty($cuenta['num_comensales'])) $out .= 'Comensales: ' . (int)$cuenta['num_comensales'] . "\n";
    if (!empty($cuenta['mozo_nombre']))     $out .= 'Mozo: ' . ebAsciiSafe((string)$cuenta['mozo_nombre']) . "\n";
    $out .= 'Fecha: ' . date('d/m/Y H:i') . "\n";
    $out .= ebSeparator();

    $total = 0.0;
    foreach ((array)($cuenta['comandas'] ?? []) as $cmd) {
        foreach ((array)($cmd['items'] ?? []) as $it) {
            if (!empty($it['anulado'])) continue;
            $qty  = max(1, (int)($it['qty'] ?? 1));
            $base = (float)($it['precio'] ?? 0);
            $mods = 0.0;
            foreach ((array)($it['modificadores'] ?? []) as $m) $mods += (float)($m['precio'] ?? 0);
            $line = ($base + $mods) * $qty;
            $total += $line;
            $out .= ebTwoCol($qty . 'x ' . (string)($it['nombre'] ?? 'Item'), 'S/ ' . number_format($line, 2));
            foreach ((array)($it['modificadores'] ?? []) as $m) {
                $mn = (string)($m['nombre'] ?? '');
                if ($mn !== '') $out .= '   + ' . ebAsciiSafe($mn) . "\n";
            }
        }
    }
    $out .= ebSeparator();
    $out .= EB_ESC_BOLD_ON . EB_ESC_DBLHW . ebTwoCol('TOTAL', 'S/ ' . number_format($total, 2)) . EB_ESC_NORMAL . EB_ESC_BOLD_OFF;
    $out .= ebSeparator();
    $out .= EB_ESC_CENTER . ebCenter('No valido como comprobante de pago') . EB_ESC_LEFT;
    $out .= "\n\n\n" . "\x1d\x56\x00"; // feed + corte total
    return $out;
}
