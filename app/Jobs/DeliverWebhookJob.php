<?php

namespace App\Jobs;

use App\Models\WebhookEndpoint;
use App\Support\SafeUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DISABLE_AFTER = 20;

    public int $tries = 4;

    public function __construct(public int $endpointId, public array $payload, public string $deliveryId)
    {
        $this->afterCommit();
    }

    /** Seconds to wait before attempts 2, 3 and 4. */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $endpoint = WebhookEndpoint::find($this->endpointId);
        if (! $endpoint || ! $endpoint->is_active) {
            return;
        }

        // Checked again on EVERY attempt, and we connect to the address we checked (no DNS rebinding).
        $target = SafeUrl::resolve($endpoint->url);
        if (is_string($target)) {
            $this->record($endpoint, "Blocked: {$target}", false);
            $this->fail(new \RuntimeException($target));

            return;
        }

        $body = json_encode($this->body($endpoint), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'DeskFlow-Webhooks/1.0'];

        if ($endpoint->type === 'generic') {
            $ts = (string) time();
            $headers += [
                'X-DeskFlow-Event' => $this->payload['event'],
                'X-DeskFlow-Delivery' => $this->deliveryId,
                'X-DeskFlow-Timestamp' => $ts,
            ];
            if ($endpoint->secret) {
                $headers['X-DeskFlow-Signature'] = 'sha256='.hash_hmac('sha256', $ts.'.'.$body, $endpoint->secret);
            }
        }

        try {
            $res = Http::withHeaders($headers)->timeout(5)->connectTimeout(3)
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]]])
                ->withBody($body, 'application/json')->post($endpoint->url);
        } catch (\Throwable $e) {
            $this->record($endpoint, 'Could not connect', false);
            throw $e;   // network trouble: retry later
        }

        if ($res->successful()) {
            $this->record($endpoint, "OK ({$res->status()})", true);

            return;
        }

        $this->record($endpoint, "HTTP {$res->status()}", false);

        // The receiver said "no" for good (bad request, gone, forbidden): retrying will not change that. 429 and 5xx are worth retrying.
        if ($res->status() >= 400 && $res->status() < 500 && $res->status() !== 429) {
            $this->fail(new \RuntimeException("HTTP {$res->status()}"));

            return;
        }

        throw new \RuntimeException("HTTP {$res->status()}");
    }

    private function body(WebhookEndpoint $e): array
    {
        $t = $this->payload['ticket'];
        $label = WebhookEndpoint::EVENTS[$this->payload['event']] ?? $this->payload['event'];
        $line = "{$label}: {$t['number']} — {$t['title']}";
        $meta = collect([ucfirst($t['priority']).' priority', $t['department'], $t['assignee'] ? "assigned to {$t['assignee']}" : 'unassigned'])->filter()->implode(' · ');

        return match ($e->type) {
            'slack' => ['text' => "*{$label}*: <{$t['url']}|{$t['number']}> — ".$this->slackEscape($t['title'])."\n{$meta}"],
            'teams' => ['text' => "**{$label}**: [{$t['number']}]({$t['url']}) — {$t['title']}  \n{$meta}"],
            default => $this->payload + ['summary' => $line],
        };
    }

    private function slackEscape(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    private function record(WebhookEndpoint $e, string $status, bool $ok): void
    {
        $e->forceFill([
            'last_status' => Str::limit($status, 120, ''),
            'last_delivery_at' => now(),
            'consecutive_failures' => $ok ? 0 : $e->consecutive_failures,
        ])->save();
    }

    /** Called once all attempts are used up. */
    public function failed(\Throwable $e): void
    {
        $endpoint = WebhookEndpoint::find($this->endpointId);
        if (! $endpoint) {
            return;
        }
        $endpoint->increment('consecutive_failures');
        if ($endpoint->consecutive_failures >= self::DISABLE_AFTER) {
            $endpoint->forceFill(['is_active' => false, 'last_status' => 'Turned off after repeated failures'])->save();
        }
    }
}
