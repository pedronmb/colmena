<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Carga config.php local fusionado con config.php.default (valores por defecto).
 */
final class ConfigLoader
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $projectRoot): array
    {
        $defaultPath = $projectRoot . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'config.php.default';
        $localPath = $projectRoot . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . 'config.php';

        /** @var array<string, mixed> $config */
        $config = [];
        if (is_file($defaultPath)) {
            $default = require $defaultPath;
            if (is_array($default)) {
                $config = $default;
            }
        }

        if (is_file($localPath)) {
            $local = require $localPath;
            if (is_array($local)) {
                $config = array_replace_recursive($config, $local);
            }
        }

        return $config;
    }
}
