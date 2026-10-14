<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Exceptions\QuotaExceededException;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;

/**
 * What an organization may do on its plan, and how much of it has been used.
 * Defaults come from config/plans.php by tier; an organization can override each limit (0 = unlimited).
 */
class PlanLimits
{
    /** @return array{monthly_tickets:?int, customers:?int, open_per_customer:?int} null = unlimited */
    public function for(?Organization $org): array
    {
        $tier = $org?->sla_tier->value ?? 'standard';
        $plan = config("plans.tiers.{$tier}", config('plans.tiers.standard'));

        $pick = function (?int $override, ?int $default): ?int {
            if ($override === null) {
                return $default;
            }

            return $override === 0 ? null : $override;
        };

        // People who do not belong to a company share the catch-all organization: no company-wide caps there.
        $shared = $org && $org->domain === 'default.local';

        return [
            'monthly_tickets' => $shared ? null : $pick($org?->monthly_ticket_limit, $plan['monthly_tickets']),
            'customers' => $shared ? null : $pick($org?->max_customers, $plan['customers']),
            'open_per_customer' => $pick($org?->max_open_per_customer, $plan['open_per_customer']),
        ];
    }

    /** @return array{tickets_this_month:int, customers:int, open_tickets:int} */
    public function usage(Organization $org): array
    {
        return [
            'tickets_this_month' => $this->ticketsThisMonth($org),
            'customers' => $org->users()->where('role', UserRole::CUSTOMER->value)->where('is_active', true)->count(),
            'open_tickets' => $org->tickets()->whereNotIn('status', [TicketStatus::RESOLVED->value, TicketStatus::CLOSED->value])->count(),
        ];
    }

    public function ticketsThisMonth(Organization $org): int
    {
        return $org->tickets()->where('created_at', '>=', now()->startOfMonth())->count();
    }

    /** Called before a ticket is created, whatever the channel. */
    public function assertCanCreateTicket(Organization $org, User $customer): void
    {
        $limits = $this->for($org);

        if ($limits['monthly_tickets'] !== null && $this->ticketsThisMonth($org) >= $limits['monthly_tickets']) {
            throw new QuotaExceededException(
                "Your company's plan allows {$limits['monthly_tickets']} tickets per month and that limit has been reached. It resets on the 1st; ask your administrator or support to raise it.",
                'monthly_tickets'
            );
        }

        if ($limits['open_per_customer'] !== null) {
            $open = Ticket::where('customer_id', $customer->id)->whereNotIn('status', [TicketStatus::RESOLVED->value, TicketStatus::CLOSED->value])->count();
            if ($open >= $limits['open_per_customer']) {
                throw new QuotaExceededException(
                    "You already have {$open} open tickets, which is the most your plan allows at once. Wait for some to be resolved, or add your question to an existing ticket.",
                    'open_per_customer'
                );
            }
        }
    }

    /** True when one more customer fits. */
    public function hasSeatFor(Organization $org): bool
    {
        $max = $this->for($org)['customers'];

        return $max === null || $this->usage($org)['customers'] < $max;
    }

    public function seatsLeft(Organization $org): ?int
    {
        $max = $this->for($org)['customers'];

        return $max === null ? null : max(0, $max - $this->usage($org)['customers']);
    }
}
