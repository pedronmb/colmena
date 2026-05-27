<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

final class InvgateTimestamp
{
    public static function parse(?string $raw): ?DateTimeImmutable
    {
        if ($raw === null) {
            return null;
        }
        $s = trim($raw);
        if ($s === '' || $s === '0') {
            return null;
        }

        if (ctype_digit($s)) {
            $n = (int) $s;
            if ($n > 1_000_000_000_000) {
                $n = (int) floor($n / 1000);
            }
            if ($n > 1_000_000_000) {
                return (new DateTimeImmutable())->setTimestamp($n);
            }
        }

        try {
            return new DateTimeImmutable($s);
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function epochSeconds(?string $raw): ?int
    {
        $dt = self::parse($raw);

        return $dt !== null ? $dt->getTimestamp() : null;
    }
}
