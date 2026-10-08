<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new TotpService();
    }

    public function test_totp_matches_rfc6238_test_vector(): void
    {
        // RFC 6238 Appendix B (SHA-1), secret "12345678901234567890", T=59s -> 94287082 (8 digits) => last 6 = 287082
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', $this->totp->code($secret, intdiv(59, 30)));
        $this->assertTrue($this->totp->verify($secret, '287082', 0, 59));
        $this->assertFalse($this->totp->verify($secret, '000000', 1, 59));
        $this->assertFalse($this->totp->verify($secret, 'abcdef', 1, 59));
    }

    private function enable(User $user): array
    {
        $secret = $this->actingAs($user, 'sanctum')->postJson('/api/v1/2fa/setup')->assertOk()->json('secret');
        $code = $this->totp->code($secret, intdiv(time(), 30));
        $codes = $this->actingAs($user, 'sanctum')->postJson('/api/v1/2fa/confirm', ['code' => $code])->assertOk()->json('recovery_codes');

        return [$secret, $codes];
    }

    public function test_enrolment_requires_a_valid_code_and_returns_recovery_codes_once(): void
    {
        $user = User::factory()->create(['role' => 'agent', 'password' => 'secret1234']);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/2fa/setup')->assertOk()->assertJsonStructure(['secret', 'otpauth_url']);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/2fa/confirm', ['code' => '000000'])->assertStatus(422);
        $this->assertFalse($user->fresh()->hasTwoFactor());

        [, $codes] = $this->enable($user);
        $this->assertCount(8, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/2fa/setup')->assertStatus(409);
        $this->assertStringNotContainsString($codes[0], (string) json_encode($user->fresh()->getRawOriginal())); // stored hashed/encrypted
    }

    public function test_login_requires_second_factor_when_enabled(): void
    {
        $user = User::factory()->create(['role' => 'agent', 'password' => 'secret1234']);
        [$secret, $codes] = $this->enable($user);
        $this->app['auth']->forgetGuards();

        $step1 = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret1234'])->assertOk();
        $this->assertTrue($step1->json('two_factor_required'));
        $this->assertArrayNotHasKey('token', $step1->json());
        $challenge = $step1->json('challenge');

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => $this->totp->code($secret, intdiv(time(), 30))])
            ->assertOk()->assertJsonStructure(['token', 'user']);

        // Challenge is single-use
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => $this->totp->code($secret, intdiv(time(), 30))])->assertStatus(422);
    }

    public function test_recovery_code_works_once(): void
    {
        $user = User::factory()->create(['role' => 'agent', 'password' => 'secret1234']);
        [, $codes] = $this->enable($user);
        $this->app['auth']->forgetGuards();

        $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret1234'])->json('challenge');

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $login(), 'code' => $codes[0]])->assertOk();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $login(), 'code' => $codes[0]])->assertStatus(422); // used up
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $login(), 'code' => $codes[1]])->assertOk();
    }

    public function test_challenge_locks_after_five_wrong_codes(): void
    {
        $user = User::factory()->create(['role' => 'agent', 'password' => 'secret1234']);
        [$secret] = $this->enable($user);
        $this->app['auth']->forgetGuards();

        $challenge = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret1234'])->json('challenge');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => '111111']);
        }
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => $this->totp->code($secret, intdiv(time(), 30))])->assertStatus(429);
    }

    public function test_disable_needs_password_and_code(): void
    {
        $user = User::factory()->create(['role' => 'agent', 'password' => 'secret1234']);
        [$secret] = $this->enable($user);
        $code = $this->totp->code($secret, intdiv(time(), 30));

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/2fa/disable', ['password' => 'wrong', 'code' => $code])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/2fa/disable', ['password' => 'secret1234', 'code' => '000000'])->assertStatus(422);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/2fa/disable', ['password' => 'secret1234', 'code' => $code])->assertOk();
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }
}
