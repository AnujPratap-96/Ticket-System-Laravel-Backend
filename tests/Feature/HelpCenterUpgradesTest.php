<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpCenterUpgradesTest extends TestCase
{
    use RefreshDatabase;

    private function article(array $a = []): Article
    {
        return tap(new Article, fn ($m) => $m->forceFill(array_merge(['title' => 'Reset your password', 'slug' => 'reset-pw-'.uniqid(), 'summary' => 's', 'body' => 'steps', 'is_published' => true, 'category' => 'Account'], $a))->save());
    }

    public function test_categories_list_and_filter_published_articles_only(): void
    {
        $this->article();
        $this->article(['title' => 'Two factor', 'category' => 'Account']);
        $this->article(['title' => 'Invoices', 'category' => 'Billing']);
        $this->article(['title' => 'Secret', 'category' => 'Draft only', 'is_published' => false]);

        $cats = $this->getJson('/api/v1/kb/categories')->assertOk()->json('categories');
        $this->assertSame([['name' => 'Account', 'articles' => 2], ['name' => 'Billing', 'articles' => 1]], $cats);

        $this->assertCount(1, $this->getJson('/api/v1/kb/articles?category=Billing')->json('articles'));
    }

    public function test_one_vote_per_reader_which_can_be_changed_and_is_counted(): void
    {
        $a = $this->article();

        $this->postJson("/api/v1/kb/articles/{$a->slug}/vote", ['helpful' => true])->assertOk();
        $this->postJson("/api/v1/kb/articles/{$a->slug}/vote", ['helpful' => true])->assertOk();           // repeat: still one
        $this->assertSame([1, 0], [$a->fresh()->helpful_count, $a->fresh()->unhelpful_count]);

        $this->postJson("/api/v1/kb/articles/{$a->slug}/vote", ['helpful' => false])->assertOk();          // changed their mind
        $this->assertSame([0, 1], [$a->fresh()->helpful_count, $a->fresh()->unhelpful_count]);

        $this->withServerVariables(['REMOTE_ADDR' => '9.9.9.9'])->postJson("/api/v1/kb/articles/{$a->slug}/vote", ['helpful' => true])->assertOk();   // another reader
        $this->assertSame([1, 1], [$a->fresh()->helpful_count, $a->fresh()->unhelpful_count]);

        $this->postJson("/api/v1/kb/articles/{$a->slug}/vote", ['helpful' => 'maybe'])->assertStatus(422);
        $this->postJson('/api/v1/kb/articles/nope/vote', ['helpful' => true])->assertStatus(404);
        $draft = $this->article(['is_published' => false]);
        $this->postJson("/api/v1/kb/articles/{$draft->slug}/vote", ['helpful' => true])->assertStatus(404);
    }

    public function test_searches_without_answers_are_reported_to_staff_only(): void
    {
        $this->article(['title' => 'Reset your password']);
        $this->getJson('/api/v1/kb/articles?q=password')->assertOk();
        $this->getJson('/api/v1/kb/articles?q=teleportation')->assertOk();
        $this->getJson('/api/v1/kb/articles?q=teleportation')->assertOk();
        $this->getJson('/api/v1/kb/articles?q=ab')->assertOk();                                            // too short to record

        $lead = User::factory()->create(['role' => 'lead']);
        $res = $this->actingAs($lead, 'sanctum')->getJson('/api/v1/kb-manage/analytics')->assertOk();
        $this->assertSame([['query' => 'teleportation', 'times' => 2]], $res->json('missing_answers'));

        $this->actingAs(User::factory()->create(['role' => 'customer']), 'sanctum')->getJson('/api/v1/kb-manage/analytics')->assertStatus(403);
    }

    public function test_analytics_ranks_views_and_least_helpful(): void
    {
        $good = $this->article(['title' => 'Good', 'views' => 50, 'helpful_count' => 9, 'unhelpful_count' => 1]);
        $bad = $this->article(['title' => 'Bad', 'views' => 5, 'helpful_count' => 1, 'unhelpful_count' => 8]);

        $res = $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum')->getJson('/api/v1/kb-manage/analytics')->json();

        $this->assertSame('Good', $res['top_viewed'][0]['title']);
        $this->assertSame('Bad', $res['least_helpful'][0]['title']);
        $this->assertSame(2, $res['totals']['published']);
    }

    public function test_staff_can_set_a_category(): void
    {
        $res = $this->actingAs(User::factory()->create(['role' => 'lead']), 'sanctum')->postJson('/api/v1/kb-manage/articles', ['title' => 'New', 'body' => 'b', 'is_published' => true, 'category' => 'Getting started'])->assertStatus(201);
        $this->assertSame('Getting started', $res->json('article.category'));
    }
}
