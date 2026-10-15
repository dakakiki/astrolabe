<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Prints a new VAPID key pair for web push in the client PWA (Phase 9c). It is
 * not written anywhere: the operator puts both lines in the server's .env.
 * A new pair makes every device's push subscription useless — clients switch
 * push on again in Portal → Profile.
 */
class GeneratePushKeys extends Command
{
    protected $signature = 'portal:push-keys';

    protected $description = 'Print a new VAPID key pair for web push in the client portal';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->warn('Put both lines in .env. Keep the private key off the repository.');
        $this->warn('Changing the keys later stops push on every device until clients switch it on again.');

        return self::SUCCESS;
    }
}
