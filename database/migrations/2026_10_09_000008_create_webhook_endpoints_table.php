<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('type', 10);                 // slack | teams | generic
            $table->text('url');                        // encrypted: Slack/Teams URLs are secrets themselves
            $table->text('secret')->nullable();         // encrypted; signs "generic" payloads
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->string('last_status', 120)->nullable();
            $table->timestamp('last_delivery_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
