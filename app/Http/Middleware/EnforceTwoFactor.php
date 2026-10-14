<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a workspace requires two-factor for its staff, a staff member without it can do nothing except set it up
 * (and read their own profile or sign out) until they have.
 */
class EnforceTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $request->attributes->get('tenant');

        if ($user && $tenant?->require_two_factor && $user->role->isStaff() && ! $user->hasTwoFactor() && ! $request->is('api/v1/auth/*', 'api/v1/2fa/*')) {
            return response()->json([
                'message' => 'Your workspace requires two-factor authentication for staff. Set it up under My account to continue.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        return $next($request);
    }
}
