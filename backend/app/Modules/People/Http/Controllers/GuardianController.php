<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Models\User;
use App\Modules\Academic\Http\Controllers\AcademicController;
use App\Modules\People\Http\Resources\GuardianResource;
use App\Shared\Enums\Role;
use Illuminate\Database\Eloquent\Builder;

/** Search of the existing parent accounts, so a new student can be linked to a parent who is already registered. */
class GuardianController extends AcademicController
{
    protected string $model = User::class;

    protected string $resource = GuardianResource::class;

    protected array $searchable = ['name', 'email', 'phone'];

    protected array $with = ['children'];

    protected string $orderBy = 'name';

    protected function baseQuery(): Builder
    {
        return User::query()->where('role', Role::Guardian);
    }

    protected function resourceKey(): string
    {
        return 'guardian';
    }
}
