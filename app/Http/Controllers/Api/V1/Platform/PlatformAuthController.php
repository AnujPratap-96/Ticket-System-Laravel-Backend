<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\DeviceLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Sign-in for the platform owner(s). They belong to no workspace. */
class PlatformAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $lockout = app(\App\Services\LoginLockout::class);
        if ($seconds = $lockout->remaining($data['email'])) {
            return response()->json(['message' => 'Too many wrong passwords. Try again in '.ceil($seconds / 60).' minute(s).', 'code' => 'account_locked', 'retry_after' => $seconds], 429);
        }

        $user = User::withoutGlobalScopes()->whereNull('tenant_id')->where('role', UserRole::SUPER_ADMIN->value)->where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $lockout->fail($data['email']);

            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }
        $lockout->clear($data['email']);
        abort_unless($user->is_active, 403, 'This account has been deactivated.');

        $token = $user->createToken(DeviceLabel::for($request->userAgent(), $request->ip()), ['role:super_admin'])->plainTextToken;
        $user->tokens()->orderByDesc('id')->skip(5)->take(1000)->get()->each->delete();

        return response()->json(['message' => 'Login successful', 'token' => $token, 'user' => new UserResource($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out']);
    }
}
