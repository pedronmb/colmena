<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use PDOException;

final class Connection
{
    private static ?PDO $pdo = null;

    public static function get(array $config): PDO
    {
        if (self::$pdo === null) {
            $path = $config['db']['path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $dsn = 'sqlite:' . $path;
            try {
                self::$pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
            } catch (PDOException $e) {
                throw new \RuntimeException('No se pudo conectar a la base de datos: ' . $e->getMessage(), 0, $e);
            }
        }
        return self::$pdo;
    }
}
