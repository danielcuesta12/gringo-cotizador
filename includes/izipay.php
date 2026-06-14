<?php
/**
 * Resolución de configuración de Izipay.
 * Orden de prioridad: company_settings (admin) → .env (respaldo).
 *
 * Las claves SECRETAS (rest_pass, hmac) son SOLO-SERVIDOR: se usan en
 * create/verify/ipn y nunca se devuelven al navegador.
 *
 * Requiere config/config.php, config/database.php e includes/helpers.php cargados.
 */

if (!function_exists('izipayCfg')) {
    function izipayCfg(): array
    {
        static $cfg = null;
        if ($cfg !== null) return $cfg;

        $env = @parse_ini_file(__DIR__ . '/../.env') ?: [];
        // setting (admin) → fallback .env → default
        $get = function (string $sKey, string $eKey, string $def = '') use ($env): string {
            $v = trim((string) getSetting($sKey, ''));
            if ($v !== '') return $v;
            return (string) ($env[$eKey] ?? $def);
        };

        $mode = strtoupper($get('izipay_mode', 'IZIPAY_MODE', 'TEST'));
        if (!in_array($mode, ['TEST', 'PROD'], true)) $mode = 'TEST';
        $t = $mode === 'TEST';

        $cfg = [
            'mode'        => $mode,
            'shop_id'     => $get('izipay_shop_id', 'IZIPAY_SHOP_ID'),
            'js_url'      => $get('izipay_js_url', 'IZIPAY_JS_URL', 'https://static.micuentaweb.pe/static/js/krypton-client/V4.0/stable/kr-payment-form.min.js'),
            'rest_server' => $get('izipay_rest_server', 'IZIPAY_REST_SERVER', 'https://api.micuentaweb.pe'),
            'public_key'  => $t ? $get('izipay_public_key_test', 'IZIPAY_PUBLIC_KEY_TEST') : $get('izipay_public_key_prod', 'IZIPAY_PUBLIC_KEY_PROD'),
            // Secretas (solo-servidor)
            'rest_pass'   => $t ? $get('izipay_rest_pass_test', 'IZIPAY_REST_PASS_TEST') : $get('izipay_rest_pass_prod', 'IZIPAY_REST_PASS_PROD'),
            'hmac'        => $t ? $get('izipay_hmac_test', 'IZIPAY_HMAC_TEST') : $get('izipay_hmac_prod', 'IZIPAY_HMAC_PROD'),
        ];
        return $cfg;
    }
}

if (!function_exists('izipayConfigured')) {
    function izipayConfigured(): bool
    {
        $c = izipayCfg();
        return $c['shop_id'] !== '' && $c['public_key'] !== '';
    }
}
