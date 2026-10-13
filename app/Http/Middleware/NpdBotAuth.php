<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class NpdBotAuth
{
    public function handle(Request $request, Closure $next): mixed
    {
        $secret = (string) config('npd.secret');
        abort_unless(strlen($secret) >= 32 && hash_equals($secret, (string) $request->bearerToken()), 401, 'Недействительный ключ бота.');

        return $next($request);
    }
}
