<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Http\Requests\PeriodSetupRequest;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Services\PeriodSetup;
use Illuminate\Http\JsonResponse;
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

    private function state(PeriodSetup $setup): JsonResponse
    {
        return response()->json(['data' => [
            'setup' => $setup->current(),
            'periods' => Period::orderBy('day_of_week')->orderBy('number')->get()->map(fn (Period $p) => [
                'id' => $p->id, 'day_of_week' => $p->day_of_week, 'number' => $p->number,
                'starts_at' => substr($p->starts_at, 0, 5), 'ends_at' => substr($p->ends_at, 0, 5),
            ])->values(),
        ]]);
    }
}
