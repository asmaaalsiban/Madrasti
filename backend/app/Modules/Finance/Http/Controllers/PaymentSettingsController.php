<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\Services\FinanceAccess;
use App\Shared\Enums\Role;
use App\Shared\Payments\PayTabsSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

/** The school's own PayTabs account: the principal and the accountant set it. The server key is write-only. */
class PaymentSettingsController extends Controller
{
    public function __construct(private readonly PayTabsSettings $settings) {}

    public function show(Request $request): JsonResponse
    {
        $this->allow($request);

        return response()->json(['data' => $this->settings->present() + ['callback_url' => url('/api/central/payments/webhook/'.tenant('id').'/paytabs')]]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->allow($request);
        $data = $request->validate([
            'profile_id' => ['required', 'string', 'max:40'], 'server_key' => ['nullable', 'string', 'max:200'],
            'base_url' => ['required', Rule::in(array_values(PayTabsSettings::REGIONS))], 'enabled' => ['required', 'boolean'],
        ]);
        $this->settings->save($data);

        return $this->show($request);
    }

    /** Asks PayTabs to open a 1.00 payment page with the saved keys: if it answers with a page, the keys are right (nothing is charged). */
    public function test(Request $request): JsonResponse
    {
        $this->allow($request);
        $own = $this->settings->own();
        if ($own === null) {
            return response()->json(['ok' => false, 'message' => __('finance.paytabs_not_saved')], 422);
        }
        $currency = (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD');
        try {
            $r = Http::withHeaders(['authorization' => $own['server_key']])->acceptJson()->asJson()->timeout(20)->post(rtrim($own['base_url'], '/').'/payment/request', [
                'profile_id' => $own['profile_id'], 'tran_type' => 'sale', 'tran_class' => 'ecom', 'cart_id' => 'test-'.uniqid(), 'cart_currency' => $currency,
                'cart_amount' => 1.0, 'cart_description' => 'Connection test', 'return' => url('/'), 'callback' => url('/api/central/payments/webhook/'.tenant('id').'/paytabs'),
            ]);
            $ok = $r->successful() && is_string($r->json('redirect_url'));

            return response()->json(['ok' => $ok, 'message' => $ok ? __('finance.paytabs_ok') : (string) ($r->json('message') ?? 'HTTP '.$r->status())]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => __('finance.gateway_unreachable')]);
        }
    }

    private function allow(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(Role::Admin) || FinanceAccess::manages($user), 403, __('finance.not_allowed'));
    }
}
