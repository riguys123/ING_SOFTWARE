<?php
/**
 * Promociones y banner de ofertas.
 *  - Público: GET /promociones/vigentes (el carrusel de la tienda).
 *  - Staff (módulo promociones): listar todas, crear, editar, eliminar y subir la imagen del banner.
 */
final class PromocionController
{
    private const SELECT = "SELECT pr.*, p.marca || ' ' || p.modelo AS producto, c.nombre AS categoria,
                                   (pr.activo = 1 AND date('now','localtime') BETWEEN pr.fecha_inicio AND pr.fecha_fin) AS vigente
                              FROM promociones pr
                              LEFT JOIN productos p ON p.id = pr.producto_id
                              LEFT JOIN categorias c ON c.id = pr.categoria_id";

    private static function formatear(array $r): array
    {
        foreach (['id', 'descuento_pct', 'producto_id', 'categoria_id', 'activo', 'vigente'] as $k) {
            if (array_key_exists($k, $r) && $r[$k] !== null) {
                $r[$k] = (int)$r[$k];
            }
        }
        $r['vigente'] = !empty($r['vigente']);
        return $r;
    }

    /** GET /api/promociones/vigentes */
    public static function vigentes(?array $yo, array $p): void
    {
        $filas = Database::pdo()->query(
            self::SELECT . " WHERE pr.activo = 1 AND date('now','localtime') BETWEEN pr.fecha_inicio AND pr.fecha_fin
                             ORDER BY pr.descuento_pct DESC, pr.id DESC LIMIT 8"
        )->fetchAll();
        Response::ok(array_map([self::class, 'formatear'], $filas));
    }

    /** GET /api/promociones */
    public static function listar(array $yo, array $p): void
    {
        $filas = Database::pdo()->query(self::SELECT . ' ORDER BY pr.fecha_fin DESC, pr.id DESC')->fetchAll();
        Response::ok(array_map([self::class, 'formatear'], $filas));
    }

