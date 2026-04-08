<?php

declare(strict_types=1);

namespace App;

final class Bootstrap
{
    /** @var bool */
    private static $autoloadRegistered = false;

    public static function registerAutoload(string $projectRoot): void
    {
        if (self::$autoloadRegistered) {
            return;
        }
        self::$autoloadRegistered = true;
        $base = $projectRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
        spl_autoload_register(static function (string $class) use ($base): void {
            $prefix = 'App\\';
            if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $base . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }

    public static function sessionStart(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_lifetime' => 86400,
                'gc_maxlifetime' => 86400,
                'cookie_httponly' => true,
                'cookie_samesite' => 'Lax',
            ]);
        }
    }
}
