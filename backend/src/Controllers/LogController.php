<?php
/**
 * Registro de auditoría (logs_acceso) — solo Admin.
 */
final class LogController
{
    /** GET /api/logs-acceso?resultado=&desde=&hasta=&q=&pagina=&por_pagina= */
    public static function listar(array $yo, array $p): void
    {
        $where = ' WHERE 1=1';
        $params = [];

        $resultado = Request::query('resultado');
        if (in_array($resultado, ['exito', 'fallido', 'bloqueado'], true)) {
            $where .= ' AND l.resultado = :resultado';
            $params[':resultado'] = $resultado;
        }
        // Fechas en formato YYYY-MM-DD
        $desde = Request::query('desde');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $where .= ' AND l.fecha >= :desde';
            $params[':desde'] = $desde . ' 00:00:00';
        }
        $hasta = Request::query('hasta');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            $where .= ' AND l.fecha <= :hasta';
            $params[':hasta'] = $hasta . ' 23:59:59';
        }
        $q = Request::query('q');
        if ($q !== '') {
            $where .= " AND (l.email_intento LIKE :q ESCAPE '\\' OR l.ip LIKE :q ESCAPE '\\')";
            $params[':q'] = Request::likeSeguro($q);
        }

        $pagina = Request::entero('pagina', 1, 1, 100000);
        $porPagina = Request::entero('por_pagina', 15, 1, 100);
        $pdo = Database::pdo();

        $from = ' FROM logs_acceso l LEFT JOIN usuarios u ON u.id = l.usuario_id';
        $stmt = $pdo->prepare('SELECT COUNT(*)' . $from . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT l.id, l.usuario_id, u.nombre AS usuario_nombre, l.email_intento, l.resultado, l.ip, l.fecha'
            . $from . $where . ' ORDER BY l.id DESC LIMIT :lim OFFSET :off'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();

        Response::ok($stmt->fetchAll(), [
            'total'         => $total,
            'pagina'        => $pagina,
            'por_pagina'    => $porPagina,
            'total_paginas' => max(1, (int)ceil($total / $porPagina)),
        ]);
    }
}
