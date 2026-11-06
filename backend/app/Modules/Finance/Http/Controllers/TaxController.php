<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Services\FinanceAccess;
use App\Modules\Finance\Services\Posting;
use App\Modules\Finance\Services\Tax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Tax (VAT / sales tax) settings. Changing them re-posts the fees and bills so the journal follows. */
class TaxController extends Controller
{
    public function __construct(private readonly Tax $tax) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless(FinanceAccess::views($request->user()), 403, __('finance.not_allowed'));
        $kinds = DB::table('student_fee_items')->where('kind', '!=', 'discount')->distinct()->orderBy('kind')->pluck('kind')->all();

        return response()->json(['data' => $this->tax->settings(), 'kinds' => array_values(array_unique([...$kinds, 'yearly', 'transport', 'other'])), 'can_manage' => FinanceAccess::manages($request->user())]);
    }

    public function save(Request $request): JsonResponse
    {
        abort_unless(FinanceAccess::manages($request->user()), 403, __('finance.not_allowed'));
        $d = $request->validate([
            'enabled' => ['required', 'boolean'], 'name' => ['required', 'string', 'max:30'], 'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'number' => ['nullable', 'string', 'max:40'], 'fee_kinds' => ['nullable', 'array', 'max:20'], 'fee_kinds.*' => ['string', 'max:30'],
        ]);
        $this->tax->save($d + ['number' => $d['number'] ?? '', 'fee_kinds' => $d['fee_kinds'] ?? []]);
        app(Posting::class)->rebuildAll();

        return response()->json(['data' => $this->tax->settings()]);
    }
}
