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

    public function __construct(public string $purpose, public string $code, public int $ttlMinutes) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reset = $this->purpose === 'password_reset';

        return $this->branded(
            $reset ? 'Reset your DeskFlow password' : 'Your DeskFlow verification code',
            'emails.otp',
            [
                'heading' => $reset ? 'Reset your password' : 'Verify your email address',
                'intro' => $reset
                    ? 'Use the code below to choose a new password for your DeskFlow account.'
                    : 'Welcome to DeskFlow Support! Enter the code below to verify your email and finish creating your account.',
                'code' => $this->code,
                'ttl' => $this->ttlMinutes,
            ]
        );
    }
}
