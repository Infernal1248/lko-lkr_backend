<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckHosting extends Command
{
    protected $signature = 'cards:check-hosting';

    protected $description = 'Проверка PHP-хостинга перед запуском, без вывода секретных значений';

    public function handle(): int
    {
        $checks = ['PHP 8.4 или новее' => version_compare(PHP_VERSION, '8.4.0', '>='), 'GD с FreeType' => extension_loaded('gd') && function_exists('imagettftext'), 'APP_KEY задан' => filled(config('app.key')), 'storage доступен для записи' => is_writable(storage_path()), 'bootstrap/cache доступен для записи' => is_writable(base_path('bootstrap/cache'))];
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'zip', 'xml', 'dom', 'intl'] as $extension) {
            $checks['PHP '.$extension] = extension_loaded($extension);
        }
        foreach (['ppd-lkr', 'ppd-lko'] as $key) {
            $checks['Изображение '.$key] = is_readable(resource_path('templates/'.$key.'.png'));
        }
        $checks['Шрифт PDF'] = is_readable(resource_path('fonts/liberationserifb.json')) && is_readable(resource_path('fonts/LiberationSerif-Bold.ttf'));
        if (app()->isProduction()) {
            $checks['APP_DEBUG выключен'] = ! config('app.debug');
            $checks['APP_URL использует HTTPS'] = str_starts_with(config('app.url'), 'https://');
            $checks['Защищённые cookies'] = (bool) config('session.secure');
            $checks['Шифрование сессий'] = (bool) config('session.encrypt');
            $checks['Почта настроена'] = ! in_array(config('mail.default'), ['log', 'array']);
            $checks['MySQL выбран'] = config('database.default') === 'mysql';
        }
        try {
            DB::connection()->getPdo();
            $checks['Подключение к БД'] = true;
            if (DB::connection()->getDriverName() === 'mysql') {
                $version = (string) DB::selectOne('select version() as version')->version;
                $checks['MySQL не ниже 5.7'] = version_compare($version, '5.7.0', '>=');
                $this->line('Версия БД: '.$version);
            }
            $checks['Миграции реестра установлены'] = Schema::hasColumn('generations', 'registry');
            $checks['Миграции оплаты установлены'] = Schema::hasColumn('payments', 'receipt_mode');
        } catch (\Throwable) {
            $checks['Подключение к БД'] = false;
        }
        if (config('cards.payments_enabled')) {
            $checks['Реквизиты ЮKassa заданы'] = filled(config('cards.yookassa.shop_id')) && filled(config('cards.yookassa.secret'));
            $checks['Режим чеков задан'] = in_array(config('cards.receipt_mode'), ['npd_manual', '54fz']);
        }
        if (config('push.enabled')) {
            $checks['Ключи push заданы'] = filled(config('push.public_key')) && filled(config('push.private_key'));
            $checks['OpenSSL поддерживает P-256'] = function_exists('openssl_get_curve_names') && in_array('prime256v1', openssl_get_curve_names());
            $checks['PHP curl для push'] = extension_loaded('curl');
            $checks['Миграция push установлена'] = Schema::hasTable('push_subscriptions');
            $this->line('Доставку push проверьте кнопкой пробного уведомления в кабинете.');
        }
        foreach ($checks as $name => $ok) {
            $ok ? $this->info('OK: '.$name) : $this->error('ПРОВЕРЬТЕ: '.$name);
        }
        if (config('cards.yookassa.test')) {
            $this->warn('ЮKassa: тестовый режим. Реальные платежи не включены.');
        }
        if (config('cards.receipt_mode') === 'npd_manual') {
            $this->warn('Чеки НПД: ручная выдача через «Мой налог». Автоматической регистрации дохода нет.');
        }
        $this->line('Проверка не выполняет оплату и не проверяет доставку почты. VPS, Poppler и постоянный worker не требуются.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
