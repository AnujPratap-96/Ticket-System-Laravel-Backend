<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a new workspace with everything needed to start: its first administrator and a first department.
 */
class TenantProvisioner
{
    public function __construct(private StaffInviteService $invites) {}

    /** "Acme Support Ltd." -> "acme-support-ltd" (free of collisions and reserved words). */
    public function suggestSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'workspace', 38, '');
        $slug = $base;
        for ($i = 2; $this->taken($slug); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public function taken(string $slug): bool
    {
        return in_array($slug, Tenant::RESERVED, true) || Tenant::where('slug', $slug)->exists();
    }

    /**
     * @param  ?string  $passwordHash  already hashed password, or null to email the admin an invitation instead
     */
    public function create(string $name, string $slug, string $adminName, string $adminEmail, ?string $passwordHash, ?User $invitedBy = null): Tenant
    {
        $tenant = DB::transaction(function () use ($name, $slug, $adminName, $adminEmail, $passwordHash) {
            $tenant = Tenant::create(['name' => $name, 'slug' => $slug, 'is_active' => true]);

            TenantContext::run($tenant, function () use ($adminName, $adminEmail, $passwordHash) {
                Department::create([
                    'name' => 'General Support', 'slug' => 'general', 'description' => 'Everything that does not fit elsewhere',
                    'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC',
                ]);

                $admin = new User([
                    'name' => $adminName, 'email' => strtolower($adminEmail), 'role' => UserRole::ADMIN,
                    'is_available_for_routing' => false, 'email_verified_at' => $passwordHash ? now() : null,
                ]);
                // a hash was given: store it as is (bypassing the "hashed" cast); otherwise an unusable password until the invite is accepted
                $admin->setRawAttributes(array_merge($admin->getAttributes(), ['password' => $passwordHash ?? Hash::make(Str::random(64))]));
                $admin->save();
            });

            return $tenant;
        });

        if (! $passwordHash) {
            TenantContext::run($tenant, fn () => $this->invites->send(User::where('email', strtolower($adminEmail))->firstOrFail(), $invitedBy));
        }

        return $tenant;
    }
}
