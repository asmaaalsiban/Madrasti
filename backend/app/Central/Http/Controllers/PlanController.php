<?php

declare(strict_types=1);

namespace App\Central\Http\Controllers;

use App\Central\Models\Plan;
use App\Central\Services\CentralAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Pricing plans. The Super Admin manages all of them; the public website only reads the active ones (see publicIndex).
 */
class PlanController extends Controller
{
    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name_ar' => ['required', 'string', 'max:100'],
            'name_en' => ['required', 'string', 'max:100'],
            'price_monthly' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'price_yearly' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'max_students' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'description_ar' => ['nullable', 'string', 'max:300'],
            'description_en' => ['nullable', 'string', 'max:300'],
            'features_ar' => ['required', 'array', 'min:1', 'max:15'],
            'features_ar.*' => ['required', 'string', 'max:120'],
            'features_en' => ['required', 'array', 'min:1', 'max:15'],
            'features_en.*' => ['required', 'string', 'max:120'],
            'is_popular' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Plan $plan): array
    {
        return $plan->only(['id', 'name_ar', 'name_en', 'price_monthly', 'price_yearly', 'currency', 'max_students', 'description_ar', 'description_en', 'features_ar', 'features_en', 'is_popular', 'is_active', 'sort_order']);
    }

    /** The website's plans: only the active ones, in the owner's order. No sign-in needed. */
    public function publicIndex(): JsonResponse
    {
        $plans = Plan::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['data' => $plans->map(fn (Plan $p) => $this->present($p))->values()]);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => Plan::orderBy('sort_order')->orderBy('id')->get()->map(fn (Plan $p) => $this->present($p))->values()]);
    }

    public function store(Request $request, CentralAudit $audit): JsonResponse
    {
        $plan = $this->save(new Plan, $request->validate($this->rules()));
        $audit->record('plan.created', null, ['plan_id' => $plan->id, 'name' => $plan->name_en]);

        return response()->json(['data' => $this->present($plan)], 201);
    }

    public function update(Request $request, Plan $plan, CentralAudit $audit): JsonResponse
    {
        $this->save($plan, $request->validate($this->rules()));
        $audit->record('plan.updated', null, ['plan_id' => $plan->id, 'name' => $plan->name_en]);

        return response()->json(['data' => $this->present($plan->fresh())]);
    }

    public function destroy(Plan $plan, CentralAudit $audit): Response
    {
        $audit->record('plan.deleted', null, ['plan_id' => $plan->id, 'name' => $plan->name_en]);
        $plan->delete();

        return response()->noContent();
    }

    /** Only one plan is the popular one: marking a plan popular clears the mark on the others. */
    private function save(Plan $plan, array $data): Plan
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($plan, $data) {
            if (! empty($data['is_popular'])) {
                Plan::where('id', '!=', $plan->id ?? 0)->update(['is_popular' => false]);
            }
            $plan->fill($data)->save();

            return $plan->refresh(); // so the database defaults (currency, is_active...) are in the answer
        });
    }
}
