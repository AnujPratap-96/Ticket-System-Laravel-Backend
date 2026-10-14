<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL = use the plan's default (config/plans.php). 0 = unlimited for this organization.
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('monthly_ticket_limit')->nullable();
            $table->unsignedInteger('max_customers')->nullable();
            $table->unsignedInteger('max_open_per_customer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn(['monthly_ticket_limit', 'max_customers', 'max_open_per_customer']));
    }
};
