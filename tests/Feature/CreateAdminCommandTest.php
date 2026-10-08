<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_verified_admin(): void
    {
        $this->artisan('deskflow:create-admin', ['--name' => 'Root', '--email' => 'Root@Example.com', '--password' => 'a-long-password-1'])->assertSuccessful();

        $u = User::where('email', 'root@example.com')->first();
        $this->assertSame('admin', $u->role->value);
        $this->assertNotNull($u->email_verified_at);
        $this->assertTrue(Hash::check('a-long-password-1', $u->password));
    }

    public function test_rejects_short_passwords_and_duplicate_emails(): void
    {
        $this->artisan('deskflow:create-admin', ['--name' => 'A', '--email' => 'a@example.com', '--password' => 'short'])->assertFailed();
        $this->assertSame(0, User::count());

        User::factory()->create(['email' => 'dup@example.com']);
        $this->artisan('deskflow:create-admin', ['--name' => 'A', '--email' => 'dup@example.com', '--password' => 'a-long-password-1'])->assertFailed();
    }
}
