<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Bootstraps the very first administrator. After that, admins create every other
 * staff account from the app itself (Manage > Team > Add member).
 */
class CreateAdmin extends Command
{
    protected $signature = 'deskflow:create-admin
        {--tenant= : Workspace address (slug) the administrator belongs to}
        {--name= : Full name}
        {--email= : Login email}
        {--password= : Password (omit to be prompted without echo)}';

    protected $description = 'Create an administrator account (use once to bootstrap; manage staff from the app afterwards)';

    public function handle(): int
    {
        $slug = $this->option('tenant') ?: $this->ask('Workspace address (slug)');
        $tenant = \App\Models\Tenant::where('slug', $slug)->first();
        if (! $tenant) {
            $this->error("No workspace called [{$slug}]. Create it first (platform console, or the sign-up page).");

            return self::FAILURE;
        }

        return \App\Tenancy\TenantContext::run($tenant, fn () => $this->create());
    }

    private function create(): int
    {
        $name = $this->option('name') ?: $this->ask('Full name');
        $email = strtolower((string) ($this->option('email') ?: $this->ask('Email')));
        $password = $this->option('password') ?: $this->secret('Password (min 12 characters)');

        $v = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', \App\Tenancy\TenantRule::unique('users', 'email')],
                'password' => ['required', 'string', 'min:12', 'max:128'],
            ]
        );

        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => UserRole::ADMIN,
            'email_verified_at' => now(),
            'is_available_for_routing' => false,
        ]);

        $this->info("Admin {$email} created. Sign in, then enable two-factor authentication under My account.");

        return self::SUCCESS;
    }
}
