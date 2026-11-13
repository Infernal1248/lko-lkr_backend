<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CanonicalSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $base = rtrim(config('site.url'), '/');
        $host = parse_url($base, PHP_URL_HOST);
        // Do not redirect API calls/webhooks, or unrelated local/preview hosts.
        if ($request->isMethodSafe() && ! $request->is('api', 'api/*')
            && in_array($request->getHost(), [$host, 'www.'.$host], true)
            && $request->getSchemeAndHttpHost() !== $base) {
            return redirect($base.$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
