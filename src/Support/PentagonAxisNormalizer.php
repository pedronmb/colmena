<?php

declare(strict_types=1);

namespace App\Support;

final class PentagonAxisNormalizer
{
    /** @param mixed $raw */
    public static function parseOptional($raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_bool($raw)) {
            throw new \InvalidArgumentException('Valor de eje inválido');
        }
        if (!is_numeric($raw)) {
            throw new \InvalidArgumentException('Valor de eje inválido (usa un número 0–10)');
        }
        $n = (int) round((float) $raw);

        return max(0, min(10, $n));
    }

    /**
     * Si la clave no está en el array, devuelve null (no cambiar / sin valor).
     * Si está y es inválida, lanza.
     *
     * @param array<string, mixed> $data
     */
    public static function parseFromData(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data)) {
            return null;
        }

        return self::parseOptional($data[$key]);
    }
}
