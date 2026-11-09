<?php
/**
 * Logística: orden de despacho (stock + IMEI) y seguimiento de la entrega.
 */
final class LogisticaController
{
    /**
     * POST /api/logistica/despacho   { pedido_id }
     * Ejecuta (o recupera, si ya existe) el despacho de un pedido PAGADO:
     * descuenta stock, asigna un IMEI único a cada unidad y crea la orden de despacho.
     * Normalmente lo dispara solo /api/pagos/procesar; este endpoint permite al personal
     * de almacén reintentarlo manualmente. Es idempotente.
     */
    public static function despachar(array $yo, array $p): void
    {
        $pedidoId = filter_var(Request::json()['pedido_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pedidoId === false) {
            Response::error(422, 'validacion', 'Indica el pedido a despachar.');
        }
        $r = Logistica::despachar($pedidoId);
        Response::ok($r['despacho'], ['creado' => $r['creado']], $r['creado'] ? 201 : 200);
    }

    /** GET /api/logistica/despachos?estado=&q=&pagina=&por_pagina= */
    public static function listar(array $yo, array $p): void
    {
        $where = ' WHERE 1=1';
        $params = [];
        if (in_array($e = Request::query('estado'), ['En_preparacion', 'En_camino', 'Entregado'], true)) {
            $where .= ' AND d.estado = :estado';
            $params[':estado'] = $e;
        }
        if (($q = Request::query('q')) !== '') {
            $where .= " AND (d.codigo_guia LIKE :q ESCAPE '\\' OR u.nombre LIKE :q ESCAPE '\\' OR d.pedido_id = :qid"
                    . " OR EXISTS (SELECT 1 FROM detalle_pedido x WHERE x.pedido_id = d.pedido_id AND x.imei_asignado LIKE :q ESCAPE '\\'))";
            $params[':q'] = Request::likeSeguro($q);
            $params[':qid'] = ctype_digit($q) ? (int)$q : -1;
        }
        $pagina = Request::entero('pagina', 1, 1, 100000);
        $porPagina = Request::entero('por_pagina', 10, 1, 50);
        $pdo = Database::pdo();
        $desde = ' FROM despachos d JOIN pedidos p ON p.id = d.pedido_id JOIN usuarios u ON u.id = p.usuario_id' . $where;

        $stmt = $pdo->prepare('SELECT COUNT(*)' . $desde);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT d.id' . $desde . ' ORDER BY d.id DESC LIMIT :lim OFFSET :off');
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();

        Response::ok(array_map(fn($id) => Logistica::ver((int)$id), $stmt->fetchAll(PDO::FETCH_COLUMN)), [
            'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina,
            'total_paginas' => max(1, (int)ceil($total / $porPagina)),
        ]);
    }

    /** PATCH /api/logistica/despachos/:id/estado   { estado: "En_camino" | "Entregado" } */
    public static function cambiarEstado(array $yo, array $p): void
    {
        $estado = (string)(Request::json()['estado'] ?? '');
        Response::ok(Logistica::cambiarEstado($p['id'], $estado, $yo));
    }
}
