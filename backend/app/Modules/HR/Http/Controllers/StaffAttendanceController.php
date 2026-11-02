<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\HR\Models\StaffAttendance;
use App\Modules\HR\Services\CommitmentRules;
use App\Modules\HR\Services\CommitmentService;
use App\Modules\HR\Services\PayrollAccess;
use App\Modules\HR\Services\StaffAttendanceService;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** The staff's own check-in and check-out, the day sheet for management, and the commitment rules and scores. */
class StaffAttendanceController extends Controller
{
    public function __construct(private readonly StaffAttendanceService $attendance, private readonly CommitmentService $commitment, private readonly CommitmentRules $rules) {}

    /** My day: today's record and whether today is a working day. */
    public function me(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $today = now();

        return response()->json(['data' => [
            'workday' => $this->attendance->isWorkday($today), 'today' => $this->present(StaffAttendance::where('user_id', $user->id)->whereDate('date', $today->toDateString())->first()),
            'starts_at' => $this->attendance->workday()['starts_at'],
        ]]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->attendance->checkIn($this->user($request)))]);
    }

    public function checkOut(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->attendance->checkOut($this->user($request)))]);
    }

    /** Everyone's day for management. */
    public function day(Request $request): JsonResponse
    {
        abort_unless(PayrollAccess::views($this->user($request)), 403, __('payroll.not_allowed'));
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? now()->toDateString();
        $rows = StaffAttendance::whereDate('date', $date)->get()->keyBy('user_id');
        $staff = User::whereIn('role', [Role::Admin, Role::Teacher])->where('is_active', true)->whereHas('staffProfile')->with('staffProfile')->orderBy('name')->get();

        return response()->json(['date' => $date, 'workday' => $this->attendance->isWorkday(Carbon::parse($date)), 'data' => $staff->map(fn (User $u) => ['user' => ['id' => $u->id, 'name' => $u->name, 'staff_type' => $u->staffProfile?->staff_type->value]] + ($this->present($rows[$u->id] ?? null) ?? ['status' => null, 'check_in' => null, 'check_out' => null, 'note' => null]))->values()]);
    }

    /** Management fixes a day (an excused absence, a forgotten check-in). */
    public function set(Request $request, User $user): JsonResponse
    {
        abort_unless(PayrollAccess::approves($this->user($request)), 403, __('payroll.not_allowed'));
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'status' => ['required', Rule::in(StaffAttendance::STATUSES)],
            'check_in' => ['nullable', 'date_format:H:i'], 'check_out' => ['nullable', 'date_format:H:i', 'after:check_in'], 'note' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->present($this->attendance->record($user, $data['date'], $data))]);
    }

    public function rules(Request $request): JsonResponse
    {
        abort_unless(PayrollAccess::views($this->user($request)), 403, __('payroll.not_allowed'));

        return response()->json(['data' => $this->rules->all()]);
    }

    public function saveRules(Request $request): JsonResponse
    {
        abort_unless(PayrollAccess::approves($this->user($request)), 403, __('payroll.not_allowed'));
        $rule = fn () => ['sometimes', 'array'];
        $rules = ['grace_minutes' => ['sometimes', 'integer', 'min:0', 'max:120']];
        foreach (CommitmentRules::CODES as $code) {
            $rules[$code] = $rule();
            $rules["$code.enabled"] = ['boolean'];
            $rules["$code.bonus_min"] = ['integer', 'min:1', 'max:100'];
            $rules["$code.deduction_below"] = ['integer', 'min:1', 'max:100'];
            $rules["$code.bonus_type"] = [Rule::in(['percent', 'amount'])];
            $rules["$code.deduction_type"] = [Rule::in(['percent', 'amount'])];
            $rules["$code.deduction_tiers"] = ['sometimes', 'array', 'max:6'];
            $rules["$code.deduction_tiers.*.below"] = ['required', 'integer', 'min:1', 'max:100'];
            $rules["$code.deduction_tiers.*.type"] = ['required', Rule::in(['percent', 'amount'])];
            $rules["$code.deduction_tiers.*.value"] = ['required', 'numeric', 'min:0', 'max:100000'];
            $rules["$code.bonus_value"] = ['numeric', 'min:0', 'max:100000'];
            $rules["$code.deduction_value"] = ['numeric', 'min:0', 'max:100000'];
        }
        $rules['absence'] = ['sometimes', 'array'];
        $rules['absence.enabled'] = ['boolean'];
        $rules['absence.type'] = [Rule::in(['percent', 'amount'])];
        $rules['absence.value'] = ['numeric', 'min:0', 'max:100000'];
        $this->rules->save($request->validate($rules));

        return response()->json(['data' => $this->rules->all()]);
    }

    /** My commitment in a month: each score and what it would add to or take off the salary. */
    public function commitment(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'user_id' => ['nullable', 'integer']]);
        $target = isset($data['user_id']) && PayrollAccess::views($user) ? User::findOrFail($data['user_id']) : $user;
        $month = Carbon::createFromFormat('Y-m-d', ($data['month'] ?? now()->format('Y-m')).'-01')->startOfDay();
        $contract = $target->staffContracts()->where('status', 'active')->first();
        $base = $contract?->base_salary ?? '0';

        return response()->json(['month' => $month->format('Y-m'), 'scores' => $this->commitment->scores($target, $month), 'lines' => $this->commitment->lines($target, $month, $base), 'rules' => $this->rules->all()]);
    }

    /** @return array<string, mixed>|null */
    private function present(?StaffAttendance $a): ?array
    {
        return $a === null ? null : ['status' => $a->status, 'check_in' => $a->check_in ? substr((string) $a->check_in, 0, 5) : null, 'check_out' => $a->check_out ? substr((string) $a->check_out, 0, 5) : null, 'note' => $a->note];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
