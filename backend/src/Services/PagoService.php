<?php
/**
 * Procesamiento de pagos: valida -> "cobra" en la pasarela simulada -> registra el pago ->
 * si fue aprobado, marca el pedido como Pagado y dispara la logística (stock + IMEI + despacho).
 */
final class PagoService
{
    /**
     * @return array ['aprobado' => bool, 'pago' => array, 'pedido' => array, 'despacho' => ?array]
     */
    public static function procesar(int $pedidoId, int $usuarioId, string $metodo, array $datos): array
    {
        // 1) El pedido debe ser del cliente que paga (si no, responde 404 para no revelar que existe)
        $stmt = Database::pdo()->prepare('SELECT * FROM pedidos WHERE id = ? AND usuario_id = ?');
        $stmt->execute([$pedidoId, $usuarioId]);
        $pedido = $stmt->fetch();
        $stmt->closeCursor();   // libera el bloqueo de lectura antes de abrir la transacción de escritura
        if (!$pedido) {
            throw new ApiException(404, 'no_encontrado', 'El pedido no existe.');
        }
        if ($pedido['estado_pago'] === 'Pagado') {
            throw new ApiException(409, 'pedido_ya_pagado', 'Este pedido ya fue pagado.');
        }
        if ($pedido['estado_envio'] === 'Cancelado') {
            throw new ApiException(409, 'pedido_cancelado', 'Este pedido fue cancelado.');
        }

        // 2) Validación de los datos del método de pago
        $errores = PasarelaSimulada::validar($metodo, $datos);
        if ($errores) {
            throw new ApiException(422, 'validacion', 'Revisa los datos de pago.', $errores);
        }

        // 3) El monto SIEMPRE sale de la BD (el navegador no puede decidir cuánto paga)
        $monto = (float)$pedido['total'];
        $auth = PasarelaSimulada::autorizar($metodo, $datos, $monto);

        // 4) Registro del pago + (si aprobó) confirmación y despacho, todo en una transacción
        return Database::transaccion(function (PDO $pdo) use ($pedidoId, $metodo, $monto, $auth) {
            $ahora = date('Y-m-d H:i:s');
            $pdo->prepare(
                'INSERT INTO pagos (pedido_id, metodo, monto, estado, referencia, tarjeta_ultimos4, motivo_rechazo, fecha)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $pedidoId, $metodo, $monto, $auth['aprobado'] ? 'Aprobado' : 'Rechazado',
                $auth['referencia'], $auth['ultimos4'], $auth['motivo'], $ahora,
            ]);
            $pagoId = (int)$pdo->lastInsertId();

            $despacho = null;
            if ($auth['aprobado']) {
                $pdo->prepare("UPDATE pedidos SET estado_pago = 'Pagado', actualizado_en = ? WHERE id = ?")
                    ->execute([$ahora, $pedidoId]);
                // Logística: descuenta stock, asigna IMEI y crea el despacho.
                // Si no hay stock lanza ApiException y se revierte TAMBIÉN el pago.
                // (En una pasarela real aquí se anularía la autorización de la tarjeta.)
                $despacho = Logistica::despachar($pedidoId)['despacho'];
                // Automatización: confirmación + comprobante listos para enviar por WhatsApp
                Notificador::generar($pedidoId, 'confirmacion');
                Notificador::generar($pedidoId, 'comprobante');
            } else {
                $pdo->prepare("UPDATE pedidos SET estado_pago = 'Rechazado', actualizado_en = ? WHERE id = ?")
                    ->execute([$ahora, $pedidoId]);
            }

            $stmt = $pdo->prepare('SELECT id, metodo, monto, estado, referencia, tarjeta_ultimos4, motivo_rechazo, fecha FROM pagos WHERE id = ?');
            $stmt->execute([$pagoId]);
            return [
                'aprobado' => $auth['aprobado'],
                'pago'     => $stmt->fetch(),
                'pedido'   => PedidoService::obtener($pedidoId),
                'despacho' => $despacho,
            ];
        });
    }
}
