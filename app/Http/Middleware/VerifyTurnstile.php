<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/** Cloudflare Turnstile (CAPTCHA) on public forms. With no TURNSTILE_SECRET set the check is skipped. */
class VerifyTurnstile
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('security.turnstile_secret');
        if (! $secret) {
            return $next($request);
        }

        $token = (string) $request->input('captcha_token', '');
        $ok = false;
        if ($token !== '') {
            try {
                $ok = (bool) Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret, 'response' => $token, 'remoteip' => $request->ip(),
                ])->json('success');
            } catch (\Throwable) {
                $ok = false; // fail closed: cannot confirm a human
            }
        }

        return $ok ? $next($request) : response()->json(['message' => 'Please complete the human check and try again.', 'code' => 'captcha_failed'], 422);
    }
}
