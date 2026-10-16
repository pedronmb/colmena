<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class InvgateManualPriority
{
    public const MAX = 2147483647;

    /** @param mixed $value */
    public static function parse($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/^[0-9]+$/D', (string) $value)
            || (float) $value < 1
            || (float) $value > self::MAX
        ) {
            throw new InvalidArgumentException('La prioridad debe ser un número entero entre 1 y ' . self::MAX . ', o quedar vacía.');
        }

        return (int) $value;
    }
}
