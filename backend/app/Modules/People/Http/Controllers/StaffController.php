<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\Academic\Http\Controllers\AcademicController;
use App\Modules\Academic\Models\Classroom;
use App\Modules\People\Actions\SaveStaff;
use App\Modules\People\Http\Requests\StaffRequest;
use App\Modules\People\Http\Resources\StaffResource;
use App\Shared\Enums\StaffType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StaffController extends AcademicController
{
    protected string $model = User::class;

    protected string $resource = StaffResource::class;

    protected array $searchable = ['name', 'email', 'phone'];

    protected array $with = ['staffProfile.subject'];

    protected string $orderBy = 'name';

    public function store(StaffRequest $request, SaveStaff $save): JsonResponse
    {
        Gate::authorize('create', User::class);

        return (new StaffResource($save->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(StaffRequest $request, SaveStaff $save, int|string $id): StaffResource
    {
        $user = $this->baseQuery()->findOrFail($id);
        Gate::authorize('update', $user);

        return new StaffResource($save->update($user, $request->validated()));
    }

    /** Staff are the users that have a staff profile (the school's first admin is not staff). */
    protected function baseQuery(): Builder
    {
        return User::query()->whereHas('staffProfile');
    }

    protected function filter(Builder $query, Request $request): void
    {
        $types = null;
        if ($request->filled('category')) {
            $types = StaffType::valuesIn((string) $request->query('category'));
        }
        if ($request->filled('staff_type')) {
            $types = array_values(array_intersect($types ?? [(string) $request->query('staff_type')], [(string) $request->query('staff_type')]));
        }
        if ($request->boolean('homeroom')) {
            $types = array_values(array_intersect($types ?? StaffType::homeroomValues(), StaffType::homeroomValues()));
        }

        if ($types !== null) {
            $query->whereHas('staffProfile', fn (Builder $q) => $q->whereIn('staff_type', $types));
        }
    }

    protected function deleting(Model $model): void
    {
        $model->tokens()->delete();
        Classroom::where('homeroom_teacher_id', $model->id)->get()->each->update(['homeroom_teacher_id' => null]);
    }

    protected function resourceKey(): string
    {
        return 'staff';
    }
}
