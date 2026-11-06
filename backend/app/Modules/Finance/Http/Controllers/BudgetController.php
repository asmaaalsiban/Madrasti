<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Services\FinanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The yearly budget per expense category and how much of it is already spent (bills dated inside the year). */
class BudgetController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless(FinanceAccess::views($request->user()), 403, __('finance.not_allowed'));
        $request->validate(['academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id']]);
        $year = $request->filled('academic_year_id') ? AcademicYear::findOrFail($request->integer('academic_year_id')) : (AcademicYear::where('is_current', true)->first() ?? AcademicYear::orderByDesc('starts_on')->first());
        if ($year === null) {
            return response()->json(['data' => ['year' => null, 'rows' => [], 'totals' => null], 'years' => [], 'currency' => $this->currency(), 'can_manage' => FinanceAccess::manages($request->user())]);
        }
        $budgets = Budget::where('academic_year_id', $year->id)->pluck('amount', 'expense_category_id');
        $spent = Expense::whereBetween('expense_date', [$year->starts_on->toDateString(), $year->ends_on->toDateString()])->select('expense_category_id', DB::raw('sum(amount) as total'))->groupBy('expense_category_id')->pluck('total', 'expense_category_id');
        $rows = [];
        $sumBudget = 0;
        $sumSpent = 0;
        foreach (ExpenseCategory::orderBy('id')->get() as $c) {
            $b = Money::cents($budgets[$c->id] ?? 0);
            $s = Money::cents($spent[$c->id] ?? 0);
            if (! $c->is_active && $b === 0 && $s === 0) {
                continue;
            }
            $sumBudget += $b;
            $sumSpent += $s;
            $rows[] = ['category_id' => $c->id, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en, 'budget' => Money::amount($b), 'spent' => Money::amount($s), 'remaining' => Money::amount($b - $s),
                'percent' => $b > 0 ? (int) round($s / $b * 100) : null, 'status' => $b === 0 ? ($s > 0 ? 'unplanned' : 'none') : ($s > $b ? 'over' : ($s >= $b * 0.9 ? 'near' : 'ok'))];
        }

        return response()->json([
            'data' => ['year' => ['id' => $year->id, 'name' => $year->name], 'rows' => $rows, 'totals' => ['budget' => Money::amount($sumBudget), 'spent' => Money::amount($sumSpent), 'remaining' => Money::amount($sumBudget - $sumSpent)]],
            'years' => AcademicYear::orderByDesc('starts_on')->get(['id', 'name']), 'can_manage' => FinanceAccess::manages($request->user()),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        abort_unless(FinanceAccess::manages($request->user()), 403, __('finance.not_allowed'));
        $d = $request->validate([
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.category_id' => ['required', 'integer', 'distinct', 'exists:expense_categories,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ]);
        DB::transaction(function () use ($d): void {
            foreach ($d['items'] as $i) {
                $row = Budget::firstOrNew(['academic_year_id' => $d['academic_year_id'], 'expense_category_id' => $i['category_id']]);
                $row->amount = $i['amount'];
                $row->save();
            }
        });

        return response()->json(['saved' => count($d['items'])]);
    }

    private function currency(): string
    {
        return (string) (DB::table('school_settings')->where('key', 'currency')->value('value') ?: 'JOD');
    }
}
