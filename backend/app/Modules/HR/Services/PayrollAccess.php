<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\Finance\Services\FinanceAccess;
use App\Shared\Enums\Role;

/** The accountant prepares and pays the payroll; the management (admin role: principal...) approves; everyone else sees only their own payslips. */
final class PayrollAccess
{
    public static function manages(User $user): bool
    {
        return FinanceAccess::manages($user);
    }

    public static function approves(User $user): bool
    {
        return $user->hasRole(Role::Admin);
    }

    public static function views(User $user): bool
    {
        return self::manages($user) || self::approves($user);
    }
}
