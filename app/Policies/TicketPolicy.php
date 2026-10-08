<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Mirrors Ticket::scopeVisibleTo(). Customers: their own tickets (a company admin: their organization's).
     * Agents: assigned to them, or unassigned in their department (the claimable pool), or watching.
     * Leads: their department. Admin: everything.
     */
    public function view(User $user, Ticket $ticket): bool
    {
        $watching = fn () => $ticket->watchers()->where('users.id', $user->id)->exists();

        return match ($user->role) {
            UserRole::ADMIN => true,
            UserRole::CUSTOMER => $ticket->customer_id === $user->id
                || ($user->is_org_admin && $user->organization_id !== null && $user->organization_id === $ticket->organization_id),
            UserRole::LEAD => ($user->department_id !== null && $user->department_id === $ticket->department_id) || $watching(),
            UserRole::AGENT => $ticket->assigned_agent_id === $user->id
                || ($ticket->assigned_agent_id === null && $user->department_id !== null && $user->department_id === $ticket->department_id)
                || $watching(),
        };
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function changeStatus(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function manage(User $user, Ticket $ticket): bool
    {
        return $user->role->isStaff() && $this->view($user, $ticket);
    }

    public function viewAudit(User $user, Ticket $ticket): bool
    {
        return in_array($user->role, [UserRole::LEAD, UserRole::ADMIN], true) && $this->view($user, $ticket);
    }

    public function viewInternal(User $user, Ticket $ticket): bool
    {
        return $this->manage($user, $ticket);
    }
}
