<?php
/**
 * Front controller de la API REST — SmartCell Commerce (Seguridad)
 *
 * Todas las URLs /api/... llegan aquí (ver .htaccess en la raíz del proyecto).
 * También funciona sin mod_rewrite usando: backend/public/index.php/api/...
 */

require __DIR__ . '/../src/config.php';
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Router.php';
foreach (['Auth', 'Usuario', 'Rol', 'Log'] as $c) {
    require __DIR__ . "/../src/Controllers/{$c}Controller.php";
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Errores inesperados => 500 genérico (sin filtrar detalles internos)
set_exception_handler(function (Throwable $e) {
    error_log('[SmartCell] ' . $e);
    Response::error(500, 'error_interno', DEPURAR ? $e->getMessage() : 'Ocurrió un error interno del servidor.');
});
set_error_handler(function ($nivel, $msg, $archivo, $linea) {
    throw new ErrorException($msg, 0, $nivel, $archivo, $linea);
});

// ---- Ruta solicitada (lo que sigue a "/api") ----
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$pos = strpos($uri, '/api/');
$ruta = $pos === false ? '/' : substr($uri, $pos + 4);
$ruta = '/' . trim($ruta, '/');
$metodo = Request::metodo();

// ---- Protección CSRF básica ----
// Las peticiones que modifican datos deben llevar este encabezado personalizado:
// un sitio externo no puede enviarlo sin pasar por CORS (que no habilitamos).
if (!in_array($metodo, ['GET', 'HEAD'], true)
    && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    Response::error(400, 'peticion_invalida', 'Falta el encabezado X-Requested-With.');
}

// ---- Rutas y RBAC ----
// Tercer argumento: null = pública | [] = cualquier usuario logueado | [roles] = solo esos roles
$router = new Router();
$admin  = [ROL_ADMIN];
$lectorUsuarios = [ROL_ADMIN, ROL_JEFE_DIST, ROL_JEFE_ALM];

$router->add('POST', '/auth/login',  [AuthController::class, 'login'],  null);
$router->add('POST', '/auth/logout', [AuthController::class, 'logout'], null);
$router->add('GET',  '/auth/me',     [AuthController::class, 'me']);

$router->add('GET',    '/usuarios',               [UsuarioController::class, 'listar'],          $lectorUsuarios);
$router->add('GET',    '/usuarios/:id',           [UsuarioController::class, 'ver'],             $lectorUsuarios);
$router->add('POST',   '/usuarios',               [UsuarioController::class, 'crear'],           $admin);
$router->add('PUT',    '/usuarios/:id',           [UsuarioController::class, 'actualizar'],      $admin);
$router->add('PATCH',  '/usuarios/:id/password',  [UsuarioController::class, 'cambiarPassword']);   // propio, o Admin
$router->add('DELETE', '/usuarios/:id',           [UsuarioController::class, 'eliminar'],        $admin);

$router->add('GET', '/roles',        [RolController::class, 'listar']);
$router->add('GET', '/permisos',     [RolController::class, 'matriz'], $admin);
$router->add('GET', '/logs-acceso',  [LogController::class, 'listar'], $admin);

$router->despachar($metodo, $ruta);
