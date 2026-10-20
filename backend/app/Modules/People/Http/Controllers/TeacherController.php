<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\Academic\Http\Controllers\AcademicController;
use App\Modules\Academic\Models\Classroom;
use App\Modules\People\Http\Requests\TeacherRequest;
use App\Modules\People\Http\Resources\TeacherResource;
use App\Shared\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TeacherController extends AcademicController
{
    protected string $model = User::class;

    protected string $resource = TeacherResource::class;

    protected array $searchable = ['name', 'email', 'phone'];

    protected string $orderBy = 'name';

    public function store(TeacherRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $teacher = User::create([
            ...$request->validated(),
            'role' => Role::Teacher,
            'password' => config('accounts.default_password'),
            'must_change_password' => true,
        ]);

        return (new TeacherResource($teacher))->response()->setStatusCode(201);
    }

    public function update(TeacherRequest $request, int|string $id): TeacherResource
    {
        $teacher = $this->baseQuery()->findOrFail($id);
        Gate::authorize('update', $teacher);
        $teacher->update($request->validated());

        return new TeacherResource($teacher);
    }

    protected function baseQuery(): Builder
    {
        return User::query()->where('role', Role::Teacher);
    }

    protected function deleting(Model $model): void
    {
        $model->tokens()->delete();
        Classroom::where('homeroom_teacher_id', $model->id)->get()->each->update(['homeroom_teacher_id' => null]);
    }

    protected function resourceKey(): string
    {
        return 'teacher';
    }
}
