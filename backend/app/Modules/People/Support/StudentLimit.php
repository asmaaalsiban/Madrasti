<?php

declare(strict_types=1);

namespace App\Modules\People\Support;

use App\Modules\Academic\Models\Student;

/**
 * The per-school student limit (`tenants.max_students`, null = no limit) set by the Super Admin.
 * It counts the school's students live (never the cached count of the Super Admin list) and only ever blocks adding students.
 */
final class StudentLimit
{
    /**
     * @throws StudentLimitReached when adding $adding students would pass the limit
     */
    public static function assertRoom(int $adding = 1): void
    {
        $limit = tenant()?->max_students;
        if ($limit === null) {
            return;
        }

        $current = Student::count();
        if ($current + $adding > $limit) {
            throw new StudentLimitReached((int) $limit, $current, max(0, (int) $limit - $current));
        }
    }
}
