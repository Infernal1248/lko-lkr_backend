<?php

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('cards:demo {email} {--credits=10}', function () {
    if (! app()->environment('local', 'testing')) {
        $this->error('Доступно только локально.');

        return 1;
    }
    $user = User::where('email', $this->argument('email'))->firstOrFail();
    $amount = (int) $this->option('credits');
    if ($amount < 1 || $amount > 1000) {
        return 1;
    }
    DB::transaction(function () use ($user, $amount) {
        $u = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        $u->increment('credits', $amount);
        LedgerEntry::create(['user_id' => $u->id, 'amount' => $amount, 'balance' => $u->credits, 'reason' => 'demo', 'reference' => 'demo:'.Str::uuid(), 'created_at' => now()]);
    });
    $this->info('Тестовый баланс пополнен.');
})->purpose('Локальное пополнение для проверки генерации');
