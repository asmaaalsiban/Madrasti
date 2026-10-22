<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Scheduling\Http\Requests\PeriodSetupRequest;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Services\PeriodSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The bells of the school week (admin). */
class PeriodController extends Controller
{
    public function index(PeriodSetup $setup): JsonResponse
    {
        Gate::authorize('viewAny', Period::class);

        return $this->state($setup);
    }

    public function setup(PeriodSetupRequest $request, PeriodSetup $setup): JsonResponse
    {
        Gate::authorize('update', new Period);
        $setup->apply($request->validated());

        return $this->state($setup);
    }

    /** The periods a day of each grade (empty = the whole school day): the lower grades usually have fewer. */
    public function grades(Request $request, PeriodSetup $setup): JsonResponse
    {
        Gate::authorize('update', new Period);
        $max = (int) $setup->current()['periods_per_day'];
        $data = $request->validate([
            'grades' => ['required', 'array', 'min:1'],
            'grades.*.grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'],
            'grades.*.periods_per_day' => ['nullable', 'integer', 'between:1,'.$max],
        ]);

        foreach ($data['grades'] as $row) {
            GradeLevel::findOrFail($row['grade_level_id'])->update(['periods_per_day' => $row['periods_per_day'] ?? null]);
        }

        return $this->state($setup);
    }

    private function state(PeriodSetup $setup): JsonResponse
    {
        return response()->json(['data' => [
            'setup' => $setup->current(),
            'grades' => GradeLevel::orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en', 'code', 'sort_order', 'periods_per_day']),
            'periods' => Period::orderBy('day_of_week')->orderBy('number')->get()->map(fn (Period $p) => [
                'id' => $p->id, 'day_of_week' => $p->day_of_week, 'number' => $p->number,
                'starts_at' => substr($p->starts_at, 0, 5), 'ends_at' => substr($p->ends_at, 0, 5),
            ])->values(),
        ]]);
    }
}
