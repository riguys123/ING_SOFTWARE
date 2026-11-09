<?php
/**
 * Seeder del subsistema de Seguridad — SmartCell Commerce
 *
 * Crea (o recrea) database/smartcell.sqlite ejecutando schema.sql y
 * cargando roles, módulos, matriz de permisos, un usuario por rol, el catálogo de
 * celulares con sus IMEI y unos pedidos de ejemplo (creados con los mismos servicios
 * que usa la API, para que los datos sean coherentes).
 *
 * Uso (desde la carpeta del proyecto):
 *   C:\xampp\php\php.exe database\seeder.php
 *
 * ¡CUIDADO! Borra todos los datos existentes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se ejecuta desde la línea de comandos.');
}

$rutaDb     = __DIR__ . '/smartcell.sqlite';
$rutaSchema = __DIR__ . '/schema.sql';

foreach ([$rutaDb, $rutaDb . '-wal', $rutaDb . '-shm'] as $archivo) {
    if (is_file($archivo)) { unlink($archivo); }
}

$pdo = new PDO('sqlite:' . $rutaDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA journal_mode = WAL');   // lectores y escritor no se bloquean entre sí (varias compras simultáneas)
$pdo->exec(file_get_contents($rutaSchema));

// Configuración y servicios reales de la aplicación (los mismos que usa la API)
require dirname(__DIR__) . '/backend/src/config.php';
foreach (['Http', 'Database', 'Auth'] as $f) { require RAIZ_PROYECTO . "/backend/src/$f.php"; }
foreach (['Imei', 'PasarelaSimulada', 'Catalogo', 'Uploads', 'PedidoService', 'Comprobante', 'Notificador', 'Logistica', 'PagoService'] as $f) {
    require RAIZ_PROYECTO . "/backend/src/Services/$f.php";
}

// ---------- Roles (Sprint 3: solo 4) ----------
$roles = [
    // nombre, descripción, área
    ['Admin',      'Gerente / propietario: control total del negocio, usuarios, informes y auditoría.', 'seguridad'],
    ['Vendedor',   'Encargado: ventas, catálogo, promociones, inventario y atención a clientes.',        'ventas'],
    ['Repartidor', 'Encargado del envío: recoge los pedidos y registra las entregas.',                    'distribucion'],
    ['Cliente',    'Comprador final de la tienda en línea.',                                             'clientes'],
];
$stmt = $pdo->prepare('INSERT INTO roles (nombre, descripcion, area) VALUES (?, ?, ?)');
foreach ($roles as $r) { $stmt->execute($r); }

$idRol = [];
foreach ($pdo->query('SELECT id, nombre FROM roles') as $fila) { $idRol[$fila['nombre']] = (int)$fila['id']; }

// ---------- Módulos ----------
$modulos = [
    // clave, nombre, descripción, grupo, orden
    ['panel',               'Panel principal',      'Resumen interactivo de ventas, inventario y pedidos.', 'General',     1],
    ['mi_perfil',           'Mi perfil',            'Datos de la cuenta y cambio de contraseña.',           'General',     2],
    ['seguridad_usuarios',  'Gestión de usuarios',  'Alta, edición, baja lógica y consulta de usuarios.',   'Seguridad',   3],
    ['seguridad_roles',     'Roles y permisos',     'Matriz de acceso por rol.',                            'Seguridad',   4],
    ['seguridad_auditoria', 'Auditoría de accesos', 'Registro de intentos de inicio de sesión.',            'Seguridad',   5],
    ['tienda',              'Tienda en línea',      'Carrito, checkout, pago y seguimiento de pedidos.',    'Tienda',      6],
    ['catalogo',            'Productos',            'Catálogo, categorías y fotos de los productos.',       'Operación',   7],
    ['promociones',         'Promociones',          'Banner de ofertas y descuentos por vigencia.',         'Operación',   8],
    ['inventario',          'Inventario',           'Stock por IMEI (teléfonos) o por cantidad (accesorios).', 'Operación', 9],
    ['ventas',              'Pedidos y mensajes',   'Pedidos de clientes y mensajes automáticos de WhatsApp.', 'Operación', 10],
    ['cobranzas',           'Pagos',                'Transacciones y cobros de la pasarela.',               'Operación',  11],
    ['distribucion',        'Despachos',            'Órdenes de despacho y seguimiento de entregas.',       'Operación',  12],
    ['informes',            'Informes',             'Ventas, productos, inventario, pedidos y clientes.',   'Operación',  13],
];
$stmt = $pdo->prepare('INSERT INTO modulos (clave, nombre, descripcion, grupo, orden) VALUES (?, ?, ?, ?, ?)');
foreach ($modulos as $m) { $stmt->execute($m); }

// ---------- Matriz de permisos (rol => [módulo => nivel]) ----------
$basicos = ['panel' => 'total', 'mi_perfil' => 'total'];
$admin = array_fill_keys(array_column($modulos, 0), 'total');
unset($admin['tienda']);               // el Admin no compra: la tienda es del Cliente
$matriz = [
    'Admin'      => $admin,
    'Vendedor'   => $basicos + ['catalogo' => 'total', 'promociones' => 'total', 'inventario' => 'total',
                                'ventas' => 'total', 'cobranzas' => 'lectura', 'distribucion' => 'lectura', 'informes' => 'lectura'],
    'Repartidor' => $basicos + ['distribucion' => 'total'],
    'Cliente'    => $basicos + ['tienda' => 'total'],
];
$stmt = $pdo->prepare('INSERT INTO permisos_rol (rol_id, modulo_clave, nivel) VALUES (?, ?, ?)');
foreach ($matriz as $rol => $permisos) {
    foreach ($permisos as $clave => $nivel) { $stmt->execute([$idRol[$rol], $clave, $nivel]); }
}

// ---------- Usuarios de prueba ----------
$claveAdmin = 'Admin123!';
$claveDemo  = 'Smart1234!';
$usuarios = [
    ['Administrador SmartCell', 'admin@smartcell.com',      'Admin',      $claveAdmin],
    ['Valeria Torrez',          'vendedor@smartcell.com',   'Vendedor',   $claveDemo],
    ['Marcos Flores',           'repartidor@smartcell.com', 'Repartidor', $claveDemo],
    ['Cliente Demo',            'cliente@smartcell.com',    'Cliente',    $claveDemo],
    ['Lucía Arce',              'lucia@smartcell.com',      'Cliente',    $claveDemo],
];
$stmt = $pdo->prepare('INSERT INTO usuarios (nombre, email, password, rol_id) VALUES (?, ?, ?, ?)');
foreach ($usuarios as [$nombre, $email, $rol, $clave]) {
    $stmt->execute([$nombre, $email, password_hash($clave, PASSWORD_BCRYPT, ['cost' => 10]), $idRol[$rol]]);
}


// ============================================================
// SPRINT 2/3 — categorías, catálogo, IMEI, imágenes, pedidos y promociones de ejemplo
// ============================================================
require RAIZ_PROYECTO . '/database/seed_imagenes.php';
const SEED_CARPETA = RAIZ_PROYECTO . '/frontend/uploads/';

// ---------- Categorías ----------
$categorias = [
    // nombre, descripción, ícono, usa_imei, orden
    ['Teléfonos',    'Celulares nuevos, cada equipo con su IMEI.',        'celular',   1, 1],
    ['Accesorios',   'Cargadores, cables, fundas, protectores y audio.',  'enchufe',   0, 2],
    ['Complementos', 'Relojes, parlantes, soportes y más.',               'reloj',     0, 3],
];
$stmt = $pdo->prepare('INSERT INTO categorias (nombre, descripcion, icono, usa_imei, orden) VALUES (?, ?, ?, ?, ?)');
foreach ($categorias as $c) { $stmt->execute($c); }
$idCat = [];
foreach ($pdo->query('SELECT id, nombre FROM categorias') as $f) { $idCat[$f['nombre']] = (int)$f['id']; }

// Carpeta de imágenes limpia (solo las de ejemplo; las que se suban después no se tocan en este script)
foreach (['productos', 'promociones'] as $carpeta) {
    if (!is_dir(SEED_CARPETA . $carpeta)) { mkdir(SEED_CARPETA . $carpeta, 0775, true); }
    foreach (glob(SEED_CARPETA . "$carpeta/*") as $viejo) { if (is_file($viejo)) { unlink($viejo); } }
}

// ---------- Teléfonos (con IMEI) ----------
// marca, modelo, procesador, RAM, ROM, cámara, pantalla, batería, color, precio Bs, unidades, stock mínimo, descripción, color hex del dibujo
$telefonos = [
    ['Samsung', 'Galaxy A15',     'MediaTek Helio G99',     4, 128, '50 MP + 5 MP + 2 MP',  '6.5" Super AMOLED 90 Hz', 5000, 'Azul oscuro', 1450, 8, 3, 'El Galaxy de entrada con pantalla AMOLED y batería para todo el día.', '#1e3a8a'],
    ['Samsung', 'Galaxy A55 5G',  'Exynos 1480',            8, 256, '50 MP + 12 MP + 5 MP', '6.6" Super AMOLED 120 Hz', 5000, 'Lila',        3600, 6, 3, 'Gama media premium: cuerpo de metal y vidrio, 5G y actualizaciones por 4 años.', '#7c3aed'],
    ['Samsung', 'Galaxy S24',     'Exynos 2400',            8, 256, '50 MP + 12 MP + 10 MP','6.2" Dynamic AMOLED 120 Hz', 4000, 'Negro',     6300, 4, 3, 'Buque insignia compacto con funciones de inteligencia artificial.', '#1f2937'],
    ['Xiaomi',  'Redmi Note 13',  'Snapdragon 685',         8, 256, '108 MP + 8 MP + 2 MP', '6.67" AMOLED 120 Hz',     5000, 'Verde menta', 1950, 10, 3, 'Cámara de 108 MP y carga rápida de 33 W a un precio accesible.', '#10b981'],
    ['Xiaomi',  'Poco X6 Pro',    'Dimensity 8300-Ultra',   8, 256, '64 MP + 8 MP + 2 MP',  '6.67" AMOLED 120 Hz',     5000, 'Amarillo',    3200, 2, 3, 'Rendimiento de gama alta para juegos, con carga de 67 W.', '#eab308'],
    ['Apple',   'iPhone 15',      'A16 Bionic',             6, 128, '48 MP + 12 MP',        '6.1" Super Retina XDR',   3349, 'Rosa',        7900, 5, 2, 'Dynamic Island, cámara de 48 MP y puerto USB-C.', '#f9a8d4'],
    ['Apple',   'iPhone 13',      'A15 Bionic',             4, 128, '12 MP + 12 MP',        '6.1" Super Retina XDR',   3240, 'Medianoche',  5200, 4, 2, 'Muy buen rendimiento y fotos excelentes a un precio más bajo.', '#334155'],
    ['Motorola','Moto G84 5G',    'Snapdragon 695',         8, 256, '50 MP + 8 MP',         '6.55" pOLED 120 Hz',      5000, 'Azul marino', 2100, 7, 3, 'Pantalla pOLED, Android casi puro y 5G.', '#1d4ed8'],
    ['Honor',   'X7b',            'Snapdragon 680',         8, 128, '108 MP + 5 MP + 2 MP', '6.8" LCD 90 Hz',          6000, 'Verde',       1700, 6, 3, 'Batería de 6000 mAh y pantalla grande.', '#15803d'],
    ['Tecno',   'Spark 20 Pro',   'MediaTek Helio G99',     8, 256, '108 MP + QVGA',        '6.78" IPS 120 Hz',        5000, 'Gris lunar',  1300, 9, 3, 'Mucho almacenamiento y pantalla fluida a bajo costo.', '#64748b'],
    ['Google',  'Pixel 8',        'Google Tensor G3',       8, 128, '50 MP + 12 MP',        '6.2" OLED 120 Hz',        4575, 'Obsidiana',   5800, 0, 2, 'La mejor cámara computacional de Android. (Agotado: ejemplo de producto sin stock)', '#0f172a'],
];
$insP = $pdo->prepare('INSERT INTO productos (categoria_id, requiere_imei, marca, modelo, procesador, ram_gb, almacenamiento_gb, camara, pantalla, bateria_mah, color, precio, stock_actual, stock_minimo, descripcion, fotos)
                       VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)');
$insI = $pdo->prepare('INSERT INTO imeis (producto_id, numero_imei) VALUES (?, ?)');
$idProd = [];
foreach ($telefonos as [$marca, $modelo, $cpu, $ram, $rom, $cam, $pant, $bat, $color, $precio, $unidades, $min, $desc, $hex]) {
    $n = count($idProd) + 1;
    $fotos = ["uploads/productos/seed-tel-$n-frente.svg", "uploads/productos/seed-tel-$n-atras.svg"];
    file_put_contents(SEED_CARPETA . 'productos/seed-tel-' . $n . '-frente.svg', svg_telefono($hex, $marca, $modelo, true));
    file_put_contents(SEED_CARPETA . 'productos/seed-tel-' . $n . '-atras.svg', svg_telefono($hex, $marca, $modelo, false));
    $insP->execute([$idCat['Teléfonos'], $marca, $modelo, $cpu, $ram, $rom, $cam, $pant, $bat, $color, $precio, $min, $desc, json_encode($fotos)]);
    $pid = (int)$pdo->lastInsertId();
    $idProd["$marca $modelo"] = $pid;
    $tac = sprintf('35%06d', 100000 + $pid * 137);          // 8 dígitos de modelo (TAC) de ejemplo
    for ($k = 1; $k <= $unidades; $k++) {
        $serie = sprintf('%06d', ($pid * 1000 + $k) * 13 % 1000000);
        $insI->execute([$pid, Imei::completar($tac . $serie)]);   // 8 + 6 + dígito de control Luhn = 15
    }
    $pdo->prepare('UPDATE productos SET stock_actual = ? WHERE id = ?')->execute([$unidades, $pid]);
}

// ---------- Accesorios y complementos (sin IMEI: se controlan por cantidad) ----------
// categoría, marca, modelo, color, precio, stock, mínimo, descripción, tipo de dibujo, color hex, fondo1, fondo2
$otros = [
    ['Accesorios', 'Samsung', 'Cargador rápido 25 W', 'Blanco', 120, 25, 5, 'Carga rápida USB-C de 25 W. No incluye cable.', 'cargador', '#e2e8f0', '#fef3c7', '#fde68a'],
    ['Accesorios', 'Anker',   'Cable USB-C a USB-C 1 m', 'Negro', 55, 40, 8, 'Cable trenzado resistente, carga de hasta 60 W.', 'cable', '#0f172a', '#e0f2fe', '#bae6fd'],
    ['Accesorios', 'SmartCell', 'Funda de silicona iPhone 15', 'Azul', 80, 18, 5, 'Silicona suave con interior de microfibra.', 'funda', '#3b82f6', '#fce7f3', '#fbcfe8'],
    ['Accesorios', 'SmartCell', 'Vidrio templado 9H universal', 'Transparente', 35, 60, 10, 'Protector de pantalla antirrayas con kit de instalación.', 'vidrio', '#7dd3fc', '#ecfeff', '#cffafe'],
    ['Accesorios', 'Xiaomi',  'Redmi Buds 5 (audífonos Bluetooth)', 'Negro', 260, 12, 4, 'Cancelación de ruido y hasta 40 h con el estuche.', 'audifonos', '#111827', '#ede9fe', '#ddd6fe'],
    ['Accesorios', 'Xiaomi',  'Power bank 10000 mAh', 'Gris', 190, 3, 4, 'Carga rápida de 22,5 W con dos salidas. (Stock bajo: ejemplo de alerta)', 'powerbank', '#475569', '#dcfce7', '#bbf7d0'],
    ['Complementos', 'Xiaomi', 'Smart Band 8 (reloj inteligente)', 'Grafito', 320, 14, 4, 'Pantalla AMOLED, pulsómetro y 16 días de batería.', 'reloj', '#0ea5e9', '#e0e7ff', '#c7d2fe'],
    ['Complementos', 'JBL',   'Go 3 (parlante Bluetooth)', 'Rojo', 280, 9, 3, 'Parlante portátil a prueba de agua y polvo.', 'parlante', '#ef4444', '#ffedd5', '#fed7aa'],
    ['Complementos', 'SmartCell', 'Soporte de celular para auto', 'Negro', 65, 20, 5, 'Soporte con ventosa, gira 360°.', 'soporte', '#1f2937', '#f1f5f9', '#e2e8f0'],
    ['Complementos', 'Ugreen', 'Trípode flexible con control', 'Negro', 95, 11, 3, 'Patas flexibles y control remoto Bluetooth para fotos.', 'tripode', '#334155', '#fef9c3', '#fef08a'],
];
$insO = $pdo->prepare('INSERT INTO productos (categoria_id, requiere_imei, marca, modelo, color, precio, stock_actual, stock_minimo, descripcion, fotos)
                       VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($otros as $n => [$cat, $marca, $modelo, $color, $precio, $stock, $min, $desc, $tipo, $hex, $f1, $f2]) {
    $archivo = 'seed-acc-' . ($n + 1) . '.svg';
    file_put_contents(SEED_CARPETA . 'productos/' . $archivo, svg_accesorio($tipo, $hex, $marca, $modelo, $f1, $f2));
    $insO->execute([$idCat[$cat], $marca, $modelo, $color, $precio, $stock, $min, $desc, json_encode(["uploads/productos/$archivo"])]);
    $idProd["$marca $modelo"] = (int)$pdo->lastInsertId();
}

// ---------- Pedidos de ejemplo (mismos servicios que la API) ----------
$idUsr = [];
foreach ($pdo->query('SELECT id, email FROM usuarios') as $f) { $idUsr[$f['email']] = (int)$f['id']; }
$cliente = $idUsr['cliente@smartcell.com'];
$vendedor = $idUsr['vendedor@smartcell.com'];
$lucia   = $idUsr['lucia@smartcell.com'];
$repart  = usuarioPorId($idUsr['repartidor@smartcell.com']);
$vence   = date('m/y', strtotime('+2 years'));
$tarjeta = ['numero' => '4242 4242 4242 4242', 'titular' => 'CLIENTE DEMO', 'vencimiento' => $vence, 'cvv' => '123'];

$pedir = function (int $uid, array $lineas, string $dir, string $ciudad) use ($pdo, $idProd) {
    $ins = $pdo->prepare('INSERT INTO carrito_items (usuario_id, producto_id, cantidad) VALUES (?, ?, ?)');
    foreach ($lineas as $nombre => $cant) { $ins->execute([$uid, $idProd[$nombre], $cant]); }
    return PedidoService::crearDesdeCarrito($uid, ['direccion_envio' => $dir, 'ciudad' => $ciudad, 'telefono' => '70012345']);
};
$atrasar = function (int $pedidoId, int $dias) use ($pdo) {
    $mod = "-$dias days";
    $pdo->prepare("UPDATE pedidos SET fecha = datetime('now','localtime',?), actualizado_en = datetime('now','localtime',?) WHERE id = ?")->execute([$mod, $mod, $pedidoId]);
    $pdo->prepare("UPDATE pagos SET fecha = datetime('now','localtime',?) WHERE pedido_id = ?")->execute([$mod, $pedidoId]);
    $pdo->prepare("UPDATE despachos SET creado_en = datetime('now','localtime',?) WHERE pedido_id = ?")->execute([$mod, $pedidoId]);
    $pdo->prepare("UPDATE imeis SET vendido_en = datetime('now','localtime',?) WHERE pedido_id = ?")->execute([$mod, $pedidoId]);
    $pdo->prepare("UPDATE notificaciones SET creado_en = datetime('now','localtime',?) WHERE pedido_id = ?")->execute([$mod, $pedidoId]);
};

// 1) Pedido entregado (3 equipos, tarjeta)
$p1 = $pedir($cliente, ['Samsung Galaxy A15' => 2, 'Xiaomi Redmi Note 13' => 1], 'Calle Calvo 123, Zona Central', 'Sucre');
$r = PagoService::procesar($p1, $cliente, 'Tarjeta', $tarjeta);
Logistica::cambiarEstado($r['despacho']['id'], 'En_camino', $repart);
Logistica::cambiarEstado($r['despacho']['id'], 'Entregado', $repart);
$atrasar($p1, 5);

// 2) Pedido en camino (QR)
$p2 = $pedir($lucia, ['Apple iPhone 15' => 1], 'Av. Hernando Siles 456', 'Sucre');
$r = PagoService::procesar($p2, $lucia, 'QR', []);
Logistica::cambiarEstado($r['despacho']['id'], 'En_camino', $repart);
$atrasar($p2, 2);

// 3) Pedido pagado, en preparación (transferencia)
$p3 = $pedir($cliente, ['Motorola Moto G84 5G' => 1], 'Calle Bolívar 78', 'Sucre');
PagoService::procesar($p3, $cliente, 'Transferencia', ['comprobante' => 'TRF-884213']);
$atrasar($p3, 1);

// 4) Pedido pendiente de pago
$pedir($lucia, ['Tecno Spark 20 Pro' => 1], 'Calle Junín 210', 'Sucre');

// 5) Pedido con pago rechazado (tarjeta de prueba declinada)
$p5 = $pedir($cliente, ['Honor X7b' => 1], 'Calle Calvo 123, Zona Central', 'Sucre');
PagoService::procesar($p5, $cliente, 'Tarjeta', ['numero' => '4000 0000 0000 0002'] + $tarjeta);


// Historial de ventas de las últimas 4 semanas (para que el panel y los informes tengan datos).
// Se crea ANTES de las promociones, así esos pedidos conservan su precio normal.
$transf = fn($n) => ['comprobante' => 'TRF-' . $n];
$historial = [
    // días atrás, cliente, líneas, método
    [27, $cliente, ['SmartCell Vidrio templado 9H universal' => 2, 'Anker Cable USB-C a USB-C 1 m' => 1], 'QR'],
    [24, $lucia,   ['Tecno Spark 20 Pro' => 1], 'Tarjeta'],
    [21, $cliente, ['Xiaomi Redmi Buds 5 (audífonos Bluetooth)' => 1], 'QR'],
    [18, $lucia,   ['Xiaomi Redmi Note 13' => 1, 'SmartCell Soporte de celular para auto' => 1], 'Transferencia'],
    [15, $cliente, ['Xiaomi Smart Band 8 (reloj inteligente)' => 1], 'Tarjeta'],
    [12, $lucia,   ['Samsung Galaxy A55 5G' => 1], 'Tarjeta'],
    [10, $cliente, ['JBL Go 3 (parlante Bluetooth)' => 2], 'QR'],
    [8,  $lucia,   ['Ugreen Trípode flexible con control' => 1, 'SmartCell Vidrio templado 9H universal' => 1], 'QR'],
    [6,  $cliente, ['Xiaomi Poco X6 Pro' => 1], 'Tarjeta'],
    [4,  $lucia,   ['SmartCell Funda de silicona iPhone 15' => 1, 'Samsung Cargador rápido 25 W' => 1], 'QR'],
];
$k = 0;
foreach ($historial as [$dias, $uid, $lineas, $metodo]) {
    $k++;
    $id = $pedir($uid, $lineas, 'Calle Junín ' . (100 + $k) . ', Zona Central', 'Sucre');
    $datos = $metodo === 'Tarjeta' ? $tarjeta : ($metodo === 'Transferencia' ? $transf(500000 + $k) : []);
    $r = PagoService::procesar($id, $uid, $metodo, $datos);
    Logistica::cambiarEstado($r['despacho']['id'], 'En_camino', $repart);
    Logistica::cambiarEstado($r['despacho']['id'], 'Entregado', $repart);
    $atrasar($id, $dias);
}

// Ahora se crean las promociones vigentes y un pedido que las usa.
$hoy = date('Y-m-d');
$ini = date('Y-m-d', strtotime('-3 days'));
$fin = date('Y-m-d', strtotime('+25 days'));
$promos = [
    // título, descripción, descuento, producto, categoría, etiqueta, colores, forma
    ['Galaxy A15 con 15 % de descuento', 'El Galaxy de entrada, ahora más barato. Por tiempo limitado.', 15, 'Samsung Galaxy A15', null, 'OFERTA', ['#1d4ed8', '#7c3aed'], 'telefono'],
    ['Accesorios con 20 % de descuento', 'Cargadores, cables, fundas y audio para tu celular.', 20, null, 'Accesorios', 'ACCESORIOS', ['#db2777', '#f97316'], 'auricular'],
    ['Llegaron los complementos', 'Relojes inteligentes, parlantes y más para completar tu equipo.', 0, null, 'Complementos', 'NUEVO', ['#0f766e', '#0ea5e9'], 'reloj'],
];
$insPromo = $pdo->prepare('INSERT INTO promociones (titulo, descripcion, imagen, descuento_pct, producto_id, categoria_id, fecha_inicio, fecha_fin) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ($promos as $i => [$titulo, $desc, $pct, $prod, $cat, $etq, [$c1, $c2], $forma]) {
    $archivo = 'seed-promo-' . ($i + 1) . '.svg';
    $txt = $pct > 0 ? "-$pct % hasta el " . date('d/m', strtotime($fin)) : 'Descúbrelos en la tienda';
    file_put_contents(SEED_CARPETA . 'promociones/' . $archivo, svg_banner(mb_strimwidth($titulo, 0, 30, '…'), $txt, $etq, $c1, $c2, $forma));
    $insPromo->execute([$titulo, $desc, "uploads/promociones/$archivo", $pct, $prod ? $idProd[$prod] : null, $cat ? $idCat[$cat] : null, $ini, $fin]);
}

// 6) Pedido con accesorios (sin IMEI) y descuento del 20 %, pagado por QR
$p6 = $pedir($lucia, ['Samsung Cargador rápido 25 W' => 1, 'SmartCell Funda de silicona iPhone 15' => 2], 'Av. Hernando Siles 456', 'Sucre');
PagoService::procesar($p6, $lucia, 'QR', []);

// Los mensajes de los primeros pedidos ya "se enviaron"; los últimos quedan pendientes para la demo
$pdo->prepare("UPDATE notificaciones SET estado = 'Enviado', enviado_en = creado_en, enviado_por = ? WHERE pedido_id NOT IN (?, ?)")
    ->execute([$vendedor, $p3, $p6]);

$totalImei = (int)$pdo->query('SELECT COUNT(*) FROM imeis')->fetchColumn();
$nProductos = (int)$pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn();
$nMensajes = (int)$pdo->query('SELECT COUNT(*) FROM notificaciones')->fetchColumn();
echo "Base de datos creada: $rutaDb\n";
echo "Roles: " . count($roles) . " | Módulos: " . count($modulos) . " | Usuarios: " . count($usuarios) . " | Categorías: " . count($categorias) . "\n";
echo "Productos: $nProductos | IMEI: $totalImei | Pedidos de ejemplo: " . (6 + count($historial)) . " | Promociones: " . count($promos) . " | Mensajes automáticos: $nMensajes\n";
echo "Admin: admin@smartcell.com / $claveAdmin\n";
echo "Demás usuarios (vendedor@, repartidor@, cliente@, lucia@smartcell.com): contraseña $claveDemo\n";
