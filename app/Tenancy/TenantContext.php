<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * "Which workspace is this code running for?" Set once per web request (ResolveTenant middleware) or around a
 * background task, and read by TenantScope so every query is limited to that workspace automatically.
 */
class TenantContext
{
    private static ?Tenant $tenant = null;

    public static function set(?Tenant $tenant): void
    {
        self::$tenant = $tenant;
    }

    public static function get(): ?Tenant
    {
        return self::$tenant;
    }

    public static function id(): ?int
    {
        return self::$tenant?->id;
    }

    /** Run something as a workspace, then put things back. */
    public static function run(Tenant $tenant, callable $fn): mixed
    {
        $previous = self::$tenant;
        self::$tenant = $tenant;

        try {
            return $fn();
        } finally {
            self::$tenant = $previous;
        }
    }

    /** Run once for each active workspace (scheduled jobs). */
    public static function eachTenant(callable $fn): void
    {
        Tenant::where('is_active', true)->orderBy('id')->get()->each(fn (Tenant $t) => self::run($t, fn () => $fn($t)));
    }

    /** Run without any workspace filter (platform tasks, migrations, maintenance). Use sparingly. */
    public static function withoutTenant(callable $fn): mixed
    {
        $previous = self::$tenant;
        self::$tenant = null;

        try {
            return $fn();
        } finally {
            self::$tenant = $previous;
        }
    }
}
