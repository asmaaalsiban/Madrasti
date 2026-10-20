<?php

declare(strict_types=1);

namespace App\Modules\People\Policies;

use App\Models\User;
use App\Shared\Enums\Role;

/**
 * Teacher, student and parent accounts are managed by admins only.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->hasRole(Role::Admin);
    }

    /** Admin passwords are never reset by another admin through this API. */
    public function resetPassword(User $user, User $target): bool
    {
        return $user->hasRole(Role::Admin) && ! $target->hasRole(Role::Admin);
    }
}
