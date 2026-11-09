<?php
/**
 * Datos del panel interactivo. Cada bloque solo se incluye si el rol tiene el módulo
 * correspondiente en la matriz de permisos. GET /api/reportes/resumen?dias=7|30|90
 */
final class ReporteController
{
    private static function uno(string $sql, array $p = []): mixed
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($p);
        $v = $stmt->fetchColumn();
        $stmt->closeCursor();
        return $v;
    }

    private static function filas(string $sql, array $p = []): array
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($p);
        return $stmt->fetchAll();
    }

    /** GET /api/reportes/resumen */
    public static function resumen(array $yo, array $p): void
    {
        $dias = in_array((int)Request::query('dias'), [7, 30, 90], true) ? (int)Request::query('dias') : 30;
        $hoy = date('Y-m-d');
        $desde = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
        $antDesde = date('Y-m-d', strtotime('-' . (2 * $dias - 1) . ' days'));
        $antHasta = date('Y-m-d', strtotime('-' . $dias . ' days'));
        $salida = ['periodo' => ['dias' => $dias, 'desde' => $desde, 'hasta' => $hoy]];

        if (tieneModulo($yo, 'ventas') || tieneModulo($yo, 'cobranzas')) {
            $ap = "estado = 'Aprobado'";
            $ingresos = (float)self::uno("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE $ap AND date(fecha) BETWEEN ? AND ?", [$desde, $hoy]);
            $ingAnt   = (float)self::uno("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE $ap AND date(fecha) BETWEEN ? AND ?", [$antDesde, $antHasta]);
            $pedidos  = (int)self::uno("SELECT COUNT(*) FROM pagos WHERE $ap AND date(fecha) BETWEEN ? AND ?", [$desde, $hoy]);
            $pedAnt   = (int)self::uno("SELECT COUNT(*) FROM pagos WHERE $ap AND date(fecha) BETWEEN ? AND ?", [$antDesde, $antHasta]);
            $unidades = (int)self::uno(
                "SELECT COALESCE(SUM(d.cantidad),0) FROM detalle_pedido d JOIN pagos g ON g.pedido_id = d.pedido_id AND g.estado = 'Aprobado'
                  WHERE date(g.fecha) BETWEEN ? AND ?", [$desde, $hoy]);

            $porEnvio = ['Pendiente' => 0, 'En_preparacion' => 0, 'En_camino' => 0, 'Entregado' => 0, 'Cancelado' => 0];
            foreach (self::filas('SELECT estado_envio, COUNT(*) AS n FROM pedidos GROUP BY estado_envio') as $f) {
                $porEnvio[$f['estado_envio']] = (int)$f['n'];
            }
            $porPago = ['Pendiente' => 0, 'Pagado' => 0, 'Rechazado' => 0];
            foreach (self::filas("SELECT estado_pago, COUNT(*) AS n FROM pedidos WHERE estado_envio <> 'Cancelado' GROUP BY estado_pago") as $f) {
                $porPago[$f['estado_pago']] = (int)$f['n'];
            }

            // Serie diaria con ceros en los días sin ventas
            $serie = [];
            for ($t = strtotime($desde); $t <= strtotime($hoy); $t = strtotime('+1 day', $t)) {
                $serie[date('Y-m-d', $t)] = ['dia' => date('Y-m-d', $t), 'total' => 0.0, 'pedidos' => 0];
            }
            foreach (self::filas("SELECT date(fecha) AS dia, SUM(monto) AS total, COUNT(*) AS n FROM pagos
                                   WHERE $ap AND date(fecha) BETWEEN ? AND ? GROUP BY dia", [$desde, $hoy]) as $f) {
                $serie[$f['dia']] = ['dia' => $f['dia'], 'total' => (float)$f['total'], 'pedidos' => (int)$f['n']];
            }

            $porCategoria = self::filas(
                "SELECT COALESCE(c.nombre, 'Sin categoría') AS etiqueta, SUM(d.cantidad * d.precio_unitario) AS valor
                   FROM detalle_pedido d JOIN pagos g ON g.pedido_id = d.pedido_id AND g.estado = 'Aprobado'
                   JOIN productos pr ON pr.id = d.producto_id LEFT JOIN categorias c ON c.id = pr.categoria_id
                  WHERE date(g.fecha) BETWEEN ? AND ? GROUP BY etiqueta ORDER BY valor DESC", [$desde, $hoy]);
            $top = self::filas(
                "SELECT pr.id, pr.marca, pr.modelo, SUM(d.cantidad) AS unidades, SUM(d.cantidad * d.precio_unitario) AS ingresos
                   FROM detalle_pedido d JOIN pagos g ON g.pedido_id = d.pedido_id AND g.estado = 'Aprobado'
                   JOIN productos pr ON pr.id = d.producto_id
                  WHERE date(g.fecha) BETWEEN ? AND ? GROUP BY pr.id ORDER BY ingresos DESC LIMIT 5", [$desde, $hoy]);

            $salida['ventas'] = [
                'ingresos'          => $ingresos,
                'ingresos_anterior' => $ingAnt,
                'pedidos'           => $pedidos,
                'pedidos_anterior'  => $pedAnt,
                'unidades'          => $unidades,
                'ticket_promedio'   => $pedidos ? round($ingresos / $pedidos, 2) : 0,
                'ingresos_hoy'      => (float)self::uno("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE $ap AND date(fecha) = ?", [$hoy]),
                'ingresos_total'    => (float)self::uno("SELECT COALESCE(SUM(monto),0) FROM pagos WHERE $ap"),
                'por_estado_pago'   => $porPago,
                'por_estado_envio'  => $porEnvio,
                'serie'             => array_values($serie),
                'por_categoria'     => array_map(fn($f) => ['etiqueta' => $f['etiqueta'], 'valor' => round((float)$f['valor'], 2)], $porCategoria),
                'top_productos'     => array_map(fn($t) => ['id' => (int)$t['id'], 'marca' => $t['marca'], 'modelo' => $t['modelo'],
                                                           'unidades' => (int)$t['unidades'], 'ingresos' => round((float)$t['ingresos'], 2)], $top),
            ];
        }

        if (tieneModulo($yo, 'inventario') || tieneModulo($yo, 'catalogo')) {
            $est = ['Disponible' => 0, 'Vendido' => 0, 'Entregado' => 0];
            foreach (self::filas('SELECT estado, COUNT(*) AS n FROM imeis GROUP BY estado') as $f) {
                $est[$f['estado']] = (int)$f['n'];
            }
            $stock = self::filas(
                "SELECT p.id, p.marca, p.modelo, p.stock_actual, p.stock_minimo, p.precio, COALESCE(c.nombre, 'Sin categoría') AS categoria
                   FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id WHERE p.activo = 1
                  ORDER BY p.stock_actual ASC, p.marca COLLATE NOCASE, p.modelo COLLATE NOCASE");
            $bajos = [];
            $valor = 0.0;
            $porCat = [];
            foreach ($stock as $s) {
                $valor += (int)$s['stock_actual'] * (float)$s['precio'];
                $porCat[$s['categoria']] = ($porCat[$s['categoria']] ?? 0) + (int)$s['stock_actual'];
                if ((int)$s['stock_actual'] <= (int)$s['stock_minimo']) {
                    $bajos[] = ['id' => (int)$s['id'], 'nombre' => $s['marca'] . ' ' . $s['modelo'], 'categoria' => $s['categoria'],
                                'stock_actual' => (int)$s['stock_actual'], 'stock_minimo' => (int)$s['stock_minimo']];
                }
            }
            $salida['inventario'] = [
                'imeis'          => $est,
                'productos'      => count($stock),
                'unidades'       => array_sum($porCat),
                'valor'          => round($valor, 2),
                'stock_bajo'     => count($bajos),
                'alertas'        => array_slice($bajos, 0, 8),
                'por_categoria'  => array_map(fn($k, $v) => ['etiqueta' => $k, 'valor' => $v], array_keys($porCat), array_values($porCat)),
            ];
        }

        if (tieneModulo($yo, 'ventas')) {
            $salida['ultimos_pedidos'] = self::filas(
                "SELECT p.id, p.total, p.estado_pago, p.estado_envio, p.fecha, u.nombre AS cliente_nombre,
                        (SELECT group_concat(imei_asignado, ', ') FROM detalle_pedido d
                          WHERE d.pedido_id = p.id AND d.imei_asignado IS NOT NULL) AS imeis,
                        (SELECT group_concat(pr.marca || ' ' || pr.modelo, ', ') FROM
                          (SELECT DISTINCT producto_id FROM detalle_pedido WHERE pedido_id = p.id) x
                          JOIN productos pr ON pr.id = x.producto_id) AS productos
                   FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id ORDER BY p.id DESC LIMIT 8");
            $salida['mensajes_pendientes'] = (int)self::uno("SELECT COUNT(*) FROM notificaciones WHERE estado = 'Pendiente'");
        }

        if (tieneModulo($yo, 'promociones')) {
            $salida['promociones_vigentes'] = (int)self::uno(
                "SELECT COUNT(*) FROM promociones WHERE activo = 1 AND date('now','localtime') BETWEEN fecha_inicio AND fecha_fin");
        }

        if (tieneModulo($yo, 'distribucion')) {
            $porEstado = ['En_preparacion' => 0, 'En_camino' => 0, 'Entregado' => 0];
            foreach (self::filas('SELECT estado, COUNT(*) AS n FROM despachos GROUP BY estado') as $f) {
                $porEstado[$f['estado']] = (int)$f['n'];
            }
            $activos = self::filas(
                "SELECT d.id, d.pedido_id, d.codigo_guia, d.estado, p.direccion_envio, p.ciudad, p.telefono, u.nombre AS cliente
                   FROM despachos d JOIN pedidos p ON p.id = d.pedido_id JOIN usuarios u ON u.id = p.usuario_id
                  WHERE d.estado <> 'Entregado' ORDER BY (d.estado = 'En_camino') DESC, d.id LIMIT 8");
            $salida['distribucion'] = [
                'por_estado' => $porEstado,
                'activos'    => array_map(function ($a) {
                    $a['id'] = (int)$a['id'];
                    $a['pedido_id'] = (int)$a['pedido_id'];
                    return $a;
                }, $activos),
            ];
        }
        Response::ok((object)$salida);
    }
}
