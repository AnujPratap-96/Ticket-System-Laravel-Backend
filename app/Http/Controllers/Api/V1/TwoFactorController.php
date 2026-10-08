<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TwoFactorController extends Controller
{
    public function __construct(private TotpService $totp) {}

    /**
     * Start enrolment: generates a secret that only becomes active after a valid code is confirmed.
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->hasTwoFactor(), 409, 'Two-factor authentication is already enabled.');

        $secret = $this->totp->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $this->totp->otpauthUrl($secret, $user->email, config('app.name')),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['code' => ['required', 'digits:6']]);

        abort_if($user->hasTwoFactor(), 409, 'Two-factor authentication is already enabled.');
        abort_if(! $user->two_factor_secret, 422, 'Start setup first.');
        abort_unless($this->totp->verify($user->two_factor_secret, $request->code), 422, 'That code is not valid. Check your authenticator app and try again.');

        $plain = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $plain),
        ])->save();

        // Recovery codes are shown exactly once.
        return response()->json(['message' => 'Two-factor authentication enabled.', 'recovery_codes' => $plain]);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string']]);

        abort_unless($user->hasTwoFactor(), 409, 'Two-factor authentication is not enabled.');
        abort_unless(Hash::check($request->password, $user->password), 422, 'Password is incorrect.');
        abort_unless($this->totp->verify($user->two_factor_secret, $request->code) || $this->consumeRecoveryCode($user, $request->code), 422, 'That code is not valid.');

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    public function consumeRecoveryCode($user, string $candidate): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        foreach ($codes as $i => $hash) {
            if (Hash::check(strtolower(trim($candidate)), $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }
}
