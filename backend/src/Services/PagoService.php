<?php
/**
 * Procesamiento de pagos.
 *  - Tarjeta: la pasarela simulada responde al instante (aprobado / rechazado).
 *  - QR y Transferencia/depósito: el dinero llega por fuera del sistema, así que el pago queda
 *    "Por_verificar" hasta que Cobranzas (Admin o Vendedor) revisa el comprobante y lo confirma o rechaza.
 * Al aprobarse (en cualquiera de los dos caminos) se marca el pedido como Pagado y se dispara la
 * logística (stock + IMEI + despacho) y los mensajes automáticos.
 */
final class PagoService
{
    public const MANUALES = ['QR', 'Transferencia'];

    /**
     * @return array ['aprobado' => bool, 'pendiente' => bool, 'pago' => array, 'pedido' => array, 'despacho' => ?array]
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
        if ($pedido['estado_pago'] === 'Por_verificar') {
            throw new ApiException(409, 'pago_en_verificacion', 'Ya enviaste un comprobante de este pedido: está en verificación.');
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

        // QR / transferencia: se registra el comprobante y queda por verificar (no se descuenta stock todavía)
        if (in_array($metodo, self::MANUALES, true)) {
            $img = (string)($datos['imagen'] ?? '');
            if ($img !== '' && !preg_match('#^uploads/pagos/[a-f0-9]{20}\.(jpg|png|webp)$#', $img)) {
                throw new ApiException(422, 'validacion', 'La imagen del comprobante no es válida.', ['imagen' => 'Sube la imagen otra vez.']);
            }
            return Database::transaccion(function (PDO $pdo) use ($pedidoId, $metodo, $monto, $datos, $img) {
                $ahora = date('Y-m-d H:i:s');
                $ref = trim((string)$datos['comprobante']);
                $pdo->prepare(
                    'INSERT INTO pagos (pedido_id, metodo, monto, estado, referencia, comprobante_ref, comprobante_img, fecha)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$pedidoId, $metodo, $monto, 'Por_verificar', ($metodo === 'QR' ? 'QR-' : 'TRF-') . strtoupper(bin2hex(random_bytes(4))),
                            $ref, $img !== '' ? $img : null, $ahora]);
                $pagoId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE pedidos SET estado_pago = 'Por_verificar', actualizado_en = ? WHERE id = ?")->execute([$ahora, $pedidoId]);
                return ['aprobado' => false, 'pendiente' => true, 'pago' => self::pago($pdo, $pagoId),
                        'pedido' => PedidoService::obtener($pedidoId), 'despacho' => null];
            });
        }

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
                $despacho = self::completarPedido($pdo, $pedidoId, $ahora);
            } else {
                $pdo->prepare("UPDATE pedidos SET estado_pago = 'Rechazado', actualizado_en = ? WHERE id = ?")
                    ->execute([$ahora, $pedidoId]);
            }
            return ['aprobado' => $auth['aprobado'], 'pendiente' => false, 'pago' => self::pago($pdo, $pagoId),
                    'pedido' => PedidoService::obtener($pedidoId), 'despacho' => $despacho];
        });
    }

    /** Pedido del cliente que todavía se puede pagar (404 si es de otra persona; 409 si ya no corresponde pagar). */
    private static function pedidoPagable(int $pedidoId, int $usuarioId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM pedidos WHERE id = ? AND usuario_id = ?');
        $stmt->execute([$pedidoId, $usuarioId]);
        $pedido = $stmt->fetch();
        $stmt->closeCursor();
        if (!$pedido) {
            throw new ApiException(404, 'no_encontrado', 'El pedido no existe.');
        }
        if ($pedido['estado_pago'] === 'Pagado') {
            throw new ApiException(409, 'pedido_ya_pagado', 'Este pedido ya fue pagado.');
        }
        if ($pedido['estado_pago'] === 'Por_verificar') {
            throw new ApiException(409, 'pago_en_verificacion', 'Este pedido tiene un comprobante en verificación.');
        }
        if ($pedido['estado_envio'] === 'Cancelado') {
            throw new ApiException(409, 'pedido_cancelado', 'Este pedido fue cancelado.');
        }
        return $pedido;
    }

    /** PayPal, paso 1: crea la orden y devuelve el enlace al que se envía al cliente para aprobar el pago. */
    public static function iniciarPayPal(int $pedidoId, int $usuarioId, string $urlBase): array
    {
        $pedido = self::pedidoPagable($pedidoId, $usuarioId);
        $o = PayPalService::crearOrden($pedidoId, (float)$pedido['total'],
            $urlBase . '?paypal=ok&pedido=' . $pedidoId, $urlBase . '?paypal=cancelado&pedido=' . $pedidoId);
        return ['url' => $o['url'], 'orden' => $o['id'], 'usd' => $o['usd'], 'total_bs' => (float)$pedido['total']];
    }

