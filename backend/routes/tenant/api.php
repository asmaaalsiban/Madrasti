<?php

declare(strict_types=1);

use App\Modules\Academic\Http\Controllers\SchoolBrandingController;
use App\Modules\Auth\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
| School API. Prefix "api" and tenancy middleware come from routes/tenant.php.
| Add one block per module below, protected with auth:sanctum and role:...
*/

// Public: school name, logo and colors for the login screen.
Route::get('school', SchoolBrandingController::class);

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});
