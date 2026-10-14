<?php

namespace App\Console\Commands;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketRating;
use App\Models\TicketSlaDeadline;
use App\Models\User;
use App\Notifications\WeeklySummaryNotification;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Emails each workspace's admins a short summary of the last seven days. */
class SendWeeklySummaries extends Command
{
    protected $signature = 'reports:weekly';
    protected $description = 'Send the weekly support summary to workspace admins';

    public function handle(): int
    {
        $sent = 0;
        TenantContext::eachTenant(function () use (&$sent) {
            $since = now()->subDays(7);
            $avg = TicketRating::where('created_at', '>=', $since)->avg('rating');
            $stats = [
                'created' => Ticket::where('created_at', '>=', $since)->count(),
                'resolved' => Ticket::whereNotNull('resolved_at')->where('resolved_at', '>=', $since)->count(),
                'open' => Ticket::whereNotIn('status', [TicketStatus::RESOLVED->value, TicketStatus::CLOSED->value])->count(),
                'breached' => TicketSlaDeadline::where('is_breached', true)->where('breached_at', '>=', $since)->count(),
                'rating' => $avg === null ? null : number_format((float) $avg, 1).' / 5',
            ];
            foreach (User::where('role', UserRole::ADMIN->value)->where('is_active', true)->whereNotNull('email_verified_at')->get() as $admin) {
                $admin->notify(new WeeklySummaryNotification($stats));
                $sent++;
            }
        });
        $this->info("Summaries sent: $sent");

        return self::SUCCESS;
    }
}
