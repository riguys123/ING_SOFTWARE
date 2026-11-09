<?php
/**
 * Módulo de informes (módulo "informes"): ventas, productos, inventario, pedidos y clientes.
 *
 * Todos devuelven la misma forma, para que la pantalla y la exportación sean genéricas:
 *   { titulo, desde, hasta, kpis:[{etiqueta, valor, tipo}], graficos:[{titulo, tipo, datos:[{etiqueta, valor}]}],
 *     columnas:[{clave, titulo, tipo}], filas:[{...}] }
 * Con ?formato=csv se descarga la tabla (separador ";" y BOM para que Excel abra bien las tildes).
 * Los ingresos salen de los PAGOS APROBADOS, por la fecha del pago.
 */
final class InformeController
{
    private const TIPOS = ['ventas', 'productos', 'inventario', 'pedidos', 'clientes'];

    /** GET /api/informes/:tipo?desde=&hasta=&agrupar=dia|semana|mes&formato=csv */
    public static function generar(array $yo, array $p): void
    {
        // El router solo pasa enteros, así que index.php registra una ruta por informe y manda el tipo aquí
        $tipo = (string)($p['tipo'] ?? '');
        if (!in_array($tipo, self::TIPOS, true)) {
            Response::error(404, 'no_encontrado', 'Ese informe no existe.');
        }
        [$desde, $hasta] = self::rango();
        $informe = match ($tipo) {
            'ventas'     => self::ventas($desde, $hasta),
            'productos'  => self::productos($desde, $hasta),
            'inventario' => self::inventario(),
            'pedidos'    => self::pedidos($desde, $hasta),
            'clientes'   => self::clientes($desde, $hasta),
        };
        $informe['tipo'] = $tipo;
        $informe['desde'] = $tipo === 'inventario' ? null : $desde;
        $informe['hasta'] = $tipo === 'inventario' ? null : $hasta;

        if (Request::query('formato') === 'csv') {
            self::csv($tipo, $informe);
        }
        Response::ok($informe);
    }

