<?php

declare(strict_types=1);

namespace App\Support;

final class InvgatePriority
{
    /** @var list<int> */
    public const HIGH_PRIORITY_IDS = [1, 2];

    public static function weight(?int $priority): int
    {
        if ($priority === 1) {
            return 5;
        }
        if ($priority === 2) {
            return 3;
        }
        if ($priority === 3) {
            return 2;
        }

        return 1;
    }

    public static function isHigh(?int $priority): bool
    {
        return $priority !== null && in_array($priority, self::HIGH_PRIORITY_IDS, true);
    }
}
