<?php

return [
    // Reject passwords that appear in known data breaches (haveibeenpwned, k-anonymity: only a hash prefix leaves the server).
    // On in production, off elsewhere so local work and tests need no internet.
    'check_breached_passwords' => (bool) env('SECURITY_CHECK_BREACHED', env('APP_ENV') === 'production'),

    'password_min_length' => 10,

    // Wrong passwords for one email before it is locked, and for how long.
    'lockout_attempts' => 5,
    'lockout_minutes' => 15,

    // Cloudflare Turnstile (a CAPTCHA): protects sign-up and code-sending forms from bots. Empty secret = off.
    'turnstile_secret' => env('TURNSTILE_SECRET'),
];
