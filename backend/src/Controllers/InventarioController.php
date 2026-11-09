<?php
/**
 * Inventario por IMEI (módulo "inventario").
 * Cada IMEI es UN equipo físico. El stock de un producto = IMEIs en estado 'Disponible'.
 */
final class InventarioController
{
    /** GET /api/inventario/stock — resumen por producto */
    public static function stock(array $yo, array $p): void
    {
        $filas = Database::pdo()->query(
            "SELECT p.id, p.marca, p.modelo, p.activo, p.stock_actual, p.stock_minimo, p.requiere_imei,
                    c.nombre AS categoria,
                    SUM(CASE WHEN i.estado = 'Disponible' THEN 1 ELSE 0 END) AS disponibles,
                    SUM(CASE WHEN i.estado = 'Vendido'    THEN 1 ELSE 0 END) AS vendidos,
                    SUM(CASE WHEN i.estado = 'Entregado'  THEN 1 ELSE 0 END) AS entregados
               FROM productos p LEFT JOIN imeis i ON i.producto_id = p.id LEFT JOIN categorias c ON c.id = p.categoria_id
              GROUP BY p.id ORDER BY p.marca COLLATE NOCASE, p.modelo COLLATE NOCASE"
        )->fetchAll();
        foreach ($filas as &$f) {
            foreach (['id', 'activo', 'stock_actual', 'stock_minimo', 'requiere_imei', 'disponibles', 'vendidos', 'entregados'] as $k) {
                $f[$k] = (int)$f[$k];
            }
            $f['stock_bajo'] = $f['activo'] === 1 && $f['stock_actual'] <= $f['stock_minimo'];
        }
        Response::ok($filas);
    }

    /** GET /api/inventario/imeis?producto_id=&estado=&q=&pagina=&por_pagina= */
    public static function listar(array $yo, array $p): void
    {
        $where = ' WHERE 1=1';
        $params = [];
        $pid = filter_var(Request::query('producto_id'), FILTER_VALIDATE_INT);
        if ($pid !== false) {
            $where .= ' AND i.producto_id = :pid';
            $params[':pid'] = $pid;
        }
        $estado = Request::query('estado');
        if (in_array($estado, ['Disponible', 'Vendido', 'Entregado'], true)) {
            $where .= ' AND i.estado = :estado';
            $params[':estado'] = $estado;
        }
        if (($q = Request::query('q')) !== '') {
            $where .= " AND i.numero_imei LIKE :q ESCAPE '\\'";
            $params[':q'] = Request::likeSeguro($q);
        }
        $pagina = Request::entero('pagina', 1, 1, 100000);
        $porPagina = Request::entero('por_pagina', 15, 1, 50);
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM imeis i' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT i.id, i.numero_imei, i.estado, i.pedido_id, i.ingresado_en, i.vendido_en, i.entregado_en,
                    p.marca, p.modelo
               FROM imeis i JOIN productos p ON p.id = i.producto_id' . $where
            . ' ORDER BY i.id DESC LIMIT :lim OFFSET :off'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();

        Response::ok($stmt->fetchAll(), [
            'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina,
            'total_paginas' => max(1, (int)ceil($total / $porPagina)),
        ]);
    }

