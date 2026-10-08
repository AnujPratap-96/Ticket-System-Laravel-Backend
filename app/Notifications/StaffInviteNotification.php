<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use App\Support\MarkdownSafe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class StaffInviteNotification extends Notification implements ShouldQueue
{
    use BrandedMail, Queueable;

    public function __construct(
        public string $name,
        public string $email,
        public string $role,
        public ?string $department,
        public ?string $invitedBy,
        public string $token,
        public int $days,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/accept-invite?'.http_build_query(['email' => $this->email, 'token' => $this->token]);

        return $this->branded("You're invited to join DeskFlow Support", 'emails.staff-invite', [
            'name' => Str::of($this->name)->before(' ')->toString() ?: $this->name,
            'role' => $this->role === 'lead' ? 'team lead' : $this->role,
            'department' => $this->department ? MarkdownSafe::escape($this->department, 80) : null,
            'invitedBy' => $this->invitedBy ? MarkdownSafe::escape($this->invitedBy, 80) : null,
            'url' => $url,
            'days' => $this->days,
        ]);
    }
}
