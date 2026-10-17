<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Notifications\PasswordChangedNotification;
use App\Exceptions\QuotaExceededException;
use App\Services\LoginLockout;
use App\Services\OtpService;
use App\Services\PlanLimits;
use App\Support\DeviceLabel;
use App\Services\StaffInviteService;
use App\Http\Resources\UserResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Services\TotpService;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp, private PlanLimits $limits, private LoginLockout $lockout) {}

    /** A company on a seat-limited plan may be full: refuse before a code is sent or an account is made. */
    private function assertSeatFor(string $email): void
    {
        $org = Organization::where('domain', substr(strrchr($email, '@'), 1))->where('is_active', true)->first();

        if ($org && ! $this->limits->hasSeatFor($org)) {
            throw new QuotaExceededException("{$org->name} has reached the number of user accounts its plan allows. Ask your company's administrator to add you or to raise the limit.", 'customers');
        }
    }

    /**
     * Step 1 of self-registration: validate and email a one-time code.
     * No account is created until the code is verified. Always customers.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $this->assertSeatFor(strtolower($request->email));

        $this->otp->start('registration', strtolower($request->email), [
            'name' => $request->name,
            'password_hash' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => 'Verification code sent. Enter it to finish creating your account.',
            'email' => strtolower($request->email),
            'expires_in_minutes' => OtpService::TTL_MINUTES,
        ], 202);
    }

    /**
     * Step 2: verify the code and create the account. The organization is derived from
     * the email domain (never from client input).
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $email = strtolower($request->email);
        $pending = $this->otp->verify('registration', $email, $request->code);

        $user = DB::transaction(function () use ($email, $pending) {
            if (User::where('email', $email)->lockForUpdate()->exists()) {
                abort(422, 'This email is already registered.');
            }

            $domain = substr(strrchr($email, '@'), 1);
            $organization = Organization::where('domain', $domain)->where('is_active', true)->lockForUpdate()->first();

            if ($organization && ! $this->limits->hasSeatFor($organization)) {
                throw new QuotaExceededException("{$organization->name} has reached the number of user accounts its plan allows. Ask your company's administrator to add you or to raise the limit.", 'customers');
            }

            $user = new User([
                'name' => $pending['name'],
                'email' => $email,
                'role' => UserRole::CUSTOMER,
                'organization_id' => $organization?->id,
                'email_verified_at' => now(),
            ]);
            // Already hashed; bypass the "hashed" cast re-hash by setting the raw attribute.
            $user->setRawAttributes(array_merge($user->getAttributes(), ['password' => $pending['password_hash']]));
            $user->save();

            return $user;
        });

        return response()->json([
            'message' => 'Email verified. Account created.',
            'token' => $this->issueToken($user),
            'user' => new UserResource($user->loadMissing('tenant')),
        ], 201);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'purpose' => ['nullable', 'in:registration,password_reset']]);
        $this->otp->resend($request->input('purpose') === 'password_reset' ? 'password_reset' : 'registration', strtolower($request->email));

        return response()->json(['message' => 'A new verification code was sent.']);
    }

    /**
     * Always answers 202 so the endpoint cannot be used to discover which emails have accounts.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = strtolower($request->email);

        if (User::where('email', $email)->exists()) {
            $this->otp->start('password_reset', $email);
        }

        return response()->json([
            'message' => 'If that email has an account, a reset code has been sent.',
            'expires_in_minutes' => OtpService::TTL_MINUTES,
        ], 202);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $email = strtolower($request->email);
        $this->otp->verify('password_reset', $email, $request->code);

        $user = User::where('email', $email)->first();
        abort_if(! $user, 422, 'Invalid verification code.');

        $user->forceFill(['password' => $request->password, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        // A reset must end every existing session, and proves the email is theirs: lift any lock.
        $user->tokens()->delete();
        $this->lockout->clear($email);

        return response()->json(['message' => 'Password updated. Please sign in with your new password.']);
    }

    /**
     * Lets the accept-invite page greet the person and reject a bad/expired link before they type a password.
     */
    public function inviteInfo(Request $request, StaffInviteService $invites): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string', 'max:100']]);
        $invite = $invites->find($data['email'], $data['token']);
        abort_if(! $invite, 422, 'This invitation link is invalid or has expired. Ask your administrator to send a new one.');

        return response()->json(['name' => $invite->user->name, 'email' => $invite->user->email, 'role' => $invite->user->role->value]);
    }

    public function acceptInvite(Request $request, StaffInviteService $invites): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::default(), 'max:128', 'confirmed'],
        ]);

        $invite = $invites->find($data['email'], $data['token']);
        abort_if(! $invite, 422, 'This invitation link is invalid or has expired. Ask your administrator to send a new one.');

        $user = $invite->user;
        $user->forceFill(['password' => $data['password'], 'email_verified_at' => now()])->save();
        $invite->update(['accepted_at' => now()]);   // single use

        return response()->json([
            'message' => 'Welcome aboard! Your account is ready.',
            'token' => $this->issueToken($user),
            'user' => new UserResource($user->load('department', 'tenant')),
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if ($seconds = $this->lockout->remaining($request->email)) {
            return response()->json([
                'message' => 'Too many wrong passwords. For your security this account is locked for '.ceil($seconds / 60).' more minute(s). You can reset your password to get in sooner.',
                'code' => 'account_locked',
                'retry_after' => $seconds,
            ], 429);
        }

        $user = User::where('email', strtolower($request->email))->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            $this->lockout->fail($request->email);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $this->lockout->clear($request->email);

        if (! $user->is_active) {
            return response()->json(['message' => 'This account has been deactivated. Contact an administrator.', 'code' => 'account_inactive'], 403);
        }

        if (! $user->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your email before signing in.',
                'code' => 'email_unverified',
            ], 403);
        }

        if ($user->hasTwoFactor()) {
            $challenge = Str::random(40);
            Cache::put("2fa:{$challenge}", ['user_id' => $user->id, 'attempts' => 0], now()->addMinutes(5));

            return response()->json([
                'message' => 'Enter your authentication code.',
                'two_factor_required' => true,
                'challenge' => $challenge,
            ]);
        }

        return response()->json([
            'message' => 'Login successful',
            'token' => $this->issueToken($user),
            'user' => new UserResource($user->loadMissing('tenant')),
        ]);
    }

    public function twoFactorChallenge(Request $request, TotpService $totp, TwoFactorController $two): JsonResponse
    {
        $request->validate(['challenge' => ['required', 'string', 'size:40'], 'code' => ['required', 'string', 'max:32']]);

        $key = "2fa:{$request->challenge}";
        $state = Cache::get($key);
        abort_if(! $state, 422, 'This sign-in expired. Please start again.');

        if ($state['attempts'] >= 5) {
            Cache::forget($key);
            abort(429, 'Too many wrong codes. Please sign in again.');
        }

        $user = User::find($state['user_id']);
        $valid = $user && $user->is_active && $user->hasTwoFactor()
            && ($totp->verify($user->two_factor_secret, $request->code) || $two->consumeRecoveryCode($user, $request->code));

        if (! $valid) {
            $state['attempts']++;
            Cache::put($key, $state, now()->addMinutes(5));
            abort(422, 'Invalid authentication code.');
        }

        Cache::forget($key);

        return response()->json([
            'message' => 'Login successful',
            'token' => $this->issueToken($user),
            'user' => new UserResource($user->loadMissing('tenant')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('department', 'organization', 'tenant');

        return response()->json([
            'user' => new UserResource($user->loadMissing('tenant')),
            'permissions' => $this->permissionsFor($user->role),
            'two_factor_enabled' => $user->hasTwoFactor(),
            // The workspace requires it for staff and this person has not set it up yet
            'two_factor_setup_required' => (bool) ($user->tenant?->require_two_factor && $user->role->isStaff() && ! $user->hasTwoFactor()),
        ]);
    }

    private function issueToken(User $user): string
    {
        // The token's name records the device, so the user can recognise it in "Active sessions".
        $name = DeviceLabel::for(request()->userAgent(), request()->ip());

        $token = $user->createToken($name, ["role:{$user->role->value}"])->plainTextToken;

        // Keep only the newest N devices signed in: old test logins and forgotten browsers do not pile up.
        $keep = (int) config('plans.global.sessions_per_user', 10);
        $stale = $user->tokens()->orderByDesc('id')->skip($keep)->take(1000)->pluck('id');
        if ($stale->isNotEmpty()) {
            $user->tokens()->whereIn('id', $stale)->delete();
        }

        return $token;
    }

    // ---- Password and sessions ----------------------------------------------------------------

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::default(), 'max:128', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Everywhere else is signed out; this device stays signed in.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        $user->notify(new PasswordChangedNotification(DeviceLabel::for($request->userAgent(), $request->ip())));

        return response()->json(['message' => 'Password changed. Your other devices were signed out.']);
    }

    public function sessions(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        return response()->json(['sessions' => $request->user()->tokens()->orderByDesc('last_used_at')->orderByDesc('id')->get()->map(fn ($t) => [
            'id' => $t->id,
            'device' => $t->name,
            'current' => $t->id === $currentId,
            'created_at' => $t->created_at?->toISOString(),
            'last_used_at' => $t->last_used_at?->toISOString(),
        ])]);
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($id)->delete();      // scoped to the caller's own tokens
        abort_if(! $deleted, 404, 'Session not found.');

        return response()->json(['message' => 'Device signed out.']);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $count = $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();

        return response()->json(['message' => $count ? "Signed out {$count} other ".($count === 1 ? 'device' : 'devices').'.' : 'No other devices were signed in.', 'revoked' => $count]);
    }

    /**
     * Mirrors the RBAC matrix so the SPA can render role-aware UI.
     */
    private function permissionsFor(UserRole $role): array
    {
        $staff = $role->isStaff();
        $lead = in_array($role, [UserRole::LEAD, UserRole::ADMIN], true);

        return [
            'create_ticket' => true,
            'view_internal_notes' => $staff,
            'post_internal_note' => $staff,
            'change_status' => true,
            'reassign_agent' => $staff,
            'change_priority' => $staff,
            'view_audit_history' => $lead,
            'view_analytics' => $lead,
            'edit_sla_policies' => $role === UserRole::ADMIN,
            'manage_routing' => $lead,
            'manage_team' => $role === UserRole::ADMIN,
            'manage_settings' => $role === UserRole::ADMIN,
            'manage_users' => $role === UserRole::ADMIN,
        ];
    }
}
