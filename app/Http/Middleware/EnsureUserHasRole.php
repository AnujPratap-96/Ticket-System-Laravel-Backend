<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->is_active === false) {
            return response()->json(['message' => 'This account has been deactivated.', 'code' => 'account_inactive'], 403);
        }

        $userRole = is_string($user->role) ? $user->role : $user->role->value;

        // Platform routes (role:super_admin) are for the platform owner ONLY. A workspace's admin is the top of their own
        // workspace, never of the platform.
        if (in_array('super_admin', $roles, true)) {
            return $userRole === 'super_admin' ? $next($request) : response()->json(['message' => 'Forbidden. This area is for the platform owner.', 'current_role' => $userRole], 403);
        }

        // A workspace admin may use every workspace route; the platform owner may not use any of them.
        if ($userRole === 'admin' || in_array($userRole, $roles, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Forbidden. You do not possess the required permissions.',
            'required_roles' => $roles,
            'current_role' => $userRole,
        ], 403);
    }
}
