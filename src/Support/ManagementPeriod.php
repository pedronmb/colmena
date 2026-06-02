<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/** Semana calendario (lunes–domingo) para el Copiloto de Management. */
final class ManagementPeriod
{
    /**
     * @return array{period_start: string, period_end: string}
     */
    public static function currentWeek(): array
    {
        return self::weekContaining(new DateTimeImmutable('now'));
    }

    /**
     * @return array{period_start: string, period_end: string}
     */
    public static function previousWeek(): array
    {
        $current = self::weekContaining(new DateTimeImmutable('now'));
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $current['period_start']);
        if ($start === false) {
            return self::currentWeek();
        }

        return self::weekContaining($start->modify('-7 days'));
    }

    /**
     * @return array{period_start: string, period_end: string}
     */
    public static function weekContaining(DateTimeImmutable $date): array
    {
        $dow = (int) $date->format('N');
        $monday = $date->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);
        $sunday = $monday->modify('+6 days');

        return [
            'period_start' => $monday->format('Y-m-d'),
            'period_end' => $sunday->format('Y-m-d'),
        ];
    }

    public static function resolve(string $which): array
    {
        if ($which === 'previous') {
            return self::previousWeek();
        }

        return self::currentWeek();
    }
}
