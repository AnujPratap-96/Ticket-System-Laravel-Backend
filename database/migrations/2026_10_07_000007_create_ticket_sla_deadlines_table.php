<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_sla_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('metric_type', 30); // first_response, resolution
            $table->timestamp('target_deadline');
            $table->timestamp('breached_at')->nullable();
            $table->boolean('is_breached')->default(false);
            $table->boolean('is_fulfilled')->default(false);
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['is_fulfilled', 'is_breached', 'target_deadline'], 'idx_sla_deadline_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sla_deadlines');
    }
};
