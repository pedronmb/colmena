<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Umbrales centralizados del Centro de Salud y Capacidad del Equipo.
 */
final class TeamHealthThresholds
{
    public const HEALTH_GREEN_MIN = 75;
    public const LOAD_GREEN_MAX = 75;

    public const HEALTH_YELLOW_MIN = 50;
    public const LOAD_YELLOW_MIN = 75;
    public const LOAD_YELLOW_MAX = 89;

    public const HEALTH_RED_MAX = 50;
    public const LOAD_RED_MIN = 90;
    public const STALE_TICKETS_RED_MIN = 5;

    public const TEAM_HEALTH_CRITICAL_MAX = 40;
    public const TEAM_CONCENTRATION_RED_MIN = 45.0;

    public const PENALTY_LOAD_90 = 30;
    public const PENALTY_LOAD_75 = 15;
    public const PENALTY_STALE_TICKET = 5;
    public const PENALTY_HIGH_PRIORITY_STALE = 10;
    public const PENALTY_CONCENTRATION = 10;

    public const CRITICAL_TOPIC_PRIORITY_MIN = 8;
    public const CRITICAL_TOPIC_IMPORTANCE_MIN = 8;

    /**
     * @return 'green'|'yellow'|'red'
     */
    public static function personStatus(int $healthScore, int $loadScore, int $staleTickets): string
    {
        if (
            $healthScore < self::HEALTH_RED_MAX
            || $loadScore >= self::LOAD_RED_MIN
            || $staleTickets >= self::STALE_TICKETS_RED_MIN
        ) {
            return 'red';
        }

        if (
            $healthScore < self::HEALTH_GREEN_MIN
            || $loadScore >= self::LOAD_YELLOW_MIN
        ) {
            return 'yellow';
        }

        if ($healthScore >= self::HEALTH_GREEN_MIN && $loadScore < self::LOAD_GREEN_MAX) {
            return 'green';
        }

        return 'yellow';
    }

    /**
     * @param list<array{
     *   status: string,
     *   load_score: int,
     *   health_score: int
     * }> $people
     * @return 'green'|'yellow'|'red'
     */
    public static function teamStatus(array $people, float $concentrationPercent): string
    {
        $hasRed = false;
        $hasYellowOrRed = false;

        foreach ($people as $row) {
            $status = (string) ($row['status'] ?? 'green');
            $load = (int) ($row['load_score'] ?? 0);
            $health = (int) ($row['health_score'] ?? 100);

            if ($status === 'red' || $status === 'yellow') {
                $hasYellowOrRed = true;
            }

            if (
                $status === 'red'
                && ($load >= self::LOAD_RED_MIN || $health < self::TEAM_HEALTH_CRITICAL_MAX)
            ) {
                $hasRed = true;
            }
        }

        if ($hasRed || $concentrationPercent > self::TEAM_CONCENTRATION_RED_MIN) {
            return 'red';
        }

        if ($hasYellowOrRed) {
            return 'yellow';
        }

        return 'green';
    }

    /**
     * Salud 0–100 a partir de 100 menos penalizaciones.
     *
     * @param array{
     *   load_score: int,
     *   stale_tickets: int,
     *   high_priority_stale: int,
     *   concentration_penalty: bool
     * } $input
     */
    public static function healthScore(array $input): int
    {
        $score = 100;
        $load = (int) ($input['load_score'] ?? 0);

        if ($load >= self::LOAD_RED_MIN) {
            $score -= self::PENALTY_LOAD_90;
        } elseif ($load >= self::LOAD_YELLOW_MIN) {
            $score -= self::PENALTY_LOAD_75;
        }

        $stale = max(0, (int) ($input['stale_tickets'] ?? 0));
        $highStale = max(0, (int) ($input['high_priority_stale'] ?? 0));
        $genericStale = max(0, $stale - $highStale);
        $score -= $genericStale * self::PENALTY_STALE_TICKET;
        $score -= $highStale * self::PENALTY_HIGH_PRIORITY_STALE;

        if (!empty($input['concentration_penalty'])) {
            $score -= self::PENALTY_CONCENTRATION;
        }

        return max(0, min(100, $score));
    }

    public static function isCriticalTopic(int $priority, int $importance): bool
    {
        return $priority >= self::CRITICAL_TOPIC_PRIORITY_MIN
            && $importance >= self::CRITICAL_TOPIC_IMPORTANCE_MIN;
    }
}
