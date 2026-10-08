<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;
    private User $other;
    private User $agent;
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.cloudinary.cloud_name' => 'demo', 'services.cloudinary.api_key' => 'k', 'services.cloudinary.api_secret' => 's']);

        $org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
        $this->other = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
        $this->agent = User::factory()->create(['role' => 'agent', 'department_id' => $dept->id]);
        $this->ticket = Ticket::create([
            'ticket_number' => 'TICK-A-1', 'organization_id' => $org->id, 'customer_id' => $this->customer->id,
            'department_id' => $dept->id, 'title' => 'T', 'description' => 'D', 'status' => 'in_progress', 'priority' => 'medium',
        ]);
    }

    private int $assetBytes = 1000;
    private bool $assetMissing = false;

    private function fakeAsset(int $bytes = 1000, bool $missing = false): void
    {
        $this->assetBytes = $bytes;
        $this->assetMissing = $missing;

        // One fake whose answer is read at request time, so tests can change it.
        Http::fake(fn ($request) => str_contains($request->url(), '/authenticated/')
            ? ($this->assetMissing ? Http::response([], 404) : Http::response(['bytes' => $this->assetBytes, 'format' => 'png']))
            : Http::response([]));
    }

    public function test_signing_is_scoped_to_the_ticket_and_requires_access(): void
    {
        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/attachments/sign', ['ticket_id' => $this->ticket->id])->assertOk();
        $this->assertSame("deskflow/tickets/{$this->ticket->id}", $res->json('params.folder'));
        $this->assertSame('authenticated', $res->json('params.type'));
        $this->assertSame(40, strlen($res->json('signature')));
        $this->assertArrayNotHasKey('api_secret', $res->json());

        $this->actingAs($this->other, 'sanctum')->postJson('/api/v1/attachments/sign', ['ticket_id' => $this->ticket->id])->assertStatus(403);

        $pending = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/attachments/sign')->assertOk();
        $this->assertSame("deskflow/pending/{$this->customer->id}", $pending->json('params.folder'));
    }

    public function test_signing_requires_authentication(): void
    {
        $this->postJson('/api/v1/attachments/sign')->assertStatus(401);
    }

    public function test_reply_with_attachment_stores_verified_metadata_and_returns_expiring_url(): void
    {
        $this->fakeAsset(2048);
        $pid = "deskflow/tickets/{$this->ticket->id}/abc123";

        $res = $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/messages", [
            'body' => 'see screenshot',
            'attachments' => [['public_id' => $pid, 'resource_type' => 'image', 'name' => 'shot.png']],
        ])->assertStatus(201);

        $att = $res->json('ticket_message.attachments.0');
        $this->assertSame(2048, $att['bytes']);          // from Cloudinary, not the client
        $this->assertTrue($att['is_image']);
        $this->assertStringContainsString('expires_at=', $att['url']);
        $this->assertStringContainsString('signature=', $att['url']);

        $stored = $this->ticket->messages()->first()->attachments_json[0];
        $this->assertArrayNotHasKey('url', $stored);      // URLs are never persisted
    }

    public function test_foreign_folder_oversize_and_missing_files_are_rejected(): void
    {
        $this->fakeAsset();
        $send = fn ($pid) => $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/messages", [
            'body' => 'x', 'attachments' => [['public_id' => $pid, 'resource_type' => 'image']],
        ]);

        $send('deskflow/tickets/999/stolen')->assertStatus(422);           // another ticket's folder
        $send("deskflow/tickets/{$this->ticket->id}/../x")->assertStatus(422); // traversal

        $this->fakeAsset(50 * 1024 * 1024);
        $send("deskflow/tickets/{$this->ticket->id}/big")->assertStatus(422);

        $this->fakeAsset(1000, missing: true);
        $send("deskflow/tickets/{$this->ticket->id}/gone")->assertStatus(422);
        $this->assertSame(0, $this->ticket->messages()->count());
    }

    public function test_internal_note_attachments_hidden_from_customer(): void
    {
        $this->fakeAsset();
        $this->actingAs($this->agent, 'sanctum')->postJson("/api/v1/tickets/{$this->ticket->id}/messages", [
            'body' => 'log', 'is_internal_note' => true,
            'attachments' => [['public_id' => "deskflow/tickets/{$this->ticket->id}/priv", 'resource_type' => 'raw']],
        ])->assertStatus(201);

        $messages = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/tickets/{$this->ticket->id}")->json('ticket.messages');
        $this->assertCount(0, $messages);
    }

    public function test_ticket_creation_accepts_pending_folder_attachments_only(): void
    {
        $this->fakeAsset();
        $payload = fn ($pid) => [
            'department_id' => $this->ticket->department_id, 'title' => 'With file', 'description' => 'see attached',
            'attachments' => [['public_id' => $pid, 'resource_type' => 'image', 'name' => 'a.png']],
        ];

        $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', $payload("deskflow/pending/{$this->other->id}/x"))->assertStatus(422);

        $res = $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', $payload("deskflow/pending/{$this->customer->id}/x"))->assertStatus(201);
        $this->assertCount(1, $res->json('ticket.attachments'));
    }
}
