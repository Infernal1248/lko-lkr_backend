<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\VerifyBonusEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BonusEmailController extends Controller
{
    public function send(Request $request): array
    {
        $user = $request->user();
        if ($user->email_verified_at === null) {
            $user->notify(new VerifyBonusEmail);
        }

        return ['message' => $user->email_verified_at ? 'Почта уже подтверждена.' : 'Письмо отправлено. Откройте ссылку, затем вернитесь в кабинет.'];
    }

    public function verify(int $id, string $hash): mixed
    {
        DB::transaction(function () use ($id, $hash) {
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals(sha1($user->email), $hash), 403);
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }
        });

        return response()->view('mail.email-verified');
    }
}
