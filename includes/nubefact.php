<?php
/**
 * Motor de emisión de comprobantes electrónicos vía NubeFact (OSE SUNAT Perú).
 *
 * Requiere que config/config.php, config/database.php e includes/helpers.php
 * ya estén cargados (getSetting/setSetting, Database::fetch/execute).
 *
 * Reglas fiscales:
 *   - Los precios de los pedidos YA INCLUYEN IGV 18%.
 *   - El `total` del pedido es el monto autoritativo cobrado (con IGV).
 *   - El motor NUNCA lanza excepciones al llamador: siempre retorna un array.
 *   - Idempotente: si ya está emitido, no re-emite.
 *
 * No imprime nada (se invoca server-side).
 */

if (!function_exists('nubefactConfigurado')) {
    /**
     * True si url + token de NubeFact están configurados.
     */
    function nubefactConfigurado(): bool
    {
        $url   = trim((string) getSetting('nubefact_url', ''));
        $token = trim((string) getSetting('nubefact_token', ''));
        return $url !== '' && $token !== '';
    }
}

if (!function_exists('nubefactSerieSettingKey')) {
    /**
     * Devuelve la clave de setting del correlativo para un tipo de comprobante.
     * factura → nubefact_num_factura ; boleta → nubefact_num_boleta
     */
    function nubefactSerieSettingKey(string $compTipo): string
    {
        return $compTipo === 'factura' ? 'nubefact_num_factura' : 'nubefact_num_boleta';
    }
}

