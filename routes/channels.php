<?php

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Each person's own channel: "your tickets/notifications changed".
Broadcast::channel('user.{id}', fn (User $user, int $id) => $user->id === $id, ['guards' => ['sanctum']]);

// A ticket's live conversation: anyone allowed to open the ticket.
Broadcast::channel('ticket.{id}', function (User $user, int $id) {
    $ticket = Ticket::find($id);

    return $ticket && $user->can('view', $ticket);
}, ['guards' => ['sanctum']]);

// Staff-only side channel (internal notes, who is typing): never customers.
Broadcast::channel('ticket.{id}.staff', function (User $user, int $id) {
    $ticket = Ticket::find($id);

    return $user->role->isStaff() && $ticket && $user->can('view', $ticket);
}, ['guards' => ['sanctum']]);
