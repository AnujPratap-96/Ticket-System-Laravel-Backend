<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketFormTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;
    private User $admin;
    private User $customer;

    private const FIELDS = [
        ['key' => 'server', 'label' => 'Server name', 'type' => 'text', 'required' => true],
        ['key' => 'env', 'label' => 'Environment', 'type' => 'select', 'required' => true, 'options' => ['prod', 'staging']],
        ['key' => 'count', 'label' => 'Affected users', 'type' => 'number'],
        ['key' => 'outage', 'label' => 'Is it an outage?', 'type' => 'checkbox'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::create(['name' => 'Acme', 'domain' => 'acme.test', 'sla_tier' => 'gold', 'is_active' => true]);
        $this->dept = Department::create(['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC', 'form_fields' => self::FIELDS]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->customer = User::factory()->create(['role' => 'customer', 'organization_id' => $org->id]);
    }

    private function file(array $custom, ?int $dept = null)
    {
        return $this->actingAs($this->customer, 'sanctum')->postJson('/api/v1/tickets', ['department_id' => $dept ?? $this->dept->id, 'title' => 'Down', 'description' => 'It is down', 'custom_fields' => $custom]);
    }

    public function test_answers_are_stored_and_shown_in_form_order(): void
    {
        $res = $this->file(['env' => 'prod', 'server' => 'web-01', 'outage' => true, 'count' => '12'])->assertStatus(201);

        $this->assertSame([
            ['label' => 'Server name', 'value' => 'web-01'],
            ['label' => 'Environment', 'value' => 'prod'],
            ['label' => 'Affected users', 'value' => '12'],
            ['label' => 'Is it an outage?', 'value' => 'Yes'],
        ], $res->json('ticket.custom_fields'));
    }

    public function test_required_fields_and_option_lists_are_enforced(): void
    {
        $this->file([])->assertStatus(422)->assertJsonValidationErrors(['custom_fields.server', 'custom_fields.env']);
        $this->file(['server' => 'x', 'env' => 'dev'])->assertStatus(422)->assertJsonValidationErrors('custom_fields.env');
        $this->file(['server' => 'x', 'env' => 'prod', 'count' => 'many'])->assertStatus(422)->assertJsonValidationErrors('custom_fields.count');
    }

    public function test_unknown_answers_are_dropped_and_departments_without_a_form_are_unaffected(): void
    {
        $res = $this->file(['server' => 'x', 'env' => 'prod', 'admin' => 'true', '<script>' => 'x'])->assertStatus(201);
        $this->assertCount(2, $res->json('ticket.custom_fields'));

        $plain = Department::create(['name' => 'Billing', 'slug' => 'billing', 'business_hours_start' => '09:00:00', 'business_hours_end' => '18:00:00', 'timezone' => 'UTC']);
        $this->file(['anything' => 'goes'], $plain->id)->assertStatus(201)->assertJsonPath('ticket.custom_fields', []);
    }

    public function test_admin_defines_the_form_with_validation(): void
    {
        $base = ['name' => 'Infra', 'slug' => 'infra', 'business_hours_start' => '09:00', 'business_hours_end' => '18:00', 'timezone' => 'UTC'];
        $this->actingAs($this->admin, 'sanctum');

        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base + ['form_fields' => [['key' => 'ref', 'label' => 'Invoice no.', 'type' => 'text', 'required' => true]]])
            ->assertOk()->assertJsonPath('department.form_fields.0.key', 'ref');

        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base + ['form_fields' => [['key' => 'Bad Key', 'label' => 'x', 'type' => 'text']]])->assertStatus(422);
        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base + ['form_fields' => [['key' => 'a', 'label' => 'x', 'type' => 'select']]])->assertStatus(422)->assertJsonValidationErrors('form_fields');
        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base + ['form_fields' => [['key' => 'a', 'label' => 'x', 'type' => 'text'], ['key' => 'a', 'label' => 'y', 'type' => 'text']]])->assertStatus(422);
        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base + ['form_fields' => [['key' => 'a', 'label' => 'x', 'type' => 'file']]])->assertStatus(422);

        // omitting form_fields leaves the form unchanged
        $this->patchJson("/api/v1/departments/{$this->dept->id}", $base)->assertOk()->assertJsonPath('department.form_fields.0.key', 'ref');
    }

    public function test_the_form_is_visible_to_customers_choosing_a_department(): void
    {
        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/departments')->assertOk()->assertJsonPath('departments.0.form_fields.0.key', 'server');
    }
}
