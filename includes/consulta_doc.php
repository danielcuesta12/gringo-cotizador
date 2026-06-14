<?php
/**
 * Consulta de DNI (RENIEC) y RUC (SUNAT) vía proveedor externo.
 * Por defecto apis.net.pe (v2), pero los endpoints son configurables en settings
 * para poder cambiar de proveedor sin tocar código.
 *
 * Requiere config/config.php, config/database.php e includes/helpers.php cargados.
 * Nunca lanza excepciones: siempre retorna un array.
 */

if (!function_exists('consultaDocConfigurado')) {
    /** True si hay token de consulta configurado. */
    function consultaDocConfigurado(): bool
    {
        return trim((string) getSetting('doc_api_token', '')) !== '';
    }
}

if (!function_exists('consultarDocumento')) {
    /**
     * @param string $tipo   'dni' | 'ruc'
     * @param string $numero documento (se limpia a solo dígitos)
     * @return array{ok:bool, nombre?:string, direccion?:string, estado?:string, error?:string}
     */
    function consultarDocumento(string $tipo, string $numero): array
    {
        $tipo   = strtolower(trim($tipo));
        $numero = preg_replace('/\D/', '', $numero);

        if ($tipo === 'dni') {
            if (strlen($numero) !== 8)  return ['ok' => false, 'error' => 'El DNI debe tener 8 dígitos'];
        } elseif ($tipo === 'ruc') {
            if (strlen($numero) !== 11) return ['ok' => false, 'error' => 'El RUC debe tener 11 dígitos'];
        } else {
            return ['ok' => false, 'error' => 'Tipo de documento no válido'];
        }

        $token = trim((string) getSetting('doc_api_token', ''));
        if ($token === '') {
            return ['ok' => false, 'error' => 'Falta configurar el token de consulta en Facturación'];
        }

        // Endpoints configurables. {n} = número de documento.
        $defDni = 'https://api.apis.net.pe/v2/reniec/dni?numero={n}';
        $defRuc = 'https://api.apis.net.pe/v2/sunat/ruc?numero={n}';
        if ($tipo === 'dni') {
            $urlTpl = trim((string) getSetting('doc_api_dni_url', $defDni));
            if ($urlTpl === '') $urlTpl = $defDni;
        } else {
            $urlTpl = trim((string) getSetting('doc_api_ruc_url', $defRuc));
            if ($urlTpl === '') $urlTpl = $defRuc;
        }
        // {n} = número de documento; {token} = token (para proveedores que lo
        // pasan en la query, como apiperu.dev). El token también va en el header.
        $url = str_replace(['{n}', '{token}'], [urlencode($numero), urlencode($token)], $urlTpl);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                    'Referer: ' . (defined('APP_URL') ? APP_URL : ''),
                ],
            ]);
            $raw  = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($raw === false || $err !== '') {
                return ['ok' => false, 'error' => 'No se pudo contactar al servicio de consulta'];
            }

            $d = json_decode($raw, true);
            if (!is_array($d)) {
                return ['ok' => false, 'error' => 'Respuesta inválida del servicio (HTTP ' . $code . ')'];
            }
            if ($code === 401 || $code === 403) {
                return ['ok' => false, 'error' => 'Token inválido o sin saldo en el proveedor'];
            }
            if ($code === 404 || $code === 422) {
                return ['ok' => false, 'error' => ($tipo === 'dni' ? 'DNI' : 'RUC') . ' no encontrado'];
            }
            if ($code >= 400) {
                $m = $d['message'] ?? $d['error'] ?? ('Error ' . $code);
                return ['ok' => false, 'error' => (string) $m];
            }
            // Algunos proveedores (apiperu.dev) responden HTTP 200 con success=false.
            if (isset($d['success']) && $d['success'] === false) {
                return ['ok' => false, 'error' => (string) ($d['message'] ?? 'No encontrado')];
            }

            if ($tipo === 'dni') {
                // apis.net.pe v2: nombres, apellidoPaterno, apellidoMaterno
                $nom = trim(
                    ($d['nombres'] ?? '') . ' ' .
                    ($d['apellidoPaterno'] ?? '') . ' ' .
                    ($d['apellidoMaterno'] ?? '')
                );
                if ($nom === '') $nom = trim((string) ($d['nombreCompleto'] ?? $d['nombre'] ?? ''));
                if ($nom === '') return ['ok' => false, 'error' => 'DNI sin datos'];
                return ['ok' => true, 'nombre' => $nom];
            }

            // RUC
            $nom = trim((string) ($d['razonSocial'] ?? $d['nombre'] ?? $d['razon_social'] ?? ''));
            $dir = trim((string) ($d['direccion'] ?? $d['direccionCompleta'] ?? ''));
            $est = trim((string) ($d['estado'] ?? ''));
            if ($nom === '') return ['ok' => false, 'error' => 'RUC sin datos'];
            return ['ok' => true, 'nombre' => $nom, 'direccion' => $dir, 'estado' => $est];

        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Error interno en la consulta'];
        }
    }
}
