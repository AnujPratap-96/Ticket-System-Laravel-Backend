<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Ai\PiiRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Department $dept;
    private User $customer;
    private User $agent;
    private User $lead;
    private string $aiContent = '{}';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->lead = User::factory()->create(['role' => 'lead', 'department_id' => $this->dept->id]);
    }

    private function aiOn(string $content = '{}'): void
    {
        config(['services.ai.api_key' => 'test-key', 'services.ai.daily_limit' => 0]);
        $this->aiContent = $content;
        Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => $this->aiContent]]]]));
    }

    private function file(string $title, string $body = 'details')
    {
        return $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', ['department_id' => $this->dept->id, 'title' => $title, 'description' => $body]);
    }

    // ---- sentiment / urgency without AI ----------------------------------------------------------

    public function test_angry_urgent_tickets_are_flagged_even_without_ai(): void
    {
        config(['services.ai.api_key' => null]);
        $t = Ticket::find($this->file('Production is DOWN!!', 'This is unacceptable and terrible, our site is not working')->json('ticket.id'));
        $this->assertSame('negative', $t->sentiment);
        $this->assertTrue($t->is_ai_urgent);

        $calm = Ticket::find($this->file('Question about invoices', 'How do I download last month invoice?')->json('ticket.id'));
        $this->assertSame('neutral', $calm->sentiment);
        $this->assertFalse($calm->is_ai_urgent);
    }

    public function test_the_ai_flags_are_staff_only(): void
    {
        $id = $this->file('Hello')->assertStatus(201)->json('ticket.id');
        $this->assertArrayNotHasKey('ai', $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$id}")->json('ticket'));
        $this->actingAs($this->lead, 'sanctum')->getJson("/api/v1/tickets/{$id}")->assertJsonPath('ticket.ai.sentiment', 'neutral');
    }

    // ---- triage ------------------------------------------------------------------------------

    public function test_ai_suggests_triage_and_staff_can_accept_or_dismiss(): void
    {
        $this->aiOn(json_encode(['sentiment' => 'negative', 'urgent' => true, 'priority' => 'urgent', 'tags' => ['Outage', 'bad tag!!', 'Web Server'], 'department_id' => $this->dept->id, 'reason' => 'Site is down']));

        $t = Ticket::find($this->file('Site down')->json('ticket.id'));
        $this->assertSame('medium', $t->priority->value);                                    // nothing applied automatically
        $this->assertSame('suggested', $t->ai_triage['status']);
        $this->assertSame(['outage', 'web-server'], $t->ai_triage['tags']);                  // sanitised

        $this->actingAs($this->lead, 'sanctum')->getJson("/api/v1/tickets/{$t->id}")->assertJsonPath('ticket.ai.triage.priority', 'urgent');

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/ai/triage/accept")->assertStatus(403);
        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/ai/triage/accept")->assertOk();

        $t->refresh();
        $this->assertSame('urgent', $t->priority->value);
        $this->assertEqualsCanonicalizing(['outage', 'web-server'], $t->tags()->pluck('name')->all());
        $this->assertSame('accepted', $t->ai_triage['status']);
        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/ai/triage/accept")->assertStatus(422);   // nothing pending anymore

        $t2 = Ticket::find($this->file('Another')->json('ticket.id'));
        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$t2->id}/ai/triage/dismiss")->assertOk();
        $this->assertSame('dismissed', $t2->fresh()->ai_triage['status']);
        $this->assertSame('medium', $t2->fresh()->priority->value);
    }

    public function test_garbage_from_the_model_is_ignored_safely(): void
    {
        $this->aiOn('not json at all');
        $t = Ticket::find($this->file('Hi')->assertStatus(201)->json('ticket.id'));
        $this->assertNull($t->ai_triage);

        $this->aiOn(json_encode(['sentiment' => 'furious', 'priority' => 'apocalyptic', 'tags' => 'x', 'department_id' => 99999]));
        $t = Ticket::find($this->file('Hi again')->json('ticket.id'));
        $this->assertSame('neutral', $t->sentiment);
        $this->assertNull($t->ai_triage);
    }

    // ---- duplicates -------------------------------------------------------------------------

    public function test_similar_tickets_are_suggested_to_staff_within_the_same_company(): void
    {
        $a = Ticket::find($this->file('VPN connection keeps dropping every hour', 'The office vpn disconnects hourly')->json('ticket.id'));
        $b = Ticket::find($this->file('VPN connection drops hourly', 'vpn disconnects every hour in the office')->json('ticket.id'));
        $this->file('Invoice for March missing', 'please resend invoice');
        $foreign = User::factory()->create(['role' => 'customer', 'organization_id' => Organization::create(['name' => 'Rival', 'domain' => 'rival.test', 'sla_tier' => 'gold', 'is_active' => true])->id]);
        $this->actingAs($foreign, 'sanctum')->postJson('/api/v1/tickets', ['department_id' => $this->dept->id, 'title' => 'VPN connection drops hourly', 'description' => 'vpn disconnects every hour']);

        $res = $this->actingAs($this->lead, 'sanctum')->getJson("/api/v1/tickets/{$b->id}/similar")->assertOk();
        $this->assertSame([$a->ticket_number], array_column($res->json('similar'), 'ticket_number'));

        $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$b->id}/similar")->assertStatus(403);
    }

    public function test_customers_are_warned_about_their_own_open_ticket_while_typing(): void
    {
        $this->file('Cannot log in to the dashboard', 'x');
        $other = User::factory()->create(['role' => 'customer', 'organization_id' => $this->org->id]);
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/tickets', ['department_id' => $this->dept->id, 'title' => 'Cannot log in to the dashboard', 'description' => 'x']);

        $res = $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/tickets-similar?title='.urlencode('cannot login dashboard log in'))->assertOk();
        $this->assertCount(1, $res->json('tickets'));                                       // only their own, never a colleague's

        $this->assertCount(0, $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/tickets-similar?title='.urlencode('billing question'))->json('tickets'));
    }

    // ---- semantic search --------------------------------------------------------------------

    public function test_meaning_search_only_runs_when_words_do_not_match_and_only_returns_real_articles(): void
    {
        $pw = Article::create(['title' => 'Reset your password', 'slug' => 'reset', 'summary' => 'Recover access', 'body' => 'steps', 'is_published' => true]);
        $draft = Article::create(['title' => 'Hidden', 'slug' => 'hidden', 'body' => 'x', 'is_published' => false]);

        $this->aiOn(json_encode(['ids' => [$pw->id, $draft->id, 99999]]));
        $res = $this->getJson('/api/v1/kb/articles?q='.urlencode('locked out of my account'))->assertOk();
        $this->assertTrue($res->json('smart'));
        $this->assertSame(['reset'], array_column($res->json('articles'), 'slug'));          // draft and invented ids dropped

        Http::assertSentCount(1);
        $this->getJson('/api/v1/kb/articles?q='.urlencode('locked out of my account'))->assertOk();   // cached
        Http::assertSentCount(1);

        $this->getJson('/api/v1/kb/articles?q=password')->assertOk()->assertJsonPath('smart', false);   // words match: no AI call
        Http::assertSentCount(1);
    }

    public function test_search_works_without_ai(): void
    {
        config(['services.ai.api_key' => null]);
        Article::create(['title' => 'Reset your password', 'slug' => 'reset', 'body' => 'steps', 'is_published' => true]);
        $this->getJson('/api/v1/kb/articles?q='.urlencode('locked out'))->assertOk()->assertJsonCount(0, 'articles');
    }

    // ---- translation -------------------------------------------------------------------------

    public function test_translation_keeps_personal_data_out_of_the_prompt_and_puts_it_back(): void
    {
        $this->aiOn('Hola, escríbanos a ⟦0⟧ o llame al ⟦1⟧.');
        $t = Ticket::find($this->file('Hi')->json('ticket.id'));

        $res = $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/ai/translate", ['text' => 'Hello, write to help@acme.test or call +91 98765 43210.', 'language' => 'Spanish'])->assertOk();

        $this->assertSame('Hola, escríbanos a help@acme.test o llame al +91 98765 43210.', $res->json('translation'));
        Http::assertSent(fn ($r) => ! str_contains(json_encode($r->data()), 'help@acme.test') && ! str_contains(json_encode($r->data()), '98765'));
    }

    public function test_translation_validation_and_access(): void
    {
        $this->aiOn('x');
        $t = Ticket::find($this->file('Hi')->json('ticket.id'));

        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/ai/translate", ['text' => 'hi', 'language' => 'Klingon'])->assertStatus(422);
        $this->actingAs($this->lead, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/ai/translate", ['text' => '', 'language' => 'Hindi'])->assertStatus(422);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/ai/translate", ['text' => 'hi', 'language' => 'Hindi'])->assertStatus(403);
        $stranger = User::factory()->create(['role' => 'agent', 'department_id' => $this->dept->id]);
        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/ai/translate", ['text' => 'hi', 'language' => 'Hindi'])->assertStatus(403);   // not their ticket
    }

    public function test_placeholder_masking_round_trips(): void
    {
        [$m, $map] = PiiRedactor::mask('Mail a@b.com, see https://x.io/y and call 555 123 4567.');
        $this->assertStringNotContainsString('a@b.com', $m);
        $this->assertSame('Mail a@b.com, see https://x.io/y and call 555 123 4567.', PiiRedactor::unmask($m, $map));
    }
}
