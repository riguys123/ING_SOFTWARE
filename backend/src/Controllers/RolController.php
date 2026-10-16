<?php
/**
 * Roles y matriz de permisos.
 */
final class RolController
{
    /** GET /api/roles — Admin ve todos; los demás solo los roles de su área */
    public static function listar(array $yo, array $p): void
    {
        $sql = 'SELECT r.id, r.nombre, r.descripcion, r.area,
                       (SELECT COUNT(*) FROM usuarios u WHERE u.rol_id = r.id) AS usuarios
                  FROM roles r';
        $params = [];
        if ($yo['rol'] !== ROL_ADMIN) {
            $sql .= ' WHERE r.area = ?';
            $params[] = $yo['area'];
        }
        $stmt = Database::pdo()->prepare($sql . ' ORDER BY r.id');
        $stmt->execute($params);
        $roles = $stmt->fetchAll();
        foreach ($roles as &$r) {
            $r['id'] = (int)$r['id'];
            $r['usuarios'] = (int)$r['usuarios'];
        }
        Response::ok($roles);
    }

    /** GET /api/permisos — matriz rol x módulo (solo Admin) */
    public static function matriz(array $yo, array $p): void
    {
        $pdo = Database::pdo();
        $roles = $pdo->query('SELECT id, nombre, area FROM roles ORDER BY id')->fetchAll();
        $modulos = $pdo->query('SELECT clave, nombre, descripcion, grupo FROM modulos ORDER BY orden')->fetchAll();

        $matriz = [];
        foreach ($pdo->query('SELECT rol_id, modulo_clave, nivel FROM permisos_rol') as $f) {
            $matriz[(int)$f['rol_id']][$f['modulo_clave']] = $f['nivel'];
        }
        Response::ok(['roles' => $roles, 'modulos' => $modulos, 'matriz' => (object)$matriz]);
    }
}
