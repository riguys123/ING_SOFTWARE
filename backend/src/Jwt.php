<?php
/**
 * JSON Web Token (HS256) sin librerías externas.
 *
 * Estructura:  base64url(header) . base64url(payload) . base64url(firma)
 * La firma es HMAC-SHA256 con un secreto del servidor, así nadie puede alterar el
 * rol ni el id dentro del token sin invalidar la firma.
 */
final class Jwt
{
    private static ?string $secreto = null;

    private static function b64(string $d): string
    {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }

    private static function deb64(string $d): string|false
    {
        return base64_decode(strtr($d, '-_', '+/'), true);
    }

    /** Secreto persistente: se crea la primera vez y se reutiliza después */
    private static function secreto(): string
    {
        if (self::$secreto === null) {
            if (!is_file(JWT_SECRET_PATH)) {
                if (@file_put_contents(JWT_SECRET_PATH, bin2hex(random_bytes(32))) === false) {
                    throw new RuntimeException('No se pudo crear database/.jwt_secret: dale permiso de escritura a la carpeta database/.');
                }
                @chmod(JWT_SECRET_PATH, 0600);
            }
            self::$secreto = trim((string)file_get_contents(JWT_SECRET_PATH));
        }
        return self::$secreto;
    }

    /** Genera un token para el usuario (sub = id, rol, vencimiento) */
    public static function emitir(array $usuario): string
    {
        $ahora = time();
        $cabecera = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $carga = self::b64(json_encode([
            'sub' => (int)$usuario['id'],
            'rol' => $usuario['rol'],
            'iat' => $ahora,
            'exp' => $ahora + JWT_TTL,
        ]));
        $firma = self::b64(hash_hmac('sha256', "$cabecera.$carga", self::secreto(), true));
        return "$cabecera.$carga.$firma";
    }

    /** Devuelve el payload si la firma y el vencimiento son válidos; null en caso contrario */
    public static function verificar(string $token): ?array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 3) {
            return null;
        }
        [$cabecera, $carga, $firma] = $partes;

        // El algoritmo NO se toma del token (evita el ataque alg=none): siempre HS256.
        $esperada = self::b64(hash_hmac('sha256', "$cabecera.$carga", self::secreto(), true));
        if (!hash_equals($esperada, $firma)) {
            return null;
        }
        $json = self::deb64($carga);
        $datos = $json === false ? null : json_decode($json, true);
        if (!is_array($datos) || empty($datos['sub']) || ($datos['exp'] ?? 0) < time()) {
            return null;
        }
        return $datos;
    }
}
