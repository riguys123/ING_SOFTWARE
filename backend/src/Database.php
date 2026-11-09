<?php
/**
 * Conexión PDO a SQLite (singleton). Reemplaza al includes/config.php de MySQL.
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static int $nivel = 0;   // profundidad de transacciones anidadas

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (!is_file(DB_PATH)) {
                throw new RuntimeException(
                    'No existe la base de datos. Ejecuta: php database/seeder.php'
                );
            }
            $pdo = new PDO('sqlite:' . DB_PATH);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /**
     * Ejecuta $fn dentro de una transacción atómica (BEGIN IMMEDIATE bloquea a otros
     * escritores, así dos compras simultáneas no pueden llevarse el mismo IMEI).
     * Si ya hay una transacción abierta, simplemente se une a ella.
     * Si $fn lanza una excepción, todo se revierte (ROLLBACK).
     */
    public static function transaccion(callable $fn): mixed
    {
        $pdo = self::pdo();
        // Contador propio: PDO no detecta los BEGIN lanzados con exec() en SQLite
        if (self::$nivel > 0) {
            return $fn($pdo);
        }
        // Si otra petición está escribiendo, SQLite responde "database is locked" al instante cuando
        // detecta un posible bloqueo mutuo. Reintentamos unos instantes en lugar de fallar con 500.
        for ($intento = 1; ; $intento++) {
            try {
                $pdo->exec('BEGIN IMMEDIATE');
                break;
            } catch (PDOException $e) {
                if ($intento >= 40 || stripos($e->getMessage(), 'locked') === false) {
                    throw $e;
                }
                usleep(100000);   // 100 ms
            }
        }
        self::$nivel++;
        try {
            $resultado = $fn($pdo);
            $pdo->exec('COMMIT');
            return $resultado;
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignorar) { /* ya revertida */ }
            throw $e;
        } finally {
            self::$nivel--;
        }
    }
}
