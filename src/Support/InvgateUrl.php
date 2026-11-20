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
        $prefix = self::ticketUrlPrefix($serverUrl);

        return $prefix === null ? null : $prefix . $incidentId;
    }

    public static function ticketUrlPrefix(?string $serverUrl): ?string
    {
        $base = self::normalizeBase($serverUrl);
        if ($base === null) {
            return null;
        }

        $parts = parse_url($base);
        if (empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return $base . '/requests/show/index/id/';
    }
}
