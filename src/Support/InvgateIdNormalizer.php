<?php

declare(strict_types=1);

namespace App\Support;

final class InvgateIdNormalizer
{
    /**
     * @param mixed $raw
     * @return int|null|false null si vacío, entero si válido, false si inválido
     */
    public static function optional($raw)
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw) && trim($raw) === '') {
            return null;
        }

        if (!is_int($raw) && !is_float($raw) && !is_string($raw)) {
            return false;
        }

        if (is_string($raw) && !preg_match('/^-?\d+$/', trim($raw))) {
            return false;
        }

        return (int) $raw;
    }
}
