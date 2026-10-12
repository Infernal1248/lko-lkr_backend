<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SupportAdmin extends Command
{
    protected $signature = 'support:admin {email} {--revoke}';

    protected $description = 'Grant or revoke access to the support inbox for an existing account';

    public function handle(): int
    {
        $u = User::where('email', $this->argument('email'))->first();
        if (! $u) {
            $this->error('Аккаунт не найден.');

            return self::FAILURE;
        }
        $u->forceFill(['is_support_admin' => ! $this->option('revoke')])->save();
        $this->info('Доступ к поддержке обновлён.');

        return self::SUCCESS;
    }
}
