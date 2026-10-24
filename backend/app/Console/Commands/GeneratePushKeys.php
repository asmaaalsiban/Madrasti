<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GeneratePushKeys extends Command
{
    protected $signature = 'push:generate-keys';

    protected $description = 'Create the VAPID keys for web push notifications (add them to .env once)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('Add these lines to backend/.env, then run php artisan config:clear:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:you@yourschool.com');
        $this->newLine();
        $this->warn('Keep the private key secret and do not change the keys later: people would have to enable notifications again.');

        return self::SUCCESS;
    }
}
