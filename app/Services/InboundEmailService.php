<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a parsed inbound email into a customer reply on an existing ticket
 * (matched by the [TICK-YYYY-NNNNN] tag in the subject) or a brand-new ticket.
 *
 * Only registered, active customers are accepted. Unknown senders are dropped, so
 * the endpoint cannot be used to spam tickets from arbitrary addresses.
 */
class InboundEmailService
{
    public function __construct(
        private TicketStateMachineService $stateMachine,
        private TicketCreationService $creator,
        private TicketNotifier $notifier,
        private AuditLoggerService $audit,
    ) {}

    /**
     * @param  array{from:string,subject:string,body:string,message_id:?string,spf_failed:bool}  $mail
     * @return array{result:string, ticket_id?:int}
     */
    public function handle(array $mail): array
    {
        if ($mail['message_id'] && ! Cache::add('inbound-email:'.hash('sha256', $mail['message_id']), 1, now()->addDays(7))) {
            return ['result' => 'duplicate'];
        }

        if ($mail['spf_failed']) {
            Log::warning('Inbound email rejected: SPF fail', ['from' => $mail['from']]);

            return ['result' => 'rejected_spf'];
        }

        $customer = User::where('email', strtolower($mail['from']))
            ->where('role', UserRole::CUSTOMER->value)->where('is_active', true)->first();

        if (! $customer) {
            Log::info('Inbound email ignored: unknown sender', ['from' => $mail['from']]);

            return ['result' => 'unknown_sender'];
        }

        $body = $this->cleanBody($mail['body']);
        if ($body === '') {
            return ['result' => 'empty'];
        }

        if (preg_match('/TICK-\d{4}-\d{5}/', $mail['subject'], $m)) {
            return $this->reply($customer, $m[0], $body);
        }

        return $this->open($customer, $mail['subject'], $body);
    }

    private function reply(User $customer, string $number, string $body): array
    {
        $ticket = Ticket::where('ticket_number', $number)->where('customer_id', $customer->id)->first();

        if (! $ticket) {
            return ['result' => 'ticket_not_found'];
        }
        if ($ticket->status === TicketStatus::CLOSED) {
            return ['result' => 'ticket_closed', 'ticket_id' => $ticket->id];
        }

        DB::transaction(function () use ($ticket, $customer, $body) {
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'sender_id' => $customer->id,
                'is_internal_note' => false,
                'body' => $body,
            ]);

            if (in_array($ticket->status, [TicketStatus::PENDING_CUSTOMER, TicketStatus::RESOLVED], true)) {
                $this->stateMachine->transition($ticket, TicketStatus::IN_PROGRESS, $customer);
            }

            $this->audit->log($ticket, 'reply_posted', null, null, "Message ID: {$message->id} (via email)", $customer);
            $this->notifier->customerReplied($ticket, $message);
        });

        return ['result' => 'reply_added', 'ticket_id' => $ticket->id];
    }

    private function open(User $customer, string $subject, string $body): array
    {
        $slug = config('services.inbound_email.default_department');
        $department = ($slug ? Department::where('slug', $slug)->first() : null) ?? Department::orderBy('id')->first();

        if (! $department) {
            return ['result' => 'no_department'];
        }

        $title = Str::limit(trim(preg_replace('/^(re|fwd?):\s*/i', '', $subject)) ?: 'Email request', 250, '');

        $ticket = $this->creator->create($customer, [
            'department_id' => $department->id,
            'title' => $title,
            'description' => $body,
            'channel' => 'email',
        ]);

        return ['result' => 'ticket_created', 'ticket_id' => $ticket->id];
    }

    /**
     * Drops quoted history ("> ..." lines, "On <date> ... wrote:", "-----Original Message-----") and signatures.
     */
    public function cleanBody(string $text): string
    {
        $lines = preg_split('/\R/', str_replace("\r\n", "\n", $text));
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(On .+wrote:|-{2,}\s*Original Message\s*-{2,}|_{5,}|From:\s.+@.+)\s*$/i', $line) || str_starts_with(ltrim($line), '>')) {
                break;
            }
            $kept[] = $line;
        }

        return Str::limit(trim(implode("\n", $kept)), 20000, '');
    }
}
