<?php

declare(strict_types=1);

namespace App\Central\Actions;

use App\Models\User;
use App\Shared\Enums\Role;

/**
 * Makes sure the current (tenant) database has an active admin with this email and password.
 * Creates the user, or repairs an existing one (role, active flag, soft delete, password).
 */
class ResetSchoolAdmin
{
    public function handle(string $email, string $password): User
    {
        $email = mb_strtolower(trim($email));

        $user = User::withTrashed()->where('email', $email)->first() ?? new User(['email' => $email, 'name' => 'Admin']);

        $user->forceFill(['password' => $password, 'role' => Role::Admin, 'is_active' => true, 'deleted_at' => null])->save();

        return $user;
    }
}
