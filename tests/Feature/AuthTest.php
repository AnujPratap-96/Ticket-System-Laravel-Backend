<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\OtpNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_as_customer_after_otp_verification(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            'role' => 'admin',
        ])->assertStatus(202)->assertJsonMissingPath('token');

        // No account until the code is verified.
        $this->assertDatabaseMissing('users', ['email' => 'john.doe@example.com']);

        $code = null;
        Notification::assertSentOnDemand(OtpNotification::class, function ($n) use (&$code) {
            $code = $n->code;
            return true;
        });

        $this->postJson('/api/v1/auth/verify-otp', ['email' => 'john.doe@example.com', 'code' => '000000'])->assertStatus(422);
        if ($code === '000000') {
            $this->markTestSkipped('Random code collided with the wrong-code probe.');
        }

        $this->postJson('/api/v1/auth/verify-otp', ['email' => 'john.doe@example.com', 'code' => $code])
            ->assertStatus(201)
            ->assertJsonStructure(['message', 'token', 'user' => ['id', 'name', 'email', 'role']])
            ->assertJsonPath('user.role', 'customer');

        $user = User::where('email', 'john.doe@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret1234', $user->password));

        // Code is single-use.
        $this->postJson('/api/v1/auth/verify-otp', ['email' => 'john.doe@example.com', 'code' => $code])->assertStatus(422);
    }

    public function test_otp_locks_after_too_many_wrong_attempts_and_resend_is_throttled(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'A', 'email' => 'a@example.com', 'password' => 'secret1234', 'password_confirmation' => 'secret1234',
        ])->assertStatus(202);

        $this->postJson('/api/v1/auth/resend-otp', ['email' => 'a@example.com'])->assertStatus(429);
        $this->postJson('/api/v1/auth/register', [
            'name' => 'A', 'email' => 'a@example.com', 'password' => 'secret1234', 'password_confirmation' => 'secret1234',
        ])->assertStatus(429);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/verify-otp', ['email' => 'a@example.com', 'code' => '123456']);
        }
        $this->postJson('/api/v1/auth/verify-otp', ['email' => 'a@example.com', 'code' => '123456'])->assertStatus(429);
    }

    public function test_unverified_user_cannot_login(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'secret1234']);

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret1234'])
            ->assertStatus(403)->assertJsonPath('code', 'email_unverified');
    }

    public function test_user_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_login_and_access_authenticated_me_endpoint(): void
    {
        $user = User::factory()->create([
            'email' => 'agent@test.com',
            'password' => bcrypt('password123'),
            'role' => 'agent',
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@test.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('token');

        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200)
            ->assertJsonPath('user.email', 'agent@test.com')
            ->assertJsonPath('user.role', 'agent');
    }

    public function test_user_logout_invalidates_bearer_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        auth()->forgetGuards();

        $protectedResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $protectedResponse->assertStatus(401);
    }
}
