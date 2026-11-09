<?php
/**
 * Lógica de pedidos (checkout). Los controladores solo traducen HTTP <-> estas funciones.
 *
 * Regla de oro: los PRECIOS y TOTALES se calculan aquí con los datos de la BD.
 * Nunca se confía en un precio enviado por el navegador.
 */
final class PedidoService
{
    /** Valida los datos de envío; lanza ApiException 422 con el detalle por campo */
    private static function validarEnvio(array $d): array
    {
        $e = [];
        $direccion = trim((string)($d['direccion_envio'] ?? ''));
        $ciudad    = trim((string)($d['ciudad'] ?? ''));
        $telefono  = trim((string)($d['telefono'] ?? ''));
        $notas     = trim((string)($d['notas'] ?? ''));

        if (mb_strlen($direccion) < 8 || mb_strlen($direccion) > 200) {
            $e['direccion_envio'] = 'Ingresa una dirección de entrega (8 a 200 caracteres).';
        }
        if (mb_strlen($ciudad) < 2 || mb_strlen($ciudad) > 60) {
            $e['ciudad'] = 'Ingresa la ciudad.';
        }
        if (!preg_match('/^[0-9+\-\s]{7,20}$/', $telefono)) {
            $e['telefono'] = 'Ingresa un teléfono válido (7 a 20 dígitos).';
        }
        if (mb_strlen($notas) > 300) {
            $e['notas'] = 'Las notas no pueden superar 300 caracteres.';
        }
        if ($e) {
            throw new ApiException(422, 'validacion', 'Revisa los datos de envío.', $e);
        }
        return [$direccion, $ciudad, $telefono, $notas !== '' ? $notas : null];
    }

    /**
     * Convierte el carrito del cliente en un pedido (estado_pago = Pendiente).
     * Todo ocurre en una transacción: o se crea el pedido completo o no se crea nada.
     * @return int id del pedido creado
     */
    public static function crearDesdeCarrito(int $usuarioId, array $envio): int
    {
        [$direccion, $ciudad, $telefono, $notas] = self::validarEnvio($envio);

        return Database::transaccion(function (PDO $pdo) use ($usuarioId, $direccion, $ciudad, $telefono, $notas) {
            $stmt = $pdo->prepare(
                'SELECT c.producto_id, c.cantidad, p.marca, p.modelo, p.precio, p.stock_actual, p.activo,
                        ' . Catalogo::descuentoSql('p') . ' AS descuento_pct
                   FROM carrito_items c JOIN productos p ON p.id = c.producto_id
                  WHERE c.usuario_id = ? ORDER BY c.id'
            );
            $stmt->execute([$usuarioId]);
            $lineas = $stmt->fetchAll();
            if (!$lineas) {
                throw new ApiException(422, 'carrito_vacio', 'Tu carrito está vacío.');
            }

            $problemas = [];
            $total = 0.0;
            foreach ($lineas as $l) {
                $nombre = $l['marca'] . ' ' . $l['modelo'];
                if ((int)$l['activo'] !== 1) {
                    $problemas[$nombre] = 'ya no está disponible.';
                } elseif ((int)$l['cantidad'] > (int)$l['stock_actual']) {
                    $problemas[$nombre] = 'solo quedan ' . (int)$l['stock_actual'] . ' unidad(es).';
                }
                $total += round(Catalogo::precioFinal((float)$l['precio'], (int)$l['descuento_pct']) * (int)$l['cantidad'], 2);
            }
            if ($problemas) {
                throw new ApiException(409, 'stock_insuficiente',
                    'Algunos productos del carrito cambiaron. Ajusta las cantidades e inténtalo de nuevo.', $problemas);
            }

            $pdo->prepare(
                'INSERT INTO pedidos (usuario_id, total, direccion_envio, ciudad, telefono, notas, fecha, actualizado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$usuarioId, round($total, 2), $direccion, $ciudad, $telefono, $notas, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $pedidoId = (int)$pdo->lastInsertId();

            $ins = $pdo->prepare(
                'INSERT INTO detalle_pedido (pedido_id, producto_id, cantidad, precio_unitario, precio_lista, descuento_pct)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($lineas as $l) {
                // El precio pagado queda congelado en el pedido (con la promoción vigente en ese momento)
                $ins->execute([$pedidoId, $l['producto_id'], $l['cantidad'],
                               Catalogo::precioFinal((float)$l['precio'], (int)$l['descuento_pct']),
                               $l['precio'], (int)$l['descuento_pct']]);
            }
            $pdo->prepare('DELETE FROM carrito_items WHERE usuario_id = ?')->execute([$usuarioId]);
            return $pedidoId;
        });
    }

    /** Pedido completo: cabecera + líneas (con IMEI) + pagos + despacho */
    public static function obtener(int $id): ?array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT p.*, u.nombre AS cliente_nombre, u.email AS cliente_email
               FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) {
            return null;
        }
        $p['id'] = (int)$p['id'];
        $p['usuario_id'] = (int)$p['usuario_id'];
        $p['total'] = (float)$p['total'];

