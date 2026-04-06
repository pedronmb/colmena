<?php

declare(strict_types=1);

namespace App\Support;

final class BirthdayNormalizer
{
    /**
     * @param mixed $value
     * @return string|null|false null si vacío, MM-DD si válido, false si inválido
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
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if ($y < 1900 || $y > 2100 || !checkdate($mo, $d, $y)) {
                return false;
            }

            return sprintf('%02d-%02d', $mo, $d);
        }
        if (preg_match('/^(\d{2})-(\d{2})$/', $s, $m)) {
            $mo = (int) $m[1];
            $d = (int) $m[2];
            if (!checkdate($mo, $d, 2000)) {
                return false;
            }

            return sprintf('%02d-%02d', $mo, $d);
        }

        return false;
    }

    /**
     * Convierte valor guardado (MM-DD o AAAA-MM-DD legado) a MM-DD para la API.
     *
     * @param mixed $stored
     */
    public static function canonicalMonthDay($stored): ?string
    {
        if ($stored === null) {
            return null;
        }
        $s = trim((string) $stored);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{2})-(\d{2})$/', $s, $m)) {
            $mo = (int) $m[1];
            $d = (int) $m[2];

            return checkdate($mo, $d, 2000) ? sprintf('%02d-%02d', $mo, $d) : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];
            if ($y < 1900 || $y > 2100 || !checkdate($mo, $d, $y)) {
                return null;
            }

            return sprintf('%02d-%02d', $mo, $d);
        }

        return null;
    }
}
