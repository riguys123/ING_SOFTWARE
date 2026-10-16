<?php
/**
 * Seeder del subsistema de Seguridad — SmartCell Commerce
 *
 * Crea (o recrea) database/smartcell.sqlite ejecutando schema.sql y
 * cargando roles, módulos, matriz de permisos y un usuario por rol.
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

if (is_file($rutaDb)) {
    unlink($rutaDb);
}

$pdo = new PDO('sqlite:' . $rutaDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec(file_get_contents($rutaSchema));

// ---------- Roles ----------
$roles = [
    // nombre, descripción, área
    ['Admin',                'Administra usuarios, roles, permisos y auditoría del sistema.', 'seguridad'],
    ['Jefe de Distribución', 'Supervisa la distribución y reparto de equipos.',               'distribucion'],
    ['Jefe de Almacén',      'Supervisa el almacén y el inventario por IMEI.',                'inventario'],
    ['Vendedor',             'Registra ventas y atiende clientes.',                           'ventas'],
    ['Inventariado',         'Auxiliar de inventario: registra ingresos y salidas de equipos.', 'inventario'],
    ['Repartidor',           'Entrega equipos a clientes.',                                   'distribucion'],
    ['Cobranzas',            'Gestiona pagos y cuentas por cobrar.',                          'cobranzas'],
    ['Cliente',              'Comprador final de la tienda en línea.',                        'clientes'],
];
$stmt = $pdo->prepare('INSERT INTO roles (nombre, descripcion, area) VALUES (?, ?, ?)');
foreach ($roles as $r) { $stmt->execute($r); }

$idRol = [];
foreach ($pdo->query('SELECT id, nombre FROM roles') as $fila) { $idRol[$fila['nombre']] = (int)$fila['id']; }

// ---------- Módulos ----------
$modulos = [
    // clave, nombre, descripción, grupo, orden
    ['panel',               'Panel principal',      'Resumen y accesos rápidos.',                       'General',        1],
    ['mi_perfil',           'Mi perfil',            'Datos de la cuenta y cambio de contraseña.',       'General',        2],
    ['seguridad_usuarios',  'Gestión de usuarios',  'Alta, edición, baja lógica y consulta de usuarios.', 'Seguridad',    3],
    ['seguridad_roles',     'Roles y permisos',     'Matriz de acceso por rol.',                        'Seguridad',      4],
    ['seguridad_auditoria', 'Auditoría de accesos', 'Registro de intentos de inicio de sesión.',        'Seguridad',      5],
    ['catalogo',            'Catálogo',             'Catálogo de celulares (otro equipo).',             'Otros módulos',  6],
    ['inventario',          'Inventario por IMEI',  'Control de stock por IMEI (otro equipo).',         'Otros módulos',  7],
    ['distribucion',        'Distribución',         'Rutas y entregas (otro equipo).',                  'Otros módulos',  8],
    ['ventas',              'Ventas',               'Registro de ventas (otro equipo).',                'Otros módulos',  9],
    ['cobranzas',           'Cobranzas',            'Cuentas por cobrar (otro equipo).',                'Otros módulos', 10],
];
$stmt = $pdo->prepare('INSERT INTO modulos (clave, nombre, descripcion, grupo, orden) VALUES (?, ?, ?, ?, ?)');
foreach ($modulos as $m) { $stmt->execute($m); }

// ---------- Matriz de permisos (rol => [módulo => nivel]) ----------
$basicos = ['panel' => 'total', 'mi_perfil' => 'total'];
$matriz = [
    // Admin: todo
    'Admin' => array_fill_keys(array_column($modulos, 0), 'total'),
    // Jefes: ven usuarios de su área (solo lectura) + su módulo operativo
    'Jefe de Almacén'      => $basicos + ['seguridad_usuarios' => 'lectura', 'inventario'    => 'total'],
    'Jefe de Distribución' => $basicos + ['seguridad_usuarios' => 'lectura', 'distribucion'  => 'total'],
    // Resto: solo su perfil y su panel
    'Vendedor'     => $basicos,
    'Inventariado' => $basicos,
    'Repartidor'   => $basicos,
    'Cobranzas'    => $basicos,
    'Cliente'      => $basicos,
];
$stmt = $pdo->prepare('INSERT INTO permisos_rol (rol_id, modulo_clave, nivel) VALUES (?, ?, ?)');
foreach ($matriz as $rol => $permisos) {
    foreach ($permisos as $clave => $nivel) { $stmt->execute([$idRol[$rol], $clave, $nivel]); }
}

// ---------- Usuarios de prueba (uno por rol) ----------
$claveAdmin = 'Admin123!';
$claveDemo  = 'Smart1234!';
$usuarios = [
    ['Administrador SmartCell', 'admin@smartcell.com',                'Admin',                $claveAdmin],
    ['Carla Mendoza',           'jefe.distribucion@smartcell.com',    'Jefe de Distribución', $claveDemo],
    ['Diego Rojas',             'jefe.almacen@smartcell.com',         'Jefe de Almacén',      $claveDemo],
    ['Valeria Torrez',          'vendedor@smartcell.com',             'Vendedor',             $claveDemo],
    ['Luis Quispe',             'inventariado@smartcell.com',         'Inventariado',         $claveDemo],
    ['Marcos Flores',           'repartidor@smartcell.com',           'Repartidor',           $claveDemo],
    ['Andrea Vargas',           'cobranzas@smartcell.com',            'Cobranzas',            $claveDemo],
    ['Cliente Demo',            'cliente@smartcell.com',              'Cliente',              $claveDemo],
];
$stmt = $pdo->prepare('INSERT INTO usuarios (nombre, email, password, rol_id) VALUES (?, ?, ?, ?)');
foreach ($usuarios as [$nombre, $email, $rol, $clave]) {
    $stmt->execute([$nombre, $email, password_hash($clave, PASSWORD_BCRYPT, ['cost' => 10]), $idRol[$rol]]);
}

echo "Base de datos creada: $rutaDb\n";
echo "Roles: " . count($roles) . " | Módulos: " . count($modulos) . " | Usuarios: " . count($usuarios) . "\n";
echo "Admin: admin@smartcell.com / $claveAdmin\n";
echo "Demás usuarios: contraseña $claveDemo\n";
