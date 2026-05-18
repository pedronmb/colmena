<?php

declare(strict_types=1);

namespace App\Support;

final class DirectTeamNormalizer
{
    /**
     * @param array<string, mixed> $data
     */
    public static function parseFromData(array $data, string $key = 'is_direct_team'): bool
    {
        if (!array_key_exists($key, $data)) {
            return false;
        }

        return self::parseValue($data[$key]);
    }

    /** @param mixed $value */
    public static function parseValue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            $s = strtolower(trim($value));

            return in_array($s, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
