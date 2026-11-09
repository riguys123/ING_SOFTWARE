<?php
/**
 * Carrito de compras (módulo "tienda", rol Cliente). Vive en la BD: sobrevive a cerrar la sesión.
 * Toda consulta filtra por usuario_id = el usuario autenticado, así nadie puede leer
 * ni modificar el carrito de otra persona aunque adivine un id.
 */
final class CarritoController
{
    private static function carrito(int $usuarioId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT c.id, c.producto_id, c.cantidad, p.marca, p.modelo, p.precio, p.stock_actual, p.fotos, p.activo,
                    ' . Catalogo::descuentoSql('p') . ' AS descuento_pct
               FROM carrito_items c JOIN productos p ON p.id = c.producto_id
              WHERE c.usuario_id = ? ORDER BY c.id'
        );
        $stmt->execute([$usuarioId]);
        $total = 0.0;
        $unidades = 0;
        $items = [];
        foreach ($stmt->fetchAll() as $i) {
            $cantidad = (int)$i['cantidad'];
            $lista = (float)$i['precio'];
            $dto = (int)$i['descuento_pct'];
            $precio = Catalogo::precioFinal($lista, $dto);   // con la promoción vigente
            $subtotal = round($precio * $cantidad, 2);
            $total += $subtotal;
            $unidades += $cantidad;
            $items[] = [
                'id' => (int)$i['id'], 'producto_id' => (int)$i['producto_id'],
                'marca' => $i['marca'], 'modelo' => $i['modelo'], 'precio' => $precio,
                'precio_lista' => $lista, 'descuento_pct' => $dto,
                'cantidad' => $cantidad, 'subtotal' => $subtotal,
                'stock_actual' => (int)$i['stock_actual'], 'fotos' => Catalogo::fotos($i['fotos']),
                // false si ya no hay suficiente stock o el producto fue dado de baja
                'disponible' => (int)$i['activo'] === 1 && $cantidad <= (int)$i['stock_actual'],
            ];
        }
        return ['items' => $items, 'total' => round($total, 2), 'unidades' => $unidades];
    }

    /** GET /api/carrito */
    public static function ver(array $yo, array $p): void
    {
        Response::ok(self::carrito((int)$yo['id']));
    }

    /** POST /api/carrito   { producto_id, cantidad } — suma a lo que ya haya */
    public static function agregar(array $yo, array $p): void
    {
        $d = Request::json();
        $pid = filter_var($d['producto_id'] ?? null, FILTER_VALIDATE_INT);
        $cant = filter_var($d['cantidad'] ?? 1, FILTER_VALIDATE_INT);
        if ($pid === false || $cant === false || $cant < 1) {
            Response::error(422, 'validacion', 'Producto o cantidad inválidos.');
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, marca, modelo, stock_actual FROM productos WHERE id = ? AND activo = 1');
        $stmt->execute([$pid]);
        $prod = $stmt->fetch();
        if (!$prod) {
            Response::error(404, 'no_encontrado', 'El producto no existe o ya no está disponible.');
        }
        if ((int)$prod['stock_actual'] < 1) {
            Response::error(409, 'sin_stock', $prod['marca'] . ' ' . $prod['modelo'] . ' está agotado.');
        }

        $stmt = $pdo->prepare('SELECT cantidad FROM carrito_items WHERE usuario_id = ? AND producto_id = ?');
        $stmt->execute([$yo['id'], $pid]);
        $nueva = (int)$stmt->fetchColumn() + $cant;
        $tope = min(MAX_POR_PRODUCTO, (int)$prod['stock_actual']);
        if ($nueva > $tope) {
            Response::error(422, 'cantidad_excedida',
                "Solo puedes llevar hasta $tope unidad(es) de este modelo.");
        }
        $pdo->prepare(
            'INSERT INTO carrito_items (usuario_id, producto_id, cantidad) VALUES (?, ?, ?)
             ON CONFLICT (usuario_id, producto_id) DO UPDATE SET cantidad = excluded.cantidad'
        )->execute([$yo['id'], $pid, $nueva]);
        Response::ok(self::carrito((int)$yo['id']), null, 201);
    }

    /** PATCH /api/carrito/:id   { cantidad } — fija la cantidad exacta */
    public static function actualizar(array $yo, array $p): void
    {
        $cant = filter_var(Request::json()['cantidad'] ?? null, FILTER_VALIDATE_INT);
        if ($cant === false || $cant < 1) {
            Response::error(422, 'validacion', 'La cantidad debe ser al menos 1.');
        }
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT c.id, p.stock_actual FROM carrito_items c JOIN productos p ON p.id = c.producto_id
              WHERE c.id = ? AND c.usuario_id = ?'
        );
        $stmt->execute([$p['id'], $yo['id']]);
        $item = $stmt->fetch();
        if (!$item) {
            Response::error(404, 'no_encontrado', 'Ese producto no está en tu carrito.');
        }
        $tope = min(MAX_POR_PRODUCTO, (int)$item['stock_actual']);
        if ($cant > $tope) {
            Response::error(422, 'cantidad_excedida', "Solo puedes llevar hasta $tope unidad(es) de este modelo.");
        }
        $pdo->prepare('UPDATE carrito_items SET cantidad = ? WHERE id = ? AND usuario_id = ?')
            ->execute([$cant, $p['id'], $yo['id']]);
        Response::ok(self::carrito((int)$yo['id']));
    }

    /** DELETE /api/carrito/:id */
    public static function quitar(array $yo, array $p): void
    {
        Database::pdo()->prepare('DELETE FROM carrito_items WHERE id = ? AND usuario_id = ?')
            ->execute([$p['id'], $yo['id']]);
        Response::ok(self::carrito((int)$yo['id']));
    }

    /** DELETE /api/carrito */
    public static function vaciar(array $yo, array $p): void
    {
        Database::pdo()->prepare('DELETE FROM carrito_items WHERE usuario_id = ?')->execute([$yo['id']]);
        Response::ok(self::carrito((int)$yo['id']));
    }
}
