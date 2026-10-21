<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Modules\Academic\Http\Requests\TeachingAssignmentRequest;
use App\Modules\Academic\Http\Resources\TeachingAssignmentResource;
use App\Modules\Academic\Models\TeachingAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Who teaches which subject in which classroom (admin). */
class TeachingAssignmentController extends AcademicController
{
    protected string $model = TeachingAssignment::class;

    protected string $resource = TeachingAssignmentResource::class;

    protected array $with = ['classroom', 'subject', 'teacher'];

    protected array $searchRelations = ['classroom' => ['name'], 'teacher' => ['name'], 'subject' => ['name_ar', 'name_en']];

    protected string $orderBy = 'classroom_id';

    public function store(TeachingAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('create', TeachingAssignment::class);

        return (new TeachingAssignmentResource(TeachingAssignment::create($request->validated())->load($this->with)))->response()->setStatusCode(201);
    }

    public function update(TeachingAssignmentRequest $request, TeachingAssignment $teachingAssignment): TeachingAssignmentResource
    {
        Gate::authorize('update', $teachingAssignment);
        $teachingAssignment->update($request->validated());

        return new TeachingAssignmentResource($teachingAssignment->load($this->with));
    }

    protected function filter(Builder $query, Request $request): void
    {
        foreach (['classroom_id', 'subject_id', 'teacher_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, (int) $request->query($column));
            }
        }
    }

    protected function resourceKey(): string
    {
        return 'teaching_assignment';
    }
}