    /**
     * PayPal, paso 2: el cliente volvió de PayPal. Se captura la orden y se verifica que sea de ESTE pedido,
     * por el monto correcto y completada. Solo entonces el pedido pasa a Pagado y se despacha.
     */
    public static function completarPayPal(int $pedidoId, int $usuarioId, string $ordenId): array
    {
        if (!preg_match('/^[A-Za-z0-9\-]{8,40}$/', $ordenId)) {
            throw new ApiException(422, 'validacion', 'Falta la orden de PayPal.');
        }
        $pedido = self::pedidoPagable($pedidoId, $usuarioId);
        $dup = Database::pdo()->prepare("SELECT COUNT(*) FROM pagos WHERE metodo = 'PayPal' AND comprobante_ref = ?");
        $dup->execute([$ordenId]);
        if ((int)$dup->fetchColumn() > 0) {
            throw new ApiException(409, 'orden_usada', 'Esta orden de PayPal ya fue registrada.');
        }

        $cap = PayPalService::capturar($ordenId);
        $esperado = PayPalService::aUsd((float)$pedido['total']);
        $ok = $cap['estado'] === 'COMPLETED' && $cap['moneda'] === 'USD' && $cap['usd'] === $esperado
            && $cap['pedido'] === 'PED-' . $pedidoId && $cap['captura'] !== '';
        $monto = (float)$pedido['total'];
        $ahora = date('Y-m-d H:i:s');

        if (!$ok) {
            // PayPal no completó el cobro (o no coincide): se registra como rechazado y el cliente puede reintentar
            $motivo = $cap['estado'] === 'COMPLETED' ? 'El pago de PayPal no coincide con el pedido.' : 'PayPal no completó el pago (' . ($cap['estado'] ?: 'sin estado') . ').';
            return Database::transaccion(function (PDO $pdo) use ($pedidoId, $monto, $ordenId, $cap, $motivo, $ahora) {
                $pdo->prepare("INSERT INTO pagos (pedido_id, metodo, monto, estado, referencia, motivo_rechazo, comprobante_ref, fecha) VALUES (?, 'PayPal', ?, 'Rechazado', ?, ?, ?, ?)")
                    ->execute([$pedidoId, $monto, $cap['captura'] ?: 'PP-' . strtoupper(bin2hex(random_bytes(4))), $motivo, $ordenId, $ahora]);
                $pagoId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE pedidos SET estado_pago = 'Rechazado', actualizado_en = ? WHERE id = ?")->execute([$ahora, $pedidoId]);
                return ['aprobado' => false, 'pendiente' => false, 'pago' => self::pago($pdo, $pagoId), 'pedido' => PedidoService::obtener($pedidoId), 'despacho' => null];
            });
        }

        try {
            return Database::transaccion(function (PDO $pdo) use ($pedidoId, $monto, $ordenId, $cap, $ahora) {
                $pdo->prepare("INSERT INTO pagos (pedido_id, metodo, monto, estado, referencia, comprobante_ref, fecha) VALUES (?, 'PayPal', ?, 'Aprobado', ?, ?, ?)")
                    ->execute([$pedidoId, $monto, $cap['captura'], $ordenId, $ahora]);
                $pagoId = (int)$pdo->lastInsertId();
                $despacho = self::completarPedido($pdo, $pedidoId, $ahora);
                return ['aprobado' => true, 'pendiente' => false, 'pago' => self::pago($pdo, $pagoId), 'pedido' => PedidoService::obtener($pedidoId), 'despacho' => $despacho];
            });
        } catch (ApiException $e) {
            // El dinero YA se cobró en PayPal pero no se pudo despachar (p. ej. sin stock): queda por verificar
            // para que Cobranzas lo resuelva (reembolsar desde PayPal o reponer stock). Nunca se pierde el rastro.
            Database::transaccion(function (PDO $pdo) use ($pedidoId, $monto, $ordenId, $cap, $e, $ahora) {
                $pdo->prepare("INSERT INTO pagos (pedido_id, metodo, monto, estado, referencia, motivo_rechazo, comprobante_ref, fecha) VALUES (?, 'PayPal', ?, 'Por_verificar', ?, ?, ?, ?)")
                    ->execute([$pedidoId, $monto, $cap['captura'], 'Cobrado en PayPal pero no se pudo despachar: ' . $e->getMessage(), $ordenId, $ahora]);
                $pdo->prepare("UPDATE pedidos SET estado_pago = 'Por_verificar', actualizado_en = ? WHERE id = ?")->execute([$ahora, $pedidoId]);
            });
            throw new ApiException(409, 'pago_cobrado_sin_despacho',
                'Tu pago en PayPal se recibió, pero no pudimos reservar el producto. Un encargado lo revisará y te contactará (reembolso o reposición).');
        }
    }

