<?php
/**
 * Cliente mínimo de la API REST v2 de PayPal (Orders): crear orden y capturarla.
 * Flujo: el servidor crea la orden -> el cliente aprueba en paypal.com -> el servidor CAPTURA y
 * comprueba estado, monto y pedido antes de dar el pedido por pagado. El navegador nunca decide el monto.
 * PayPal no cobra en bolivianos: el total en Bs se convierte a USD con PAYPAL_TIPO_CAMBIO.
 */
final class PayPalService
{
    public static function configurado(): bool
    {
        return PAYPAL_CLIENT_ID !== '' && PAYPAL_SECRET !== '';
    }

    /** Monto en USD (2 decimales, mínimo 0,01) que se cobra por un total en Bs */
    public static function aUsd(float $bs): string
    {
        return number_format(max(0.01, round($bs / PAYPAL_TIPO_CAMBIO, 2)), 2, '.', '');
    }

    /** @return array ['id' => orden, 'url' => enlace de aprobación de PayPal, 'usd' => monto] */
    public static function crearOrden(int $pedidoId, float $totalBs, string $urlRetorno, string $urlCancelar): array
    {
        $usd = self::aUsd($totalBs);
        $r = self::llamar('POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => 'PED-' . $pedidoId,
                'description'  => TIENDA_NOMBRE . ' - Pedido #' . $pedidoId,
                'amount'       => ['currency_code' => 'USD', 'value' => $usd],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'brand_name'  => TIENDA_NOMBRE,
                'user_action' => 'PAY_NOW',
                'return_url'  => $urlRetorno,
                'cancel_url'  => $urlCancelar,
            ]]],
        ]);
        $url = '';
        foreach ($r['links'] ?? [] as $l) {
            if (in_array($l['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $url = (string)$l['href'];
                break;
            }
        }
        if (empty($r['id']) || $url === '') {
            throw new ApiException(502, 'paypal_error', 'PayPal no devolvió el enlace de pago. Inténtalo de nuevo.');
        }
        return ['id' => (string)$r['id'], 'url' => $url, 'usd' => $usd];
    }

    /** Captura la orden aprobada. @return array ['captura' => id, 'estado' => string, 'usd' => string, 'pedido' => reference_id] */
    public static function capturar(string $ordenId): array
    {
        $r = self::llamar('POST', '/v2/checkout/orders/' . rawurlencode($ordenId) . '/capture', new stdClass());
        $unidad = $r['purchase_units'][0] ?? [];
        $cap = $unidad['payments']['captures'][0] ?? [];
        return [
            'estado'  => (string)($cap['status'] ?? ($r['status'] ?? '')),
            'captura' => (string)($cap['id'] ?? ''),
            'usd'     => (string)($cap['amount']['value'] ?? ''),
            'moneda'  => (string)($cap['amount']['currency_code'] ?? ''),
            'pedido'  => (string)($unidad['reference_id'] ?? ''),
        ];
    }

    private static function token(): string
    {
        $r = self::http('POST', '/v1/oauth2/token', 'grant_type=client_credentials',
            ['Authorization: Basic ' . base64_encode(PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET), 'Content-Type: application/x-www-form-urlencoded']);
        if (empty($r['access_token'])) {
            throw new ApiException(502, 'paypal_credenciales', 'PayPal rechazó las credenciales del comercio. Revisa el Client ID y el Secret.');
        }
        return (string)$r['access_token'];
    }

    private static function llamar(string $metodo, string $ruta, array|object $cuerpo): array
    {
        if (!self::configurado()) {
            throw new ApiException(503, 'paypal_no_configurado', 'PayPal todavía no está configurado en el servidor.');
        }
        return self::http($metodo, $ruta, json_encode($cuerpo),
            ['Authorization: Bearer ' . self::token(), 'Content-Type: application/json', 'PayPal-Request-Id: ' . bin2hex(random_bytes(12))]);
    }

    private static function http(string $metodo, string $ruta, string $cuerpo, array $cabeceras): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException(500, 'error_interno', 'Falta la extensión curl de PHP (actívala en php.ini).');
        }
        $ch = curl_init(PAYPAL_API_BASE . $ruta);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $falla = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new ApiException(502, 'paypal_sin_conexion', 'No se pudo conectar con PayPal: ' . $falla);
        }
        $json = json_decode((string)$resp, true);
        if (!is_array($json)) {
            throw new ApiException(502, 'paypal_error', 'Respuesta inesperada de PayPal (HTTP ' . $codigo . ').');
        }
        if ($codigo >= 400) {
            $det = $json['details'][0]['description'] ?? ($json['message'] ?? ($json['error_description'] ?? 'error'));
            throw new ApiException(502, 'paypal_error', 'PayPal respondió: ' . $det);
        }
        return $json;
    }
}
