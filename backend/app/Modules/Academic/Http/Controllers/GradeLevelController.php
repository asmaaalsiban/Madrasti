<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Modules\Academic\Http\Requests\GradeLevelRequest;
use App\Modules\Academic\Http\Resources\GradeLevelResource;
use App\Modules\Academic\Models\GradeLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class GradeLevelController extends AcademicController
{
    protected string $model = GradeLevel::class;

    protected string $resource = GradeLevelResource::class;

    protected array $searchable = ['name_ar', 'name_en'];

    protected string $orderBy = 'sort_order';

    public function store(GradeLevelRequest $request): JsonResponse
    {
        Gate::authorize('create', GradeLevel::class);

        return (new GradeLevelResource(GradeLevel::create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(GradeLevelRequest $request, GradeLevel $gradeLevel): GradeLevelResource
    {
        Gate::authorize('update', $gradeLevel);
        $gradeLevel->update($request->validated());

        return new GradeLevelResource($gradeLevel);
    }

    protected function guardDelete(Model $model): void
    {
        if ($model->classrooms()->exists()) {
            $this->inUse('grade_level');
        }
    }
}
