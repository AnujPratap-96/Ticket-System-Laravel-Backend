<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->json('working_days')->nullable();      // ISO weekdays, 1 = Monday ... 7 = Sunday. NULL = Monday to Friday
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('away_until')->nullable();   // on leave: skipped by automatic routing until then
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $t) => $t->dropColumn('working_days'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('away_until'));
    }
};
