<?php
/**
 * Solo para desarrollo sin Apache (servidor integrado de PHP):
 *   php -S localhost:8000 dev-router.php
 * Emula lo que hace el .htaccess (reescritura de /api/...).
 * En XAMPP NO se usa.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Bloquea carpetas internas
if (preg_match('#^/(database|backend/src)/#', $uri)) {
    http_response_code(403);
    exit('Prohibido');
}
if (strpos($uri, '/api/') === 0) {
    require __DIR__ . '/backend/public/index.php';
    return true;
}
return false; // sirve el archivo estático normalmente
