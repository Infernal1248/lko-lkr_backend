<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('app.url').'/?reset_token='.urlencode($token).'&email='.urlencode($user->email));
    }
}
