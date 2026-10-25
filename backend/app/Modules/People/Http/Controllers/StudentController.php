<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\Academic\Http\Controllers\AcademicController;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Services\AccountReader;
use App\Modules\People\Actions\SaveStudent;
use App\Modules\People\Http\Requests\StudentRequest;
use App\Modules\People\Http\Resources\StudentResource;
use App\Shared\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StudentController extends AcademicController
{
    protected string $model = Student::class;

    protected string $resource = StudentResource::class;

    protected array $searchable = ['student_number', 'name_ar', 'name_en'];

    protected array $searchRelations = ['user' => ['email']];

    protected array $with = ['user', 'guardians', 'enrollments.classroom', 'fees.items'];

    protected string $orderBy = 'student_number';

    /** The list; for the admin each student also shows what they owe and what remains (fees of the current year). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $response = parent::index($request);
        $year = AcademicYear::where('is_current', true)->first();
        if ($year !== null && $request->user()->hasRole(Role::Admin)) {
            $ids = $response->collection->map(fn ($r) => $r->resource->id)->all();
            $request->attributes->set('student_balances', app(AccountReader::class)->balances($ids, $year));
        }

        return $response;
    }

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
