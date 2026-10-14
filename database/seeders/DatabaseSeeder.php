<?php

namespace Database\Seeders;

use App\Enums\SlaTier;
use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\BusinessHoliday;
use App\Models\Department;
use App\Models\Organization;
use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SlaCalculatorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo data: TWO independent workspaces (so isolation can be seen), plus the platform owner.
     *   techcorp  - the full demo (admin@deskflow.com, lead@, agent.sarah@, rahul@acme.com ... password: DEMO_PASSWORD)
     *   shopease  - a small second company, including its own "rahul@acme.com" (same email, different person and data)
     *   platform@deskflow.com - platform owner (manages workspaces, sees no tickets)
     */
    private ?string $demoPassword = null;

    /** One password for every demo account: DEMO_PASSWORD from the environment, otherwise a random one printed once. */
    private function demoPassword(): string
    {
        if ($this->demoPassword === null) {
            $this->demoPassword = (string) env('DEMO_PASSWORD');
            if ($this->demoPassword === '') {
                $this->demoPassword = \Illuminate\Support\Str::password(16, symbols: false);
                $this->command?->warn("DEMO_PASSWORD is not set. Demo accounts were given this random password: {$this->demoPassword}");
            }
        }

        return $this->demoPassword;
    }

    public function run(): void
    {
        $techcorp = Tenant::where('slug', 'techcorp')->first()
            ?? Tenant::where('slug', 'default')->first()
            ?? Tenant::create(['name' => 'TechCorp Support', 'slug' => 'techcorp']);
        $techcorp->update(['name' => 'TechCorp Support', 'slug' => 'techcorp', 'is_active' => true]);

        TenantContext::run($techcorp, fn () => $this->seedTechCorp());

        $shop = Tenant::firstOrCreate(['slug' => 'shopease'], ['name' => 'ShopEase Support', 'is_active' => true]);
        TenantContext::run($shop, fn () => $this->seedShopEase());

        TenantContext::withoutTenant(function () {
            User::withoutGlobalScopes()->whereNull('tenant_id')->where('email', 'platform@deskflow.com')->first()
                ?? User::forceCreate([
                    'tenant_id' => null, 'name' => 'Platform Owner', 'email' => 'platform@deskflow.com', 'role' => UserRole::SUPER_ADMIN,
                    'password' => Hash::make($this->demoPassword()), 'email_verified_at' => now(), 'is_active' => true, 'is_available_for_routing' => false,
                ]);
        });
    }

    private function seedShopEase(): void
    {
        $dept = Department::firstOrCreate(['slug' => 'orders'], [
            'name' => 'Orders & Returns', 'description' => 'Where is my order, refunds, exchanges',
            'business_hours_start' => '10:00:00', 'business_hours_end' => '19:00:00', 'timezone' => 'Asia/Kolkata',
        ]);
        $org = Organization::firstOrCreate(['domain' => 'brightretail.com'], ['name' => 'Bright Retail', 'sla_tier' => SlaTier::GOLD, 'is_active' => true]);

        $mk = fn (string $email, string $name, UserRole $role, array $extra = []) => User::firstOrCreate(['email' => $email], array_merge([
            'name' => $name, 'role' => $role, 'password' => Hash::make($this->demoPassword()), 'email_verified_at' => now(), 'is_active' => true,
        ], $extra));

        $mk('admin@shopease.com', 'Meera Admin', UserRole::ADMIN, ['is_available_for_routing' => false]);
        $mk('agent@shopease.com', 'Kabir Agent', UserRole::AGENT, ['department_id' => $dept->id, 'is_available_for_routing' => true, 'max_active_tickets' => 8]);
        $customer = $mk('rahul@acme.com', 'Rahul (ShopEase customer)', UserRole::CUSTOMER, ['organization_id' => $org->id]);

        if (! Ticket::where('customer_id', $customer->id)->exists()) {
            $ticket = Ticket::create([
                'ticket_number' => 'TMP-'.bin2hex(random_bytes(6)), 'organization_id' => $org->id, 'customer_id' => $customer->id, 'department_id' => $dept->id,
                'title' => 'Where is my order #4412?', 'description' => 'Ordered a week ago and the tracking has not moved.',
                'status' => TicketStatus::OPEN, 'priority' => TicketPriority::MEDIUM, 'channel' => TicketChannel::PORTAL,
            ]);
            $ticket->update(['ticket_number' => sprintf('TICK-%s-%05d', date('Y'), $ticket->id)]);
            app(SlaCalculatorService::class)->attachDeadlines($ticket->fresh(['organization', 'department']));
        }
    }

    private function seedTechCorp(): void
    {
        $this->call(KnowledgeBaseSeeder::class);

        // 1. Create Organizations
        $acme = Organization::firstOrCreate(
            ['domain' => 'acme.com'],
            ['name' => 'Acme Cloud Enterprises', 'sla_tier' => SlaTier::PLATINUM, 'is_active' => true]
        );

        $globex = Organization::firstOrCreate(
            ['domain' => 'globex.corp'],
            ['name' => 'Globex Corporation', 'sla_tier' => SlaTier::GOLD, 'is_active' => true]
        );

        $initech = Organization::firstOrCreate(
            ['domain' => 'initech.io'],
            ['name' => 'Initech Software', 'sla_tier' => SlaTier::STANDARD, 'is_active' => true]
        );

        // 2. Create Departments
        $infraDept = Department::firstOrCreate(
            ['slug' => 'infrastructure'],
            [
                'name' => 'Enterprise Infrastructure',
                'description' => 'Cloud servers, database clusters, network architecture',
                'business_hours_start' => '09:00:00',
                'business_hours_end' => '18:00:00',
                'timezone' => 'UTC',
            ]
        );

        $billingDept = Department::firstOrCreate(
            ['slug' => 'billing-finance'],
            [
                'name' => 'Billing & Invoicing',
                'description' => 'Subscription management, tax invoices, payment gateways',
                'business_hours_start' => '09:00:00',
                'business_hours_end' => '18:00:00',
                'timezone' => 'UTC',
            ]
        );

        $devDept = Department::firstOrCreate(
            ['slug' => 'developer-support'],
            [
                'name' => 'Developer & API Support',
                'description' => 'SDK integrations, REST endpoints, webhooks',
                'business_hours_start' => '09:00:00',
                'business_hours_end' => '18:00:00',
                'timezone' => 'UTC',
            ]
        );

        // 3. Create SLA Policy Matrix
        $tiers = [
            SlaTier::PLATINUM->value => [
                'urgent' => ['response' => 15, 'resolution' => 120],
                'high' => ['response' => 30, 'resolution' => 240],
                'medium' => ['response' => 60, 'resolution' => 480],
                'low' => ['response' => 120, 'resolution' => 960],
            ],
            SlaTier::GOLD->value => [
                'urgent' => ['response' => 30, 'resolution' => 240],
                'high' => ['response' => 60, 'resolution' => 480],
                'medium' => ['response' => 120, 'resolution' => 960],
                'low' => ['response' => 240, 'resolution' => 1440],
            ],
            SlaTier::STANDARD->value => [
                'urgent' => ['response' => 60, 'resolution' => 480],
                'high' => ['response' => 120, 'resolution' => 960],
                'medium' => ['response' => 240, 'resolution' => 1440],
                'low' => ['response' => 480, 'resolution' => 2880],
            ],
        ];

        foreach ($tiers as $tierName => $priorities) {
            foreach ($priorities as $priorityName => $times) {
                SlaPolicy::firstOrCreate(
                    ['tier' => $tierName, 'priority' => $priorityName],
                    [
                        'name' => ucfirst($tierName) . ' ' . ucfirst($priorityName) . ' SLA',
                        'first_response_time_minutes' => $times['response'],
                        'resolution_time_minutes' => $times['resolution'],
                        'applies_business_hours_only' => true,
                    ]
                );
            }
        }

        // 4. Create Business Holidays
        BusinessHoliday::firstOrCreate(
            ['holiday_date' => '2026-12-25'],
            ['name' => 'Christmas Day']
        );
        BusinessHoliday::firstOrCreate(
            ['holiday_date' => '2026-01-01'],
            ['name' => 'New Year Day']
        );

        // 5. Create Staff Users
        $admin = User::firstOrCreate(
            ['email' => 'admin@deskflow.com'],
            [
                'name' => 'Chief Operations Admin',
                'password' => Hash::make($this->demoPassword()),
                'email_verified_at' => now(),
                'role' => UserRole::ADMIN,
                'is_available_for_routing' => false,
            ]
        );

        $lead = User::firstOrCreate(
            ['email' => 'lead@deskflow.com'],
            [
                'name' => 'Marcus Vance (Infra Lead)',
                'password' => Hash::make($this->demoPassword()),
                'email_verified_at' => now(),
                'role' => UserRole::LEAD,
                'department_id' => $infraDept->id,
                'is_available_for_routing' => true,
                'max_active_tickets' => 15,
            ]
        );

        $agent1 = User::firstOrCreate(
            ['email' => 'agent.sarah@deskflow.com'],
            [
                'name' => 'Sarah Connor',
                'password' => Hash::make($this->demoPassword()),
                'email_verified_at' => now(),
                'role' => UserRole::AGENT,
                'department_id' => $infraDept->id,
                'is_available_for_routing' => true,
                'max_active_tickets' => 10,
            ]
        );

        $agent2 = User::firstOrCreate(
            ['email' => 'agent.alex@deskflow.com'],
            [
                'name' => 'Alex Miller',
                'password' => Hash::make($this->demoPassword()),
                'email_verified_at' => now(),
                'role' => UserRole::AGENT,
                'department_id' => $infraDept->id,
                'is_available_for_routing' => true,
                'max_active_tickets' => 10,
            ]
        );

        // 6. Create Customer Users
        $customer1 = User::firstOrCreate(
            ['email' => 'rahul@acme.com'],
            [
                'name' => 'Rahul Sharma',
                'password' => Hash::make($this->demoPassword()),
                'email_verified_at' => now(),
                'role' => UserRole::CUSTOMER,
                'organization_id' => $acme->id,
                'is_available_for_routing' => false,
            ]
        );

        $customer2 = User::firstOrCreate(
            ['email' => 'customer@initech.com'],
            [
                'name' => 'Peter Gibbons',
                'password' => Hash::make($this->demoPassword()),
                'email_verified_at' => now(),
                'role' => UserRole::CUSTOMER,
                'organization_id' => $initech->id,
                'is_available_for_routing' => false,
            ]
        );

        // 7. Seed Sample Tickets & Attach SLAs
        $slaCalculator = app(SlaCalculatorService::class);

        $ticket1 = Ticket::firstOrCreate(
            ['ticket_number' => 'TICK-2026-000001'],
            [
                'organization_id' => $acme->id,
                'customer_id' => $customer1->id,
                'assigned_agent_id' => $agent1->id,
                'department_id' => $infraDept->id,
                'title' => 'Database connection timeout on production replica',
                'description' => 'Queries to the analytics read replica are timing out after 30 seconds. Please investigate connection pool.',
                'status' => TicketStatus::IN_PROGRESS,
                'priority' => TicketPriority::HIGH,
                'channel' => TicketChannel::PORTAL,
                'created_at' => now()->subHours(2),
            ]
        );
        $slaCalculator->attachDeadlines($ticket1);

        TicketMessage::firstOrCreate(
            ['ticket_id' => $ticket1->id, 'body' => 'Queries to the analytics read replica are timing out after 30 seconds.'],
            ['sender_id' => $customer1->id, 'is_internal_note' => false]
        );

        TicketMessage::firstOrCreate(
            ['ticket_id' => $ticket1->id, 'body' => 'Investigating MySQL max_connections limit on the replica host.'],
            ['sender_id' => $agent1->id, 'is_internal_note' => false]
        );

        TicketMessage::firstOrCreate(
            ['ticket_id' => $ticket1->id, 'body' => 'Internal note: Thread dump shows 400 idle connections from old worker pods.'],
            ['sender_id' => $lead->id, 'is_internal_note' => true]
        );

        $ticket2 = Ticket::firstOrCreate(
            ['ticket_number' => 'TICK-2026-000002'],
            [
                'organization_id' => $initech->id,
                'customer_id' => $customer2->id,
                'assigned_agent_id' => $agent2->id,
                'department_id' => $infraDept->id,
                'title' => 'SSL certificate renewal question',
                'description' => 'Our custom vanity domain certificate expires in 14 days. Is auto-renewal enabled?',
                'status' => TicketStatus::OPEN,
                'priority' => TicketPriority::MEDIUM,
                'channel' => TicketChannel::PORTAL,
                'created_at' => now()->subMinutes(30),
            ]
        );
        $slaCalculator->attachDeadlines($ticket2);
    }
}
