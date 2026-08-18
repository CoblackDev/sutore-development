<?php

declare(strict_types=1);

namespace SutoreMarketplace\Shared\Security;

/**
 * Allowlist for plugin-initiated HTTP calls (webhook, NVI). Rejects SSRF to
 * loopback, link-local, and private networks.
 */
final class OutboundUrl
{
    public static function isSafe(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        if (!self::isPlausibleHostname($host)) {
            return false;
        }

        $ips = gethostbynamel($host);
        if (!is_array($ips) || $ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp((string) $ip)) {
                return false;
            }
        }

        return true;
    }

    private static function isPlausibleHostname(string $host): bool
    {
        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $host);
    }

    private static function isPublicIp(string $ip): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
    }
}