    private static function fechaValida(string $f): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $f);
        return $d && $d->format('Y-m-d') === $f;
    }

    private static function validar(array $d): array
    {
        $e = [];
        $titulo = trim((string)($d['titulo'] ?? ''));
        if (mb_strlen($titulo) < 3 || mb_strlen($titulo) > 80) {
            $e['titulo'] = 'El título debe tener entre 3 y 80 caracteres.';
        }
        $desc = trim((string)($d['descripcion'] ?? ''));
        if (mb_strlen($desc) > 200) {
            $e['descripcion'] = 'Máximo 200 caracteres.';
        }
        $pct = filter_var($d['descuento_pct'] ?? 0, FILTER_VALIDATE_INT);
        if ($pct === false || $pct < 0 || $pct > 90) {
            $e['descuento_pct'] = 'El descuento es un número entero entre 0 y 90.';
            $pct = 0;
        }
        $pdo = Database::pdo();
        $prod = ($d['producto_id'] ?? '') === '' ? null : filter_var($d['producto_id'], FILTER_VALIDATE_INT);
        $cat  = ($d['categoria_id'] ?? '') === '' ? null : filter_var($d['categoria_id'], FILTER_VALIDATE_INT);
        if ($prod !== null && $prod !== false) {
            $s = $pdo->prepare('SELECT 1 FROM productos WHERE id = ? AND activo = 1');
            $s->execute([$prod]);
            if (!$s->fetch()) {
                $e['producto_id'] = 'El producto no existe.';
            }
        } elseif ($prod === false) {
            $e['producto_id'] = 'Producto inválido.';
        }
        if ($cat !== null && $cat !== false) {
            $s = $pdo->prepare('SELECT 1 FROM categorias WHERE id = ?');
            $s->execute([$cat]);
            if (!$s->fetch()) {
                $e['categoria_id'] = 'La categoría no existe.';
            }
        } elseif ($cat === false) {
            $e['categoria_id'] = 'Categoría inválida.';
        }
        if ($prod && $cat) {
            $e['producto_id'] = 'Elige un producto O una categoría, no ambos.';
        }
        if ($pct > 0 && !$prod && !$cat) {
            $e['producto_id'] = 'Para aplicar un descuento elige un producto o una categoría.';
        }
        $ini = trim((string)($d['fecha_inicio'] ?? ''));
        $fin = trim((string)($d['fecha_fin'] ?? ''));
        if (!self::fechaValida($ini)) {
            $e['fecha_inicio'] = 'Fecha de inicio inválida.';
        }
        if (!self::fechaValida($fin)) {
            $e['fecha_fin'] = 'Fecha de fin inválida.';
        } elseif (!isset($e['fecha_inicio']) && $fin < $ini) {
            $e['fecha_fin'] = 'La fecha de fin no puede ser anterior al inicio.';
        }
        $imagen = trim((string)($d['imagen'] ?? ''));
        // La imagen del banner debe estar en la carpeta de promociones (así borrar la promoción no toca fotos de productos)
        if ($imagen !== '' && (!Uploads::rutaValida($imagen) || str_starts_with($imagen, 'uploads/productos/'))) {
            $e['imagen'] = 'Imagen inválida: súbela desde el formulario.';
        }
        if ($e) {
            Response::error(422, 'validacion', 'Revisa los campos de la promoción.', $e);
        }
        return [
            'titulo' => $titulo, 'descripcion' => $desc !== '' ? $desc : null, 'imagen' => $imagen !== '' ? $imagen : null,
            'descuento_pct' => $pct, 'producto_id' => $prod ?: null, 'categoria_id' => $cat ?: null,
            'fecha_inicio' => $ini, 'fecha_fin' => $fin,
            'activo' => filter_var($d['activo'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false ? 0 : 1,
        ];
    }

    private static function obtener(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(self::SELECT . ' WHERE pr.id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r ? self::formatear($r) : null;
    }

    /** POST /api/promociones */
    public static function crear(array $yo, array $p): void
    {
        $r = self::validar(Request::json());
        $pdo = Database::pdo();
        $campos = array_keys($r);
        $pdo->prepare('INSERT INTO promociones (' . implode(', ', $campos) . ') VALUES (:' . implode(', :', $campos) . ')')->execute($r);
        Response::ok(self::obtener((int)$pdo->lastInsertId()), null, 201);
    }

    /** PUT /api/promociones/:id */
    public static function actualizar(array $yo, array $p): void
    {
        $antes = self::obtener($p['id']);
        if (!$antes) {
            Response::error(404, 'no_encontrado', 'La promoción no existe.');
        }
        $r = self::validar(Request::json());
        if ($antes['imagen'] && $antes['imagen'] !== $r['imagen']) {
            Uploads::borrar($antes['imagen']);
        }
        $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($r)));
        $r['id'] = $p['id'];
        Database::pdo()->prepare("UPDATE promociones SET $sets WHERE id = :id")->execute($r);
        Response::ok(self::obtener($p['id']));
    }

    /** DELETE /api/promociones/:id */
    public static function eliminar(array $yo, array $p): void
    {
        $antes = self::obtener($p['id']);
        if (!$antes) {
            Response::error(404, 'no_encontrado', 'La promoción no existe.');
        }
        Database::pdo()->prepare('DELETE FROM promociones WHERE id = ?')->execute([$p['id']]);
        if ($antes['imagen']) {
            Uploads::borrar($antes['imagen']);
        }
        Response::ok(['mensaje' => 'Promoción eliminada.']);
    }

    /** POST /api/promociones/imagen — multipart, campo "imagen". Devuelve la ruta para guardarla en el formulario. */
    public static function subirImagen(array $yo, array $p): void
    {
        Response::ok(['ruta' => Uploads::guardarImagen('imagen', 'promociones')], null, 201);
    }
}
