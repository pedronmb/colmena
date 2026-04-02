<?php

declare(strict_types=1);

namespace App\Support;

final class BirthdayNormalizer
{
    /**
     * @param mixed $value
     * @return string|null|false null si vacío, string AAAA-MM-DD si válido, false si inválido
     */
    public static function optional($value)
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        $y = (int) substr($s, 0, 4);
        $m = (int) substr($s, 5, 2);
        $d = (int) substr($s, 8, 2);
        if ($y < 1900 || $y > 2100 || !checkdate($m, $d, $y)) {
            return false;
        }

        return $s;
    }
}
