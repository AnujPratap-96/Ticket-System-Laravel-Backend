<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('category', 60)->nullable()->index();
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('unhelpful_count')->default(0);
        });

        // One vote per reader per article (a reader may change their mind); readers are never identified.
        Schema::create('article_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->string('voter_key', 64);
            $table->boolean('helpful');
            $table->timestamps();
            $table->unique(['article_id', 'voter_key']);
        });

        // What people searched for and whether we had anything: the gaps show what to write next.
        Schema::create('kb_searches', function (Blueprint $table) {
            $table->id();
            $table->string('query', 100);
            $table->unsignedSmallInteger('results');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['results', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_searches');
        Schema::dropIfExists('article_votes');
        Schema::table('articles', fn (Blueprint $t) => $t->dropColumn(['category', 'helpful_count', 'unhelpful_count']));
    }
};
