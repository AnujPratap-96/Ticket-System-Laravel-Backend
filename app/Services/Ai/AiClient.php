<?php

namespace App\Services\Ai;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin client for an OpenAI-compatible chat completions API (Groq by default).
 * Never throws to callers: returns null when the AI is off, over budget, or the provider fails.
 */
class AiClient
{
    public function configured(): bool
    {
        return (bool) config('services.ai.api_key');
    }

    public function enabled(): bool
    {
        return $this->configured() && AppSetting::get('ai_enabled', '1') === '1';
    }

    public function usageToday(): int
    {
        return (int) Cache::get($this->usageKey(), 0);
    }

    public function overBudget(): bool
    {
        $limit = (int) config('services.ai.daily_limit');

        return $limit > 0 && $this->usageToday() >= $limit;
    }

    /**
     * @param  array<int, array{role:string, content:string}>  $messages
     */
    public function chat(array $messages, int $maxTokens = 350, float $temperature = 0.2, bool $json = false): ?string
    {
        if (! $this->enabled() || $this->overBudget()) {
            return null;
        }

        Cache::add($this->usageKey(), 0, now()->addDays(2));
        Cache::increment($this->usageKey());

        try {
            $res = Http::withToken(config('services.ai.api_key'))
                ->timeout((int) config('services.ai.timeout'))
                ->acceptJson()
                ->post(rtrim(config('services.ai.base_url'), '/').'/chat/completions', $this->payload($messages, $maxTokens, $temperature, $json));

            if ($res->failed()) {
                Log::warning('AI provider error', ['status' => $res->status()]);

                return null;
            }

            $text = $res->json('choices.0.message.content');
            // Defensive: never show a reasoning model's scratchpad to a user.
            $text = is_string($text) ? preg_replace('#<think>.*?</think>#si', '', $text) : $text;

            return is_string($text) && trim($text) !== '' ? trim($text) : null;
        } catch (Throwable $e) {
            Log::warning('AI provider unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Qwen3 "thinks" by default, which is slow and spends tokens; for short support answers turn it off.
     */
    private function payload(array $messages, int $maxTokens, float $temperature, bool $json = false): array
    {
        $model = (string) config('services.ai.model');
        $payload = ['model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens, 'temperature' => $temperature];

        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        if (str_starts_with($model, 'qwen/qwen3')) {
            $payload['reasoning_effort'] = 'none';
        }

        return $payload;
    }

    private function usageKey(): string
    {
        return 'ai:usage:'.now()->utc()->format('Ymd');
    }
}
