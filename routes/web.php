<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminCreditController;
use App\Http\Controllers\AdminPackageController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CalibrationController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RegistryController;
use App\Http\Controllers\SupportController;
use App\Http\Middleware\PrivateResponse;
use App\Http\Middleware\RequireConsent;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->middleware(PrivateResponse::class)->group(function () {
    Route::get('/csrf', fn () => response()->json(['token' => csrf_token()]));
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:3,1');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:5,1');
    Route::post('/payments/webhook', [PaymentController::class, 'webhook'])->middleware('throttle:120,1');
    Route::get('/templates', [AccountController::class, 'templates']);
    Route::get('/public-settings', fn () => ['operator_name' => config('cards.operator_name'), 'operator_inn' => config('cards.operator_inn'), 'operator_address' => config('cards.operator_address'), 'privacy_email' => config('cards.privacy_email'), 'privacy_version' => config('cards.privacy_version')]);
    Route::get('/packages', [PaymentController::class, 'packages']);
    Route::middleware('auth')->group(function () {
        Route::get('/admin/packages', [AdminPackageController::class, 'index']);
        Route::post('/admin/packages', [AdminPackageController::class, 'save']);
        Route::put('/admin/packages/{key}', [AdminPackageController::class, 'save']);
        Route::get('/admin/accounts', [AdminCreditController::class, 'index']);
        Route::post('/admin/credits', [AdminCreditController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/push/settings', [PushController::class, 'settings']);
        Route::post('/push/subscriptions', [PushController::class, 'store'])->middleware('throttle:20,1');
        Route::delete('/push/subscriptions', [PushController::class, 'destroy']);
        Route::post('/push/test', [PushController::class, 'test'])->middleware('throttle:3,1');
        Route::get('/receipts', [ReceiptController::class, 'index']);
        Route::put('/receipts/{id}', [ReceiptController::class, 'update']);
        Route::get('/support', [SupportController::class, 'index']);
        Route::post('/support', [SupportController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/support/{id}', [SupportController::class, 'show']);
        Route::post('/support/{id}/messages', [SupportController::class, 'reply'])->middleware('throttle:20,1');
        Route::post('/support/{id}/close', [SupportController::class, 'close']);
        Route::get('/me', [AccountController::class, 'me']);
        Route::post('/onboarding/seen', [AccountController::class, 'onboarding']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/consent', [AccountController::class, 'consent']);
        Route::get('/ledger', [AccountController::class, 'ledger']);
        Route::get('/calibration/layout', [CalibrationController::class, 'layout']);
        Route::get('/calibration/background', [CalibrationController::class, 'background']);
        Route::get('/calibration/font', [CalibrationController::class, 'font']);
        Route::post('/calibration/apply', [CalibrationController::class, 'apply']);
        Route::get('/profiles', [AccountController::class, 'profiles']);
        Route::post('/profiles', [AccountController::class, 'saveProfile']);
        Route::delete('/profiles/{id}', [AccountController::class, 'deleteProfile']);
        Route::post('/calibration/pdf', [GenerationController::class, 'test'])->middleware('throttle:20,1');
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::post('/payments', [PaymentController::class, 'store'])->middleware('throttle:10,1');
        Route::post('/payments/{id}/refresh', [PaymentController::class, 'refresh'])->middleware('throttle:10,1');
        Route::middleware(RequireConsent::class)->group(function () {
            Route::put('/organization', [AccountController::class, 'organization']);
            Route::get('/cards/import-example', [CardController::class, 'sample']);
            Route::post('/cards/import', [CardController::class, 'import'])->middleware('throttle:5,1');
            Route::get('/cards', [CardController::class, 'index']);
            Route::post('/cards', [CardController::class, 'store']);
            Route::put('/cards/{id}', [CardController::class, 'update']);
            Route::delete('/cards/{id}', [CardController::class, 'destroy']);
            Route::post('/preview', [GenerationController::class, 'preview'])->middleware('throttle:180,1');
            Route::post('/generations', [GenerationController::class, 'store'])->middleware('throttle:20,1');
            Route::get('/registry/export/{format}', [RegistryController::class, 'export'])->middleware('throttle:5,1');
            Route::put('/generations/{id}/registry', [RegistryController::class, 'update']);
            Route::get('/generations', [GenerationController::class, 'index']);
            Route::get('/generations/{id}/download', [GenerationController::class, 'download']);
            Route::put('/generations/{id}/number', [GenerationController::class, 'assignNumber']);
            Route::delete('/generations/{id}', [GenerationController::class, 'destroy']);
        });
    });
});
Route::get('/push-sw.js', function () {
    $file = public_path('app/push-sw.js');
    abort_unless(is_file($file), 404);

    return response()->file($file, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-cache', 'Service-Worker-Allowed' => '/']);
});
Route::get('/{path?}', function () {
    $file = public_path('app/index.html');
    abort_unless(is_file($file), 503, 'Соберите Vue-приложение: npm run build в frontend.');

    return response()->file($file, ['Cache-Control' => 'no-cache']);
})->where('path', '^(?!api(?:/|$)).*');
