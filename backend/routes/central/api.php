<?php

declare(strict_types=1);

use App\Central\Http\Controllers\CentralAuthController;
use App\Central\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

/*
| Central API (Super Admin only). Registered per central domain in bootstrap/app.php
| with prefix "api/central".
*/

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
