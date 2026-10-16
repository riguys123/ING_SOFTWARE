<?php
/**
 * Utilidades HTTP: respuesta JSON uniforme y lectura de la petición.
 *
 * Formato de éxito:  { "ok": true,  "data": ..., "meta": ... }
 * Formato de error:  { "ok": false, "error": { "codigo", "mensaje", "detalles" } }
 */
final class Response
{
    public static function json(array $cuerpo, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null, ?array $meta = null, int $status = 200): never
    {
        $cuerpo = ['ok' => true, 'data' => $data];
        if ($meta !== null) {
            $cuerpo['meta'] = $meta;
        }
        self::json($cuerpo, $status);
    }

    public static function error(int $status, string $codigo, string $mensaje, ?array $detalles = null): never
    {
        $error = ['codigo' => $codigo, 'mensaje' => $mensaje];
        if ($detalles) {
            $error['detalles'] = $detalles;
        }
        self::json(['ok' => false, 'error' => $error], $status);
    }
}

final class Request
{
    private static ?array $cuerpo = null;

    public static function metodo(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /** Cuerpo JSON de la petición como arreglo asociativo */
    public static function json(): array
    {
        if (self::$cuerpo === null) {
            $crudo = file_get_contents('php://input');
            if ($crudo === '' || $crudo === false) {
                self::$cuerpo = [];
            } else {
                $datos = json_decode($crudo, true);
                if (!is_array($datos)) {
                    Response::error(400, 'json_invalido', 'El cuerpo de la petición no es un JSON válido.');
                }
                self::$cuerpo = $datos;
            }
        }
        return self::$cuerpo;
    }

    /** Parámetro de query string como texto recortado ('' si no existe) */
    public static function query(string $clave): string
    {
        $v = $_GET[$clave] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    public static function entero(string $clave, int $defecto, int $min, int $max): int
    {
        $v = filter_var($_GET[$clave] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v === null) {
            return $defecto;
        }
        return max($min, min($max, $v));
    }

    /** Escapa % _ \ para usar el texto dentro de un LIKE ... ESCAPE '\' */
    public static function likeSeguro(string $texto): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto) . '%';
    }
}
