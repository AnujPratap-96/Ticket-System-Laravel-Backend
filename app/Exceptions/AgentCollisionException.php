<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class AgentCollisionException extends Exception
{
    public function __construct(public readonly string $otherAgentName)
    {
        parent::__construct("Collision detected: Agent [{$otherAgentName}] is actively responding to this ticket. Confirmation required.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'agent_collision',
            'other_agent' => $this->otherAgentName,
        ], 409);
    }
}
