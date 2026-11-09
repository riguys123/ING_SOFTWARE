<?php
/**
 * Pagos: procesar (cliente) y consultar (Cobranzas / Admin).
 */
final class PagoController
{
    /**
     * POST /api/pagos/procesar
     * { pedido_id, metodo: "Tarjeta"|"QR"|"Transferencia",
     *   tarjeta: { numero, titular, vencimiento: "MM/AA", cvv }  |  comprobante: "..." }
     * 201 = aprobado (stock descontado, IMEI asignado, despacho creado)
     * 402 = rechazado por la pasarela (el pedido queda pendiente para reintentar)
     */
    public static function procesar(array $yo, array $p): void
    {
        $d = Request::json();
        $pedidoId = filter_var($d['pedido_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pedidoId === false) {
            Response::error(422, 'validacion', 'Indica el pedido a pagar.');
        }
        $datos = is_array($d['tarjeta'] ?? null) ? $d['tarjeta'] : [];
        $datos['comprobante'] = $d['comprobante'] ?? '';

        $r = PagoService::procesar($pedidoId, (int)$yo['id'], (string)($d['metodo'] ?? ''), $datos);
        if (!$r['aprobado']) {
            Response::error(402, 'pago_rechazado', $r['pago']['motivo_rechazo'] ?? 'El pago fue rechazado.',
                ['referencia' => $r['pago']['referencia']]);
        }
        Response::ok($r, null, 201);
    }

    /** GET /api/pagos?estado=&metodo=&q=&desde=&hasta=&pagina=&por_pagina= */
    public static function listar(array $yo, array $p): void
    {
        $where = ' WHERE 1=1';
        $params = [];
        if (in_array($e = Request::query('estado'), ['Aprobado', 'Rechazado'], true)) {
            $where .= ' AND g.estado = :estado';
            $params[':estado'] = $e;
        }
        if (in_array($m = Request::query('metodo'), PasarelaSimulada::METODOS, true)) {
            $where .= ' AND g.metodo = :metodo';
            $params[':metodo'] = $m;
        }
        foreach (['desde' => 'g.fecha >= :desde', 'hasta' => 'g.fecha < :hasta'] as $k => $cond) {
            $v = Request::query($k);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $where .= " AND $cond";
                $params[":$k"] = $k === 'hasta' ? date('Y-m-d', strtotime($v . ' +1 day')) : $v;
            }
        }
        if (($q = Request::query('q')) !== '') {
            $where .= " AND (g.referencia LIKE :q ESCAPE '\\' OR u.nombre LIKE :q ESCAPE '\\' OR u.email LIKE :q ESCAPE '\\' OR g.pedido_id = :qid)";
            $params[':q'] = Request::likeSeguro($q);
            $params[':qid'] = ctype_digit($q) ? (int)$q : -1;
        }
        $pagina = Request::entero('pagina', 1, 1, 100000);
        $porPagina = Request::entero('por_pagina', 15, 1, 50);
        $pdo = Database::pdo();
        $desde = ' FROM pagos g JOIN pedidos p ON p.id = g.pedido_id JOIN usuarios u ON u.id = p.usuario_id' . $where;

        $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN g.estado = 'Aprobado' THEN g.monto END), 0)" . $desde);
        $stmt->execute($params);
        [$total, $suma] = array_values($stmt->fetch(PDO::FETCH_NUM));

        $stmt = $pdo->prepare(
            'SELECT g.id, g.pedido_id, g.metodo, g.monto, g.estado, g.referencia, g.tarjeta_ultimos4, g.motivo_rechazo, g.fecha,
                    u.nombre AS cliente_nombre, u.email AS cliente_email' . $desde
            . ' ORDER BY g.id DESC LIMIT :lim OFFSET :off'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();
        $filas = array_map(function ($f) {
            $f['id'] = (int)$f['id'];
            $f['pedido_id'] = (int)$f['pedido_id'];
            $f['monto'] = (float)$f['monto'];
            return $f;
        }, $stmt->fetchAll());

        Response::ok($filas, [
            'total' => (int)$total, 'pagina' => $pagina, 'por_pagina' => $porPagina,
            'total_paginas' => max(1, (int)ceil($total / $porPagina)),
            'total_aprobado' => (float)$suma,
        ]);
    }
}
