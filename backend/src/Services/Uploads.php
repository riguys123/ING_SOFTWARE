<?php
/**
 * Subida segura de imágenes (fotos de productos y banners).
 *  - Valida el contenido real (finfo + getimagesize), no la extensión que dice el usuario.
 *  - Solo JPG, PNG y WebP; máximo UPLOAD_MAX_BYTES.
 *  - El nombre final lo genera el servidor (aleatorio): no se puede pisar ni ejecutar nada.
 * Los archivos quedan en frontend/uploads/<carpeta>/ y se guardan en la BD como ruta relativa.
 */
final class Uploads
{
    private const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public static function guardarImagen(string $campo, string $carpeta): string
    {
        $f = $_FILES[$campo] ?? null;
        if (!$f || !is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ApiException(422, 'validacion', 'Selecciona una imagen.', [$campo => 'Selecciona una imagen.']);
        }
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > UPLOAD_MAX_BYTES) {
            throw new ApiException(422, 'imagen_grande', 'La imagen supera ' . (UPLOAD_MAX_BYTES / 1048576) . ' MB.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new ApiException(422, 'subida_fallida', 'No se pudo recibir la imagen. Inténtalo de nuevo.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!isset(self::TIPOS[$mime]) || @getimagesize($f['tmp_name']) === false) {
            throw new ApiException(422, 'imagen_invalida', 'Solo se aceptan imágenes JPG, PNG o WebP.');
        }
        $dir = RAIZ_PROYECTO . '/frontend/uploads/' . $carpeta;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ApiException(500, 'error_interno', 'No se pudo crear la carpeta de imágenes.');
        }
        $nombre = bin2hex(random_bytes(10)) . '.' . self::TIPOS[$mime];
        if (!move_uploaded_file($f['tmp_name'], "$dir/$nombre")) {
            throw new ApiException(500, 'error_interno', 'No se pudo guardar la imagen.');
        }
        return "uploads/$carpeta/$nombre";
    }

    /** ¿Es una ruta subida por nosotros? (para no permitir borrar archivos arbitrarios) */
    public static function esPropia(string $ruta): bool
    {
        return (bool)preg_match('#^uploads/(productos|promociones)/[a-f0-9]{20}\.(jpg|png|webp)$#', $ruta);
    }

    public static function borrar(string $ruta): void
    {
        if (self::esPropia($ruta)) {
            @unlink(RAIZ_PROYECTO . '/frontend/' . $ruta);
        }
    }

    /** Ruta de imagen aceptada en la BD: subida propia, de la carpeta assets/ o URL http(s) */
    public static function rutaValida(string $r): bool
    {
        // seed-*.svg son las ilustraciones de ejemplo que genera el seeder (los usuarios no pueden subir SVG)
        return self::esPropia($r)
            || (bool)preg_match('#^uploads/(productos|promociones)/seed-[a-z0-9-]+\.svg$#', $r)
            || (bool)preg_match('#^(https?://|assets/)#i', $r);
    }
}
