<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Modules\Academic\Http\Requests\ClassroomRequest;
use App\Modules\Academic\Http\Resources\ClassroomResource;
use App\Modules\Academic\Models\Classroom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClassroomController extends AcademicController
{
    protected string $model = Classroom::class;

    protected string $resource = ClassroomResource::class;

    protected array $searchable = ['name'];

    protected array $with = ['gradeLevel', 'academicYear', 'homeroomTeacher'];

    protected string $orderBy = 'name';

    public function store(ClassroomRequest $request): JsonResponse
    {
        Gate::authorize('create', Classroom::class);
        $classroom = Classroom::create($request->validated());

        return (new ClassroomResource($classroom->load($this->with)))->response()->setStatusCode(201);
    }

    public function update(ClassroomRequest $request, Classroom $classroom): ClassroomResource
    {
        Gate::authorize('update', $classroom);
        $classroom->update($request->validated());

        return new ClassroomResource($classroom->load($this->with));
    }

    protected function filter(Builder $query, Request $request): void
    {
        foreach (['grade_level_id', 'academic_year_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, (int) $request->query($column));
            }
        }
    }

    protected function guardDelete(Model $model): void
    {
        if (DB::table('enrollments')->where('classroom_id', $model->id)->exists()) {
            $this->inUse('classroom');
        }
    }
}
