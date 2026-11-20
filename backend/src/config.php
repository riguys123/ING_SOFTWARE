<?php
/**
 * Configuración general — SmartCell Commerce (Subsistema de Seguridad)
 */

date_default_timezone_set('America/La_Paz');

define('RAIZ_PROYECTO', dirname(__DIR__, 2));
define('DB_PATH', RAIZ_PROYECTO . '/database/smartcell.sqlite');

// Nombres de roles (deben coincidir con la tabla roles). Sprint 3: 4 roles; se agregó Empaque.
const ROL_ADMIN      = 'Admin';        // gerente / propietario
const ROL_VENDEDOR   = 'Vendedor';     // encargado de ventas, catálogo e inventario
const ROL_REPARTIDOR = 'Repartidor';   // encargado del envío
const ROL_EMPAQUE    = 'Empaque';      // prepara y empaca los pedidos pagados
const ROL_CLIENTE    = 'Cliente';

// Parámetros de seguridad
const SESION_INACTIVIDAD = 1800;  // 30 min sin actividad => sesión expirada
const MAX_INTENTOS       = 5;     // intentos fallidos antes del bloqueo
const BLOQUEO_MINUTOS    = 15;    // duración del bloqueo temporal
const PASSWORD_MIN       = 8;
const PASSWORD_MAX       = 72;    // límite real de bcrypt
const BCRYPT_COST        = 10;    // buen equilibrio seguridad / velocidad (RNF-02)

// JWT (HS256). El secreto se genera una sola vez en database/.jwt_secret (no se sube a Git).
define('JWT_SECRET_PATH', RAIZ_PROYECTO . '/database/.jwt_secret');
const JWT_TTL = 7200;             // el token vence a las 2 horas

// Tienda
const MONEDA          = 'Bs';
const MAX_POR_PRODUCTO = 10;      // unidades máximas de un mismo modelo por pedido

// Datos de la tienda (comprobantes y mensajes de WhatsApp)
const TIENDA_NOMBRE   = 'SmartCell';
const TIENDA_DIRECCION = 'Av. Hernando Siles 123, Sucre, Bolivia';
const TIENDA_TELEFONO = '70012345';
const TIENDA_NIT      = '1023456029';
const PAIS_TELEFONO   = '591';     // prefijo para armar enlaces wa.me (Bolivia)

// Imágenes subidas desde el panel
const UPLOAD_MAX_BYTES = 2 * 1024 * 1024;   // 2 MB (el php.ini de XAMPP permite 2 MB por defecto)
const FOTOS_POR_PRODUCTO = 5;

// PayPal (REST API v2). Las credenciales NUNCA se escriben aquí ni se suben a Git:
//  - en Render: variables de entorno PAYPAL_CLIENT_ID, PAYPAL_SECRET (y opcional PAYPAL_MODO=sandbox|live, PAYPAL_TIPO_CAMBIO)
//  - en XAMPP: archivo backend/src/paypal.local.php (copia paypal.local.example.php); está en .gitignore
$paypalLocal = is_file(__DIR__ . '/paypal.local.php') ? (array)require __DIR__ . '/paypal.local.php' : [];
$paypalCfg = fn(string $k, string $def = '') => (string)(getenv($k) ?: ($paypalLocal[$k] ?? $def));
define('PAYPAL_CLIENT_ID', $paypalCfg('PAYPAL_CLIENT_ID'));
define('PAYPAL_SECRET', $paypalCfg('PAYPAL_SECRET'));
define('PAYPAL_MODO', $paypalCfg('PAYPAL_MODO', 'sandbox'));
define('PAYPAL_TIPO_CAMBIO', (float)$paypalCfg('PAYPAL_TIPO_CAMBIO', '6.96'));   // Bs por 1 USD (PayPal no cobra en bolivianos)
define('PAYPAL_API_BASE', $paypalCfg('PAYPAL_API_BASE', PAYPAL_MODO === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com'));

// Poner en true SOLO en desarrollo para ver el detalle de errores 500
// Versión del esquema de la base de datos. Debe coincidir con "PRAGMA user_version" al final de
// database/schema.sql. Si no coincide (base vieja tras actualizar los archivos), la API avisa con un
// mensaje claro en vez de fallar con un "error interno".
const SCHEMA_VERSION = 10;

const DEPURAR = false;
