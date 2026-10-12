<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GeneratePushKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Однократно записать VAPID-ключи в .env без вывода секретов';

    public function handle(): int
    {
        $path = app()->environmentFilePath();
        $handle = fopen($path, 'c+');
        if (! $handle || ! flock($handle, LOCK_EX)) {
            $this->error('Не удалось открыть .env для записи.');

            return self::FAILURE;
        }
        try {
            $content = stream_get_contents($handle);
            foreach (['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY'] as $key) {
                if (preg_match('/^'.$key.'=(.*)$/m', $content, $match) && trim($match[1], " \t\r\n\"'") !== '') {
                    $this->error('VAPID-ключи уже заданы. Существующие ключи не изменены.');

                    return self::FAILURE;
                }
            }
            $keys = VAPID::createVapidKeys();
            foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']] as $key => $value) {
                $content = preg_match('/^'.$key.'=.*$/m', $content) ? preg_replace('/^'.$key.'=.*$/m', $key.'='.$value, $content) : rtrim($content)."\n".$key.'='.$value."\n";
            }
            rewind($handle);
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new \RuntimeException('Не удалось записать .env полностью.');
            }
            ftruncate($handle, strlen($content));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->info('VAPID-ключи записаны в .env. Установите PUSH_ENABLED=true и выполните config:cache. APP_KEY не изменён.');

        return self::SUCCESS;
    }
}
