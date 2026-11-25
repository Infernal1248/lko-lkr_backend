<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class UseRussianApiLocale
{
    public function handle(Request $request, Closure $next): mixed
    {
        App::setLocale('ru');

        return $next($request);
    }
}
