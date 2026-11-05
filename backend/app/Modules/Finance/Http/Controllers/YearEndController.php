<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Finance\Models\FeeCarryover;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Services\FinanceAccess;
use App\Modules\Finance\Services\YearEndCarryOver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Year-end: see who still owes for a year, carry it to the next year, undo a move. The accountant does it; the principal can read. */
class YearEndController extends Controller
{
    public function __construct(private readonly YearEndCarryOver $carry) {}

    /** The years, and for the chosen one the students who still owe (with the total) and the moves made so far. */
    public function show(Request $request): JsonResponse
    {
        abort_unless(FinanceAccess::views($request->user()), 403, __('finance.not_allowed'));
        $years = AcademicYear::orderByDesc('starts_on')->get(['id', 'name', 'starts_on', 'ends_on', 'is_current']);
        $from = $request->filled('from') ? $years->firstWhere('id', (int) $request->query('from')) : ($years->firstWhere('is_current', true) ?? $years->first());
        $rows = $from ? $this->carry->preview($from) : collect();

        return response()->json(['data' => [
            'years' => $years->map(fn ($y) => ['id' => $y->id, 'name' => $y->name, 'starts_on' => $y->starts_on->format('Y-m-d'), 'is_current' => $y->is_current])->values(),
            'from_year_id' => $from?->id,
            'students' => $rows->values(),
            'total' => Money::amount((int) $rows->sum(fn ($r) => Money::cents($r['remaining']))),
            'moves' => FeeCarryover::with(['student:id,name_ar,name_en,student_number', 'fromYear:id,name', 'toYear:id,name', 'newFee.payments'])->latest('id')->limit(100)->get()->map(fn (FeeCarryover $m) => [
                'id' => $m->id, 'student' => $m->student?->only(['id', 'name_ar', 'name_en', 'student_number']), 'from' => $m->fromYear?->name, 'to' => $m->toYear?->name, 'amount' => $m->amount,
                'date' => $m->created_at?->format('Y-m-d'), 'reverted' => $m->reverted_at !== null, 'can_undo' => $m->reverted_at === null && ! ($m->newFee?->payments->isNotEmpty() ?? false),
            ])->values(),
            'currency' => (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD'),
            'can_manage' => FinanceAccess::manages($request->user()),
        ]]);
    }

    public function run(Request $request): JsonResponse
    {
        abort_unless(FinanceAccess::manages($request->user()), 403, __('finance.not_allowed'));
        $data = $request->validate([
            'from_year_id' => ['required', 'integer', 'exists:academic_years,id'], 'to_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'first_due' => ['required', 'date_format:Y-m-d'], 'installments' => ['required', 'integer', 'min:1', 'max:12'],
            'student_ids' => ['nullable', 'array', 'max:2000'], 'student_ids.*' => ['integer'],
        ]);
        $result = $this->carry->run(AcademicYear::findOrFail($data['from_year_id']), AcademicYear::findOrFail($data['to_year_id']), $data['first_due'], (int) $data['installments'], $data['student_ids'] ?? null, $request->user());

        return response()->json(['data' => $result]);
    }

    public function undo(Request $request, FeeCarryover $move): JsonResponse
    {
        abort_unless(FinanceAccess::manages($request->user()), 403, __('finance.not_allowed'));
        $this->carry->revert($move, $request->user());

        return response()->json(['data' => ['id' => $move->id]]);
    }
}
