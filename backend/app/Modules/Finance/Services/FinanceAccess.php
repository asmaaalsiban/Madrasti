<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;

/** The accountant manages the school's money; the admin (management) can read it but not change it. */
class FinanceAccess
{
    public static function manages(User $user): bool
    {
        return $user->hasRole(Role::Teacher) && $user->staffProfile?->staff_type === StaffType::Accountant;
    }

    public static function views(User $user): bool
    {
        return $user->hasRole(Role::Admin) || self::manages($user);
    }
}
