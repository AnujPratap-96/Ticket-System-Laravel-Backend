<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Security notice: if you did not do this, someone else is in your account.
 */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use BrandedMail, Queueable;

    public function __construct(public string $device) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->branded('Your DeskFlow password was changed', 'emails.password-changed', [
            'name' => Str::of($notifiable->name)->before(' ')->toString() ?: $notifiable->name,
            'device' => $this->device,
            'when' => now()->utc()->format('j M Y, H:i').' UTC',
            'resetUrl' => rtrim(config('app.frontend_url'), '/').'/forgot-password',
        ]);
    }
}
