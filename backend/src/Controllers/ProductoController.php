<?php
/**
 * Catálogo de celulares.
 *  - Público: listar con filtros, ver detalle, opciones de filtro.
 *  - Staff (módulo "catalogo"): listar todo, crear, editar y dar de baja.
 * El stock NO se edita aquí: se mueve solo al registrar IMEIs (inventario) y al despachar pedidos.
 */
final class ProductoController
{
    private const ORDEN = [
        'nuevo'       => 'p.id DESC',
        'precio_asc'  => 'p.precio ASC',
        'precio_desc' => 'p.precio DESC',
        'oferta'      => 'descuento_pct DESC, p.id DESC',
        'nombre'      => 'p.marca COLLATE NOCASE, p.modelo COLLATE NOCASE',
    ];

    // ---------- Público ----------

    /** GET /api/productos?q=&marca=&min=&max=&ram_gb=&almacenamiento_gb=&disponible=1&orden=&pagina=&por_pagina= */
    public static function listar(?array $yo, array $p): void
    {
        $where = ' WHERE p.activo = 1';
        $params = [];

        $q = Request::query('q');
        if ($q !== '') {
            $where .= " AND (p.marca LIKE :q ESCAPE '\\' OR p.modelo LIKE :q ESCAPE '\\' OR p.procesador LIKE :q ESCAPE '\\'"
                    . " OR (p.marca || ' ' || p.modelo) LIKE :q ESCAPE '\\')";
            $params[':q'] = Request::likeSeguro($q);
        }
        if (($cat = Request::query('categoria')) !== '') {
            // Acepta el id o el nombre (p. ej. "Accesorios")
            $where .= ' AND p.categoria_id = (SELECT id FROM categorias WHERE id = :cat_id OR nombre = :cat_nom COLLATE NOCASE LIMIT 1)';
            $params[':cat_id'] = ctype_digit($cat) ? (int)$cat : -1;
            $params[':cat_nom'] = $cat;
        }
        if (Request::query('oferta') === '1') {
            $where .= ' AND ' . Catalogo::descuentoSql('p') . ' > 0';
        }
        if (($marca = Request::query('marca')) !== '') {
            $where .= ' AND p.marca = :marca COLLATE NOCASE';
            $params[':marca'] = $marca;
        }
        // El rango de precio se aplica al precio que realmente se paga (con promoción)
        $final = '(p.precio * (1 - ' . Catalogo::descuentoSql('p') . ' / 100.0))';
        foreach (['min' => "$final >= :min", 'max' => "$final <= :max"] as $clave => $cond) {
            $v = filter_var(Request::query($clave), FILTER_VALIDATE_FLOAT);
            if ($v !== false) {
                $where .= " AND $cond";
                $params[":$clave"] = $v;
            }
        }
        foreach (['ram_gb', 'almacenamiento_gb'] as $col) {
            $v = filter_var(Request::query($col), FILTER_VALIDATE_INT);
            if ($v !== false) {
                $where .= " AND p.$col = :$col";
                $params[":$col"] = $v;
            }
        }
        if (Request::query('disponible') === '1') {
            $where .= ' AND p.stock_actual > 0';
        }

        $orden = self::ORDEN[Request::query('orden')] ?? self::ORDEN['nuevo'];
        $pagina = Request::entero('pagina', 1, 1, 100000);
        $porPagina = Request::entero('por_pagina', 12, 1, 48);
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM productos p' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT p.id, p.marca, p.modelo, p.procesador, p.ram_gb, p.almacenamiento_gb, p.camara, p.pantalla,
                    p.bateria_mah, p.color, p.descripcion, p.precio, p.stock_actual, p.fotos, p.categoria_id, p.requiere_imei,
                    c.nombre AS categoria, ' . Catalogo::descuentoSql('p') . ' AS descuento_pct
               FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id' . $where . " ORDER BY $orden LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();

        Response::ok(array_map([Catalogo::class, 'formatear'], $stmt->fetchAll()), [
            'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina,
            'total_paginas' => max(1, (int)ceil($total / $porPagina)),
        ]);
    }

