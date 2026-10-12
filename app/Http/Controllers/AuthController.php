<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): mixed
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $d = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:254|unique:users,email', 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()], 'terms' => 'accepted', 'privacy' => 'accepted']);
        $u = User::create($d);
        $u->forceFill(['privacy_accepted_at' => now(), 'privacy_version' => config('cards.privacy_version')])->save();
        Auth::login($u);
        $request->session()->regenerate();

        return response()->json($u, 201);
    }

    public function login(Request $request): mixed
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $d = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($d)) {
            throw ValidationException::withMessages(['email' => 'Неверная почта или пароль.']);
        }
        $request->session()->regenerate();

        return $request->user();
    }

    public function logout(Request $request): mixed
    {
        $endpoint = $request->input('push_endpoint');
        $hash = is_string($endpoint) && strlen($endpoint) <= 2048 ? hash('sha256', $endpoint) : $request->session()->get('push_endpoint_hash');
        if ($hash) {
            PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', $hash)->delete();
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function forgot(Request $request): mixed
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        return ['message' => 'Если аккаунт существует, инструкция отправлена на почту.'];
    }

    public function reset(Request $request): mixed
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $d = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()]]);
        $status = Password::reset($d, function (User $u, string $password) {
            $u->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $u->id)->delete();
            PushSubscription::where('user_id', $u->id)->delete();
            event(new PasswordReset($u));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => 'Ссылка недействительна или истекла.']);
        }

        return ['message' => 'Пароль обновлён. Войдите в аккаунт.'];
    }
}
