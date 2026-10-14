<?php

namespace App\Http\Middleware;

use App\Models\AdminAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Logs who changed what in admin-only areas (never request bodies, so no secrets are stored). */
class RecordAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user && ! $request->isMethodSafe() && $response->getStatusCode() < 400 && $user->tenant_id) {
            $targets = collect($request->route()?->parameters() ?? [])
                ->map(fn ($v) => is_object($v) && method_exists($v, 'getKey') ? $v->getKey() : (is_scalar($v) ? $v : null))
                ->filter()->all();

            AdminAudit::create([
                'user_id' => $user->id,
                'method' => $request->method(),
                'route' => substr(preg_replace('#^api/v1/#', '', $request->route()?->uri() ?? $request->path()), 0, 190),
                'targets' => $targets ?: null,
                'status' => $response->getStatusCode(),
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }
}
