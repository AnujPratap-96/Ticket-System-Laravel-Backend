<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Channel authorisation uses the same bearer token as the API: POST /api/v1/broadcasting/auth
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/v1', 'middleware' => ['auth:sanctum', 'tenant']])
    ->withMiddleware(function (Middleware $middleware) {
        // Behind Render's TLS-terminating proxy: trust it so URLs and client IPs are correct.
        $middleware->trustProxies(at: '*');

        // Order matters: forget the previous request's workspace first, sign in, THEN choose the workspace,
        // and only then let route-model binding load anything.
        $middleware->api(prepend: [\App\Http\Middleware\ResetTenantContext::class]);
        $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: \App\Http\Middleware\ResolveTenant::class);

        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'two-factor' => \App\Http\Middleware\EnforceTwoFactor::class,
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'turnstile' => \App\Http\Middleware\VerifyTurnstile::class,
            'admin-audit' => \App\Http\Middleware\RecordAdminActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());
    })->create();
