<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GeneratePushKeys extends Command
{
    protected $signature = 'push:generate-keys {--openssl-config= : Path of openssl.cnf (Windows/XAMPP when PHP cannot find it)}';

    protected $description = 'Create the VAPID keys for web push notifications (add them to .env once)';

    public function handle(): int
    {
        $this->useOpenSslConfig();

        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->error('Could not create the keys: '.$e->getMessage());
            $this->line('On Windows (XAMPP) PHP often cannot find its OpenSSL settings. Run again with the path of openssl.cnf, for example:');
            $this->line('  php artisan push:generate-keys --openssl-config=C:\\xampp\\php\\extras\\openssl\\openssl.cnf');

            return self::FAILURE;
        }
        $this->line('Add these lines to backend/.env, then run php artisan config:clear:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:you@yourschool.com');
        $this->newLine();
        $this->warn('Keep the private key secret and do not change the keys later: people would have to enable notifications again.');

        return self::SUCCESS;
    }

    /** Points OpenSSL to its settings file: the given one, or the ones XAMPP / PHP installs usually have. */
    private function useOpenSslConfig(): void
    {
        if (getenv('OPENSSL_CONF') && is_file((string) getenv('OPENSSL_CONF'))) {
            return;
        }
        $php = dirname(PHP_BINARY);
        $candidates = array_filter([
            $this->option('openssl-config'),
            $php.'/extras/ssl/openssl.cnf', $php.'/extras/openssl/openssl.cnf', $php.'/openssl.cnf', dirname($php).'/apache/conf/openssl.cnf',
            'C:/xampp/php/extras/openssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf', '/etc/ssl/openssl.cnf',
        ]);
        foreach ($candidates as $file) {
            if (is_file((string) $file)) {
                putenv('OPENSSL_CONF='.$file);

                return;
            }
        }
    }
}
