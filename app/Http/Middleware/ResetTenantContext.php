<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** First in the stack: nothing from a previous request (or test) may leak into this one. */
class ResetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        TenantContext::set(null);

        return $next($request);
    }
}
