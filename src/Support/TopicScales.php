<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Urgencia (priority) e importancia (importance): enteros 1–10.
 */
final class TopicScales
{
    public const MIN = 1;

    public const MAX = 10;

    public const DEFAULT = 5;

    /** @param mixed $value */
    public static function normalizePriority($value): int
    {
        return self::clamp(self::parsePriority($value));
    }

    /** @param mixed $value */
    public static function normalizeImportance($value): int
    {
        return self::clamp(self::parseImportance($value));
    }

    private static function clamp(int $n): int
    {
        if ($n < self::MIN) {
            return self::MIN;
        }
        if ($n > self::MAX) {
            return self::MAX;
        }

        return $n;
    }

    /** @param mixed $value */
    private static function parsePriority($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return self::DEFAULT;
        }
        if (ctype_digit($s)) {
            return (int) $s;
        }
        $map = [
            'very_low' => 2,
            'low' => 4,
            'medium' => 5,
            'high' => 8,
            'critical' => 10,
            'urgent' => 10,
        ];

        return $map[$s] ?? self::DEFAULT;
    }

    /** @param mixed $value */
    private static function parseImportance($value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        $s = trim((string) $value);
        if ($s === '') {
            return self::DEFAULT;
        }
        if (ctype_digit($s)) {
            return (int) $s;
        }
        $map = [
            'very_low' => 2,
            'low' => 4,
            'medium' => 5,
            'high' => 8,
            'very_high' => 10,
        ];

        return $map[$s] ?? self::DEFAULT;
    }

    /**
     * Convierte valores antiguos (4 urgencia + 3 importancia) a la escala de 5.
     * Usado por migrate_topics_five_levels.php.
     */
    public static function migrateLegacyPriority(string $p): string
    {
        $map = [
            'low' => 'very_low',
            'medium' => 'medium',
            'high' => 'high',
            'urgent' => 'critical',
        ];
        if (isset($map[$p])) {
            return $map[$p];
        }

        return in_array($p, ['very_low', 'low', 'medium', 'high', 'critical'], true) ? $p : 'medium';
    }

    public static function migrateLegacyImportance(string $i): string
    {
        $map = [
            'low' => 'very_low',
            'medium' => 'medium',
            'high' => 'very_high',
        ];
        if (isset($map[$i])) {
            return $map[$i];
        }

        return in_array($i, ['very_low', 'low', 'medium', 'high', 'very_high'], true) ? $i : 'medium';
    }
}
