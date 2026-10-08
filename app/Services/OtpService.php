<?php

namespace App\Services;

use App\Notifications\OtpNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * One-time email codes for a named purpose ('registration', 'password_reset').
 * Codes are stored hashed, expire, are single-use, and lock after too many wrong guesses.
 */
class OtpService
{
    public const TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Start (or restart) a code for $email. $payload is kept server-side until verified.
     */
    public function start(string $purpose, string $email, array $payload = []): void
    {
        $this->assertCanSend(Cache::get($this->key($purpose, $email)));

        $this->issue($purpose, $email, $payload);
    }

    public function resend(string $purpose, string $email): void
    {
        $pending = Cache::get($this->key($purpose, $email));
        abort_if(! $pending, 422, 'No pending request for this email. Please start again.');
        $this->assertCanSend($pending);

        $this->issue($purpose, $email, $pending['payload']);
    }

    /**
     * Returns the stored payload on success.
     */
    public function verify(string $purpose, string $email, string $code): array
    {
        $key = $this->key($purpose, $email);
        $pending = Cache::get($key);
        abort_if(! $pending, 422, 'Code expired or not requested. Please start again.');

        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            abort(429, 'Too many wrong attempts. Please start again.');
        }

        if (! Hash::check($code, $pending['otp_hash'])) {
            $pending['attempts']++;
            Cache::put($key, $pending, now()->addMinutes(self::TTL_MINUTES));
            abort(422, 'Invalid verification code.');
        }

        Cache::forget($key);

        return $pending['payload'];
    }

    private function issue(string $purpose, string $email, array $payload): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($purpose, $email), [
            'payload' => $payload,
            'otp_hash' => Hash::make($code),
            'attempts' => 0,
            'sent_at' => now()->timestamp,
        ], now()->addMinutes(self::TTL_MINUTES));

        $notification = new OtpNotification($purpose, $code, self::TTL_MINUTES);
        if (env('QUEUE_OTP', false)) {
            Notification::route('mail', $email)->notify($notification);
        } else {
            Notification::route('mail', $email)->notifyNow($notification);
        }
    }

    private function assertCanSend(?array $pending): void
    {
        if ($pending && now()->timestamp - $pending['sent_at'] < self::RESEND_COOLDOWN_SECONDS) {
            abort(429, 'Please wait a minute before requesting another code.');
        }
    }

    private function key(string $purpose, string $email): string
    {
        return "otp:{$purpose}:".hash('sha256', strtolower($email));
    }
}
