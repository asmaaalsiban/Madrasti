<?php

declare(strict_types=1);

namespace App\Shared\Traits;

use App\Central\Support\SchoolCounts;

/** For the models the Super Admin's school numbers count: any add, remove, restore or change drops the cached numbers. */
trait ForgetsSchoolCounts
{
    public static function bootForgetsSchoolCounts(): void
    {
        foreach (['created', 'updated', 'deleted', 'restored', 'forceDeleted'] as $event) {
            static::$event(fn () => SchoolCounts::forget());
        }
    }
}
