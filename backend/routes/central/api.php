<?php

declare(strict_types=1);

use App\Central\Http\Controllers\CentralAuthController;
use App\Central\Http\Controllers\ExitWindowController;
use App\Central\Http\Controllers\PaymentWebhookController;
use App\Central\Http\Controllers\PlanController;
use App\Central\Http\Controllers\RegisterSchoolController;
use App\Central\Http\Controllers\SchoolActionController;
use App\Central\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

/*
| Central API (Super Admin only). Registered per central domain in bootstrap/app.php
| with prefix "api/central".
*/

// Public self-service school registration (throttled, can be switched off).
Route::post('register', RegisterSchoolController::class)->middleware('throttle:register');
// Is public registration on? The website hides its "register a school" links while it is off.
Route::get('registration', fn () => response()->json(['enabled' => (bool) config('tenancy.registration_enabled')]));

// The pricing plans shown on the website (only the active ones; the Super Admin manages them below).
Route::get('plans', [PlanController::class, 'publicIndex'])->middleware('throttle:60,1');

// The payment provider's callback: the school code is in the URL (a provider cannot send our X-School header); its signature is what is trusted.
Route::post('payments/webhook/{school}/{provider}', PaymentWebhookController::class)->middleware('throttle:120,1');

Route::prefix('auth')->group(function (): void {
    Route::post('login', [CentralAuthController::class, 'login'])->middleware('throttle:login');
    Route::post('verify', [CentralAuthController::class, 'verify'])->middleware('throttle:central-code');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [CentralAuthController::class, 'logout']);
        Route::get('me', [CentralAuthController::class, 'me']);
    });
});

Route::middleware(['auth:sanctum', 'super-admin'])->group(function (): void {
    Route::get('admin/plans', [PlanController::class, 'index']);
    Route::post('admin/plans', [PlanController::class, 'store']);
    Route::put('admin/plans/{plan}', [PlanController::class, 'update']);
    Route::delete('admin/plans/{plan}', [PlanController::class, 'destroy']);
    Route::apiResource('tenants', TenantController::class)->only(['index', 'show', 'store', 'update']);
    Route::post('tenants/{tenant}/suspend', [SchoolActionController::class, 'suspend']);
    Route::post('tenants/{tenant}/reactivate', [SchoolActionController::class, 'reactivate']);
    Route::post('tenants/{tenant}/extend', [SchoolActionController::class, 'extend']);
    Route::get('tenants/{tenant}/audit', [SchoolActionController::class, 'audit']);
    Route::post('tenants/{tenant}/exit-window', [ExitWindowController::class, 'open']);
    Route::patch('tenants/{tenant}/exit-window', [ExitWindowController::class, 'extend']);
    Route::get('tenants/{tenant}/exit-steps', [ExitWindowController::class, 'steps']);
    Route::post('tenants/{tenant}/exit-steps/{step}/retry', [ExitWindowController::class, 'retry']);
    Route::get('tenants/{tenant}/package', [ExitWindowController::class, 'package']);
    Route::get('tenants/{tenant}/archive', [ExitWindowController::class, 'archive']);
});
