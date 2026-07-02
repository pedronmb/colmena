<?php

declare(strict_types=1);

namespace App\Support;

final class InvgateUrl
{
    public static function normalizeBase(?string $serverUrl): ?string
    {
        if ($serverUrl === null) {
            return null;
        }
        $base = rtrim(trim($serverUrl), '/');
        if ($base === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }

        return $base;
    }

    public static function ticketUrl(?string $serverUrl, int $incidentId): ?string
    {
        if ($incidentId <= 0) {
            return null;
        }
        $base = self::normalizeBase($serverUrl);
        if ($base === null) {
            return null;
        }

        return $base . '/requests/show/index/id/' . $incidentId;
    }
}