    /** Rango de fechas validado (por defecto, los últimos 30 días; máximo 1 año) */
    private static function rango(): array
    {
        $ok = fn(string $f) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f) !== false;
        $hasta = Request::query('hasta');
        $desde = Request::query('desde');
        $hasta = $ok($hasta) ? $hasta : date('Y-m-d');
        $desde = $ok($desde) ? $desde : date('Y-m-d', strtotime($hasta . ' -29 days'));
        if ($desde > $hasta) {
            Response::error(422, 'validacion', 'La fecha inicial no puede ser posterior a la final.');
        }
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > 366) {
            Response::error(422, 'validacion', 'El rango máximo es de un año.');
        }
        return [$desde, $hasta];
    }

    private static function consulta(string $sql, array $params = []): array
    {
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private static function num(mixed $v): float
    {
        return round((float)$v, 2);
    }

    // ---------------------------------------------------------------- ventas
    private static function ventas(string $desde, string $hasta): array
    {
        $par = [':d' => $desde, ':h' => $hasta];
        $filas = self::consulta(
            "SELECT p.id AS pedido_id, g.fecha, u.nombre AS cliente, g.metodo,
                    (SELECT COALESCE(SUM(cantidad), 0) FROM detalle_pedido x WHERE x.pedido_id = p.id) AS unidades,
                    (SELECT COALESCE(SUM((COALESCE(x.precio_lista, x.precio_unitario) - x.precio_unitario) * x.cantidad), 0)
                       FROM detalle_pedido x WHERE x.pedido_id = p.id) AS descuento,
                    g.monto AS total
               FROM pagos g JOIN pedidos p ON p.id = g.pedido_id JOIN usuarios u ON u.id = p.usuario_id
              WHERE g.estado = 'Aprobado' AND date(g.fecha) BETWEEN :d AND :h ORDER BY g.id DESC",
            $par
        );
        $ingresos = 0.0; $unidades = 0; $descuentos = 0.0;
        foreach ($filas as &$f) {
            $f['pedido_id'] = Comprobante::numero((int)$f['pedido_id']);
            $f['unidades'] = (int)$f['unidades'];
            $f['descuento'] = self::num($f['descuento']);
            $f['total'] = self::num($f['total']);
            $ingresos += $f['total']; $unidades += $f['unidades']; $descuentos += $f['descuento'];
        }
        unset($f);
        $n = count($filas);

        // Serie temporal: se suma por día en SQL y se agrupa aquí, con ceros en los períodos sin ventas
        $agrupar = in_array(Request::query('agrupar'), ['dia', 'semana', 'mes'], true) ? Request::query('agrupar') : 'dia';
        $porDia = [];
        foreach (self::consulta(
            "SELECT date(g.fecha) AS k, SUM(g.monto) AS v FROM pagos g
              WHERE g.estado = 'Aprobado' AND date(g.fecha) BETWEEN :d AND :h GROUP BY k", $par) as $r) {
            $porDia[$r['k']] = (float)$r['v'];
        }
        $serie = [];
        for ($t = strtotime($desde); $t <= strtotime($hasta); $t = strtotime('+1 day', $t)) {
            $dia = date('Y-m-d', $t);
            $k = match ($agrupar) {
                'dia'    => $dia,
                'semana' => date('Y-m-d', strtotime('monday this week', $t)),   // etiqueta = lunes de esa semana
                'mes'    => date('Y-m', $t),
            };
            $serie[$k] = ($serie[$k] ?? 0.0) + ($porDia[$dia] ?? 0.0);
        }
        $serieDatos = [];
        foreach ($serie as $k => $v) {
            $serieDatos[] = ['etiqueta' => $k, 'valor' => round($v, 2)];
        }

        $porCategoria = self::consulta(
            "SELECT COALESCE(c.nombre, 'Sin categoría') AS etiqueta, SUM(d.cantidad * d.precio_unitario) AS valor
               FROM detalle_pedido d JOIN pedidos p ON p.id = d.pedido_id
               JOIN pagos g ON g.pedido_id = p.id AND g.estado = 'Aprobado'
               JOIN productos pr ON pr.id = d.producto_id LEFT JOIN categorias c ON c.id = pr.categoria_id
              WHERE date(g.fecha) BETWEEN :d AND :h GROUP BY etiqueta ORDER BY valor DESC", $par);
        $porMetodo = self::consulta(
            "SELECT g.metodo AS etiqueta, SUM(g.monto) AS valor FROM pagos g
              WHERE g.estado = 'Aprobado' AND date(g.fecha) BETWEEN :d AND :h GROUP BY g.metodo ORDER BY valor DESC", $par);

        return [
            'titulo' => 'Informe de ventas',
            'agrupar' => $agrupar,
            'kpis' => [
                ['etiqueta' => 'Ingresos', 'valor' => round($ingresos, 2), 'tipo' => 'dinero'],
                ['etiqueta' => 'Pedidos pagados', 'valor' => $n, 'tipo' => 'numero'],
                ['etiqueta' => 'Unidades vendidas', 'valor' => $unidades, 'tipo' => 'numero'],
                ['etiqueta' => 'Ticket promedio', 'valor' => $n ? round($ingresos / $n, 2) : 0, 'tipo' => 'dinero'],
                ['etiqueta' => 'Descuentos otorgados', 'valor' => round($descuentos, 2), 'tipo' => 'dinero'],
            ],
            'graficos' => [
                ['titulo' => 'Ingresos por ' . ['dia' => 'día', 'semana' => 'semana (desde el lunes)', 'mes' => 'mes'][$agrupar], 'tipo' => 'barras', 'datos' => $serieDatos],
                ['titulo' => 'Por categoría', 'tipo' => 'dona', 'datos' => array_map(fn($r) => ['etiqueta' => $r['etiqueta'], 'valor' => self::num($r['valor'])], $porCategoria)],
                ['titulo' => 'Por método de pago', 'tipo' => 'dona', 'datos' => array_map(fn($r) => ['etiqueta' => $r['etiqueta'], 'valor' => self::num($r['valor'])], $porMetodo)],
            ],
            'columnas' => [
                ['clave' => 'pedido_id', 'titulo' => 'Comprobante', 'tipo' => 'texto'],
                ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha'],
                ['clave' => 'cliente', 'titulo' => 'Cliente', 'tipo' => 'texto'],
                ['clave' => 'metodo', 'titulo' => 'Método', 'tipo' => 'texto'],
                ['clave' => 'unidades', 'titulo' => 'Unidades', 'tipo' => 'numero'],
                ['clave' => 'descuento', 'titulo' => 'Descuento', 'tipo' => 'dinero'],
                ['clave' => 'total', 'titulo' => 'Total', 'tipo' => 'dinero'],
            ],
            'filas' => $filas,
        ];
    }

    // ------------------------------------------------------------- productos
    private static function productos(string $desde, string $hasta): array
    {
        $filas = self::consulta(
            "SELECT pr.marca || ' ' || pr.modelo AS producto, COALESCE(c.nombre, 'Sin categoría') AS categoria,
                    SUM(d.cantidad) AS unidades, SUM(d.cantidad * d.precio_unitario) AS ingresos
               FROM detalle_pedido d JOIN pedidos p ON p.id = d.pedido_id
               JOIN pagos g ON g.pedido_id = p.id AND g.estado = 'Aprobado'
               JOIN productos pr ON pr.id = d.producto_id LEFT JOIN categorias c ON c.id = pr.categoria_id
              WHERE date(g.fecha) BETWEEN :d AND :h GROUP BY pr.id ORDER BY ingresos DESC",
            [':d' => $desde, ':h' => $hasta]
        );
        $total = array_sum(array_map(fn($f) => (float)$f['ingresos'], $filas));
        $unidades = 0;
        foreach ($filas as $i => &$f) {
            $f['unidades'] = (int)$f['unidades'];
            $f['ingresos'] = self::num($f['ingresos']);
            $f['participacion'] = $total > 0 ? round($f['ingresos'] / $total * 100, 1) : 0;
            $unidades += $f['unidades'];
            $f['puesto'] = $i + 1;
        }
        unset($f);
        return [
            'titulo' => 'Productos más vendidos',
            'kpis' => [
                ['etiqueta' => 'Productos vendidos', 'valor' => count($filas), 'tipo' => 'numero'],
                ['etiqueta' => 'Unidades', 'valor' => $unidades, 'tipo' => 'numero'],
                ['etiqueta' => 'Ingresos', 'valor' => round($total, 2), 'tipo' => 'dinero'],
                ['etiqueta' => 'Más vendido', 'valor' => $filas[0]['producto'] ?? '—', 'tipo' => 'texto'],
            ],
            'graficos' => [['titulo' => 'Ingresos por producto (top 8)', 'tipo' => 'barras_h',
                'datos' => array_map(fn($f) => ['etiqueta' => $f['producto'], 'valor' => $f['ingresos']], array_slice($filas, 0, 8))]],
            'columnas' => [
                ['clave' => 'puesto', 'titulo' => '#', 'tipo' => 'numero'],
                ['clave' => 'producto', 'titulo' => 'Producto', 'tipo' => 'texto'],
                ['clave' => 'categoria', 'titulo' => 'Categoría', 'tipo' => 'texto'],
                ['clave' => 'unidades', 'titulo' => 'Unidades', 'tipo' => 'numero'],
                ['clave' => 'ingresos', 'titulo' => 'Ingresos', 'tipo' => 'dinero'],
                ['clave' => 'participacion', 'titulo' => '% del total', 'tipo' => 'porcentaje'],
            ],
            'filas' => $filas,
        ];
    }

    // ------------------------------------------------------------ inventario
    private static function inventario(): array
    {
        $filas = self::consulta(
            "SELECT pr.marca || ' ' || pr.modelo AS producto, COALESCE(c.nombre, 'Sin categoría') AS categoria,
                    pr.stock_actual AS stock, pr.stock_minimo AS minimo, pr.precio,
                    pr.stock_actual * pr.precio AS valor
               FROM productos pr LEFT JOIN categorias c ON c.id = pr.categoria_id
              WHERE pr.activo = 1 ORDER BY pr.stock_actual ASC, producto COLLATE NOCASE"
        );
        $unidades = 0; $valor = 0.0; $bajos = 0; $agotados = 0; $porCat = [];
        foreach ($filas as &$f) {
            $f['stock'] = (int)$f['stock']; $f['minimo'] = (int)$f['minimo'];
            $f['precio'] = self::num($f['precio']); $f['valor'] = self::num($f['valor']);
            $f['estado'] = $f['stock'] === 0 ? 'Agotado' : ($f['stock'] <= $f['minimo'] ? 'Stock bajo' : 'Normal');
            $unidades += $f['stock']; $valor += $f['valor'];
            $bajos += $f['estado'] === 'Stock bajo' ? 1 : 0;
            $agotados += $f['estado'] === 'Agotado' ? 1 : 0;
            $porCat[$f['categoria']] = ($porCat[$f['categoria']] ?? 0) + $f['valor'];
        }
        unset($f);
        arsort($porCat);
        return [
            'titulo' => 'Estado del inventario',
            'kpis' => [
                ['etiqueta' => 'Unidades en stock', 'valor' => $unidades, 'tipo' => 'numero'],
                ['etiqueta' => 'Valor del inventario', 'valor' => round($valor, 2), 'tipo' => 'dinero'],
                ['etiqueta' => 'Con stock bajo', 'valor' => $bajos, 'tipo' => 'numero'],
                ['etiqueta' => 'Agotados', 'valor' => $agotados, 'tipo' => 'numero'],
            ],
            'graficos' => [['titulo' => 'Valor por categoría', 'tipo' => 'dona',
                'datos' => array_map(fn($k, $v) => ['etiqueta' => $k, 'valor' => round($v, 2)], array_keys($porCat), array_values($porCat))]],
            'columnas' => [
                ['clave' => 'producto', 'titulo' => 'Producto', 'tipo' => 'texto'],
                ['clave' => 'categoria', 'titulo' => 'Categoría', 'tipo' => 'texto'],
                ['clave' => 'stock', 'titulo' => 'Stock', 'tipo' => 'numero'],
                ['clave' => 'minimo', 'titulo' => 'Mínimo', 'tipo' => 'numero'],
                ['clave' => 'precio', 'titulo' => 'Precio', 'tipo' => 'dinero'],
                ['clave' => 'valor', 'titulo' => 'Valor en stock', 'tipo' => 'dinero'],
                ['clave' => 'estado', 'titulo' => 'Estado', 'tipo' => 'texto'],
            ],
            'filas' => $filas,
        ];
    }

    // --------------------------------------------------------------- pedidos
    private static function pedidos(string $desde, string $hasta): array
    {
        $filas = self::consulta(
            "SELECT p.id, p.fecha, u.nombre AS cliente, p.ciudad, p.total, p.estado_pago, p.estado_envio
               FROM pedidos p JOIN usuarios u ON u.id = p.usuario_id
              WHERE date(p.fecha) BETWEEN :d AND :h ORDER BY p.id DESC",
            [':d' => $desde, ':h' => $hasta]
        );
        $envio = []; $pagados = 0; $monto = 0.0;
        foreach ($filas as &$f) {
            $f['total'] = self::num($f['total']);
            $envio[$f['estado_envio']] = ($envio[$f['estado_envio']] ?? 0) + 1;
            if ($f['estado_pago'] === 'Pagado') { $pagados++; $monto += $f['total']; }
            $f['id'] = Comprobante::numero((int)$f['id']);
        }
        unset($f);
        $nombres = ['Pendiente' => 'Sin despachar', 'En_preparacion' => 'En preparación', 'En_camino' => 'En camino', 'Entregado' => 'Entregado', 'Cancelado' => 'Cancelado'];
        return [
            'titulo' => 'Informe de pedidos',
            'kpis' => [
                ['etiqueta' => 'Pedidos', 'valor' => count($filas), 'tipo' => 'numero'],
                ['etiqueta' => 'Pagados', 'valor' => $pagados, 'tipo' => 'numero'],
                ['etiqueta' => 'Entregados', 'valor' => $envio['Entregado'] ?? 0, 'tipo' => 'numero'],
                ['etiqueta' => 'Monto pagado', 'valor' => round($monto, 2), 'tipo' => 'dinero'],
            ],
            'graficos' => [['titulo' => 'Pedidos por estado de envío', 'tipo' => 'dona',
                'datos' => array_map(fn($k, $v) => ['etiqueta' => $nombres[$k] ?? $k, 'valor' => $v], array_keys($envio), array_values($envio))]],
            'columnas' => [
                ['clave' => 'id', 'titulo' => 'Pedido', 'tipo' => 'texto'],
                ['clave' => 'fecha', 'titulo' => 'Fecha', 'tipo' => 'fecha'],
                ['clave' => 'cliente', 'titulo' => 'Cliente', 'tipo' => 'texto'],
                ['clave' => 'ciudad', 'titulo' => 'Ciudad', 'tipo' => 'texto'],
                ['clave' => 'total', 'titulo' => 'Total', 'tipo' => 'dinero'],
                ['clave' => 'estado_pago', 'titulo' => 'Pago', 'tipo' => 'texto'],
                ['clave' => 'estado_envio', 'titulo' => 'Envío', 'tipo' => 'texto'],
            ],
            'filas' => $filas,
        ];
    }

    // -------------------------------------------------------------- clientes
    private static function clientes(string $desde, string $hasta): array
    {
        $filas = self::consulta(
            "SELECT u.nombre AS cliente, u.email, COUNT(*) AS pedidos, SUM(g.monto) AS gastado, MAX(g.fecha) AS ultima
               FROM pagos g JOIN pedidos p ON p.id = g.pedido_id JOIN usuarios u ON u.id = p.usuario_id
              WHERE g.estado = 'Aprobado' AND date(g.fecha) BETWEEN :d AND :h GROUP BY u.id ORDER BY gastado DESC",
            [':d' => $desde, ':h' => $hasta]
        );
        $total = 0.0;
        foreach ($filas as &$f) {
            $f['pedidos'] = (int)$f['pedidos'];
            $f['gastado'] = self::num($f['gastado']);
            $total += $f['gastado'];
        }
        unset($f);
        return [
            'titulo' => 'Mejores clientes',
            'kpis' => [
                ['etiqueta' => 'Clientes que compraron', 'valor' => count($filas), 'tipo' => 'numero'],
                ['etiqueta' => 'Ingresos', 'valor' => round($total, 2), 'tipo' => 'dinero'],
                ['etiqueta' => 'Mejor cliente', 'valor' => $filas[0]['cliente'] ?? '—', 'tipo' => 'texto'],
            ],
            'graficos' => [['titulo' => 'Gasto por cliente (top 8)', 'tipo' => 'barras_h',
                'datos' => array_map(fn($f) => ['etiqueta' => $f['cliente'], 'valor' => $f['gastado']], array_slice($filas, 0, 8))]],
            'columnas' => [
                ['clave' => 'cliente', 'titulo' => 'Cliente', 'tipo' => 'texto'],
                ['clave' => 'email', 'titulo' => 'Correo', 'tipo' => 'texto'],
                ['clave' => 'pedidos', 'titulo' => 'Pedidos', 'tipo' => 'numero'],
                ['clave' => 'gastado', 'titulo' => 'Total gastado', 'tipo' => 'dinero'],
                ['clave' => 'ultima', 'titulo' => 'Última compra', 'tipo' => 'fecha'],
            ],
            'filas' => $filas,
        ];
    }

    // ------------------------------------------------------------------- CSV
    private static function csv(string $tipo, array $inf): never
    {
        $nombre = "informe-$tipo-" . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM: Excel reconoce UTF-8
        // Evita "inyección de fórmulas" si un nombre empieza con = + - @
        $seguro = fn($v) => is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false ? "'" . $v : $v;
        fputcsv($out, array_column($inf['columnas'], 'titulo'), ';');
        foreach ($inf['filas'] as $f) {
            $fila = [];
            foreach ($inf['columnas'] as $c) {
                $v = $f[$c['clave']] ?? '';
                $fila[] = is_float($v) ? number_format($v, 2, ',', '') : $seguro($v);
            }
            fputcsv($out, $fila, ';');
        }
        fclose($out);
        exit;
    }
}
