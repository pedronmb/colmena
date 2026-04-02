<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Escala de 5 niveles para urgencia (priority) e importancia (importance).
 *
 * Urgencia (cuándo actuar): muy baja → crítica
 * Importancia (valor/impacto): muy baja → muy alta
 */
final class TopicScales
{
    public const PRIORITY_LEVELS = ['very_low', 'low', 'medium', 'high', 'critical'];

    public const IMPORTANCE_LEVELS = ['very_low', 'low', 'medium', 'high', 'very_high'];

    public static function normalizePriority(string $p): string
    {
        return in_array($p, self::PRIORITY_LEVELS, true) ? $p : 'medium';
    }

    public static function normalizeImportance(string $i): string
    {
        return in_array($i, self::IMPORTANCE_LEVELS, true) ? $i : 'medium';
    }

    /**
     * Convierte valores antiguos (4 urgencia + 3 importancia) a la escala de 5.
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

        return self::normalizePriority($p);
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

        return self::normalizeImportance($i);
    }
}
