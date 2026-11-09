<?php
/**
 * Pasarela de pagos SIMULADA (no mueve dinero real).
 *
 * Reproduce lo que hace una pasarela real: valida los datos, "autoriza" y devuelve un
 * id de transacción. Tarjetas de prueba:
 *   4242 4242 4242 4242  -> aprobada          (cualquier otro número válido por Luhn también)
 *   4000 0000 0000 0002  -> rechazada: tarjeta declinada por el banco
 *   4000 0000 0000 9995  -> rechazada: fondos insuficientes
 *
 * IMPORTANTE (PCI-DSS): el número completo y el CVV se usan solo en memoria durante esta
 * llamada. En la base de datos se guardan únicamente los últimos 4 dígitos.
 */
final class PasarelaSimulada
{
    public const METODOS = ['Tarjeta', 'QR', 'Transferencia'];

    private const RECHAZOS = [
        '4000000000000002' => 'La tarjeta fue declinada por el banco emisor.',
        '4000000000009995' => 'Fondos insuficientes.',
    ];

    /** Valida los datos del método; devuelve [campo => mensaje] (vacío si todo está bien) */
    public static function validar(string $metodo, array $d): array
    {
        $e = [];
        if (!in_array($metodo, self::METODOS, true)) {
            return ['metodo' => 'Método de pago no soportado.'];
        }
        if ($metodo === 'Tarjeta') {
            $numero = preg_replace('/\D/', '', (string)($d['numero'] ?? ''));
            if (strlen($numero) < 13 || strlen($numero) > 19 || !Imei::luhn($numero)) {
                $e['numero'] = 'El número de tarjeta no es válido.';
            }
            if (mb_strlen(trim((string)($d['titular'] ?? ''))) < 3) {
                $e['titular'] = 'Ingresa el nombre del titular.';
            }
            if (!preg_match('#^(0[1-9]|1[0-2])\s*/\s*(\d{2})$#', trim((string)($d['vencimiento'] ?? '')), $m)) {
                $e['vencimiento'] = 'Usa el formato MM/AA.';
            } else {
                $finMes = mktime(0, 0, 0, (int)$m[1] + 1, 1, 2000 + (int)$m[2]);   // 1er día del mes siguiente
                if ($finMes <= time()) {
                    $e['vencimiento'] = 'La tarjeta está vencida.';
                }
            }
            if (!preg_match('/^\d{3,4}$/', (string)($d['cvv'] ?? ''))) {
                $e['cvv'] = 'El CVV debe tener 3 o 4 dígitos.';
            }
        } elseif ($metodo === 'Transferencia') {
            if (!preg_match('/^[A-Za-z0-9\-]{6,30}$/', trim((string)($d['comprobante'] ?? '')))) {
                $e['comprobante'] = 'Ingresa el número de comprobante (6 a 30 letras o números).';
            }
        }
        return $e;
    }

    /**
     * "Autoriza" el cobro. Devuelve:
     * [aprobado(bool), referencia(string), ultimos4(?string), motivo(?string)]
     */
    public static function autorizar(string $metodo, array $d, float $monto): array
    {
        $referencia = 'SIM-' . strtoupper(bin2hex(random_bytes(5)));
        $ultimos4 = null;
        $motivo = null;
        $aprobado = true;

        if ($metodo === 'Tarjeta') {
            $numero = preg_replace('/\D/', '', (string)$d['numero']);
            $ultimos4 = substr($numero, -4);
            if (isset(self::RECHAZOS[$numero])) {
                $aprobado = false;
                $motivo = self::RECHAZOS[$numero];
            }
        }
        return ['aprobado' => $aprobado, 'referencia' => $referencia, 'ultimos4' => $ultimos4, 'motivo' => $motivo];
    }
}
