<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Reply-by-email channel: each workspace has its OWN secret and default department.
            $table->text('inbound_secret')->nullable();
            $table->unsignedBigInteger('inbound_department_id')->nullable();

            // Branding: how the workspace looks to its own people and in its emails.
            $table->string('brand_color', 7)->nullable();          // "#4f46e5"
            $table->string('logo_public_id', 255)->nullable();     // Cloudinary
            $table->string('sender_name', 120)->nullable();        // "Acme Support" in the From line
            $table->string('reply_to_email', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['inbound_secret', 'inbound_department_id', 'brand_color', 'logo_public_id', 'sender_name', 'reply_to_email']));
    }
};
