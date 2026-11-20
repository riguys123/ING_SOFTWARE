<?php
/**
 * Pone las fotos reales a los celulares de la presentación y agrega el iPhone 15 Pro,
 * SIN borrar los datos existentes (a diferencia del seeder).
 *
 * Uso:   C:\xampp\php\php.exe database\actualizar_telefonos.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo línea de comandos.'); }

require dirname(__DIR__) . '/backend/src/config.php';
foreach (['Http', 'Database', 'Auth'] as $f) { require RAIZ_PROYECTO . "/backend/src/$f.php"; }
require RAIZ_PROYECTO . '/backend/src/Services/Imei.php';

$pdo = new PDO('sqlite:' . __DIR__ . '/smartcell.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$reales = [
    ['Apple', 'iPhone 15 Pro', 'assets/img/telefonos/iphone-15-pro.png'],
    ['Google', 'Pixel 8', 'assets/img/telefonos/pixel-8.png'],
    ['Tecno', 'Spark 20 Pro', 'assets/img/telefonos/tecno-spark-20-pro.png'],
    ['Honor', 'X7b', 'assets/img/telefonos/honor-x7b.png'],
    ['Motorola', 'Moto G84 5G', 'assets/img/telefonos/moto-g84.png'],
];

$existe = $pdo->prepare('SELECT id FROM productos WHERE marca = ? AND modelo = ?');
if (!$existe->execute(['Apple', 'iPhone 15 Pro']) || !$existe->fetchColumn()) {
    $cat = (int)$pdo->query("SELECT id FROM categorias WHERE nombre = 'Teléfonos'")->fetchColumn();
    $pdo->prepare('INSERT INTO productos (categoria_id, requiere_imei, marca, modelo, procesador, ram_gb, almacenamiento_gb, camara, pantalla, bateria_mah, color, precio, stock_actual, stock_minimo, descripcion, fotos)
                   VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)')
        ->execute([$cat, 'Apple', 'iPhone 15 Pro', 'A17 Pro', 8, 256, '48 MP + 12 MP + 12 MP', '6.1" Super Retina XDR ProMotion', 3274, 'Titanio negro', 9800, 2,
                   'Cuerpo de titanio, chip A17 Pro y cámara de 48 MP con zoom óptico.', '[]']);
    $pid = (int)$pdo->lastInsertId();
    $tac = sprintf('35%06d', 100000 + $pid * 137);
    $ins = $pdo->prepare('INSERT INTO imeis (producto_id, numero_imei) VALUES (?, ?)');
    for ($k = 1; $k <= 3; $k++) {
        $ins->execute([$pid, Imei::completar($tac . sprintf('%06d', ($pid * 1000 + $k) * 13 % 1000000))]);
    }
    $pdo->prepare('UPDATE productos SET stock_actual = 3 WHERE id = ?')->execute([$pid]);
    echo "Agregado: Apple iPhone 15 Pro (3 unidades)\n";
}
$upd = $pdo->prepare('UPDATE productos SET fotos = ? WHERE marca = ? AND modelo = ?');
foreach ($reales as [$marca, $modelo, $ruta]) {
    $upd->execute([json_encode([$ruta]), $marca, $modelo]);
    echo "Foto real: $marca $modelo\n";
}
echo "Listo.\n";
