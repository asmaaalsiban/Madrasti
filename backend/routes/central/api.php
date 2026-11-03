<?php

declare(strict_types=1);

use App\Central\Http\Controllers\CentralAuthController;
use App\Central\Http\Controllers\PaymentWebhookController;
use App\Central\Http\Controllers\RegisterSchoolController;
use App\Central\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

/*
| Central API (Super Admin only). Registered per central domain in bootstrap/app.php
| with prefix "api/central".
*/

// Public self-service school registration (throttled, can be switched off).
Route::post('register', RegisterSchoolController::class)->middleware('throttle:register');

// The payment provider's callback: the school code is in the URL (a provider cannot send our X-School header); its signature is what is trusted.
Route::post('payments/webhook/{school}/{provider}', PaymentWebhookController::class)->middleware('throttle:120,1');

Route::prefix('auth')->group(function (): void {
    Route::post('login', [CentralAuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [CentralAuthController::class, 'logout']);
        Route::get('me', [CentralAuthController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', 'super-admin'])->group(function (): void {
    Route::apiResource('tenants', TenantController::class)->only(['index', 'store', 'update']);
});
