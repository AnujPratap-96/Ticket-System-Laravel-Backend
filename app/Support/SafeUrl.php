<?php

namespace App\Support;

/**
 * Keeps outbound webhooks from being turned against our own network (SSRF).
 * A URL is acceptable only if it is https (http allowed only when explicitly configured for local development),
 * has no credentials, and EVERY address its host resolves to is public. The resolved address is returned so the
 * caller can connect to exactly that IP: a name that later resolves somewhere else (DNS rebinding) is never followed.
 */
class SafeUrl
{
    /** @return array{host:string, port:int, ip:string}|string  the pinned target, or a human-readable reason it was refused */
    public static function resolve(string $url, ?callable $resolver = null)
    {
        $p = parse_url($url);
        if (! $p || empty($p['host']) || empty($p['scheme'])) {
            return 'That is not a valid URL.';
        }
        $scheme = strtolower($p['scheme']);
        if (! in_array($scheme, ['https', 'http'], true) || ($scheme === 'http' && ! config('services.webhooks.allow_http'))) {
            return 'Only https:// addresses are allowed.';
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return 'The address must not contain a username or password.';
        }

        $host = trim(strtolower($p['host']), '[]');
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port < 1 || $port > 65535) {
            return 'That port is not valid.';
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($resolver ?? [self::class, 'lookup'])($host);
        if (! $ips) {
            return 'That host could not be found.';
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return 'That address points to a private or internal network, which is not allowed.';
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ips[0], 'scheme' => $scheme];
    }

    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        // Rejects private (10/8, 172.16/12, 192.168/16, fc00::/7) and reserved (0/8, 127/8, 169.254/16, ::1, fe80::/10 …) ranges.
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // IPv4-mapped IPv6 (::ffff:127.0.0.1) hides an IPv4 address inside an IPv6 one.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return self::isPublic($m[1]);
        }
        // Carrier-grade NAT 100.64.0.0/10 and cloud metadata are not covered by PHP's flags.
        if (str_starts_with($ip, '100.') && (int) explode('.', $ip)[1] >= 64 && (int) explode('.', $ip)[1] <= 127) {
            return false;
        }

        return $ip !== '169.254.169.254';
    }

    /** @return array<int,string> */
    public static function lookup(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            $ips[] = $r['ip'] ?? $r['ipv6'] ?? null;
        }

        return array_values(array_filter($ips));
    }
}
