<?php

namespace App\Http\Middleware;

use App\Models\Consent;
use Closure;
use Illuminate\Http\Request;

class RequireConsent
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless(Consent::where('user_id', $r->user()->id)->where('version', config('cards.consent_version'))->exists(), 403, 'Подтвердите полномочия на обработку персональных данных.');

        return $next($r);
    }
}
