<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Per-workspace settings (for example the AI on/off switch). */
class AppSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['key', 'value'];

    private static function cacheKey(string $key): string
    {
        return 'setting:'.(TenantContext::id() ?? 0).":{$key}";
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return Cache::remember(self::cacheKey($key), 60, fn () => static::where('key', $key)->value('value') ?? $default);
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::cacheKey($key));
    }
}
