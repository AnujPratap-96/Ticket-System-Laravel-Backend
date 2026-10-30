<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Assign officialthakur94@gmail.com as platform super_admin (belongs to no tenant)
        $platformEmail = 'officialthakur94@gmail.com';
        $platformUser = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($platformEmail)])->first();

        if ($platformUser) {
            DB::table('users')->where('id', $platformUser->id)->update([
                'tenant_id' => null,
                'role' => 'super_admin',
                'is_active' => true,
                'email_verified_at' => $platformUser->email_verified_at ?? now(),
                'updated_at' => now(),
            ]);
        } else {
            $defaultPassword = Hash::make(env('DEMO_PASSWORD', 'Password123!'));
            DB::table('users')->insert([
                'tenant_id' => null,
                'name' => 'Official Thakur (Platform Admin)',
                'email' => strtolower($platformEmail),
                'password' => $defaultPassword,
                'role' => 'super_admin',
                'is_active' => true,
                'is_available_for_routing' => false,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Locate or create techcorp tenant
        $techcorp = DB::table('tenants')->where('slug', 'techcorp')->first()
            ?? DB::table('tenants')->where('slug', 'default')->first();

        if (! $techcorp) {
            $techcorpId = DB::table('tenants')->insertGetId([
                'name' => 'TechCorp Support',
                'slug' => 'techcorp',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $techcorpId = $techcorp->id;
            if ($techcorp->slug !== 'techcorp') {
                DB::table('tenants')->where('id', $techcorpId)->update(['slug' => 'techcorp', 'name' => 'TechCorp Support']);
            }
        }

        // 3. Assign thakurajsingh9@gmail.com as techcorp admin
        $techcorpEmail = 'thakurajsingh9@gmail.com';
        $techcorpUser = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($techcorpEmail)])->first();

        if ($techcorpUser) {
            DB::table('users')->where('id', $techcorpUser->id)->update([
                'tenant_id' => $techcorpId,
                'role' => 'admin',
                'is_active' => true,
                'email_verified_at' => $techcorpUser->email_verified_at ?? now(),
                'updated_at' => now(),
            ]);
        } else {
            $defaultPassword = Hash::make(env('DEMO_PASSWORD', 'Password123!'));
            DB::table('users')->insert([
                'tenant_id' => $techcorpId,
                'name' => 'Thakur Aj Singh (TechCorp Admin)',
                'email' => strtolower($techcorpEmail),
                'password' => $defaultPassword,
                'role' => 'admin',
                'is_active' => true,
                'is_available_for_routing' => false,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Reversible if needed
    }
};
