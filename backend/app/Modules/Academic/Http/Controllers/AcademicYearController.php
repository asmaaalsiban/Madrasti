<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Modules\Academic\Http\Requests\AcademicYearRequest;
use App\Modules\Academic\Http\Resources\AcademicYearResource;
use App\Modules\Academic\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AcademicYearController extends AcademicController
{
    protected string $model = AcademicYear::class;

    protected array $referencedBy = ['classrooms' => 'academic_year_id', 'terms' => 'academic_year_id', 'enrollments' => 'academic_year_id', 'schedule_generations' => 'academic_year_id', 'timetable_entries' => 'academic_year_id', 'fee_structures' => 'academic_year_id', 'student_fees' => 'academic_year_id'];

    protected string $resource = AcademicYearResource::class;

    protected array $searchable = ['name'];

    protected string $orderBy = 'starts_on';

    protected string $orderDirection = 'desc';

    public function store(AcademicYearRequest $request): JsonResponse
    {
        Gate::authorize('create', AcademicYear::class);

        $year = DB::transaction(function () use ($request): AcademicYear {
            $this->unsetOtherCurrent($request, null);

            return AcademicYear::create($request->validated());
        });

        return (new AcademicYearResource($year))->response()->setStatusCode(201);
    }

    public function update(AcademicYearRequest $request, AcademicYear $academicYear): AcademicYearResource
    {
        Gate::authorize('update', $academicYear);

        DB::transaction(function () use ($request, $academicYear): void {
            $this->unsetOtherCurrent($request, $academicYear->id);
            $academicYear->update($request->validated());
        });

        return new AcademicYearResource($academicYear);
    }

    /** Only one year may be current at a time. */
    private function unsetOtherCurrent(AcademicYearRequest $request, ?int $exceptId): void
    {
        if ($request->boolean('is_current')) {
            AcademicYear::where('is_current', true)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->get()
                ->each->update(['is_current' => false]);
        }
    }

    protected function resourceKey(): string
    {
        return 'academic_year';
    }
}
