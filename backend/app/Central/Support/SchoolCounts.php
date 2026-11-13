<?php

declare(strict_types=1);

namespace App\Central\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The students / staff numbers the Super Admin sees for each school. They are cached for a few minutes so a long list of schools
 * does not open every database on every request, and the cache is dropped the moment a school adds or removes a student or staff
 * member, so the owner's numbers follow the school at once.
 */
final class SchoolCounts
{
    public const MINUTES = 5;

    public static function key(string $schoolId): string
    {
        return "school.counts.{$schoolId}";
    }

    /** Drops the cached numbers of the school the code is running in (nothing when it runs outside a school). */
    public static function forget(): void
    {
        $id = tenant('id');
        if ($id !== null) {
            Cache::forget(self::key((string) $id));
        }
    }
}