    /**
     * POST /api/inventario/imeis   { producto_id, imeis: ["...", "..."] | "texto separado por saltos/comas" }
     * Ingresa equipos al almacén: valida formato + dígito de control (Luhn), rechaza duplicados
     * y suma el stock del producto. Todo en una transacción.
     */
    public static function registrar(array $yo, array $p): void
    {
        $d = Request::json();
        $pid = filter_var($d['producto_id'] ?? null, FILTER_VALIDATE_INT);
        if ($pid === false) {
            Response::error(422, 'validacion', 'Selecciona un producto.', ['producto_id' => 'Selecciona un producto.']);
        }
        $stmt = Database::pdo()->prepare('SELECT id, requiere_imei FROM productos WHERE id = ?');
        $stmt->execute([$pid]);
        $prod = $stmt->fetch();
        $stmt->closeCursor();
        if (!$prod) {
            Response::error(404, 'no_encontrado', 'El producto no existe.');
        }
        if ((int)$prod['requiere_imei'] === 0) {
            Response::error(422, 'sin_imei', 'Este producto se controla por cantidad, no por IMEI. Usa "Ajustar stock".');
        }

        $crudo = $d['imeis'] ?? [];
        if (is_string($crudo)) {
            $crudo = preg_split('/[\s,;]+/', trim($crudo), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($crudo) || !$crudo) {
            Response::error(422, 'validacion', 'Ingresa al menos un IMEI.', ['imeis' => 'Ingresa al menos un IMEI.']);
        }
        if (count($crudo) > 200) {
            Response::error(422, 'validacion', 'Máximo 200 IMEI por carga.', ['imeis' => 'Máximo 200 IMEI por carga.']);
        }

        $lista = array_values(array_unique(array_map(fn($x) => trim((string)$x), $crudo)));
        $invalidos = array_values(array_filter($lista, fn($x) => !Imei::valido($x)));
        $validos = array_values(array_diff($lista, $invalidos));

        $resultado = Database::transaccion(function (PDO $pdo) use ($validos, $pid) {
            $duplicados = [];
            $nuevos = [];
            $existe = $pdo->prepare('SELECT 1 FROM imeis WHERE numero_imei = ?');
            foreach ($validos as $imei) {
                $existe->execute([$imei]);
                if ($existe->fetch()) {
                    $duplicados[] = $imei;
                } else {
                    $nuevos[] = $imei;
                }
            }
            if ($nuevos) {
                $ahora = date('Y-m-d H:i:s');
                $ins = $pdo->prepare('INSERT INTO imeis (producto_id, numero_imei, ingresado_en) VALUES (?, ?, ?)');
                foreach ($nuevos as $imei) {
                    $ins->execute([$pid, $imei, $ahora]);
                }
                $pdo->prepare('UPDATE productos SET stock_actual = stock_actual + ?, actualizado_en = ? WHERE id = ?')
                    ->execute([count($nuevos), $ahora, $pid]);
            }
            return ['insertados' => count($nuevos), 'duplicados' => $duplicados];
        });

        $resultado['invalidos'] = $invalidos;
        if ($resultado['insertados'] === 0) {
            Response::error(422, 'sin_imeis_validos',
                'No se registró ningún IMEI: ' . count($invalidos) . ' inválido(s) y ' . count($resultado['duplicados']) . ' repetido(s).',
                ['imeis' => 'Cada IMEI debe tener 15 dígitos válidos y no estar registrado.']);
        }
        Response::ok($resultado, null, 201);
    }

    /**
     * POST /api/inventario/ajuste   { producto_id, cantidad (+entra / -sale), motivo }
     * Solo para productos SIN IMEI (accesorios, complementos). El stock nunca queda negativo.
     */
    public static function ajustar(array $yo, array $p): void
    {
        $d = Request::json();
        $pid = filter_var($d['producto_id'] ?? null, FILTER_VALIDATE_INT);
        $cant = filter_var($d['cantidad'] ?? null, FILTER_VALIDATE_INT);
        $motivo = trim((string)($d['motivo'] ?? ''));
        $e = [];
        if ($pid === false) { $e['producto_id'] = 'Selecciona un producto.'; }
        if ($cant === false || $cant === 0 || abs($cant) > 10000) { $e['cantidad'] = 'Ingresa una cantidad distinta de 0 (positiva entra, negativa sale).'; }
        if (mb_strlen($motivo) < 3 || mb_strlen($motivo) > 120) { $e['motivo'] = 'Indica el motivo (3 a 120 caracteres).'; }
        if ($e) {
            Response::error(422, 'validacion', 'Revisa los datos del ajuste.', $e);
        }
        $resultado = Database::transaccion(function (PDO $pdo) use ($pid, $cant) {
            $stmt = $pdo->prepare('SELECT requiere_imei, stock_actual FROM productos WHERE id = ?');
            $stmt->execute([$pid]);
            $prod = $stmt->fetch();
            $stmt->closeCursor();
            if (!$prod) {
                throw new ApiException(404, 'no_encontrado', 'El producto no existe.');
            }
            if ((int)$prod['requiere_imei'] === 1) {
                throw new ApiException(422, 'requiere_imei', 'Este producto se controla por IMEI: ingresa los equipos con su IMEI.');
            }
            if ((int)$prod['stock_actual'] + $cant < 0) {
                throw new ApiException(409, 'stock_insuficiente', 'No puedes sacar más unidades de las que hay (' . (int)$prod['stock_actual'] . ').');
            }
            $pdo->prepare('UPDATE productos SET stock_actual = stock_actual + ?, actualizado_en = ? WHERE id = ?')
                ->execute([$cant, date('Y-m-d H:i:s'), $pid]);
            return ['stock_actual' => (int)$prod['stock_actual'] + $cant];
        });
        Response::ok($resultado);
    }
}
