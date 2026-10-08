<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_search_only_returns_published_articles(): void
    {
        Article::create(['title' => 'Reset your password', 'slug' => 'reset-password', 'body' => 'Use the forgot password link.', 'is_published' => true]);
        Article::create(['title' => 'Draft about passwords', 'slug' => 'draft', 'body' => 'secret draft', 'is_published' => false]);

        $res = $this->getJson('/api/v1/kb/articles?q=password')->assertOk();
        $this->assertSame(['reset-password'], collect($res->json('articles'))->pluck('slug')->all());

        $this->getJson('/api/v1/kb/articles/draft')->assertStatus(404);
        $this->getJson('/api/v1/kb/articles/reset-password')->assertOk()->assertJsonPath('article.title', 'Reset your password');
        $this->assertSame(1, Article::where('slug', 'reset-password')->value('views'));
    }

    public function test_multiword_search_requires_every_word(): void
    {
        Article::create(['title' => 'Billing invoices', 'slug' => 'a', 'body' => 'x', 'is_published' => true]);
        Article::create(['title' => 'Billing refunds', 'slug' => 'b', 'body' => 'x', 'is_published' => true]);

        $this->assertCount(1, $this->getJson('/api/v1/kb/articles?q=billing+refunds')->json('articles'));
        $this->assertCount(2, $this->getJson('/api/v1/kb/articles?q=billing')->json('articles'));
    }

    public function test_only_lead_and_admin_manage_articles(): void
    {
        $payload = ['title' => 'How to rotate API keys', 'body' => 'Steps…', 'is_published' => true];

        $this->actingAs(User::factory()->create(['role' => 'customer']), 'sanctum')->postJson('/api/v1/kb-manage/articles', $payload)->assertStatus(403);
        $this->actingAs(User::factory()->create(['role' => 'agent']), 'sanctum')->postJson('/api/v1/kb-manage/articles', $payload)->assertStatus(403);

        $lead = User::factory()->create(['role' => 'lead']);
        $id = $this->actingAs($lead, 'sanctum')->postJson('/api/v1/kb-manage/articles', $payload)->assertStatus(201)->json('article.id');
        $second = $this->actingAs($lead, 'sanctum')->postJson('/api/v1/kb-manage/articles', $payload)->json('article.slug');
        $this->assertSame('how-to-rotate-api-keys-2', $second); // unique slug

        $this->actingAs($lead, 'sanctum')->patchJson("/api/v1/kb-manage/articles/{$id}", ['title' => 'T', 'body' => 'b', 'is_published' => false])->assertOk();
        $this->getJson('/api/v1/kb/articles/how-to-rotate-api-keys')->assertStatus(404); // unpublished
        $this->actingAs($lead, 'sanctum')->deleteJson("/api/v1/kb-manage/articles/{$id}")->assertOk();
    }
}
