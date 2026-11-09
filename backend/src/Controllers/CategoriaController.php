<?php
/**
 * Categorías del catálogo (Teléfonos, Accesorios, Complementos...).
 *  - Público: GET /categorias (para las pestañas de la tienda).
 *  - Staff (módulo catalogo, total): crear, editar y eliminar.
 * usa_imei = 1 => los productos de la categoría se controlan por IMEI; 0 => solo por cantidad.
 */
final class CategoriaController
{
    private static function formatear(array $c): array
    {
        foreach (['id', 'usa_imei', 'orden', 'activo', 'productos'] as $k) {
            if (isset($c[$k])) {
                $c[$k] = (int)$c[$k];
            }
        }
        return $c;
    }

    /** GET /api/categorias — con la cantidad de productos activos de cada una */
    public static function listar(?array $yo, array $p): void
    {
        $filas = Database::pdo()->query(
            'SELECT c.id, c.nombre, c.descripcion, c.icono, c.usa_imei, c.orden, c.activo,
                    (SELECT COUNT(*) FROM productos x WHERE x.categoria_id = c.id AND x.activo = 1) AS productos
               FROM categorias c WHERE c.activo = 1 ORDER BY c.orden, c.nombre'
        )->fetchAll();
        Response::ok(array_map([self::class, 'formatear'], $filas));
    }

    private static function validar(array $d, ?int $id): array
    {
        $e = [];
        $nombre = trim((string)($d['nombre'] ?? ''));
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 40) {
            $e['nombre'] = 'El nombre debe tener entre 3 y 40 caracteres.';
        } else {
            $stmt = Database::pdo()->prepare('SELECT id FROM categorias WHERE nombre = ? COLLATE NOCASE AND id <> ?');
            $stmt->execute([$nombre, $id ?? 0]);
            if ($stmt->fetch()) {
                $e['nombre'] = 'Ya existe una categoría con ese nombre.';
            }
        }
        $descripcion = trim((string)($d['descripcion'] ?? ''));
        if (mb_strlen($descripcion) > 150) {
            $e['descripcion'] = 'Máximo 150 caracteres.';
        }
        $icono = (string)($d['icono'] ?? 'etiqueta');
        if (!preg_match('/^[a-z_]{2,20}$/', $icono)) {
            $icono = 'etiqueta';
        }
        $orden = filter_var($d['orden'] ?? 0, FILTER_VALIDATE_INT);
        if ($orden === false || $orden < 0 || $orden > 99) {
            $e['orden'] = 'El orden es un número entre 0 y 99.';
        }
        if ($e) {
            Response::error(422, 'validacion', 'Revisa los campos de la categoría.', $e);
        }
        return [
            'nombre' => $nombre, 'descripcion' => $descripcion !== '' ? $descripcion : null, 'icono' => $icono,
            'usa_imei' => filter_var($d['usa_imei'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0, 'orden' => $orden,
        ];
    }

    /** POST /api/categorias */
    public static function crear(array $yo, array $p): void
    {
        $r = self::validar(Request::json(), null);
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO categorias (nombre, descripcion, icono, usa_imei, orden) VALUES (:nombre, :descripcion, :icono, :usa_imei, :orden)')
            ->execute($r);
        $stmt = $pdo->prepare('SELECT * FROM categorias WHERE id = ?');
        $stmt->execute([$pdo->lastInsertId()]);
        Response::ok(self::formatear($stmt->fetch()), null, 201);
    }

    /** PUT /api/categorias/:id */
    public static function actualizar(array $yo, array $p): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM categorias WHERE id = ?');
        $stmt->execute([$p['id']]);
        $actual = $stmt->fetch();
        if (!$actual) {
            Response::error(404, 'no_encontrado', 'La categoría no existe.');
        }
        $r = self::validar(Request::json(), $p['id']);
        // El tipo de control (IMEI / cantidad) no cambia si ya hay productos: rompería el inventario
        if ((int)$actual['usa_imei'] !== $r['usa_imei']) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id = ?');
            $stmt->execute([$p['id']]);
            if ((int)$stmt->fetchColumn() > 0) {
                Response::error(409, 'categoria_con_productos',
                    'No se puede cambiar el tipo de control porque la categoría ya tiene productos.');
            }
        }
        $r['id'] = $p['id'];
        $pdo->prepare('UPDATE categorias SET nombre = :nombre, descripcion = :descripcion, icono = :icono, usa_imei = :usa_imei, orden = :orden WHERE id = :id')
            ->execute($r);
        $stmt = $pdo->prepare('SELECT * FROM categorias WHERE id = ?');
        $stmt->execute([$p['id']]);
        Response::ok(self::formatear($stmt->fetch()));
    }

    /** DELETE /api/categorias/:id — solo si no tiene productos */
    public static function eliminar(array $yo, array $p): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE categoria_id = ?');
        $stmt->execute([$p['id']]);
        if ((int)$stmt->fetchColumn() > 0) {
            Response::error(409, 'categoria_con_productos', 'La categoría tiene productos: muévelos a otra antes de eliminarla.');
        }
        $stmt = $pdo->prepare('DELETE FROM categorias WHERE id = ?');
        $stmt->execute([$p['id']]);
        if ($stmt->rowCount() === 0) {
            Response::error(404, 'no_encontrado', 'La categoría no existe.');
        }
        Response::ok(['mensaje' => 'Categoría eliminada.']);
    }
}
