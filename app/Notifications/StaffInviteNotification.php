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
        public ?\App\Models\Tenant $tenant = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->forNotifiable($notifiable);
        $path = '/accept-invite?'.http_build_query(['email' => $this->email, 'token' => $this->token]);
        $url = $this->tenant ? $this->tenant->url($path) : rtrim(config('app.frontend_url'), '/').$path;

        return $this->branded("You're invited to join ".$this->brandName(), 'emails.staff-invite', [
            'name' => Str::of($this->name)->before(' ')->toString() ?: $this->name,
            'role' => $this->role === 'lead' ? 'team lead' : $this->role,
            'department' => $this->department ? MarkdownSafe::escape($this->department, 80) : null,
            'invitedBy' => $this->invitedBy ? MarkdownSafe::escape($this->invitedBy, 80) : null,
            'url' => $url,
            'days' => $this->days,
        ]);
    }
}
