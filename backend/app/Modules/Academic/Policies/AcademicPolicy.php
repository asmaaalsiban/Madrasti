<?php

declare(strict_types=1);

namespace App\Modules\Academic\Policies;

use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Database\Eloquent\Model;

/**
 * School structure (years, grade levels, classrooms, subjects) is managed by admins only.
 */
class AcademicPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasRole(Role::Admin);
    }
}
