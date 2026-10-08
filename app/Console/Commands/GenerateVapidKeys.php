<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Create the key pair that identifies this server to browser push services';

    public function handle(): int
    {
        $k = VAPID::createVapidKeys();
        $this->line('Add these to the API environment (keep the private key secret, and never change them once people subscribed):');
        $this->newLine();
        $this->line("VAPID_PUBLIC_KEY={$k['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$k['privateKey']}");
        $this->line('VAPID_SUBJECT=mailto:you@example.com');

        return self::SUCCESS;
    }
}