        $stmt = $pdo->prepare(
            'SELECT d.id, d.producto_id, d.imei_asignado, d.cantidad, d.precio_unitario, d.precio_lista, d.descuento_pct,
                    pr.marca, pr.modelo, pr.fotos, pr.requiere_imei
               FROM detalle_pedido d JOIN productos pr ON pr.id = d.producto_id
              WHERE d.pedido_id = ? ORDER BY d.producto_id, d.id'
        );
        $stmt->execute([$id]);
        $p['items'] = array_map(function ($i) {
            $i['id'] = (int)$i['id'];
            $i['producto_id'] = (int)$i['producto_id'];
            $i['cantidad'] = (int)$i['cantidad'];
            $i['precio_unitario'] = (float)$i['precio_unitario'];
            $i['precio_lista'] = (float)($i['precio_lista'] ?? $i['precio_unitario']);
            $i['descuento_pct'] = (int)$i['descuento_pct'];
            $i['requiere_imei'] = (int)$i['requiere_imei'];
            $i['subtotal'] = round($i['precio_unitario'] * $i['cantidad'], 2);
            $i['fotos'] = Catalogo::fotos($i['fotos']);
            return $i;
        }, $stmt->fetchAll());

        $stmt = $pdo->prepare(
            'SELECT id, metodo, monto, estado, referencia, tarjeta_ultimos4, motivo_rechazo, fecha
               FROM pagos WHERE pedido_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$id]);
        $p['pagos'] = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT d.id, d.codigo_guia, d.estado, d.creado_en, d.enviado_en, d.entregado_en,
                    r.nombre AS repartidor
               FROM despachos d LEFT JOIN usuarios r ON r.id = d.repartidor_id WHERE d.pedido_id = ?'
        );
        $stmt->execute([$id]);
        $p['despacho'] = $stmt->fetch() ?: null;
        return $p;
    }

    /** Cancela un pedido que aún no se pagó. Quien llama ya verificó que puede hacerlo. */
    public static function cancelar(int $id): void
    {
        Database::transaccion(function (PDO $pdo) use ($id) {
            $stmt = $pdo->prepare('SELECT estado_pago, estado_envio FROM pedidos WHERE id = ?');
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p) {
                throw new ApiException(404, 'no_encontrado', 'El pedido no existe.');
            }
            if ($p['estado_pago'] === 'Pagado') {
                throw new ApiException(409, 'pedido_pagado',
                    'Un pedido ya pagado no se puede cancelar desde aquí (requiere reembolso).');
            }
            if ($p['estado_envio'] === 'Cancelado') {
                throw new ApiException(409, 'ya_cancelado', 'El pedido ya estaba cancelado.');
            }
            $pdo->prepare("UPDATE pedidos SET estado_envio = 'Cancelado', actualizado_en = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s'), $id]);
        });
    }
}
