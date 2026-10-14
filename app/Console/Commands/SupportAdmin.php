<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SupportAdmin extends Command
{
    protected $signature = 'support:admin {email : Логин или однозначный email аккаунта} {--revoke}';

    protected $description = 'Grant or revoke access to the support inbox for an existing account';

    public function handle(): int
    {
        $identity = mb_strtolower(trim($this->argument('email')));
        $matches = User::where(str_contains($identity, '@') ? 'email' : 'username', $identity)->limit(2)->get();
        if ($matches->count() > 1) {
            $this->error('Эта почта используется несколькими аккаунтами. Укажите логин.');

            return self::FAILURE;
        }
        $u = $matches->first();
        if (! $u) {
            $this->error('Аккаунт не найден.');

            return self::FAILURE;
        }
        $u->forceFill(['is_support_admin' => ! $this->option('revoke')])->save();
        $this->info('Доступ к поддержке обновлён.');

        return self::SUCCESS;
    }
}
