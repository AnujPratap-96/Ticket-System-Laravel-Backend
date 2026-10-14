<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One independent support company on the platform. Deliberately NOT tenant-scoped itself.
 */
class Tenant extends Model
{
    protected $fillable = ['name', 'slug', 'is_active', 'suspended_at', 'inbound_secret', 'inbound_department_id', 'brand_color', 'logo_public_id', 'sender_name', 'reply_to_email', 'require_two_factor'];

    protected $casts = ['is_active' => 'boolean', 'suspended_at' => 'datetime', 'inbound_secret' => 'encrypted', 'require_two_factor' => 'boolean'];

    protected $hidden = ['inbound_secret'];

    /** Reserved words can never be a workspace address. */
    public const RESERVED = ['www', 'api', 'app', 'admin', 'platform', 'static', 'assets', 'help', 'support', 'mail', 'status', 'billing', 'login', 'register'];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /** Where this tenant's people open the web app (emails link here). */
    public function url(string $path = ''): string
    {
        $base = rtrim(config('app.frontend_url'), '/');
        $path = '/'.ltrim($path, '/');

        if ($domain = config('tenancy.base_domain')) {
            $parts = parse_url($base);

            return ($parts['scheme'] ?? 'https').'://'.$this->slug.'.'.$domain.(isset($parts['port']) ? ':'.$parts['port'] : '').($path === '/' ? '' : $path);
        }

        // No wildcard domain: the workspace travels as ?tenant=slug and the web app remembers it.
        return $base.$path.(str_contains($path, '?') ? '&' : '?').'tenant='.$this->slug;
    }
}
