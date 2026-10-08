<?php

namespace App\Services;

use App\Enums\TicketChannel;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single place that opens a ticket, whatever the channel (portal, API, inbound email).
 */
class TicketCreationService
{
    public function __construct(
        private SlaCalculatorService $sla,
        private TicketRoutingService $routing,
        private AuditLoggerService $audit,
        private AutomationEngine $automation,
    ) {}

    /**
     * @param  array{department_id:int,title:string,description:string,priority?:?string,channel?:?string,organization_id?:?int,attachments?:array}  $data
     */
    public function create(User $customer, array $data, ?string $ip = null, bool $actorIsStaff = false): Ticket
    {
        // Customers always file under their own organization; staff may file on behalf of any org.
        $orgId = $actorIsStaff
            ? ($data['organization_id'] ?? $customer->organization_id)
            : $customer->organization_id;

        $orgId ??= Organization::firstOrCreate(
            ['domain' => 'default.local'],
            ['name' => 'Default Organization', 'sla_tier' => 'standard']
        )->id;

        $ticket = DB::transaction(function () use ($customer, $data, $orgId, $ip) {
            $ticket = Ticket::create([
                'ticket_number' => 'TMP-'.bin2hex(random_bytes(8)),
                'organization_id' => $orgId,
                'customer_id' => $customer->id,
                'department_id' => $data['department_id'],
                'title' => $data['title'],
                'description' => $data['description'],
                'attachments_json' => ($data['attachments'] ?? []) ?: null,
                'custom_fields' => ($data['custom_fields'] ?? []) ?: null,
                'status' => TicketStatus::OPEN,
                'priority' => ! empty($data['priority']) ? TicketPriority::from($data['priority']) : TicketPriority::MEDIUM,
                'channel' => ! empty($data['channel']) ? TicketChannel::from($data['channel']) : TicketChannel::PORTAL,
            ]);

            // Sequential, human-friendly number derived from the unique primary key.
            $ticket->update(['ticket_number' => sprintf('TICK-%s-%05d', date('Y'), $ticket->id)]);

            $this->sla->attachDeadlines($ticket);
            $assigned = $this->routing->autoRoute($ticket);

            $this->audit->log($ticket, 'ticket_created', null, null, $ticket->ticket_number, $customer, $ip);

            if ($assigned) {
                $this->audit->log($ticket, 'agent_assigned', 'assigned_agent_id', null, $assigned->name, null, $ip);
            }

            return $ticket;
        });

        // After commit, so rules see the final ticket (priority, SLA, routing) and can never block filing it.
        $this->automation->fire('ticket_created', $ticket);
        \App\Jobs\AnalyzeTicketJob::dispatch($ticket->id);
        app(TicketEvents::class)->publish('ticket.created', $ticket->refresh());

        return $ticket->refresh();
    }
}
