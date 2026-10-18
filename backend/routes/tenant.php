<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| School (tenant) API
|--------------------------------------------------------------------------
|
| The school is resolved from the request domain (e.g. alnoor.localhost).
| Every route inside routes/tenant/*.php runs against that school's database.
| Loaded by App\Providers\TenancyServiceProvider.
|
*/

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->prefix('api')->group(base_path('routes/tenant/api.php'));
