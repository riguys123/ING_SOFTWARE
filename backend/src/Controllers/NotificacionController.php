<?php
/**
 * Bandeja de mensajes automáticos (WhatsApp local). Módulo "ventas".
 * El sistema genera los mensajes solo; aquí el encargado los abre con un clic (enlace wa.me).
 */
final class NotificacionController
{
    /** GET /api/notificaciones?estado=&q=&pedido_id=&pagina=&por_pagina= */
    public static function listar(array $yo, array $p): void
    {
        $where = ' WHERE 1=1';
        $params = [];
        if (in_array($e = Request::query('estado'), ['Pendiente', 'Enviado'], true)) {
            $where .= ' AND n.estado = :estado';
            $params[':estado'] = $e;
        }
        if (($pid = filter_var(Request::query('pedido_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND n.pedido_id = :pid';
            $params[':pid'] = $pid;
        }
        if (($q = Request::query('q')) !== '') {
            $where .= " AND (u.nombre LIKE :q ESCAPE '\\' OR n.telefono LIKE :q ESCAPE '\\' OR n.pedido_id = :qid)";
            $params[':q'] = Request::likeSeguro($q);
            $params[':qid'] = ctype_digit($q) ? (int)$q : -1;
        }
        $pagina = Request::entero('pagina', 1, 1, 100000);
        $porPagina = Request::entero('por_pagina', 10, 1, 50);
        $pdo = Database::pdo();
        $desde = ' FROM notificaciones n JOIN usuarios u ON u.id = n.usuario_id' . $where;

        $stmt = $pdo->prepare('SELECT COUNT(*)' . $desde);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        $pendientes = (int)$pdo->query("SELECT COUNT(*) FROM notificaciones WHERE estado = 'Pendiente'")->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT n.*, u.nombre AS cliente' . $desde . ' ORDER BY (n.estado = \'Pendiente\') DESC, n.id DESC LIMIT :lim OFFSET :off'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();

        Response::ok(array_map([Notificador::class, 'formatear'], $stmt->fetchAll()), [
            'total' => $total, 'pendientes' => $pendientes, 'pagina' => $pagina, 'por_pagina' => $porPagina,
            'total_paginas' => max(1, (int)ceil($total / $porPagina)),
        ]);
    }

    /** PATCH /api/notificaciones/:id/enviado — se marca al abrir WhatsApp */
    public static function marcarEnviado(array $yo, array $p): void
    {
        $stmt = Database::pdo()->prepare(
            "UPDATE notificaciones SET estado = 'Enviado', enviado_en = COALESCE(enviado_en, ?), enviado_por = COALESCE(enviado_por, ?) WHERE id = ?"
        );
        $stmt->execute([date('Y-m-d H:i:s'), $yo['id'], $p['id']]);
        if ($stmt->rowCount() === 0) {
            Response::error(404, 'no_encontrado', 'El mensaje no existe.');
        }
        Response::ok(['mensaje' => 'Marcado como enviado.']);
    }
}
