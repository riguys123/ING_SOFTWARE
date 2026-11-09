<?php
/**
 * Pedidos.
 *  - Cliente: crea pedidos desde su carrito y ve SOLO los suyos.
 *  - Staff con el módulo "ventas": ve todos los pedidos (buscables por IMEI).
 */
final class PedidoController
{
    private static function veTodos(array $yo): bool
    {
        return $yo['rol'] !== ROL_CLIENTE && tieneModulo($yo, 'ventas');
    }

    /** Carga el pedido verificando que el usuario pueda verlo (404 si es ajeno: no revela que existe) */
    private static function cargarAutorizado(array $yo, int $id): array
    {
        $pedido = PedidoService::obtener($id);
        if (!$pedido) {
            Response::error(404, 'no_encontrado', 'El pedido no existe.');
        }
        if (!self::veTodos($yo) && $pedido['usuario_id'] !== (int)$yo['id']) {
            Response::error(404, 'no_encontrado', 'El pedido no existe.');
        }
        return $pedido;
    }

    /** POST /api/pedidos — checkout: convierte el carrito en un pedido pendiente de pago */
    public static function crear(array $yo, array $p): void
    {
        $id = PedidoService::crearDesdeCarrito((int)$yo['id'], Request::json());
        Response::ok(PedidoService::obtener($id), null, 201);
    }

    /** GET /api/pedidos?estado_pago=&estado_envio=&q=&desde=&hasta=&pagina=&por_pagina= */
    public static function listar(array $yo, array $p): void
    {
        $todos = self::veTodos($yo);
        if (!$todos && $yo['rol'] !== ROL_CLIENTE) {
            Response::error(403, 'acceso_denegado', 'Tu rol no tiene permiso para ver pedidos.');
        }
        $where = ' WHERE 1=1';
        $params = [];
        if (!$todos) {
            $where .= ' AND p.usuario_id = :uid';
            $params[':uid'] = (int)$yo['id'];
        }
        $ep = Request::query('estado_pago');
        if (in_array($ep, ['Pendiente', 'Pagado', 'Rechazado'], true)) {
            $where .= ' AND p.estado_pago = :ep';
            $params[':ep'] = $ep;
        }
        $ee = Request::query('estado_envio');
        if (in_array($ee, ['Pendiente', 'En_preparacion', 'En_camino', 'Entregado', 'Cancelado'], true)) {
            $where .= ' AND p.estado_envio = :ee';
            $params[':ee'] = $ee;
        }
        foreach (['desde' => 'p.fecha >= :desde', 'hasta' => 'p.fecha < :hasta'] as $k => $cond) {
            $v = Request::query($k);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $where .= " AND $cond";
                $params[":$k"] = $k === 'hasta' ? date('Y-m-d', strtotime($v . ' +1 day')) : $v;
            }
        }
        if ($todos && ($q = Request::query('q')) !== '') {
            // Busca por nº de pedido, nombre/correo del cliente o IMEI asignado
            $where .= " AND (p.id = :qid OR u.nombre LIKE :q ESCAPE '\\' OR u.email LIKE :q ESCAPE '\\'"
                    . " OR EXISTS (SELECT 1 FROM detalle_pedido d WHERE d.pedido_id = p.id AND d.imei_asignado LIKE :q ESCAPE '\\'))";
            $params[':qid'] = ctype_digit($q) ? (int)$q : -1;
            $params[':q'] = Request::likeSeguro($q);
        }

        $pagina = Request::entero('pagina', 1, 1, 100000);
        $porPagina = Request::entero('por_pagina', 10, 1, 50);
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT p.id, p.total, p.estado_pago, p.estado_envio, p.fecha, p.ciudad,
                    u.nombre AS cliente_nombre, u.email AS cliente_email,
                    (SELECT COALESCE(SUM(cantidad), 0) FROM detalle_pedido d WHERE d.pedido_id = p.id) AS unidades,
                    (SELECT group_concat(imei_asignado, \', \') FROM detalle_pedido d
                      WHERE d.pedido_id = p.id AND d.imei_asignado IS NOT NULL) AS imeis
               FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id' . $where
            . ' ORDER BY p.id DESC LIMIT :lim OFFSET :off'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();

        $filas = array_map(function ($f) {
            $f['id'] = (int)$f['id'];
            $f['total'] = (float)$f['total'];
            $f['unidades'] = (int)$f['unidades'];
            return $f;
        }, $stmt->fetchAll());

        Response::ok($filas, [
            'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina,
            'total_paginas' => max(1, (int)ceil($total / $porPagina)),
        ]);
    }

    /** GET /api/pedidos/:id */
    public static function ver(array $yo, array $p): void
    {
        if ($yo['rol'] !== ROL_CLIENTE && !self::veTodos($yo)) {
            Response::error(403, 'acceso_denegado', 'Tu rol no tiene permiso para ver pedidos.');
        }
        $pedido = self::cargarAutorizado($yo, $p['id']);
        if (self::veTodos($yo)) {
            // El personal ve también los mensajes automáticos de este pedido (con su enlace de WhatsApp)
            $stmt = Database::pdo()->prepare('SELECT * FROM notificaciones WHERE pedido_id = ? ORDER BY id');
            $stmt->execute([$p['id']]);
            $pedido['mensajes'] = array_map([Notificador::class, 'formatear'], $stmt->fetchAll());
        }
        Response::ok($pedido);
    }

    /**
     * GET /api/pedidos/:id/comprobante — comprobante del pedido pagado.
     * El cliente solo ve los suyos. Incluye el texto listo para compartir por WhatsApp.
     */
    public static function comprobante(array $yo, array $p): void
    {
        if ($yo['rol'] !== ROL_CLIENTE && !self::veTodos($yo)) {
            Response::error(403, 'acceso_denegado', 'Tu rol no tiene permiso para ver comprobantes.');
        }
        self::cargarAutorizado($yo, $p['id']);
        $c = Comprobante::datos($p['id']);
        $c['texto_whatsapp'] = Comprobante::texto($c);
        Response::ok($c);
    }

    /** PATCH /api/pedidos/:id/cancelar — solo pedidos sin pagar */
    public static function cancelar(array $yo, array $p): void
    {
        $puede = $yo['rol'] === ROL_CLIENTE || tieneModulo($yo, 'ventas', 'total');
        if (!$puede) {
            Response::error(403, 'acceso_denegado', 'Tu rol no tiene permiso para cancelar pedidos.');
        }
        self::cargarAutorizado($yo, $p['id']);   // verifica que sea suyo (o staff)
        PedidoService::cancelar($p['id']);
        Response::ok(PedidoService::obtener($p['id']));
    }
}
