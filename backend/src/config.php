<?php
/**
 * Configuración general — SmartCell Commerce (Subsistema de Seguridad)
 */

date_default_timezone_set('America/La_Paz');

define('RAIZ_PROYECTO', dirname(__DIR__, 2));
define('DB_PATH', RAIZ_PROYECTO . '/database/smartcell.sqlite');

// Nombres de roles (deben coincidir con la tabla roles)
const ROL_ADMIN      = 'Admin';
const ROL_JEFE_DIST  = 'Jefe de Distribución';
const ROL_JEFE_ALM   = 'Jefe de Almacén';

// Parámetros de seguridad
const SESION_INACTIVIDAD = 1800;  // 30 min sin actividad => sesión expirada
const MAX_INTENTOS       = 5;     // intentos fallidos antes del bloqueo
const BLOQUEO_MINUTOS    = 15;    // duración del bloqueo temporal
const PASSWORD_MIN       = 8;
const PASSWORD_MAX       = 72;    // límite real de bcrypt
const BCRYPT_COST        = 10;    // buen equilibrio seguridad / velocidad (RNF-02)

// Poner en true SOLO en desarrollo para ver el detalle de errores 500
const DEPURAR = false;
