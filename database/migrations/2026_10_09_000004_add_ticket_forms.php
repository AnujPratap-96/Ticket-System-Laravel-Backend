<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->json('form_fields')->nullable();        // [{key,label,type,required,options?}]
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->json('custom_fields')->nullable();      // {key: value}, validated against the department's form when filed
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $t) => $t->dropColumn('form_fields'));
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn('custom_fields'));
    }
};
