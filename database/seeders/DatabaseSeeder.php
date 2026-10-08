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
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SlaCalculatorService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
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
                'password' => Hash::make('password123'),
                'email_verified_at' => now(),
                'role' => UserRole::ADMIN,
                'is_available_for_routing' => false,
            ]
        );

        $lead = User::firstOrCreate(
            ['email' => 'lead@deskflow.com'],
            [
                'name' => 'Marcus Vance (Infra Lead)',
                'password' => Hash::make('password123'),
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
                'password' => Hash::make('password123'),
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
                'password' => Hash::make('password123'),
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
                'password' => Hash::make('password123'),
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
                'password' => Hash::make('password123'),
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
