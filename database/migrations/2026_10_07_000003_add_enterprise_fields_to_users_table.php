<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
            $table->string('role', 30)->default('customer')->after('password');
            $table->foreignId('department_id')->nullable()->after('role')->constrained('departments')->nullOnDelete();
            $table->boolean('is_available_for_routing')->default(true)->after('department_id');
            $table->unsignedInteger('max_active_tickets')->default(10)->after('is_available_for_routing');

            $table->index(['role', 'is_available_for_routing']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['department_id']);
            $table->dropIndex(['role', 'is_available_for_routing']);
            $table->dropColumn(['organization_id', 'role', 'department_id', 'is_available_for_routing', 'max_active_tickets']);
        });
    }
};
