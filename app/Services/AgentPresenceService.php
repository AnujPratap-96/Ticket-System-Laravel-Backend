<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Presence heartbeats. One cache key per (ticket, agent) with a short TTL
 * ("ticket:{id}:presence:{agent}") plus a small per-ticket index of agent ids,
 * so no read-modify-write of a shared blob is needed for the heartbeat itself.
 */
class AgentPresenceService
{
    public const PRESENCE_TTL_SECONDS = 30;

    public function recordPresence(int $ticketId, User $agent, string $action = 'viewing'): void
    {
        $action = in_array($action, ['viewing', 'typing'], true) ? $action : 'viewing';

        Cache::put($this->key($ticketId, $agent->id), [
            'agent_id' => $agent->id,
            'name' => $agent->name,
            'action' => $action,
            'pinged_at' => now()->timestamp,
        ], now()->addSeconds(self::PRESENCE_TTL_SECONDS));

        $this->addToIndex($ticketId, $agent->id);
    }

    public function clearPresence(int $ticketId, int $agentId): void
    {
        Cache::forget($this->key($ticketId, $agentId));
    }

    /**
     * Active viewers excluding the querying agent.
     */
    public function getActiveCollisions(int $ticketId, ?int $currentAgentId = null): array
    {
        $ids = Cache::get($this->indexKey($ticketId), []);
        $active = [];
        $live = [];

        foreach ($ids as $agentId) {
            $viewer = Cache::get($this->key($ticketId, (int) $agentId));
            if (! $viewer) {
                continue;
            }
            $live[] = (int) $agentId;
            if ((int) $agentId !== (int) $currentAgentId) {
                $active[] = $viewer;
            }
        }

        if (count($live) !== count($ids)) {
            Cache::put($this->indexKey($ticketId), $live, now()->addMinutes(5));
        }

        return $active;
    }

    /**
     * Viewers that currently hold a "typing" draft lock.
     */
    public function getTypingCollisions(int $ticketId, ?int $currentAgentId = null): array
    {
        return array_values(array_filter(
            $this->getActiveCollisions($ticketId, $currentAgentId),
            fn ($viewer) => ($viewer['action'] ?? null) === 'typing'
        ));
    }

    private function addToIndex(int $ticketId, int $agentId): void
    {
        $lock = Cache::lock($this->indexKey($ticketId).':lock', 3);

        try {
            $lock->block(2);
            $ids = Cache::get($this->indexKey($ticketId), []);
            if (! in_array($agentId, $ids, true)) {
                $ids[] = $agentId;
            }
            Cache::put($this->indexKey($ticketId), $ids, now()->addMinutes(5));
        } catch (\Throwable $e) {
            // Lock contention must never break a heartbeat; the next ping repairs the index.
        } finally {
            optional($lock)->release();
        }
    }

    private function key(int $ticketId, int $agentId): string
    {
        return "ticket:{$ticketId}:presence:{$agentId}";
    }

    private function indexKey(int $ticketId): string
    {
        return "ticket:{$ticketId}:presence_index";
    }
}
