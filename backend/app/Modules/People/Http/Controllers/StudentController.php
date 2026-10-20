<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\Academic\Http\Controllers\AcademicController;
use App\Modules\Academic\Models\Student;
use App\Modules\People\Actions\SaveStudent;
use App\Modules\People\Http\Requests\StudentRequest;
use App\Modules\People\Http\Resources\StudentResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentController extends AcademicController
{
    protected string $model = Student::class;

    protected string $resource = StudentResource::class;

    protected array $searchable = ['student_number', 'name_ar', 'name_en'];

    protected array $searchRelations = ['user' => ['email']];

    protected array $with = ['user', 'guardians', 'enrollments.classroom'];

    protected string $orderBy = 'student_number';

    public function store(StudentRequest $request, SaveStudent $save): JsonResponse
    {
        Gate::authorize('create', Student::class);

        return (new StudentResource($save->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(StudentRequest $request, SaveStudent $save, int|string $id): StudentResource
    {
        $student = Student::findOrFail($id);
        Gate::authorize('update', $student);

        return new StudentResource($save->update($student, $request->validated()));
    }

    protected function filter(Builder $query, Request $request): void
    {
        if ($request->filled('classroom_id')) {
            $query->whereHas('enrollments', fn (Builder $q) => $q->where('classroom_id', (int) $request->query('classroom_id')));
        }
    }

    /** The student's login account goes with the student: signed out everywhere and removed from the lists. */
    protected function deleting(Model $model): void
    {
        $model->user?->tokens()->delete();
        $model->user?->delete();
    }

    protected function resourceKey(): string
    {
        return 'student';
    }
}
