<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->json('ai_triage')->nullable();                   // {priority,tags,department_id,reason,status: suggested|accepted|dismissed}
            $table->string('sentiment', 10)->nullable()->index();    // negative | neutral | positive
            $table->boolean('is_ai_urgent')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn(['ai_triage', 'sentiment', 'is_ai_urgent']));
    }
};
