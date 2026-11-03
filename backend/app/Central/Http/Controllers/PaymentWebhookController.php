<?php

declare(strict_types=1);

namespace App\Central\Http\Controllers;

use App\Central\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Controllers\OnlinePaymentController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A payment provider cannot send our X-School header, so its callback comes to a URL that carries the school code:
 * POST /api/central/payments/webhook/{school}/{provider}. The code is not a secret; the provider's signature is what is trusted.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $school, string $provider): JsonResponse
    {
        $tenant = Tenant::find($school);
        abort_if($tenant === null || ! $tenant->is_active, 404);
        tenancy()->initialize($tenant);
        try {
            return app(OnlinePaymentController::class)->webhook($request, $provider);
        } finally {
            tenancy()->end();
        }
    }
}
