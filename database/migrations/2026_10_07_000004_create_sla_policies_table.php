<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('tier', 30); // standard, silver, gold, platinum
            $table->string('priority', 30); // low, medium, high, urgent
            $table->unsignedInteger('first_response_time_minutes');
            $table->unsignedInteger('resolution_time_minutes');
            $table->boolean('applies_business_hours_only')->default(true);
            $table->timestamps();

            $table->unique(['tier', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_policies');
    }
};