    /**
     * PayPal en MODO DEMOSTRACIÓN (sin credenciales): simula que el cliente aprobó el pago en PayPal.
     * No mueve dinero. Solo se permite cuando PayPal real no está configurado.
     */
    public static function completarPayPalDemo(int $pedidoId, int $usuarioId): array
    {
        if (PayPalService::configurado()) {
            throw new ApiException(409, 'paypal_real', 'PayPal real está activo: usa el flujo normal.');
        }
        $pedido = self::pedidoPagable($pedidoId, $usuarioId);
        $monto = (float)$pedido['total'];
        $ahora = date('Y-m-d H:i:s');
        $ref = 'PAYPAL-DEMO-' . strtoupper(bin2hex(random_bytes(5)));
        return Database::transaccion(function (PDO $pdo) use ($pedidoId, $monto, $ref, $ahora) {
            $pdo->prepare("INSERT INTO pagos (pedido_id, metodo, monto, estado, referencia, comprobante_ref, fecha) VALUES (?, 'PayPal', ?, 'Aprobado', ?, ?, ?)")
                ->execute([$pedidoId, $monto, $ref, $ref, $ahora]);
            $pagoId = (int)$pdo->lastInsertId();
            $despacho = self::completarPedido($pdo, $pedidoId, $ahora);
            return ['aprobado' => true, 'pendiente' => false, 'pago' => self::pago($pdo, $pagoId), 'pedido' => PedidoService::obtener($pedidoId), 'despacho' => $despacho];
        });
    }

    /** Cobranzas confirma que el dinero llegó: el pedido pasa a Pagado y se despacha. */
    public static function confirmar(int $pagoId, int $verificadorId): array
    {
        return Database::transaccion(function (PDO $pdo) use ($pagoId, $verificadorId) {
            $g = self::pendiente($pdo, $pagoId);
            $ahora = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE pagos SET estado = 'Aprobado', verificado_por = ?, verificado_en = ? WHERE id = ?")
                ->execute([$verificadorId, $ahora, $pagoId]);
            // Si no hay stock lanza ApiException y se revierte también la confirmación
            $despacho = self::completarPedido($pdo, (int)$g['pedido_id'], $ahora);
            return ['aprobado' => true, 'pendiente' => false, 'pago' => self::pago($pdo, $pagoId),
                    'pedido' => PedidoService::obtener((int)$g['pedido_id']), 'despacho' => $despacho];
        });
    }

    /** Cobranzas no encontró el dinero: el pago se rechaza con un motivo y el cliente puede volver a intentar. */
    public static function rechazar(int $pagoId, int $verificadorId, string $motivo): array
    {
        return Database::transaccion(function (PDO $pdo) use ($pagoId, $verificadorId, $motivo) {
            $g = self::pendiente($pdo, $pagoId);
            $ahora = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE pagos SET estado = 'Rechazado', motivo_rechazo = ?, verificado_por = ?, verificado_en = ? WHERE id = ?")
                ->execute([$motivo, $verificadorId, $ahora, $pagoId]);
            $pdo->prepare("UPDATE pedidos SET estado_pago = 'Rechazado', actualizado_en = ? WHERE id = ?")
                ->execute([$ahora, (int)$g['pedido_id']]);
            return ['aprobado' => false, 'pendiente' => false, 'pago' => self::pago($pdo, $pagoId),
                    'pedido' => PedidoService::obtener((int)$g['pedido_id']), 'despacho' => null];
        });
    }

    /** Marca Pagado, descuenta stock, asigna IMEI, crea el despacho y redacta los mensajes de WhatsApp. */
    private static function completarPedido(PDO $pdo, int $pedidoId, string $ahora): array
    {
        $pdo->prepare("UPDATE pedidos SET estado_pago = 'Pagado', actualizado_en = ? WHERE id = ?")->execute([$ahora, $pedidoId]);
        $despacho = Logistica::despachar($pedidoId)['despacho'];
        Notificador::generar($pedidoId, 'confirmacion');
        Notificador::generar($pedidoId, 'comprobante');
        return $despacho;
    }

    private static function pendiente(PDO $pdo, int $pagoId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM pagos WHERE id = ?');
        $stmt->execute([$pagoId]);
        $g = $stmt->fetch();
        if (!$g) {
            throw new ApiException(404, 'no_encontrado', 'El pago no existe.');
        }
        if ($g['estado'] !== 'Por_verificar') {
            throw new ApiException(409, 'pago_ya_resuelto', 'Este pago ya fue ' . strtolower($g['estado'] === 'Aprobado' ? 'confirmado' : 'rechazado') . '.');
        }
        return $g;
    }

    private static function pago(PDO $pdo, int $pagoId): array
    {
        $stmt = $pdo->prepare('SELECT id, metodo, monto, estado, referencia, tarjeta_ultimos4, motivo_rechazo, comprobante_ref, comprobante_img, fecha FROM pagos WHERE id = ?');
        $stmt->execute([$pagoId]);
        return $stmt->fetch();
    }
}
