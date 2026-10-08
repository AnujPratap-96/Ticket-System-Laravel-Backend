<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketReplyNotification;
use App\Support\RichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RichTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_formatting_is_rendered_and_dangerous_content_is_not(): void
    {
        $html = RichText::toHtml("**bold** and *it*\n\n- one\n- two\n\n<script>alert(1)</script> <img src=x onerror=alert(1)>\n\n[ok](https://example.com) [bad](javascript:alert(1)) [data](data:text/html,x)\n\n![pixel](https://t.example/p.png)");

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text', $html);
        $this->assertStringContainsString('href="https://example.com" target="_blank" rel="noopener noreferrer nofollow"', $html);
    }

    public function test_preview_endpoint_needs_sign_in_and_validates(): void
    {
        $this->postJson('/api/v1/rich-text/preview', ['body' => 'x'])->assertStatus(401);
        $u = User::factory()->create(['role' => 'customer']);
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/rich-text/preview', ['body' => '**hi**'])->assertOk()->assertJsonPath('html', "<p><strong>hi</strong></p>\n");
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/rich-text/preview', ['body' => str_repeat('a', 20001)])->assertStatus(422);
    }

    public function test_job_title_is_saved_cleaned_and_clearable(): void
    {
        $u = User::factory()->create(['role' => 'agent', 'name' => 'Anuj Pratap Singh']);
        $r = $this->actingAs($u, 'sanctum')->patchJson('/api/v1/auth/profile', ['name' => 'Anuj Pratap Singh', 'job_title' => '  <b>Senior</b>   Support Engineer ']);
        $r->assertOk()->assertJsonPath('user.job_title', 'Senior Support Engineer');
        $this->actingAs($u, 'sanctum')->patchJson('/api/v1/auth/profile', ['name' => 'Anuj Pratap Singh', 'job_title' => ''])->assertOk()->assertJsonPath('user.job_title', null);
        $this->actingAs($u, 'sanctum')->patchJson('/api/v1/auth/profile', ['name' => 'Anuj', 'job_title' => str_repeat('x', 101)])->assertStatus(422);
    }

    public function test_messages_carry_safe_html_and_the_email_keeps_formatting_but_not_html(): void
    {
        $org = Organization::create(['name' => 'A', 'domain' => 'a.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $dept = Department::create(['name' => 'I', 'slug' => 'i', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $c = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
        $a = User::factory()->create(['role' => 'agent', 'department_id' => $dept->id]);
        $t = Ticket::create(['ticket_number' => 'TICK-R-9', 'organization_id' => $org->id, 'customer_id' => $c->id, 'department_id' => $dept->id, 'assigned_agent_id' => $a->id, 'title' => 'T', 'description' => 'D', 'status' => 'open', 'priority' => 'medium']);

        $body = "Hello,\n\n**Please** try:\n\n1. restart\n2. retry <script>x()</script>";
        $res = $this->actingAs($a, 'sanctum')->postJson("/api/v1/tickets/{$t->id}/messages", ['body' => $body, 'force_send' => true])->assertStatus(201);
        $this->assertStringContainsString('<strong>Please</strong>', $res->json('ticket_message.body_html'));
        $this->assertStringNotContainsString('<script', $res->json('ticket_message.body_html'));
        $this->assertSame($body, $res->json('ticket_message.body'));          // the original text is kept as typed

        $msg = $t->messages()->latest('id')->first();
        $msg->setRelation('sender', $a);
        $mail = (string) (new TicketReplyNotification($t, $msg))->toMail($c)->render();
        $this->assertMatchesRegularExpression('/<strong[^>]*>Please<\/strong>/', $mail);
        $this->assertMatchesRegularExpression('/<li[^>]*>restart<\/li>/', $mail);
        $this->assertStringNotContainsString('<script', $mail);
    }
}
