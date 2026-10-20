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
        return $user->hasRole(Role::Admin) && ! $user->is($target);
    }

    /**
     * An admin may reset teachers, students, parents and admin-level staff (principal, ...), never themselves
     * and never the school's first admin (an admin without a staff profile).
     */
    public function resetPassword(User $user, User $target): bool
    {
        if (! $user->hasRole(Role::Admin) || $user->is($target)) {
            return false;
        }

        return ! $target->hasRole(Role::Admin) || $target->staffProfile()->exists();
    }
}
