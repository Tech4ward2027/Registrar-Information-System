<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature   = 'webpush:vapid';
    protected $description = 'Generate a VAPID key pair for Web Push (run once per environment, then keep the keys).';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('Add these to the environment of the backend AND the broadcast-worker:');
        $this->newLine();
        $this->line('WEBPUSH_ENABLED=true');
        $this->line('VAPID_SUBJECT=mailto:registrar@your-domain.edu.ph');
        $this->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);
        $this->newLine();
        $this->warn('Keep the private key secret. Regenerating keys invalidates every existing subscription.');

        return self::SUCCESS;
    }
}
