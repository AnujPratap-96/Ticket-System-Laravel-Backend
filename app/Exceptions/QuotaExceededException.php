<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/** A plan limit was reached (monthly tickets, seats, open tickets). 422, so forms can show the message. */
class QuotaExceededException extends Exception
{
    public function __construct(string $message, public string $limit)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'quota_exceeded', 'limit' => $this->limit], 422);
    }
}