if (!function_exists('nubefactEmitir')) {
    /**
     * Emite el comprobante de un pedido y guarda el resultado.
     *
     * @return array{ok:bool,estado?:string,serie?:string,numero?:int,pdf?:string,error?:string}
     */
    function nubefactEmitir(int $pedidoId): array
    {
        try {
            // ---- 1. Cargar pedido --------------------------------------
            $pedido = Database::fetch("SELECT * FROM pedidos WHERE id = ?", [$pedidoId]);
            if (!$pedido) {
                return ['ok' => false, 'error' => 'Pedido no encontrado'];
            }

            $compTipo = $pedido['comprobante_tipo'] ?? '';
            if (!in_array($compTipo, ['boleta', 'factura'], true)) {
                return ['ok' => false, 'error' => 'No aplica'];
            }

            // Idempotencia: ya emitido → devolver lo existente sin re-emitir.
            if (($pedido['comprobante_estado'] ?? '') === 'emitido') {
                return [
                    'ok'     => true,
                    'estado' => 'emitido',
                    'serie'  => $pedido['comprobante_serie'],
                    'numero' => (int) $pedido['comprobante_numero'],
                    'pdf'    => $pedido['comprobante_pdf'],
                ];
            }

            // ---- 2. Config NubeFact ------------------------------------
            $url   = trim((string) getSetting('nubefact_url', ''));
            $token = trim((string) getSetting('nubefact_token', ''));
            if ($url === '' || $token === '') {
                return ['ok' => false, 'error' => 'Falta configurar NubeFact'];
            }

            // ---- 3. Tipo / serie / numero ------------------------------
            $tipo = ($compTipo === 'factura') ? 1 : 2; // 1 factura, 2 boleta

            if ($compTipo === 'factura') {
                $serie    = trim((string) getSetting('nubefact_serie_factura', 'F001'));
                $serie    = $serie !== '' ? $serie : 'F001';
                $numKey   = 'nubefact_num_factura';
            } else {
                $serie    = trim((string) getSetting('nubefact_serie_boleta', 'B001'));
                $serie    = $serie !== '' ? $serie : 'B001';
                $numKey   = 'nubefact_num_boleta';
            }
            $numero = (int) getSetting($numKey, '1');
            if ($numero < 1) $numero = 1;

            // ---- 4. Items + IGV (precios incluyen IGV 18%) -------------
            $items = json_decode($pedido['items_json'] ?? '[]', true);
            if (!is_array($items) || count($items) === 0) {
                return ['ok' => false, 'error' => 'El pedido no tiene ítems'];
            }

            // 4a. Calcular bruto (con IGV) por línea, aplicando descuento de línea.
            $lineas   = [];   // estructura intermedia por línea
            $subBruto = 0.0;
            foreach ($items as $it) {
                $qty = (float) ($it['qty'] ?? 0);
                if ($qty <= 0) $qty = 1;
                $precio = (float) ($it['precio'] ?? 0);

                $modSum = 0.0;
                if (!empty($it['modificadores']) && is_array($it['modificadores'])) {
                    foreach ($it['modificadores'] as $m) {
                        $modSum += (float) ($m['precio'] ?? 0);
                    }
                }

                $bruto = $qty * ($precio + $modSum); // con IGV, antes de descuento

                // Descuento de línea
                $descTipo  = $it['desc_tipo']  ?? '';
                $descValor = (float) ($it['desc_valor'] ?? 0);
                if ($descTipo === 'porcentaje' && $descValor > 0) {
                    $bruto = $bruto * (1 - $descValor / 100);
                } elseif ($descTipo === 'monto' && $descValor > 0) {
                    $bruto = $bruto - $descValor;
                }
                if ($bruto < 0) $bruto = 0.0; // floor 0

                $lineas[] = [
                    'qty'         => $qty,
                    'descripcion' => (string) ($it['nombre'] ?? 'Item'),
                    'codigo'      => (string) ($it['id'] ?? '001'),
                    'bruto'       => $bruto,
                ];
                $subBruto += $bruto;
            }

            // 4b. Escalar para que la suma de líneas iguale el total cobrado
            // (el `total` del pedido ya refleja el descuento global, si lo hubo).
            $pedidoTotal = round((float) ($pedido['total'] ?? 0), 2);
            $escala = ($subBruto > 0) ? ($pedidoTotal / $subBruto) : 1.0;

            // 4c. Construir items NubeFact y acumular gravada / igv
            $itemsPayload  = [];
            $total_gravada = 0.0;
            $total_igv     = 0.0;
            $sumGi         = 0.0; // suma de g_i escalados (con IGV) para chequeo

            foreach ($lineas as $ln) {
                $qty = $ln['qty'];
                $gi  = round($ln['bruto'] * $escala, 2); // bruto escalado con IGV
                if ($gi < 0) $gi = 0.0;

                $valorLinea = round($gi / 1.18, 2);      // sin IGV
                $igvLinea   = round($gi - $valorLinea, 2);

                $precio_unitario = $qty > 0 ? round($gi / $qty, 2) : $gi;          // con IGV
                $valor_unitario  = $qty > 0 ? round($valorLinea / $qty, 2) : $valorLinea; // sin IGV
                $subtotalLinea   = round($valor_unitario * $qty, 2);              // subtotal NubeFact (sin IGV)

                $itemsPayload[] = [
                    'unidad_de_medida'         => 'NIU',
                    'codigo'                   => $ln['codigo'] !== '' ? $ln['codigo'] : '001',
                    'descripcion'              => $ln['descripcion'] !== '' ? $ln['descripcion'] : 'Item',
                    'cantidad'                 => $qty,
                    'valor_unitario'           => $valor_unitario,
                    'precio_unitario'          => $precio_unitario,
                    'descuento'                => '',
                    'subtotal'                 => $subtotalLinea,
                    'tipo_de_igv'              => 1,
                    'igv'                      => $igvLinea,
                    'total'                    => $gi,
                    'anticipo_regularizacion'  => false,
                ];

                $total_gravada += $valorLinea;
                $total_igv     += $igvLinea;
                $sumGi         += $gi;
            }

            $total_gravada = round($total_gravada, 2);
            $total_igv     = round($total_igv, 2);
            $total         = round($total_gravada + $total_igv, 2);

            // 4d. Cuadre fino con el total cobrado del pedido.
            // El total del comprobante debe igualar pedido.total. Ajustar IGV.
            $objetivo = round($sumGi, 2); // suma exacta de líneas escaladas
            // Si el redondeo se desvió del total del pedido por ±0.02, alinear al pedido.
            if (abs($objetivo - $pedidoTotal) <= 0.02) {
                $objetivo = $pedidoTotal;
            }
            if (abs($total - $objetivo) > 0.0) {
                $diff = round($objetivo - $total_gravada, 2);
                $total_igv = $diff; // gravada + igv = objetivo
                if ($total_igv < 0) $total_igv = 0.0;
                $total = round($total_gravada + $total_igv, 2);
            }

            // ---- 5. Cliente --------------------------------------------
            $clienteDoc   = trim((string) ($pedido['cliente_documento'] ?? ''));
            $clienteNom   = trim((string) ($pedido['cliente_nombre'] ?? ''));
            $clienteRazon = trim((string) ($pedido['cliente_razon_social'] ?? ''));

            if ($compTipo === 'factura') {
                // Factura requiere RUC de 11 dígitos
                $docNorm = preg_replace('/\D/', '', $clienteDoc);
                if (strlen($docNorm) !== 11) {
                    $msg = 'La factura requiere un RUC válido de 11 dígitos.';
                    Database::execute(
                        "UPDATE pedidos SET comprobante_estado='error', comprobante_error=? WHERE id=?",
                        [$msg, $pedidoId]
                    );
                    return ['ok' => false, 'estado' => 'error', 'error' => $msg];
                }
                $cltipo  = 6;
                $cldoc   = $docNorm;
                $cldenom = $clienteRazon !== '' ? $clienteRazon : $clienteNom;
                if ($cldenom === '') $cldenom = 'CLIENTE';
            } else {
                // Boleta
                $docNorm = preg_replace('/\D/', '', $clienteDoc);
                if ($docNorm !== '' && strlen($docNorm) === 8) {
                    // Boleta con DNI
                    $cltipo  = 1;
                    $cldoc   = $docNorm;
                    $cldenom = $clienteNom !== '' ? $clienteNom : 'CLIENTE';
                } else {
                    // Boleta sin documento → cliente varios
                    $cltipo  = '-';
                    $cldoc   = '0';
                    $cldenom = 'CLIENTE VARIOS';
                }
            }

            // ---- 6. Payload NubeFact -----------------------------------
            $payload = [
                'operacion'                            => 'generar_comprobante',
                'tipo_de_comprobante'                  => $tipo,
                'serie'                                => $serie,
                'numero'                               => $numero,
                'sunat_transaction'                    => 1,
                'cliente_tipo_de_documento'            => $cltipo,
                'cliente_numero_de_documento'          => $cldoc,
                'cliente_denominacion'                 => $cldenom,
                'cliente_direccion'                    => '',
                'cliente_email'                        => '',
                'fecha_de_emision'                     => date('d-m-Y'),
                'moneda'                               => 1,
                'porcentaje_de_igv'                    => 18.00,
                'total_gravada'                        => $total_gravada,
                'total_igv'                            => $total_igv,
                'total'                                => $total,
                'enviar_automaticamente_a_la_sunat'    => true,
                'enviar_automaticamente_al_cliente'    => false,
                'items'                                => $itemsPayload,
            ];

            // ---- 7. POST cURL ------------------------------------------
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Token token="' . $token . '"',
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ]);
            $raw      = curl_exec($ch);
            $curlErr  = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // curl_close() es no-op desde PHP 8.0 (y deprecado en 8.5+); el recurso
            // se libera al salir de scope. No lo llamamos para evitar warnings.

            if ($raw === false || $curlErr !== '') {
                // Error de red/timeout → pendiente (NO error): se puede reintentar.
                $msg = 'No se pudo contactar a NubeFact (' . ($curlErr ?: 'sin respuesta') . '). Reintentar.';
                Database::execute(
                    "UPDATE pedidos SET comprobante_estado='pendiente', comprobante_error=? WHERE id=?",
                    [$msg, $pedidoId]
                );
                return ['ok' => false, 'estado' => 'pendiente', 'error' => $msg];
            }

            // ---- 8. Parsear respuesta ----------------------------------
            $resp = json_decode($raw, true);
            if (!is_array($resp)) {
                // Respuesta no-JSON (HTML de error, gateway caído, etc.) → pendiente.
                $msg = 'Respuesta inválida de NubeFact (HTTP ' . $httpCode . '). Reintentar.';
                Database::execute(
                    "UPDATE pedidos SET comprobante_estado='pendiente', comprobante_error=? WHERE id=?",
                    [$msg, $pedidoId]
                );
                return ['ok' => false, 'estado' => 'pendiente', 'error' => $msg];
            }

            // 8a. Errores reportados por NubeFact
            if (!empty($resp['errors'])) {
                $errMsg = is_string($resp['errors']) ? $resp['errors'] : json_encode($resp['errors']);

                // Caso especial: el número ya existe / debe ser otro → avanzar correlativo
                // y sugerir reintento (sin loop infinito: solo un auto-avance).
                $lower = mb_strtolower($errMsg);
                $numeroChoca = (strpos($lower, 'numero') !== false || strpos($lower, 'número') !== false || strpos($lower, 'correlativo') !== false)
                    && (strpos($lower, 'existe') !== false || strpos($lower, 'otro') !== false || strpos($lower, 'mayor') !== false || strpos($lower, 'registrado') !== false || strpos($lower, 'repetido') !== false);

                if ($numeroChoca) {
                    setSetting($numKey, (string) ($numero + 1));
                    $msg = 'El número ' . $serie . '-' . $numero . ' ya estaba usado; se avanzó al siguiente correlativo. Reintenta la emisión.';
                    Database::execute(
                        "UPDATE pedidos SET comprobante_estado='error', comprobante_error=? WHERE id=?",
                        [$msg, $pedidoId]
                    );
                    return ['ok' => false, 'estado' => 'error', 'error' => $msg];
                }

                Database::execute(
                    "UPDATE pedidos SET comprobante_estado='error', comprobante_error=? WHERE id=?",
                    [$errMsg, $pedidoId]
                );
                return ['ok' => false, 'estado' => 'error', 'error' => $errMsg];
            }

            // 8b. Aceptado
            $aceptado = !empty($resp['enlace_del_pdf']) || !empty($resp['aceptada_por_sunat']);
            if ($aceptado) {
                $rSerie  = (string) ($resp['serie']  ?? $serie);
                $rNumero = (int)    ($resp['numero'] ?? $numero);

                Database::execute(
                    "UPDATE pedidos SET
                        comprobante_serie       = ?,
                        comprobante_numero      = ?,
                        comprobante_estado      = 'emitido',
                        comprobante_pdf         = ?,
                        comprobante_xml         = ?,
                        comprobante_cdr         = ?,
                        comprobante_hash        = ?,
                        comprobante_qr          = ?,
                        comprobante_error       = NULL,
                        comprobante_emitido_at  = NOW()
                     WHERE id = ?",
                    [
                        $rSerie,
                        $rNumero,
                        (string) ($resp['enlace_del_pdf'] ?? ''),
                        (string) ($resp['enlace_del_xml'] ?? ''),
                        (string) ($resp['enlace_del_cdr'] ?? ''),
                        (string) ($resp['codigo_hash'] ?? ''),
                        (string) ($resp['cadena_para_codigo_qr'] ?? ''),
                        $pedidoId,
                    ]
                );

                // Avanzar el correlativo para la próxima emisión de esta serie.
                setSetting($numKey, (string) ($rNumero + 1));

                return [
                    'ok'     => true,
                    'estado' => 'emitido',
                    'serie'  => $rSerie,
                    'numero' => $rNumero,
                    'pdf'    => (string) ($resp['enlace_del_pdf'] ?? ''),
                ];
            }

            // 8c. Respuesta sin errores explícitos pero tampoco aceptada → pendiente.
            $msg = 'NubeFact no confirmó la aceptación. Reintentar / revisar en el panel.';
            Database::execute(
                "UPDATE pedidos SET comprobante_estado='pendiente', comprobante_error=? WHERE id=?",
                [$msg, $pedidoId]
            );
            return ['ok' => false, 'estado' => 'pendiente', 'error' => $msg];

        } catch (\Throwable $e) {
            // Blindaje final: nunca propagar excepciones al llamador.
            $msg = 'Error interno al emitir: ' . $e->getMessage();
            try {
                Database::execute(
                    "UPDATE pedidos SET comprobante_estado='pendiente', comprobante_error=? WHERE id=?",
                    [$msg, $pedidoId]
                );
            } catch (\Throwable $e2) { /* silencio */ }
            return ['ok' => false, 'estado' => 'pendiente', 'error' => $msg];
        }
    }
}
