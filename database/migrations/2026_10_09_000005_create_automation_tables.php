<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('trigger', 30);                 // ticket_created | customer_replied | idle
            $table->json('conditions');                    // [{field, op, value}]  all must match
            $table->json('actions');                       // [{type, value}]
            $table->unsignedInteger('idle_hours')->nullable();   // for the "idle" trigger
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('runs_count')->default(0);
            $table->timestamps();
        });

        // One row per rule that fired on a ticket: shown to admins and used so "idle" rules fire once per ticket.
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->json('applied');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['rule_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
