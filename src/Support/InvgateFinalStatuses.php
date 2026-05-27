<?php

declare(strict_types=1);

namespace App\Support;

final class InvgateFinalStatuses
{
    /** @var list<int> */
    public const FINAL_STATUS_IDS = [5, 6, 7, 8];

    public static function isFinal(?int $statusId): bool
    {
        if ($statusId === null) {
            return false;
        }

        return in_array($statusId, self::FINAL_STATUS_IDS, true);
    }

    /**
     * @return list<int>
     */
    public static function ids(): array
    {
        return self::FINAL_STATUS_IDS;
    }

    public static function sqlNotInPlaceholders(): string
    {
        return implode(',', array_fill(0, count(self::FINAL_STATUS_IDS), '?'));
    }
}
