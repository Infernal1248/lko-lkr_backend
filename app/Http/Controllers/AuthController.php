<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\QueryException;
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
        $request->validate(['email' => 'required|string', 'username' => 'required|string'], $this->usernameMessages());
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email'))), 'username' => strtolower(trim((string) $request->input('username')))]);
        $d = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:254', 'username' => $this->usernameRules(), 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()], 'terms' => 'accepted', 'privacy' => 'accepted'], $this->usernameMessages());
        try {
            $u = User::create($d);
        } catch (QueryException $e) {
            $this->usernameConflict($e);
        }
        $u->forceFill(['privacy_accepted_at' => now(), 'privacy_version' => config('cards.privacy_version')])->save();
        Auth::login($u);
        $request->session()->regenerate();

        return response()->json($u, 201);
    }

    private function usernameMessages(): array
    {
        return [
            'username.required' => 'Укажите логин.',
            'username.min' => 'Логин должен содержать не менее 3 символов.',
            'username.max' => 'Логин должен содержать не более 40 символов.',
            'username.regex' => 'Начните логин с латинской буквы или цифры. Допустимы латинские буквы, цифры, точка, дефис и подчёркивание.',
            'username.unique' => 'Этот логин уже занят. Выберите другой.',
        ];
    }

    private function usernameRules(): array
    {
        return ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9][a-z0-9._-]{2,39}$/', 'unique:users,username'];
    }

    private function usernameConflict(QueryException $e): never
    {
        if ((string) $e->getCode() === '23000') {
            throw ValidationException::withMessages(['username' => 'Этот логин уже занят. Выберите другой.']);
        }
        throw $e;
    }

    private function identity(Request $request): ?User
    {
        $data = $request->validate(['identifier' => 'required|string|max:254']);
        $value = mb_strtolower(trim($data['identifier']));

        return User::where('username', $value)->first();
    }

    public function login(Request $request): mixed
    {
        $request->validate(['password' => 'required|string']);
        $user = $this->identity($request);
        if (! Auth::attempt(['id' => $user?->id ?? 0, 'password' => $request->input('password')])) {
            throw ValidationException::withMessages(['identifier' => 'Неверный логин или пароль.']);
        }
        $request->session()->regenerate();

        return $request->user();
    }

    public function chooseUsername(Request $request): mixed
    {
        $request->validate(['username' => 'required|string'], $this->usernameMessages());
        $request->merge(['username' => strtolower(trim((string) $request->input('username')))]);
        $data = $request->validate(['username' => $this->usernameRules()], $this->usernameMessages());
        try {
            return DB::transaction(function () use ($request, $data) {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_if($user->username !== null, 409, 'Логин уже задан.');
                $user->update(['username' => $data['username']]);

                return $user;
            });
        } catch (QueryException $e) {
            $this->usernameConflict($e);
        }
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
        $user = $this->identity($request);
        if ($user) {
            Password::sendResetLink(['id' => $user->id]);
        }

        return ['message' => 'Если аккаунт существует, инструкция отправлена на почту.'];
    }

    public function reset(Request $request): mixed
    {
        $d = $request->validate([
            'token' => 'required|string', 'account_id' => 'nullable|integer|min:1',
            'email' => 'nullable|email|max:254',
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
        ]);
        // Only old accounts have this immutable alias, so old email links cannot target a new account.
        $user = ! empty($d['account_id']) ? User::find($d['account_id'])
            : User::where('legacy_email', mb_strtolower(trim($d['email'] ?? '')))->first();
        $status = $user ? DB::transaction(function () use ($user, $d) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            return Password::reset(['id' => $user->id, 'token' => $d['token'], 'password' => $d['password']], function (User $u, string $password) {
                $u->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $u->id)->delete();
                PushSubscription::where('user_id', $u->id)->delete();
                event(new PasswordReset($u));
            });
        }, 3) : Password::InvalidToken;
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['token' => 'Ссылка недействительна или истекла.']);
        }

        return ['message' => 'Пароль обновлён. Войдите в аккаунт.'];
    }
}
