<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/** The platform owner manages workspaces and belongs to none of them. */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'deskflow:create-platform-admin {--name=} {--email=} {--password=}';

    protected $description = 'Create a platform owner account (manages workspaces; cannot see any workspace\'s tickets)';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = strtolower((string) ($this->option('email') ?: $this->ask('Email')));
        $password = $this->option('password') ?: $this->secret('Password (min 12 characters)');

        $v = Validator::make(compact('name', 'email', 'password'), ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'min:12', 'max:128']]);
        if ($v->fails()) {
            collect($v->errors()->all())->each(fn ($e) => $this->error($e));

            return self::FAILURE;
        }
        if (User::withoutGlobalScopes()->whereNull('tenant_id')->where('email', $email)->exists()) {
            $this->error('A platform account with that email already exists.');

            return self::FAILURE;
        }

        User::forceCreate(['tenant_id' => null, 'name' => $name, 'email' => $email, 'role' => UserRole::SUPER_ADMIN, 'password' => Hash::make($password), 'email_verified_at' => now(), 'is_active' => true, 'is_available_for_routing' => false]);
        $this->info("Platform owner {$email} created. Sign in at /platform/login.");

        return self::SUCCESS;
    }
}
