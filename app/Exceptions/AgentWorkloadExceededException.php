<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class AgentWorkloadExceededException extends Exception
{
    public function __construct(string $agentName, int $active, int $max)
    {
        parent::__construct("Agent [{$agentName}] has reached active capacity ({$active}/{$max}). Cannot assign more tickets.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'agent_at_capacity'], 409);
    }
}
