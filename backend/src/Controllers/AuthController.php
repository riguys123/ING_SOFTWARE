<?php
/**
 * Autenticación: login, logout y usuario actual.
 */
final class AuthController
{
    /** Estructura que consume el frontend (usuario + módulos permitidos) */
    public static function payload(array $u, bool $conToken = false): array
    {
        $datos = [
            'usuario' => [
                'id'     => (int)$u['id'],
                'nombre' => $u['nombre'],
                'email'  => $u['email'],
                'rol'    => $u['rol'],
                'rol_id' => (int)$u['rol_id'],
                'area'   => $u['area'],
            ],
            'modulos'     => modulosDeRol((int)$u['rol_id']),
            'inactividad' => SESION_INACTIVIDAD,
        ];
        if ($conToken) {
            // JWT para clientes de la API que no usan cookies (Postman, apps móviles, etc.)
            $datos['token']      = Jwt::emitir($u);
            $datos['token_tipo'] = 'Bearer';
            $datos['expira_en']  = JWT_TTL;
        }
        return $datos;
    }

    /** POST /api/auth/login */
    public static function login(?array $yo, array $p): void
    {
        $d = Request::json();
        $email = mb_strtolower(trim((string)($d['email'] ?? '')));
        $password = (string)($d['password'] ?? '');

        if ($email === '' || $password === '') {
            Response::error(422, 'validacion', 'Completa tu correo y contraseña.');
        }

        // Bloqueo temporal tras 5 intentos fallidos
        $min = minutosBloqueoRestantes($email);
        if ($min > 0) {
            registrarIntento(null, $email, 'bloqueado');
            Response::error(429, 'cuenta_bloqueada',
                "Demasiados intentos fallidos. Intenta de nuevo en $min minuto(s).");
        }

        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.nombre, u.email, u.password, u.activo, u.rol_id, r.nombre AS rol, r.area
               FROM usuarios u JOIN roles r ON r.id = u.rol_id
              WHERE u.email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        // Se verifica siempre un hash (aunque el usuario no exista) para no revelar por tiempo si el correo existe
        $hash = $u['password'] ?? '$2y$10$EzJuBafJuEPlIT5mePAwpOuFri6Abp6dBlrWKYF4zq4TJHyaW2x/S';
        $valida = password_verify($password, $hash);

        if ($u && $valida && (int)$u['activo'] === 1) {
            iniciarSesion();
            session_regenerate_id(true);   // evita fijación de sesión
            $_SESSION['usuario_id'] = (int)$u['id'];
            $_SESSION['ultimo_acceso'] = time();
            registrarIntento((int)$u['id'], $email, 'exito');
            Response::ok(self::payload($u, true));
        }

        registrarIntento($u ? (int)$u['id'] : null, $email, 'fallido');
        Response::error(401, 'credenciales_invalidas', 'Correo o contraseña incorrectos.');
    }

    /**
     * POST /api/auth/register — autoregistro de CLIENTES de la tienda.
     * El rol siempre es "Cliente": nadie puede elegir un rol más alto al registrarse
     * (los usuarios internos los crea el Admin desde /api/usuarios).
     */
    public static function register(?array $yo, array $p): void
    {
        $d = Request::json();
        $nombre   = trim((string)($d['nombre'] ?? ''));
        $email    = mb_strtolower(trim((string)($d['email'] ?? '')));
        $password = (string)($d['password'] ?? '');
        $e = [];

        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
            $e['nombre'] = 'El nombre debe tener entre 2 y 100 caracteres.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $e['email'] = 'Ingresa un correo electrónico válido.';
        } else {
            $stmt = Database::pdo()->prepare('SELECT 1 FROM usuarios WHERE email = ?');
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $e['email'] = 'Ya existe una cuenta con ese correo.';
            }
        }
        if (strlen($password) < PASSWORD_MIN) {
            $e['password'] = 'La contraseña debe tener al menos ' . PASSWORD_MIN . ' caracteres.';
        } elseif (strlen($password) > PASSWORD_MAX) {
            $e['password'] = 'La contraseña no puede superar ' . PASSWORD_MAX . ' caracteres.';
        }
        if ($e) {
            Response::error(422, 'validacion', 'Revisa los datos del formulario.', $e);
        }

        $pdo = Database::pdo();
        $rolId = (int)$pdo->query("SELECT id FROM roles WHERE nombre = '" . ROL_CLIENTE . "'")->fetchColumn();
        $pdo->prepare('INSERT INTO usuarios (nombre, email, password, rol_id) VALUES (?, ?, ?, ?)')
            ->execute([$nombre, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $rolId]);
        $u = usuarioPorId((int)$pdo->lastInsertId());

        iniciarSesion();
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int)$u['id'];
        $_SESSION['ultimo_acceso'] = time();
        registrarIntento((int)$u['id'], $email, 'exito');
        Response::ok(self::payload($u, true), null, 201);
    }

    /** POST /api/auth/logout */
    public static function logout(?array $yo, array $p): void
    {
        cerrarSesion();
        Response::ok(['mensaje' => 'Sesión cerrada.']);
    }

    /** GET /api/auth/me */
    public static function me(?array $yo, array $p): void
    {
        Response::ok(self::payload($yo));
    }
}
