<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which workspace this request is for, before any data is touched.
 *
 *  - Signed in:  the workspace is the person's own. Nothing in the request can change it.
 *  - Signed out: the workspace comes from the address (acme.yourdomain.com), the X-Tenant header, or ?tenant=.
 *                With only one workspace in existence it is optional.
 * From here on every query on a tenant-owned model is filtered to that workspace.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum');

        if ($user) {
            if ($user->role->isPlatform()) {
                return response()->json(['message' => 'Platform accounts manage workspaces; they cannot open one\'s data.', 'code' => 'platform_account'], 403);
            }
            $tenant = Tenant::find($user->tenant_id);
        } else {
            $slug = $this->requestedSlug($request);

            if ($slug) {
                $tenant = Tenant::where('slug', $slug)->first();
                if (! $tenant) {
                    return response()->json(['message' => 'We could not find that workspace. Check the address.', 'code' => 'workspace_not_found'], 404);
                }
            } elseif (config('tenancy.single_tenant_fallback') && Tenant::where('is_active', true)->count() === 1) {
                $tenant = Tenant::where('is_active', true)->first();
            } else {
                return response()->json(['message' => 'Which workspace? Open your company\'s own address, or enter its name.', 'code' => 'workspace_required'], 400);
            }
        }

        if (! $tenant || ! $tenant->is_active) {
            return response()->json(['message' => 'This workspace is suspended. Contact the platform owner.', 'code' => 'workspace_suspended'], 403);
        }

        TenantContext::set($tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    private function requestedSlug(Request $request): ?string
    {
        $slug = $request->header('X-Tenant') ?: $request->query('tenant');

        if (! $slug && ($base = config('tenancy.base_domain'))) {
            $host = $request->getHost();
            if (str_ends_with($host, '.'.$base)) {
                $slug = substr($host, 0, -strlen('.'.$base));
            }
        }

        return $slug ? strtolower(preg_replace('/[^a-z0-9-]/i', '', (string) $slug)) : null;
    }
}
