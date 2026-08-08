<?php

use Illuminate\Support\Facades\Artisan;
use Minishlink\WebPush\VAPID;

Artisan::command('webpush:generate-vapid {--file=.env} {--subject=https://lazareva-secret.ru}', function (): int {
    $fileName = (string) $this->option('file');
    if (! preg_match('/^\.env(?:\.[A-Za-z0-9_-]+)?$/', $fileName)) {
        $this->error('The environment file must be located in the backend root.');

        return 1;
    }

    $path = base_path($fileName);
    $contents = file_exists($path) ? (string) file_get_contents($path) : '';
    if (preg_match('/^VAPID_PUBLIC_KEY=.+$/m', $contents)
        && preg_match('/^VAPID_PRIVATE_KEY=.+$/m', $contents)) {
        $this->info('VAPID keys already exist; nothing was changed.');

        return 0;
    }

    $keys = VAPID::createVapidKeys();
    $values = [
        'VAPID_SUBJECT' => (string) $this->option('subject'),
        'VAPID_PUBLIC_KEY' => $keys['publicKey'],
        'VAPID_PRIVATE_KEY' => $keys['privateKey'],
        'WEB_PUSH_TTL' => '86400',
    ];

    foreach ($values as $name => $value) {
        $line = $name.'='.$value;
        if (preg_match('/^'.preg_quote($name, '/').'=.*$/m', $contents)) {
            $contents = (string) preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $line, $contents);
        } else {
            $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
        }
    }

    file_put_contents($path, ltrim($contents), LOCK_EX);
    @chmod($path, 0600);
    $this->info('VAPID keys generated and saved without printing the private key.');

    return 0;
})->purpose('Generate persistent VAPID keys for Web Push');
