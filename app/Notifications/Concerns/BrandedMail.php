<?php

namespace App\Notifications\Concerns;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Brand;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\View;
use Symfony\Component\Mime\Email;

/**
 * Every email uses the branded Markdown layout, in the WORKSPACE's own name, colour and logo (the DeskFlow defaults
 * when it has set none). The default logo travels as an inline (cid:) attachment so it renders in any mail client.
 */
trait BrandedMail
{
    private ?object $brandNotifiable = null;

    /** Call first in toMail(): tells the layout whose email this is. */
    protected function forNotifiable(object $notifiable): void
    {
        $this->brandNotifiable = $notifiable;
    }

    private function brandTenant(): ?Tenant
    {
        $n = $this->brandNotifiable;

        if ($n instanceof User) {
            return $n->tenant;
        }
        if (property_exists($this, 'tenant') && $this->tenant instanceof Tenant) {
            return $this->tenant;
        }
        if (property_exists($this, 'tenantId') && $this->tenantId) {
            return Tenant::find($this->tenantId);
        }

        return \App\Tenancy\TenantContext::get();
    }

    protected function brandName(): string
    {
        return Brand::for($this->brandTenant())['name'];
    }

    protected function branded(string $subject, string $view, array $data = []): MailMessage
    {
        $brand = Brand::for($this->brandTenant());
        View::share('brand', $brand + ['dark' => Brand::shade($brand['color'], 18)]);   // read by the layout, header and footer

        $message = (new MailMessage)->subject($subject)->markdown($view, $data);

        if ($brand['sender_name'] && config('mail.from.address')) {
            $message->from(config('mail.from.address'), $brand['sender_name']);
        }
        if ($brand['reply_to']) {
            $message->replyTo($brand['reply_to'], $brand['name']);
        }
        if (! $brand['logo_url']) {
            $message->withSymfonyMessage(function (Email $email) {
                $email->embedFromPath(public_path('images/email/logo.png'), 'deskflow-logo', 'image/png');
            });
        }

        return $message;
    }
}
