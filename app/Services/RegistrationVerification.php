<?php

namespace App\Services;

use App\Notifications\RegistrationCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class RegistrationVerification
{
    public function send(Request $request): array
    {
        abort_if($request->user(), 409, 'Сначала выйдите из текущего аккаунта.');
        $request->validate(['email' => 'required|string|email|max:254', 'captcha_token' => 'nullable|string|max:8192']);
        $email = mb_strtolower(trim($request->input('email')));
        if (config('registration.captcha_enabled')) {
            abort_unless(config('registration.site_key') && config('registration.secret_key'), 503, 'Регистрация временно недоступна. Попробуйте позже.');
            if (! $request->filled('captcha_token')) {
                throw ValidationException::withMessages(['captcha_token' => 'Пройдите проверку «Я не робот».']);
            }
            try {
                $response = Http::asForm()->connectTimeout(3)->timeout(8)->post('https://smartcaptcha.cloud.yandex.ru/validate', [
                    'secret' => config('registration.secret_key'), 'token' => $request->input('captcha_token'), 'ip' => $request->ip(),
                ]);
            } catch (ConnectionException) {
                abort(503, 'Проверка «Я не робот» временно недоступна. Попробуйте ещё раз.');
            }
            abort_unless($response->successful(), 503, 'Проверка «Я не робот» временно недоступна. Попробуйте ещё раз.');
            $rawHost = (string) $response->json('host');
            $host = $rawHost !== '' ? strtolower(idn_to_ascii($rawHost) ?: $rawHost) : '';
            $expected = strtolower(idn_to_ascii((string) config('registration.host')) ?: (string) config('registration.host'));
            if ($response->json('status') !== 'ok' || $host === '' || $host !== $expected) {
                throw ValidationException::withMessages(['captcha_token' => 'Проверка не пройдена. Пройдите её заново.']);
            }
        }
        $code = (string) random_int(100000, 999999);
        Notification::route('mail', $email)->notify(new RegistrationCode($code));
        $request->session()->put('registration_verification', [
            'email' => $email, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)->timestamp, 'attempts' => 0,
        ]);

        return ['message' => 'Код отправлен на почту. Он действует 10 минут.'];
    }

    public function check(Request $request, string $email): void
    {
        $request->validate(['email_code' => ['required', 'string', 'regex:/^[0-9]{6}$/']], ['email_code.required' => 'Подтвердите почту кодом из письма.']);
        $challenge = $request->session()->get('registration_verification');
        if (! $challenge || $challenge['email'] !== $email || $challenge['expires_at'] <= now()->timestamp || $challenge['attempts'] >= 5) {
            throw ValidationException::withMessages(['email_code' => 'Код недействителен или истёк. Запросите новый.']);
        }
        $request->session()->increment('registration_verification.attempts');
        if (! Hash::check($request->input('email_code'), $challenge['code_hash'])) {
            throw ValidationException::withMessages(['email_code' => 'Неверный код подтверждения.']);
        }
    }
}
