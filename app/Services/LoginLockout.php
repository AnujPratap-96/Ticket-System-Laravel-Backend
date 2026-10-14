<?php

namespace App\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Brute-force protection per account (the request rate limiter only slows one IP down): after N wrong passwords
 * for an email the account is locked for a while, whatever IP the guesses come from. Works the same for emails that do
 * not exist, so it reveals nothing. Per workspace, because the same email can exist in two.
 */
class LoginLockout
{
    private function key(string $email, string $what): string
    {
        return "lockout:{$what}:".(TenantContext::id() ?? 0).':'.hash('sha256', strtolower($email));
    }

    /** Seconds still locked, or 0. */
    public function remaining(string $email): int
    {
        $until = Cache::get($this->key($email, 'until'));

        return $until ? max(0, $until - now()->timestamp) : 0;
    }

    public function fail(string $email): void
    {
        $window = now()->addMinutes((int) config('security.lockout_minutes'));
        Cache::add($this->key($email, 'count'), 0, $window);
        $count = Cache::increment($this->key($email, 'count'));

        if ($count >= (int) config('security.lockout_attempts')) {
            Cache::put($this->key($email, 'until'), $window->timestamp, $window);
            Cache::forget($this->key($email, 'count'));
        }
    }

    public function clear(string $email): void
    {
        Cache::forget($this->key($email, 'count'));
        Cache::forget($this->key($email, 'until'));
    }
}
