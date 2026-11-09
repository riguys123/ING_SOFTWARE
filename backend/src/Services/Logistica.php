<?php
/**
 * Logística e inventario: al confirmarse un pago,
 *   1) DESCUENTA el stock del producto,
 *   2) ASIGNA un IMEI único y disponible a cada unidad vendida,
 *   3) GENERA la orden de despacho.
 * Las tres cosas ocurren en UNA transacción: si algo falla, no queda nada a medias.
 */
final class Logistica
{
    private const SIGUIENTE = ['En_preparacion' => 'En_camino', 'En_camino' => 'Entregado'];

    /**
     * Despacha un pedido PAGADO. Es idempotente: si ya tiene despacho, lo devuelve sin duplicar.
     * @return array ['creado' => bool, 'despacho' => array]
     */
    public static function despachar(int $pedidoId): array
    {
        return Database::transaccion(function (PDO $pdo) use ($pedidoId) {
            $stmt = $pdo->prepare('SELECT id, estado_pago, estado_envio FROM pedidos WHERE id = ?');
            $stmt->execute([$pedidoId]);
            $pedido = $stmt->fetch();
            if (!$pedido) {
                throw new ApiException(404, 'no_encontrado', 'El pedido no existe.');
            }
            if ($pedido['estado_pago'] !== 'Pagado') {
                throw new ApiException(409, 'pedido_no_pagado', 'Solo se despachan pedidos con el pago confirmado.');
            }
            if ($pedido['estado_envio'] === 'Cancelado') {
                throw new ApiException(409, 'pedido_cancelado', 'El pedido está cancelado.');
            }

            $stmt = $pdo->prepare('SELECT id FROM despachos WHERE pedido_id = ?');
            $stmt->execute([$pedidoId]);
            if ($existente = $stmt->fetchColumn()) {
                return ['creado' => false, 'despacho' => self::ver((int)$existente)];
            }

            $ahora = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                'SELECT d.id, d.producto_id, d.cantidad, d.precio_unitario, d.precio_lista, d.descuento_pct,
                        p.marca, p.modelo, p.requiere_imei
                   FROM detalle_pedido d JOIN productos p ON p.id = d.producto_id
                  WHERE d.pedido_id = ? AND d.imei_asignado IS NULL ORDER BY d.id'
            );
            $stmt->execute([$pedidoId]);

            $buscar   = $pdo->prepare("SELECT id, numero_imei FROM imeis WHERE producto_id = ? AND estado = 'Disponible' ORDER BY id LIMIT ?");
            $descontar = $pdo->prepare('UPDATE productos SET stock_actual = stock_actual - ?, actualizado_en = ? WHERE id = ? AND stock_actual >= ?');
            $vender   = $pdo->prepare("UPDATE imeis SET estado = 'Vendido', pedido_id = ?, vendido_en = ? WHERE id = ? AND estado = 'Disponible'");
            $primera  = $pdo->prepare('UPDATE detalle_pedido SET imei_asignado = ?, cantidad = 1 WHERE id = ?');
            $otra     = $pdo->prepare('INSERT INTO detalle_pedido (pedido_id, producto_id, imei_asignado, cantidad, precio_unitario, precio_lista, descuento_pct)
                                VALUES (?, ?, ?, 1, ?, ?, ?)');

            foreach ($stmt->fetchAll() as $linea) {
                $n = (int)$linea['cantidad'];

                // Accesorios y complementos: solo se descuenta la cantidad (no tienen IMEI)
                if ((int)$linea['requiere_imei'] === 0) {
                    $descontar->execute([$n, $ahora, $linea['producto_id'], $n]);
                    if ($descontar->rowCount() === 0) {
                        throw new ApiException(409, 'stock_insuficiente',
                            'No hay stock suficiente de ' . $linea['marca'] . ' ' . $linea['modelo'] . ' para despachar el pedido.');
                    }
                    continue;
                }
                $buscar->bindValue(1, (int)$linea['producto_id'], PDO::PARAM_INT);
                $buscar->bindValue(2, $n, PDO::PARAM_INT);
                $buscar->execute();
                $imeis = $buscar->fetchAll();

                $descontar->execute([$n, $ahora, $linea['producto_id'], $n]);
                if (count($imeis) < $n || $descontar->rowCount() === 0) {
                    throw new ApiException(409, 'stock_insuficiente',
                        'No hay stock suficiente de ' . $linea['marca'] . ' ' . $linea['modelo'] . ' para despachar el pedido.');
                }

                // Cada unidad queda en su propia fila de detalle con su IMEI único
                foreach ($imeis as $i => $imei) {
                    $vender->execute([$pedidoId, $ahora, $imei['id']]);
                    if ($vender->rowCount() !== 1) {
                        throw new ApiException(409, 'imei_en_uso', 'El IMEI ya fue asignado a otro pedido. Reintenta.');
                    }
                    if ($i === 0) {
                        $primera->execute([$imei['numero_imei'], $linea['id']]);
                    } else {
                        $otra->execute([$pedidoId, $linea['producto_id'], $imei['numero_imei'], $linea['precio_unitario'],
                                        $linea['precio_lista'], $linea['descuento_pct']]);
                    }
                }
            }

            $guia = 'SC-' . date('ymd') . '-' . str_pad((string)$pedidoId, 5, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO despachos (pedido_id, codigo_guia, creado_en) VALUES (?, ?, ?)')
                ->execute([$pedidoId, $guia, $ahora]);
            $despachoId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE pedidos SET estado_envio = 'En_preparacion', actualizado_en = ? WHERE id = ?")
                ->execute([$ahora, $pedidoId]);

            return ['creado' => true, 'despacho' => self::ver($despachoId)];
        });
    }

    /**
     * Avanza el despacho: En_preparacion -> En_camino -> Entregado.
     * Al entregar, los IMEI pasan a 'Entregado'.
     */
    public static function cambiarEstado(int $despachoId, string $nuevo, array $operador): array
    {
        return Database::transaccion(function (PDO $pdo) use ($despachoId, $nuevo, $operador) {
            $stmt = $pdo->prepare('SELECT * FROM despachos WHERE id = ?');
            $stmt->execute([$despachoId]);
            $d = $stmt->fetch();
            if (!$d) {
                throw new ApiException(404, 'no_encontrado', 'El despacho no existe.');
            }
            if ((self::SIGUIENTE[$d['estado']] ?? null) !== $nuevo) {
                throw new ApiException(422, 'transicion_invalida',
                    'No se puede pasar de "' . $d['estado'] . '" a "' . $nuevo . '".');
            }
            // Un repartidor no puede tocar entregas asignadas a otro compañero
            if ($operador['rol'] === ROL_REPARTIDOR && $d['repartidor_id'] && (int)$d['repartidor_id'] !== (int)$operador['id']) {
                throw new ApiException(403, 'acceso_denegado', 'Este despacho está asignado a otro repartidor.');
            }

            $ahora = date('Y-m-d H:i:s');
            if ($nuevo === 'En_camino') {
                $repartidor = $operador['rol'] === ROL_REPARTIDOR ? (int)$operador['id'] : null;
                $pdo->prepare('UPDATE despachos SET estado = ?, enviado_en = ?, repartidor_id = COALESCE(?, repartidor_id) WHERE id = ?')
                    ->execute([$nuevo, $ahora, $repartidor, $despachoId]);
            } else { // Entregado
                $pdo->prepare('UPDATE despachos SET estado = ?, entregado_en = ? WHERE id = ?')
                    ->execute([$nuevo, $ahora, $despachoId]);
                $pdo->prepare("UPDATE imeis SET estado = 'Entregado', entregado_en = ? WHERE pedido_id = ? AND estado = 'Vendido'")
                    ->execute([$ahora, $d['pedido_id']]);
            }
            $pdo->prepare('UPDATE pedidos SET estado_envio = ?, actualizado_en = ? WHERE id = ?')
                ->execute([$nuevo, $ahora, $d['pedido_id']]);
            // Mensaje automático al cliente: "va en camino" / "fue entregado"
            Notificador::generar((int)$d['pedido_id'], $nuevo === 'En_camino' ? 'envio' : 'entrega');

            return self::ver($despachoId);
        });
    }

    /** Despacho con datos del pedido, del cliente y los IMEI asignados */
    public static function ver(int $id): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT d.id, d.pedido_id, d.codigo_guia, d.estado, d.creado_en, d.enviado_en, d.entregado_en,
                    p.direccion_envio, p.ciudad, p.telefono, p.total, u.nombre AS cliente, r.nombre AS repartidor
               FROM despachos d
               JOIN pedidos p ON p.id = d.pedido_id
               JOIN usuarios u ON u.id = p.usuario_id
               LEFT JOIN usuarios r ON r.id = d.repartidor_id
              WHERE d.id = ?'
        );
        $stmt->execute([$id]);
        $d = $stmt->fetch();
        $d['id'] = (int)$d['id'];
        $d['pedido_id'] = (int)$d['pedido_id'];
        $d['total'] = (float)$d['total'];

        $stmt = $pdo->prepare(
            'SELECT pr.marca, pr.modelo, dp.imei_asignado AS imei, dp.cantidad
               FROM detalle_pedido dp JOIN productos pr ON pr.id = dp.producto_id
              WHERE dp.pedido_id = ? ORDER BY pr.requiere_imei DESC, dp.id'
        );
        $stmt->execute([$d['pedido_id']]);
        $d['equipos'] = array_map(function ($e) {
            $e['cantidad'] = (int)$e['cantidad'];
            return $e;
        }, $stmt->fetchAll());
        return $d;
    }
}
