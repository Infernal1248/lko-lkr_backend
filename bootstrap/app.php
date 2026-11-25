<?php

use App\Http\Middleware\CanonicalSite;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [CanonicalSite::class]);
        $middleware->validateCsrfTokens(except: ['api/payments/webhook', 'api/npd/receipts']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api', 'api/*') || $exception instanceof ValidationException || $exception instanceof HttpResponseException) {
                return null;
            }

            $status = 500;
            $headers = [];
            $message = '';
            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();
                $headers = $exception->getHeaders();
                $message = $exception->getMessage();
            } elseif ($exception instanceof AuthenticationException) {
                $status = 401;
            }

            if ($exception instanceof InvalidSignatureException) {
                $message = trans('http.invalid_signature', locale: 'ru');
            } elseif (! preg_match('/^(?:Push-|PDF )?\p{Cyrillic}/u', $message)) {
                $key = 'http.'.$status;
                $message = trans($key, locale: 'ru');
                if ($message === $key) {
                    $message = trans('http.default', locale: 'ru');
                }
            }

            return response()->json(['message' => $message], $status, $headers);
        });
    })->create();
