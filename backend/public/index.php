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
require __DIR__ . '/../src/Jwt.php';
foreach (['Imei', 'PasarelaSimulada', 'Catalogo', 'Uploads', 'PedidoService', 'Comprobante', 'Notificador', 'Logistica', 'PagoService'] as $s) {
    require __DIR__ . "/../src/Services/{$s}.php";
}
foreach (['Auth', 'Usuario', 'Rol', 'Log', 'Producto', 'Inventario', 'Carrito', 'Pedido', 'Pago', 'Logistica', 'Reporte', 'Categoria', 'Promocion', 'Notificacion', 'Informe'] as $c) {
    require __DIR__ . "/../src/Controllers/{$c}Controller.php";
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Errores inesperados => 500 genérico (sin filtrar detalles internos)
set_exception_handler(function (Throwable $e) {
    // Errores de negocio controlados (stock insuficiente, pedido ya pagado, validaciones...)
    if ($e instanceof ApiException) {
        Response::error($e->status, $e->codigo, $e->getMessage(), $e->detalles);
    }
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
// Si la petición trae un token JWT en "Authorization: Bearer", no hace falta: CSRF solo afecta a cookies.
if (!in_array($metodo, ['GET', 'HEAD'], true)
    && tokenBearer() === null
    && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    Response::error(400, 'peticion_invalida', 'Falta el encabezado X-Requested-With.');
}

// ---- Rutas y RBAC ----
// Tercer argumento: null = pública | [] = cualquier usuario logueado | [roles] = solo esos roles
$router = new Router();
$admin  = [ROL_ADMIN];
$lectorUsuarios = [ROL_ADMIN];   // Sprint 3: solo el Admin (gerente/propietario) gestiona usuarios

$router->add('POST', '/auth/register', [AuthController::class, 'register'], null);
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

// ======================= SPRINT 2: E-COMMERCE, PAGOS Y LOGÍSTICA =======================
// 'modulo:clave[:total]' = RBAC según la matriz permisos_rol (editable en BD, sin tocar código).

// ---- Catálogo público (sin login) ----
$router->add('GET', '/productos',         [ProductoController::class, 'listar'],  null);
$router->add('GET', '/productos/filtros', [ProductoController::class, 'filtros'], null);
$router->add('GET', '/productos/:id',     [ProductoController::class, 'ver'],     null);

// ---- Gestión de productos (módulo catalogo) ----
$router->add('GET',    '/admin/productos', [ProductoController::class, 'listarAdmin'], 'modulo:catalogo');
$router->add('POST',   '/productos',       [ProductoController::class, 'crear'],       'modulo:catalogo:total');
$router->add('POST',   '/productos/:id/fotos', [ProductoController::class, 'subirFoto'],  'modulo:catalogo:total');
$router->add('PUT',    '/productos/:id',   [ProductoController::class, 'actualizar'],  'modulo:catalogo:total');
$router->add('DELETE', '/productos/:id',   [ProductoController::class, 'eliminar'],    'modulo:catalogo:total');

// ---- Categorías (lectura pública; gestión en el módulo catalogo) ----
$router->add('GET',    '/categorias',     [CategoriaController::class, 'listar'],     null);
$router->add('POST',   '/categorias',     [CategoriaController::class, 'crear'],      'modulo:catalogo:total');
$router->add('PUT',    '/categorias/:id', [CategoriaController::class, 'actualizar'], 'modulo:catalogo:total');
$router->add('DELETE', '/categorias/:id', [CategoriaController::class, 'eliminar'],   'modulo:catalogo:total');

// ---- Promociones y banner de ofertas ----
$router->add('GET',    '/promociones/vigentes', [PromocionController::class, 'vigentes'],      null);
$router->add('GET',    '/promociones',          [PromocionController::class, 'listar'],        'modulo:promociones');
$router->add('POST',   '/promociones/imagen',   [PromocionController::class, 'subirImagen'],   'modulo:promociones:total');
$router->add('POST',   '/promociones',          [PromocionController::class, 'crear'],         'modulo:promociones:total');
$router->add('PUT',    '/promociones/:id',      [PromocionController::class, 'actualizar'],    'modulo:promociones:total');
$router->add('DELETE', '/promociones/:id',      [PromocionController::class, 'eliminar'],      'modulo:promociones:total');

// ---- Inventario por IMEI (módulo inventario) ----
$router->add('GET',  '/inventario/stock', [InventarioController::class, 'stock'],     'modulo:inventario');
$router->add('GET',  '/inventario/imeis', [InventarioController::class, 'listar'],    'modulo:inventario');
$router->add('POST', '/inventario/imeis', [InventarioController::class, 'registrar'], 'modulo:inventario:total');
$router->add('POST', '/inventario/ajuste', [InventarioController::class, 'ajustar'],   'modulo:inventario:total');

// ---- Carrito (módulo tienda = rol Cliente) ----
$router->add('GET',    '/carrito',     [CarritoController::class, 'ver'],        'modulo:tienda');
$router->add('POST',   '/carrito',     [CarritoController::class, 'agregar'],    'modulo:tienda');
$router->add('DELETE', '/carrito',     [CarritoController::class, 'vaciar'],     'modulo:tienda');
$router->add('PATCH',  '/carrito/:id', [CarritoController::class, 'actualizar'], 'modulo:tienda');
$router->add('DELETE', '/carrito/:id', [CarritoController::class, 'quitar'],     'modulo:tienda');

// ---- Pedidos: el cliente ve los suyos; el staff con módulo ventas ve todos ----
$router->add('POST',  '/pedidos',               [PedidoController::class, 'crear'],    'modulo:tienda');
$router->add('GET',   '/pedidos',               [PedidoController::class, 'listar']);
$router->add('GET',   '/pedidos/:id',           [PedidoController::class, 'ver']);
$router->add('GET',   '/pedidos/:id/comprobante', [PedidoController::class, 'comprobante']);
$router->add('PATCH', '/pedidos/:id/cancelar',  [PedidoController::class, 'cancelar']);

// ---- Mensajes automáticos (WhatsApp local) ----
$router->add('GET',   '/notificaciones',             [NotificacionController::class, 'listar'],       'modulo:ventas');
$router->add('PATCH', '/notificaciones/:id/enviado', [NotificacionController::class, 'marcarEnviado'], 'modulo:ventas:total');

// ---- Pagos ----
$router->add('POST', '/pagos/procesar', [PagoController::class, 'procesar'], 'modulo:tienda');
$router->add('GET',  '/pagos',          [PagoController::class, 'listar'],   'modulo:cobranzas');

// ---- Logística ----
$router->add('POST',  '/logistica/despacho',            [LogisticaController::class, 'despachar'],    'modulo:inventario:total');
$router->add('GET',   '/logistica/despachos',           [LogisticaController::class, 'listar'],       'modulo:distribucion');
$router->add('PATCH', '/logistica/despachos/:id/estado', [LogisticaController::class, 'cambiarEstado'], 'modulo:distribucion:total');

// ---- Panel administrativo ----
$router->add('GET', '/reportes/resumen', [ReporteController::class, 'resumen']);

// ---- Módulo de informes (una ruta por informe; ?desde=&hasta=&formato=csv) ----
foreach (['ventas', 'productos', 'inventario', 'pedidos', 'clientes'] as $tipoInforme) {
    $router->add('GET', "/informes/$tipoInforme",
        fn($yo, $p) => InformeController::generar($yo, ['tipo' => $tipoInforme]), 'modulo:informes');
}

$router->despachar($metodo, $ruta);