    /** GET /api/productos/filtros?categoria= — opciones para armar los filtros del catálogo */
    public static function filtros(?array $yo, array $p): void
    {
        $pdo = Database::pdo();
        $cat = Request::query('categoria');
        $w = ' WHERE activo = 1';
        $par = [];
        if ($cat !== '') {
            $w .= ' AND categoria_id = (SELECT id FROM categorias WHERE id = :cid OR nombre = :cnom COLLATE NOCASE LIMIT 1)';
            $par = [':cid' => ctype_digit($cat) ? (int)$cat : -1, ':cnom' => $cat];
        }
        $consulta = function (string $sql) use ($pdo, $par) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($par);
            return $stmt;
        };
        $marcas = $consulta("SELECT marca, COUNT(*) AS total FROM productos $w GROUP BY marca ORDER BY marca")->fetchAll();
        $rango = $consulta("SELECT MIN(precio) AS min, MAX(precio) AS max FROM productos $w")->fetch();
        $rams = $consulta("SELECT DISTINCT ram_gb FROM productos $w AND ram_gb IS NOT NULL ORDER BY ram_gb")->fetchAll(PDO::FETCH_COLUMN);
        $alm = $consulta("SELECT DISTINCT almacenamiento_gb FROM productos $w AND almacenamiento_gb IS NOT NULL ORDER BY almacenamiento_gb")->fetchAll(PDO::FETCH_COLUMN);
        Response::ok([
            'marcas' => array_map(fn($m) => ['marca' => $m['marca'], 'total' => (int)$m['total']], $marcas),
            'precio_min' => (float)($rango['min'] ?? 0),
            'precio_max' => (float)($rango['max'] ?? 0),
            'ram_gb' => array_map('intval', $rams),
            'almacenamiento_gb' => array_map('intval', $alm),
        ]);
    }

    /** GET /api/productos/:id */
    public static function ver(?array $yo, array $p): void
    {
        $stmt = Database::pdo()->prepare(
            'SELECT p.*, c.nombre AS categoria, ' . Catalogo::descuentoSql('p') . ' AS descuento_pct
               FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id WHERE p.id = ? AND p.activo = 1'
        );
        $stmt->execute([$p['id']]);
        $prod = $stmt->fetch();
        if (!$prod) {
            Response::error(404, 'no_encontrado', 'El producto no existe o ya no está disponible.');
        }
        Response::ok(Catalogo::formatear($prod));
    }

    // ---------- Staff (módulo catalogo) ----------

    /** GET /api/admin/productos?q=&activo=&pagina=&por_pagina= */
    public static function listarAdmin(array $yo, array $p): void
    {
        $where = ' WHERE 1=1';
        $params = [];
        if (($q = Request::query('q')) !== '') {
            $where .= " AND ((p.marca || ' ' || p.modelo) LIKE :q ESCAPE '\\')";
            $params[':q'] = Request::likeSeguro($q);
        }
        if (($cid = filter_var(Request::query('categoria_id'), FILTER_VALIDATE_INT)) !== false) {
            $where .= ' AND p.categoria_id = :cid';
            $params[':cid'] = $cid;
        }
        $activo = Request::query('activo');
        if ($activo === '0' || $activo === '1') {
            $where .= ' AND p.activo = :activo';
            $params[':activo'] = (int)$activo;
        }
        $pagina = Request::entero('pagina', 1, 1, 100000);
        $porPagina = Request::entero('por_pagina', 10, 1, 50);
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM productos p' . $where);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT p.*, c.nombre AS categoria, ' . Catalogo::descuentoSql('p') . ' AS descuento_pct
                                 FROM productos p LEFT JOIN categorias c ON c.id = p.categoria_id' . $where . ' ORDER BY p.marca COLLATE NOCASE, p.modelo COLLATE NOCASE LIMIT :lim OFFSET :off');
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
        $stmt->execute();

        Response::ok(array_map([Catalogo::class, 'formatear'], $stmt->fetchAll()), [
            'total' => $total, 'pagina' => $pagina, 'por_pagina' => $porPagina,
            'total_paginas' => max(1, (int)ceil($total / $porPagina)),
        ]);
    }

    /** Valida y normaliza el cuerpo; lanza 422 con detalle por campo */
    private static function validar(array $d, ?int $productoId = null): array
    {
        $e = [];
        $texto = function (string $k, int $max, bool $obligatorio = false) use ($d, &$e): ?string {
            $v = trim((string)($d[$k] ?? ''));
            if ($v === '') {
                if ($obligatorio) {
                    $e[$k] = 'Este campo es obligatorio.';
                }
                return null;
            }
            if (mb_strlen($v) > $max) {
                $e[$k] = "Máximo $max caracteres.";
            }
            return $v;
        };
        $entero = function (string $k, int $min, int $max, ?int $defecto = null) use ($d, &$e): ?int {
            $crudo = $d[$k] ?? null;
            if ($crudo === null || $crudo === '') {
                return $defecto;
            }
            $v = filter_var($crudo, FILTER_VALIDATE_INT);
            if ($v === false || $v < $min || $v > $max) {
                $e[$k] = "Debe ser un número entero entre $min y $max.";
                return null;
            }
            return $v;
        };

        $r = [
            'marca'             => $texto('marca', 40, true),
            'modelo'            => $texto('modelo', 80, true),
            'procesador'        => $texto('procesador', 60),
            'ram_gb'            => $entero('ram_gb', 1, 64),
            'almacenamiento_gb' => $entero('almacenamiento_gb', 1, 4096),
            'camara'            => $texto('camara', 100),
            'pantalla'          => $texto('pantalla', 60),
            'bateria_mah'       => $entero('bateria_mah', 500, 20000),
            'color'             => $texto('color', 40),
            'descripcion'       => $texto('descripcion', 1000),
            'stock_minimo'      => $entero('stock_minimo', 0, 1000, 3),
        ];

        // Categoría: define si el producto se controla por IMEI (teléfonos) o solo por cantidad
        $stmtCat = Database::pdo()->prepare('SELECT id, usa_imei FROM categorias WHERE id = ? AND activo = 1');
        $stmtCat->execute([filter_var($d['categoria_id'] ?? null, FILTER_VALIDATE_INT)]);
        $cat = $stmtCat->fetch();
        if (!$cat) {
            $e['categoria_id'] = 'Selecciona una categoría.';
        }
        $r['categoria_id'] = $cat ? (int)$cat['id'] : null;
        $r['requiere_imei'] = $cat ? (int)$cat['usa_imei'] : 1;
        if ($cat && $productoId !== null) {
            $stmt = Database::pdo()->prepare('SELECT requiere_imei, stock_actual, (SELECT COUNT(*) FROM imeis WHERE producto_id = productos.id) AS n_imei FROM productos WHERE id = ?');
            $stmt->execute([$productoId]);
            $act = $stmt->fetch();
            if ($act && (int)$act['requiere_imei'] !== $r['requiere_imei'] && ((int)$act['n_imei'] > 0 || (int)$act['stock_actual'] > 0)) {
                $e['categoria_id'] = 'No se puede cambiar a una categoría con otro tipo de control mientras el producto tenga stock o IMEI.';
            }
        }

        $precio = filter_var($d['precio'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($precio === false || $precio <= 0 || $precio > 200000) {
            $e['precio'] = 'Ingresa un precio mayor a 0 (máximo 200000).';
        }
        $r['precio'] = $precio === false ? 0 : round($precio, 2);

        $fotos = $d['fotos'] ?? [];
        if (is_string($fotos)) {   // el formulario puede mandar una URL por línea
            $fotos = preg_split('/\R+/', trim($fotos), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($fotos) || count($fotos) > FOTOS_POR_PRODUCTO) {
            $e['fotos'] = 'Máximo ' . FOTOS_POR_PRODUCTO . ' fotos.';
            $fotos = [];
        }
        $limpias = [];
        foreach ($fotos as $f) {
            $f = trim((string)$f);
            // Solo imágenes subidas, http(s) o assets/: bloquea "javascript:" y similares
            if ($f === '' || mb_strlen($f) > 500 || !Uploads::rutaValida($f)) {
                $e['fotos'] = 'Cada foto debe ser una imagen subida o una URL http(s):// válida.';
                break;
            }
            $limpias[] = $f;
        }
        $r['fotos'] = json_encode($limpias, JSON_UNESCAPED_SLASHES);

        $activo = filter_var($d['activo'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $r['activo'] = $activo === false ? 0 : 1;

        if ($e) {
            Response::error(422, 'validacion', 'Revisa los campos del producto.', $e);
        }
        return $r;
    }

    /** POST /api/productos */
    public static function crear(array $yo, array $p): void
    {
        $r = self::validar(Request::json());
        $pdo = Database::pdo();
        $campos = array_keys($r);
        $pdo->prepare(
            'INSERT INTO productos (' . implode(', ', $campos) . ') VALUES (:' . implode(', :', $campos) . ')'
        )->execute($r);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM productos WHERE id = ?');
        $stmt->execute([$id]);
        Response::ok(Catalogo::formatear($stmt->fetch()), null, 201);
    }

    /** PUT /api/productos/:id */
    public static function actualizar(array $yo, array $p): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id, fotos FROM productos WHERE id = ?');
        $stmt->execute([$p['id']]);
        $antes = $stmt->fetch();
        if (!$antes) {
            Response::error(404, 'no_encontrado', 'El producto no existe.');
        }
        $r = self::validar(Request::json(), (int)$p['id']);
        // Las fotos subidas que ya no están en la lista se borran del disco
        foreach (array_diff(Catalogo::fotos($antes['fotos']), json_decode($r['fotos'], true)) as $quitada) {
            Uploads::borrar($quitada);
        }
        $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($r)));
        $r['id'] = $p['id'];
        $r['ahora'] = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE productos SET $sets, actualizado_en = :ahora WHERE id = :id")->execute($r);
        $stmt = $pdo->prepare('SELECT * FROM productos WHERE id = ?');
        $stmt->execute([$p['id']]);
        Response::ok(Catalogo::formatear($stmt->fetch()));
    }

    /** DELETE /api/productos/:id — baja lógica (se conserva el historial de pedidos) */
    public static function eliminar(array $yo, array $p): void
    {
        $stmt = Database::pdo()->prepare('UPDATE productos SET activo = 0, actualizado_en = ? WHERE id = ?');
        $stmt->execute([date('Y-m-d H:i:s'), $p['id']]);
        if ($stmt->rowCount() === 0) {
            Response::error(404, 'no_encontrado', 'El producto no existe.');
        }
        Response::ok(['mensaje' => 'Producto dado de baja.']);
    }

    /** POST /api/productos/:id/fotos — multipart, campo "foto". Agrega la imagen al final de la lista. */
    public static function subirFoto(array $yo, array $p): void
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT fotos FROM productos WHERE id = ?');
        $stmt->execute([$p['id']]);
        $fila = $stmt->fetch();
        $stmt->closeCursor();
        if (!$fila) {
            Response::error(404, 'no_encontrado', 'El producto no existe.');
        }
        if (count(Catalogo::fotos($fila['fotos'])) >= FOTOS_POR_PRODUCTO) {
            Response::error(422, 'limite_fotos', 'Máximo ' . FOTOS_POR_PRODUCTO . ' fotos por producto.');
        }
        $ruta = Uploads::guardarImagen('foto', 'productos');
        $fotos = Catalogo::fotos($fila['fotos']);
        $fotos[] = $ruta;
        $pdo->prepare('UPDATE productos SET fotos = ?, actualizado_en = ? WHERE id = ?')
            ->execute([json_encode($fotos, JSON_UNESCAPED_SLASHES), date('Y-m-d H:i:s'), $p['id']]);
        Response::ok(['ruta' => $ruta, 'fotos' => $fotos], null, 201);
    }
}
