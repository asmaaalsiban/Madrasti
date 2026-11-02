<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Services\PayrollAccess;
use App\Modules\HR\Services\PayrollService;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Staff contracts: the accountant writes, renews (with the annual raise) and ends them; the management reads. */
class ContractController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    /** Every member of staff with their current contract and how soon it ends. */
    public function staff(Request $request): JsonResponse
    {
        $this->view($request);
        $contracts = StaffContract::with('items')->orderByDesc('starts_on')->orderByDesc('id')->get()->groupBy('user_id');
        $rows = User::whereIn('role', [Role::Admin, Role::Teacher])->where('is_active', true)->whereHas('staffProfile')->with('staffProfile.subject:id,name_ar,name_en')->orderBy('name')->get()->map(function (User $u) use ($contracts): array {
            $mine = $contracts->get($u->id, collect());
            $current = $mine->firstWhere('status', 'active') ?? $mine->first();

            return [
                'id' => $u->id, 'name' => $u->name, 'staff_type' => $u->staffProfile->staff_type->value, 'subject' => $u->staffProfile->subject?->only(['name_ar', 'name_en']),
                'contract' => $current ? $this->present($current) : null, 'contracts_count' => $mine->count(),
            ];
        });

        return response()->json(['data' => $rows->values(), 'currency' => $this->currency()]);
    }

    /** Contracts that end within `days` days (default 60), soonest first. */
    public function expiring(Request $request): JsonResponse
    {
        $this->view($request);
        $days = min(max((int) $request->query('days', 60), 1), 365);
        $rows = StaffContract::with(['user:id,name', 'items'])->where('status', 'active')->whereNotNull('ends_on')->whereDate('ends_on', '<=', now()->addDays($days))->orderBy('ends_on')->get();

        return response()->json(['data' => $rows->map(fn (StaffContract $c) => $this->present($c) + ['name' => $c->user->name])->values(), 'currency' => $this->currency()]);
    }

    /** All the contracts of one person: the history with the raises. */
    public function history(Request $request, User $user): JsonResponse
    {
        $this->view($request);

        return response()->json(['data' => StaffContract::with('items')->where('user_id', $user->id)->orderByDesc('starts_on')->orderByDesc('id')->get()->map(fn ($c) => $this->present($c))->values(), 'currency' => $this->currency()]);
    }

    public function store(Request $request): JsonResponse
    {
        $by = $this->manage($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after:starts_on'],
            'base_salary' => ['required', 'numeric', 'gt:0', 'max:1000000'], 'notes' => ['nullable', 'string', 'max:2000'],
        ] + $this->paymentRules() + $this->itemRules());
        $staff = User::findOrFail($data['user_id']);
        $this->assertStaff($staff);

        $contract = $this->payroll->createContract($staff, $data, $data['items'] ?? [], $by);

        return response()->json(['data' => $this->present($contract->load('items'))], 201);
    }

    /** Corrects a running contract (its dates, salary, allowances, notes). A raise belongs in a renewal, which keeps the history. */
    public function update(Request $request, StaffContract $contract): JsonResponse
    {
        $this->manage($request);
        if ($contract->status !== 'active') {
            throw ValidationException::withMessages(['contract' => [__('payroll.not_active')]]);
        }
        $data = $request->validate([
            'starts_on' => ['sometimes', 'date_format:Y-m-d'], 'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'base_salary' => ['sometimes', 'numeric', 'gt:0', 'max:1000000'], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ] + $this->paymentRules() + $this->itemRules());
        $starts = $data['starts_on'] ?? $contract->starts_on->format('Y-m-d');
        $ends = array_key_exists('ends_on', $data) ? $data['ends_on'] : $contract->ends_on?->format('Y-m-d');
        if ($ends !== null && $ends <= $starts) {
            throw ValidationException::withMessages(['ends_on' => [__('payroll.dates_order')]]);
        }

        $contract->update(collect($data)->except('items')->all());
        if (array_key_exists('items', $data)) {
            $this->payroll->writeItems($contract, $data['items'] ?? []);
        }

        return response()->json(['data' => $this->present($contract->refresh()->load('items'))]);
    }

    /** Renews with the annual raise: a percent, a fixed amount, or the new salary. The old contract stays as "renewed". */
    public function renew(Request $request, StaffContract $contract): JsonResponse
    {
        $by = $this->manage($request);
        $data = $request->validate([
            'starts_on' => ['nullable', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'raise_type' => ['required', Rule::in(['none', 'percent', 'amount', 'salary'])],
            'raise_value' => ['required_unless:raise_type,none', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'carry_items' => ['sometimes', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ] + $this->paymentRules());

        $new = $this->payroll->renew($contract, $data, $by);

        return response()->json(['data' => $this->present($new->load('items'))], 201);
    }

    public function end(Request $request, StaffContract $contract): JsonResponse
    {
        $this->manage($request);
        $data = $request->validate(['ends_on' => ['nullable', 'date_format:Y-m-d']]);
        $this->payroll->endContract($contract, $data['ends_on'] ?? null);

        return response()->json(['data' => $this->present($contract->refresh()->load('items'))]);
    }

    /** @return array<string, mixed> */
    private function itemRules(): array
    {
        return [
            'items' => ['sometimes', 'nullable', 'array', 'max:20'], 'items.*.kind' => ['required', Rule::in(['allowance', 'deduction'])],
            'items.*.label' => ['required', 'string', 'max:120'], 'items.*.amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }

    private function assertStaff(User $user): void
    {
        if (! $user->is_active || ! $user->hasRole(Role::Admin, Role::Teacher) || $user->staffProfile === null) {
            throw ValidationException::withMessages(['user_id' => [__('payroll.not_staff')]]);
        }
    }

    /** @return array<string, mixed> */
    private function paymentRules(): array
    {
        return ['payment_method' => ['sometimes', 'nullable', Rule::in(PayrollController::METHODS)], 'payment_details' => ['sometimes', 'nullable', 'string', 'max:255']];
    }

    /** @return array<string, mixed> */
    private function present(StaffContract $c): array
    {
        $left = $c->ends_on ? (int) now()->startOfDay()->diffInDays($c->ends_on->startOfDay(), false) : null;

        return [
            'id' => $c->id, 'user_id' => $c->user_id, 'starts_on' => $c->starts_on->format('Y-m-d'), 'ends_on' => $c->ends_on?->format('Y-m-d'), 'base_salary' => $c->base_salary,
            'status' => $c->status, 'days_left' => $c->status === 'active' ? $left : null, 'renewed_from_id' => $c->renewed_from_id,
            'raise_amount' => $c->raise_amount, 'raise_percent' => $c->raise_percent, 'notes' => $c->notes, 'payment_method' => $c->payment_method, 'payment_details' => $c->payment_details,
            'items' => $c->items->map(fn ($i) => ['id' => $i->id, 'kind' => $i->kind, 'label' => $i->label, 'amount' => $i->amount])->values(),
        ];
    }

    private function currency(): string
    {
        return (string) (\Illuminate\Support\Facades\DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD');
    }

    private function view(Request $request): User
    {
        $user = $this->user($request);
        abort_unless(PayrollAccess::views($user), 403, __('payroll.not_allowed'));

        return $user;
    }

    private function manage(Request $request): User
    {
        $user = $this->user($request);
        abort_unless(PayrollAccess::manages($user), 403, __('payroll.not_allowed'));

        return $user;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
