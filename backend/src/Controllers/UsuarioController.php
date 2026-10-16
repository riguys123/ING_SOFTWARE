<?php
/**
 * CRUD de usuarios.
 *  - Admin: lectura y escritura sobre todos los usuarios.
 *  - Jefe de Distribución / Jefe de Almacén: solo VEN usuarios de su área.
 *  - Cualquier usuario: puede cambiar su propia contraseña.
 */
final class UsuarioController
{
    private const COLUMNAS =
        'u.id, u.nombre, u.email, u.rol_id, r.nombre AS rol, r.area, u.activo, u.creado_en, u.actualizado_en';

    // ---------- Utilidades ----------

    /** Agrega a la consulta el filtro de área para quienes no son Admin */
    private static function filtroArea(array $yo, string &$where, array &$params): void
    {
        if ($yo['rol'] !== ROL_ADMIN) {
            $where .= ' AND r.area = :area_ambito';
            $params[':area_ambito'] = $yo['area'];
        }
    }

    private static function buscar(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?'
        );
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        return $u ? self::formatear($u) : null;
    }

    private static function formatear(array $u): array
    {
        $u['id'] = (int)$u['id'];
        $u['rol_id'] = (int)$u['rol_id'];
        $u['activo'] = (int)$u['activo'];
        return $u;
    }

    /** Valida los campos de usuario; devuelve [campo => mensaje] */
    private static function validar(array $d, bool $crear, ?int $idActual): array
    {
        $e = [];
        $nombre = trim((string)($d['nombre'] ?? ''));
        $email  = mb_strtolower(trim((string)($d['email'] ?? '')));

        if ($nombre === '') {
            $e['nombre'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 100) {
            $e['nombre'] = 'El nombre debe tener entre 2 y 100 caracteres.';
        }

        if ($email === '') {
            $e['email'] = 'El correo es obligatorio.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $e['email'] = 'Ingresa un correo electrónico válido.';
        } else {
            $stmt = Database::pdo()->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
            $stmt->execute([$email, $idActual ?? 0]);
            if ($stmt->fetch()) {
                $e['email'] = 'Ya existe un usuario con ese correo.';
            }
        }

        $rolId = filter_var($d['rol_id'] ?? null, FILTER_VALIDATE_INT);
        if ($rolId === false) {
            $e['rol_id'] = 'Selecciona un rol.';
        } else {
            $stmt = Database::pdo()->prepare('SELECT 1 FROM roles WHERE id = ?');
            $stmt->execute([$rolId]);
            if (!$stmt->fetch()) {
                $e['rol_id'] = 'El rol seleccionado no existe.';
            }
        }

        if ($crear) {
            $err = self::validarPassword((string)($d['password'] ?? ''));
            if ($err) {
                $e['password'] = $err;
            }
        }
        return $e;
    }

    private static function validarPassword(string $p): ?string
    {
        if (strlen($p) < PASSWORD_MIN) {
            return 'La contraseña debe tener al menos ' . PASSWORD_MIN . ' caracteres.';
        }
        if (strlen($p) > PASSWORD_MAX) {
            return 'La contraseña no puede superar ' . PASSWORD_MAX . ' caracteres.';
        }
        return null;
    }

    private static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }

    private static function aBooleano(mixed $v): ?int
    {
        $b = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $b === null ? null : (int)$b;
    }

    // ---------- Endpoints ----------

    /** GET /api/usuarios?q=&rol_id=&activo=&pagina=&por_pagina= */
    public static function listar(array $yo, array $p): void
    {
        $where = ' WHERE 1=1';
        $params = [];
        self::filtroArea($yo, $where, $params);

        $q = Request::query('q');
        if ($q !== '') {
            $where .= " AND (u.nombre LIKE :q ESCAPE '\\' OR u.email LIKE :q ESCAPE '\\')";
            $params[':q'] = Request::likeSeguro($q);
        }
        $rolId = filter_var(Request::query('rol_id'), FILTER_VALIDATE_INT);
        if ($rolId !== false) {
            $where .= ' AND u.rol_id = :rol_id';
            $params[':rol_id'] = $rolId;
        }
        $activo = Request::query('activo');
        if ($activo === '0' || $activo === '1') {
            $where .= ' AND u.activo = :activo';
            $params[':activo'] = (int)$activo;
        }

        $pagina = Request::entero('pagina', 1, 1, 100000);
        $porPagina = Request::entero('por_pagina', 10, 1, 50);
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id = u.rol_id' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = 'SELECT ' . self::COLUMNAS . ' FROM usuarios u JOIN roles r ON r.id = u.rol_id'
             . $where . ' ORDER BY u.id ASC LIMIT :lim OFFSET :off';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();

        Response::ok(array_map([self::class, 'formatear'], $stmt->fetchAll()), [
            'total'          => $total,
            'pagina'         => $pagina,
            'por_pagina'     => $porPagina,
            'total_paginas'  => max(1, (int)ceil($total / $porPagina)),
        ]);
    }

    /** GET /api/usuarios/:id */
    public static function ver(array $yo, array $p): void
    {
        $u = self::buscar($p['id']);
        if (!$u) {
            Response::error(404, 'no_encontrado', 'El usuario no existe.');
        }
        if ($yo['rol'] !== ROL_ADMIN && $u['area'] !== $yo['area']) {
            Response::error(403, 'fuera_de_area', 'Este usuario no pertenece a tu área.');
        }
        Response::ok($u);
    }

    /** POST /api/usuarios (solo Admin) */
    public static function crear(array $yo, array $p): void
    {
        $d = Request::json();
        $errores = self::validar($d, true, null);
        if ($errores) {
            Response::error(422, 'validacion', 'Revisa los datos del formulario.', $errores);
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO usuarios (nombre, email, password, rol_id, activo, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, 1, ?, ?)'
        );
        $ahora = date('Y-m-d H:i:s');
        $stmt->execute([
            trim($d['nombre']),
            mb_strtolower(trim($d['email'])),
            self::hash($d['password']),
            (int)$d['rol_id'],
            $ahora, $ahora,
        ]);
        Response::ok(self::buscar((int)Database::pdo()->lastInsertId()), null, 201);
    }

    /** PUT /api/usuarios/:id (solo Admin): nombre, email, rol_id, activo */
    public static function actualizar(array $yo, array $p): void
    {
        $actual = self::buscar($p['id']);
        if (!$actual) {
            Response::error(404, 'no_encontrado', 'El usuario no existe.');
        }
        $d = Request::json();
        $errores = self::validar($d, false, $p['id']);

        $activo = array_key_exists('activo', $d) ? self::aBooleano($d['activo']) : $actual['activo'];
        if ($activo === null) {
            $errores['activo'] = 'Valor de estado no válido.';
        }

        // Un Admin no puede desactivarse ni quitarse su propio rol de Admin (evita quedarse sin acceso)
        if ($p['id'] === (int)$yo['id']) {
            if ($activo === 0) {
                $errores['activo'] = 'No puedes desactivarte a ti mismo.';
            }
            if (isset($d['rol_id']) && (int)$d['rol_id'] !== (int)$yo['rol_id']) {
                $errores['rol_id'] = 'No puedes cambiar tu propio rol.';
            }
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisa los datos del formulario.', $errores);
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE usuarios SET nombre = ?, email = ?, rol_id = ?, activo = ?, actualizado_en = ? WHERE id = ?'
        );
        $stmt->execute([
            trim($d['nombre']),
            mb_strtolower(trim($d['email'])),
            (int)$d['rol_id'],
            $activo,
            date('Y-m-d H:i:s'),
            $p['id'],
        ]);
        Response::ok(self::buscar($p['id']));
    }

    /**
     * PATCH /api/usuarios/:id/password
     *  - Uno mismo: debe enviar password_actual.
     *  - Admin sobre otro usuario: restablece sin conocer la anterior.
     */
    public static function cambiarPassword(array $yo, array $p): void
    {
        $d = Request::json();
        $esPropia = $p['id'] === (int)$yo['id'];
        if (!$esPropia && $yo['rol'] !== ROL_ADMIN) {
            Response::error(403, 'acceso_denegado', 'Solo puedes cambiar tu propia contraseña.');
        }

        $stmt = Database::pdo()->prepare('SELECT password FROM usuarios WHERE id = ?');
        $stmt->execute([$p['id']]);
        $hashActual = $stmt->fetchColumn();
        if ($hashActual === false) {
            Response::error(404, 'no_encontrado', 'El usuario no existe.');
        }

        $nueva = (string)($d['password'] ?? '');
        $errores = [];
        if ($err = self::validarPassword($nueva)) {
            $errores['password'] = $err;
        }
        if ($esPropia) {
            if (!password_verify((string)($d['password_actual'] ?? ''), $hashActual)) {
                $errores['password_actual'] = 'La contraseña actual es incorrecta.';
            } elseif ($nueva === ($d['password_actual'] ?? '')) {
                $errores['password'] = 'La nueva contraseña debe ser distinta a la actual.';
            }
        }
        if ($errores) {
            Response::error(422, 'validacion', 'Revisa los datos del formulario.', $errores);
        }

        $stmt = Database::pdo()->prepare('UPDATE usuarios SET password = ?, actualizado_en = ? WHERE id = ?');
        $stmt->execute([self::hash($nueva), date('Y-m-d H:i:s'), $p['id']]);
        Response::ok(['mensaje' => 'Contraseña actualizada correctamente.']);
    }

    /** DELETE /api/usuarios/:id (solo Admin) — baja lógica: activo = 0 */
    public static function eliminar(array $yo, array $p): void
    {
        if ($p['id'] === (int)$yo['id']) {
            Response::error(422, 'validacion', 'No puedes desactivarte a ti mismo.');
        }
        if (!self::buscar($p['id'])) {
            Response::error(404, 'no_encontrado', 'El usuario no existe.');
        }
        $stmt = Database::pdo()->prepare('UPDATE usuarios SET activo = 0, actualizado_en = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $p['id']]);
        Response::ok(self::buscar($p['id']));
    }
}
