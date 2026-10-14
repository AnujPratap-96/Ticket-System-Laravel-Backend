<?php

namespace App\Notifications;

use App\Notifications\Concerns\BrandedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpNotification extends Notification
{
    use BrandedMail, Queueable;

    /** The workspace the code is for (the queue worker has no ambient workspace, so remember it now). */
    public ?int $tenantId = null;

    public function __construct(public string $purpose, public string $code, public int $ttlMinutes)
    {
        $this->tenantId = \App\Tenancy\TenantContext::id();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->forNotifiable($notifiable);
        $reset = $this->purpose === 'password_reset';

        return $this->branded(
            $reset ? 'Reset your '.$this->brandName().' password' : 'Your '.$this->brandName().' verification code',
            'emails.otp',
            [
                'heading' => $reset ? 'Reset your password' : 'Verify your email address',
                'intro' => $reset
                    ? 'Use the code below to choose a new password for your '.$this->brandName().' account.'
                    : 'Welcome to '.$this->brandName().'! Enter the code below to verify your email and finish creating your account.',
                'code' => $this->code,
                'ttl' => $this->ttlMinutes,
            ]
        );
    }
}
