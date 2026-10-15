<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WelcomeBonus
{
    private static function emailHash(User $user): string
    {
        return hash('sha256', mb_strtolower(trim($user->email)));
    }

    public static function claimed(User $user): bool
    {
        return DB::table('welcome_bonus_emails')->where('email_hash', self::emailHash($user))->whereNotNull('claimed_at')->exists();
    }

    public static function claim(User $account): array
    {
        return DB::transaction(function () use ($account) {
            $hash = self::emailHash($account);
            DB::table('welcome_bonus_emails')->insertOrIgnore(['email_hash' => $hash]);
            $email = DB::table('welcome_bonus_emails')->where('email_hash', $hash)->lockForUpdate()->first();
            $user = User::whereKey($account->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::emailHash($user) === $hash, 409, 'Почта изменилась. Обновите страницу.');
            $credited = false;
            if ($email->claimed_at === null) {
                abort_unless($user->email_verified_at !== null, 422, 'Сначала подтвердите электронную почту.');
                abort_if($user->credits > 4294967295 - 3, 422, 'Достигнут предел баланса аккаунта.');
                $user->increment('credits', 3);
                LedgerEntry::create([
                    'user_id' => $user->id, 'amount' => 3, 'balance' => $user->credits,
                    'reason' => 'welcome_bonus', 'reference' => 'welcome:'.$user->id, 'created_at' => now(),
                ]);
                DB::table('welcome_bonus_emails')->where('email_hash', $hash)->update(['claimed_at' => now()]);
                $credited = true;
            }

            return ['credits' => $user->credits, 'welcome_bonus_claimed' => true, 'credited' => $credited];
        }, 3);
    }
}
