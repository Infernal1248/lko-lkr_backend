<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $resetUrl = fn ($user, string $token) => rtrim(config('app.url'), '/').'/?reset_token='.urlencode($token).'&email='.urlencode($user->email);
        ResetPassword::createUrlUsing($resetUrl);
        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject('Восстановление пароля — ЛКО / ЛКР')
            ->view(['html' => 'mail.reset-password', 'text' => 'mail.reset-password-text'], [
                'resetUrl' => $resetUrl($user, $token),
                'homeUrl' => rtrim(config('app.url'), '/'),
                'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]));
    }
}
