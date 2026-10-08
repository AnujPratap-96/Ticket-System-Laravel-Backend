<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\WebPushChannel;
use Illuminate\Support\Str;

/**
 * Adds browser push to a notification that already has toArray() = {title, body, ticket_id}.
 * The push is only used for people who turned it on for a device.
 */
trait SendsWebPush
{
    protected function withPush(object $notifiable, array $channels): array
    {
        if (WebPushChannel::configured() && method_exists($notifiable, 'pushSubscriptions') && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(object $notifiable): array
    {
        $data = $this->toArray($notifiable);
        $staff = $notifiable->role->isStaff();

        return [
            'title' => Str::limit($data['title'], 90, '…'),
            // A customer's lock screen should not show message text; staff chose their work notifications.
            'body' => $staff ? Str::limit(strip_tags((string) ($data['body'] ?? '')), 110, '…') : 'Open DeskFlow to read it.',
            'url' => ($staff ? '/staff/tickets/' : '/portal/tickets/').$data['ticket_id'],
            'tag' => 'ticket-'.$data['ticket_id'],
        ];
    }
}
