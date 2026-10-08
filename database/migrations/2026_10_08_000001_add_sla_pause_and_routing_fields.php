<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_sla_deadlines', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('fulfilled_at');
            $table->timestamp('warning_sent_at')->nullable()->after('paused_at');
            $table->unique(['ticket_id', 'metric_type'], 'uq_sla_ticket_metric');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_assigned_at')->nullable()->after('max_active_tickets');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_assigned_at');
        });

        Schema::table('ticket_sla_deadlines', function (Blueprint $table) {
            $table->dropUnique('uq_sla_ticket_metric');
            $table->dropColumn(['paused_at', 'warning_sent_at']);
        });
    }
};
