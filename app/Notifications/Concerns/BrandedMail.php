<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * Every DeskFlow email uses the branded Markdown layout and carries the logo as an inline (cid:) attachment,
 * so it renders in any mail client, with no public image URL needed.
 */
trait BrandedMail
{
    protected function branded(string $subject, string $view, array $data = []): MailMessage
    {
        return (new MailMessage)
            ->subject($subject)
            ->markdown($view, $data)
            ->withSymfonyMessage(function (Email $email) {
                $email->embedFromPath(public_path('images/email/logo.png'), 'deskflow-logo', 'image/png');
            });
    }
}
