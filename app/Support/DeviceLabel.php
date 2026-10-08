<?php

namespace App\Support;

/**
 * "Chrome on Windows · 203.0.113.9": a human-readable name for a sign-in, stored as the token's name.
 */
class DeviceLabel
{
    public static function for(?string $userAgent, ?string $ip): string
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'Chromium/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            $ua === '' => 'Unknown browser',
            default => 'Browser',
        };

        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iOS') => 'iOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') || str_contains($ua, 'X11') => 'Linux',
            default => null,
        };

        return mb_substr($browser.($os ? " on {$os}" : '').($ip ? " · {$ip}" : ''), 0, 250);
    }
}
