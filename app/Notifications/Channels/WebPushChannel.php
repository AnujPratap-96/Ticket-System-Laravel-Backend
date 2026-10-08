<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use App\Support\SafeUrl;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Browser push (works with the app closed). The notification supplies a tiny payload via toWebPush():
 * ['title' => ..., 'body' => ..., 'url' => ...].
 */
class WebPushChannel
{
    public static function configured(): bool
    {
        return (bool) (config('services.webpush.public_key') && config('services.webpush.private_key'));
    }

    /** Only the browsers' own push services (and only public addresses). */
    public static function endpointAllowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        $ok = collect(config('services.webpush.allowed_hosts'))->contains(fn ($d) => $host === $d || str_ends_with($host, '.'.$d));

        return $ok && ! is_string(SafeUrl::resolve($endpoint));
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! self::configured() || ! method_exists($notification, 'toWebPush')) {
            return;
        }

        $subs = PushSubscription::where('user_id', $notifiable->id)->get();
        if ($subs->isEmpty()) {
            return;
        }

        $payload = json_encode($notification->toWebPush($notifiable), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $push = new WebPush(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]], ['TTL' => 3600], new \GuzzleHttp\Client(['timeout' => 5, 'connect_timeout' => 3, 'allow_redirects' => false]));

            foreach ($subs as $s) {
                if (! self::endpointAllowed($s->endpoint)) {
                    $s->delete();   // defence in depth: an old/forged row must never make us call an arbitrary URL
                    continue;
                }
                $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->p256dh, 'authToken' => $s->auth, 'contentEncoding' => 'aes128gcm']), $payload, ['_sub' => $s->id]);
            }

            foreach ($push->flush() as $report) {
                $id = $report->getRequest()->getUri()->__toString();
                $sub = $subs->first(fn ($s) => $s->endpoint === $id);
                if ($report->isSuccess()) {
                    $sub?->forceFill(['last_used_at' => now()])->save();
                } elseif ($report->isSubscriptionExpired()) {
                    $sub?->delete();   // the browser unsubscribed or the subscription expired (404/410)
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Web push failed', ['error' => $e->getMessage()]);
        }
    }
}
