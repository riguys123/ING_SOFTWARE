<?php
/**
 * Utilidades de IMEI. Un IMEI real tiene 15 dígitos y el último es un dígito de
 * control calculado con el algoritmo de Luhn: sirve para detectar errores de tipeo.
 */
final class Imei
{
    /** Algoritmo de Luhn (también lo usa la pasarela para validar tarjetas) */
    public static function luhn(string $digitos): bool
    {
        if ($digitos === '' || !ctype_digit($digitos)) {
            return false;
        }
        $suma = 0;
        $doblar = false;
        for ($i = strlen($digitos) - 1; $i >= 0; $i--) {
            $d = (int)$digitos[$i];
            if ($doblar) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $suma += $d;
            $doblar = !$doblar;
        }
        return $suma % 10 === 0;
    }

    public static function valido(string $imei): bool
    {
        return strlen($imei) === 15 && self::luhn($imei);
    }

    /** Completa 14 dígitos con el dígito de control (lo usa el seeder para datos de prueba) */
    public static function completar(string $catorce): string
    {
        for ($c = 0; $c <= 9; $c++) {
            if (self::luhn($catorce . $c)) {
                return $catorce . $c;
            }
        }
        throw new LogicException('No se pudo calcular el dígito de control.');
    }
}
