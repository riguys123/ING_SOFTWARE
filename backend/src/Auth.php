<?php
/**
 * Autenticación, sesión y control de acceso por roles (RBAC).
 * Evolución de includes/auth.php del login original, ahora para API REST (JSON).
 */

/** Inicia la sesión PHP con cookie segura (HttpOnly + SameSite) */
function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.gc_maxlifetime', (string)SESION_INACTIVIDAD);
    ini_set('session.use_strict_mode', '1');
    session_name('SMARTCELLSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,   // en producción con HTTPS la cookie solo viaja cifrada (RNF-01)
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Usuario activo por id, con su rol (consulta fresca a la BD) */
function usuarioPorId(int $id): ?array
{
    $stmt = Database::pdo()->prepare(
        'SELECT u.id, u.nombre, u.email, u.activo, u.rol_id, r.nombre AS rol, r.area
           FROM usuarios u JOIN roles r ON r.id = u.rol_id
          WHERE u.id = ?'
    );
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    return ($u && (int)$u['activo'] === 1) ? $u : null;
}

/** Token JWT enviado en "Authorization: Bearer xxx" (null si no viene) */
function tokenBearer(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $h = $v;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
}

/**
 * Devuelve el usuario autenticado leyéndolo de la BD en cada petición,
 * así una baja o cambio de rol surte efecto de inmediato.
 */
function usuarioActual(): ?array
{
    iniciarSesion();
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }
    $u = usuarioPorId((int)$_SESSION['usuario_id']);
    if (!$u) {
        cerrarSesion();
    }
    return $u;
}

/**
 * Exige autenticación. Acepta DOS mecanismos:
 *   1) Token JWT:  Authorization: Bearer <token>   (Postman, apps móviles, otros servicios)
 *   2) Sesión PHP con cookie HttpOnly              (el frontend Vue de este proyecto)
 * Responde 401 si no hay credenciales válidas.
 */
function requerirLogin(): array
{
    $token = tokenBearer();
    if ($token !== null) {
        $payload = Jwt::verificar($token);
        if ($payload === null) {
            Response::error(401, 'token_invalido', 'El token es inválido o ya venció. Inicia sesión nuevamente.');
        }
        $u = usuarioPorId((int)$payload['sub']);   // el rol se lee de la BD, no del token
        if ($u === null) {
            Response::error(401, 'no_autenticado', 'Tu cuenta ya no está activa.');
        }
        return $u;
    }

    iniciarSesion();
    if (empty($_SESSION['usuario_id'])) {
        Response::error(401, 'no_autenticado', 'Debes iniciar sesión.');
    }
    $ultimo = $_SESSION['ultimo_acceso'] ?? 0;
    if (time() - $ultimo > SESION_INACTIVIDAD) {
        cerrarSesion();
        Response::error(401, 'sesion_expirada', 'Tu sesión expiró por inactividad. Inicia sesión nuevamente.');
    }
    $usuario = usuarioActual();
    if ($usuario === null) {
        Response::error(401, 'no_autenticado', 'Tu cuenta ya no está activa. Inicia sesión nuevamente.');
    }
    $_SESSION['ultimo_acceso'] = time();
    return $usuario;
}

/**
 * Middleware RBAC reutilizable.
 * 401 si no hay sesión, 403 si el rol no está permitido.
 * Ejemplo: requerirRol([ROL_ADMIN])
 */
function requerirRol(array $rolesPermitidos): array
{
    $usuario = requerirLogin();
    if (!in_array($usuario['rol'], $rolesPermitidos, true)) {
        Response::error(403, 'acceso_denegado',
            'Tu rol (' . $usuario['rol'] . ') no tiene permiso para esta acción.');
    }
    return $usuario;
}

function cerrarSesion(): void
{
    iniciarSesion();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Registra un intento de acceso en logs_acceso (auditoría) */
function registrarIntento(?int $usuarioId, string $email, string $resultado): void
{
    $stmt = Database::pdo()->prepare(
        'INSERT INTO logs_acceso (usuario_id, email_intento, resultado, ip, fecha) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$usuarioId, mb_substr($email, 0, 150), $resultado, Request::ip(), date('Y-m-d H:i:s')]);
}

/**
 * Minutos de bloqueo restantes para un correo (0 = no bloqueado).
 * Regla: 5 intentos fallidos consecutivos (desde el último éxito y dentro de la
 * ventana de 15 min) bloquean el acceso hasta 15 min después del último fallo.
 */
function minutosBloqueoRestantes(string $email): int
{
    $pdo = Database::pdo();
    $ventana = date('Y-m-d H:i:s', time() - BLOQUEO_MINUTOS * 60);

    $stmt = $pdo->prepare("SELECT MAX(fecha) FROM logs_acceso WHERE email_intento = ? AND resultado = 'exito'");
    $stmt->execute([$email]);
    $desde = max($ventana, (string)$stmt->fetchColumn());

    $stmt = $pdo->prepare(
        "SELECT COUNT(*), MAX(fecha) FROM logs_acceso
          WHERE email_intento = ? AND resultado = 'fallido' AND fecha > ?"
    );
    $stmt->execute([$email, $desde]);
    [$fallos, $ultimoFallo] = array_values($stmt->fetch(PDO::FETCH_NUM));

    if ((int)$fallos < MAX_INTENTOS) {
        return 0;
    }
    $libre = strtotime($ultimoFallo) + BLOQUEO_MINUTOS * 60;
    return max(1, (int)ceil(($libre - time()) / 60));
}

/** Módulos a los que accede un rol: ['clave' => 'total'|'lectura'] */
function modulosDeRol(int $rolId): array
{
    $stmt = Database::pdo()->prepare('SELECT modulo_clave, nivel FROM permisos_rol WHERE rol_id = ?');
    $stmt->execute([$rolId]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** ¿El usuario tiene el módulo (con al menos ese nivel) en la matriz permisos_rol? */
function tieneModulo(array $usuario, string $clave, string $nivel = 'lectura'): bool
{
    $actual = modulosDeRol((int)$usuario['rol_id'])[$clave] ?? null;
    if ($actual === null) {
        return false;
    }
    return $nivel === 'lectura' || $actual === 'total';
}

/**
 * RBAC basado en la matriz de permisos de la BD (tabla permisos_rol).
 *   requerirModulo('inventario')           -> lectura o total
 *   requerirModulo('inventario', 'total')  -> solo escritura
 */
function requerirModulo(string $clave, string $nivel = 'lectura'): array
{
    $usuario = requerirLogin();
    if (!tieneModulo($usuario, $clave, $nivel)) {
        Response::error(403, 'acceso_denegado',
            'Tu rol (' . $usuario['rol'] . ') no tiene permiso para esta acción.');
    }
    return $usuario;
}
