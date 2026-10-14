<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Monday-morning numbers for workspace admins. Email only. */
class WeeklySummaryNotification extends Notification implements ShouldQueue
{
    use BrandedMail, Queueable;

    public function __construct(public array $stats)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->forNotifiable($notifiable);

        return $this->branded("Your support week: {$this->stats['created']} new, {$this->stats['resolved']} resolved", 'emails.weekly-summary', $this->stats + [
            'name' => \Illuminate\Support\Str::of($notifiable->name)->before(' ')->toString() ?: $notifiable->name,
            'url' => $notifiable->tenant?->url('/staff/analytics') ?? rtrim(config('app.frontend_url'), '/').'/staff/analytics',
        ]);
    }
}
