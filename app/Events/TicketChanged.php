<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tiny "this ticket changed" ping. It deliberately carries no ticket content:
 * open browsers re-fetch through the normal API, which applies the usual permissions.
 */
class TicketChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public bool $afterCommit = true;

    /** @param array<int,int> $userIds people whose lists should refresh */
    public function __construct(public int $ticketId, public string $kind, public bool $staffOnly = false, public array $userIds = []) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("ticket.{$this->ticketId}.staff")];
        if (! $this->staffOnly) {
            $channels[] = new PrivateChannel("ticket.{$this->ticketId}");
        }
        foreach (array_unique($this->userIds) as $id) {
            $channels[] = new PrivateChannel("user.{$id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ticket.changed';
    }

    public function broadcastWith(): array
    {
        return ['ticket_id' => $this->ticketId, 'kind' => $this->kind];
    }
}
