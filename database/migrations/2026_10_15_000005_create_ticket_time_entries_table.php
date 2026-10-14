<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_time_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('minutes');
            $t->string('note', 255)->nullable();
            $t->boolean('billable')->default(true);
            $t->timestamps();
            $t->index(['ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_time_entries');
    }
};
