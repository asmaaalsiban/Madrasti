<?php

declare(strict_types=1);

namespace App\Modules\People\Support;

use App\Modules\Academic\Models\Student;
use App\Models\User;
use App\Modules\Communication\Services\Notifier;
use App\Shared\Enums\Role;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The per-school student limit (`tenants.max_students`, null = no limit) set by the Super Admin.
 * It counts the school's students live (never the cached count of the Super Admin list) and only ever blocks adding students.
 */
final class StudentLimit
{
    private static bool $held = false;

    /**
     * Runs $work as the only student creation of this school (two requests cannot both take the last place).
     * Re-entrant: an import holds it across all its rows, and each row's own creation then runs inside it.
     */
    public static function exclusively(Closure $work): mixed
    {
        if (self::$held) {
            return $work();
        }

        return Cache::lock('student-limit:'.(tenant('id') ?? 'school'), 60)->block(30, function () use ($work) {
            self::$held = true;
            try {
                return $work();
            } finally {
                self::$held = false;
            }
        });
    }

    /**
     * Tells the school's administrators (the bell) when the student count has just reached 90% of the limit and when it has just
     * reached the limit itself. It fires on the exact count, so each threshold rings once as the school grows. Call it after a student is created.
     */
    public static function notifyAdmins(): void
    {
        $limit = tenant()?->max_students;
        if ($limit === null) {
            return;
        }

        $limit = (int) $limit;
        $current = Student::count();
        $near = max(1, (int) ceil($limit * 0.9));
        $kind = match (true) {
            $current === $limit => 'student_limit_reached',
            $current === $near && $near < $limit => 'student_limit_near',
            default => null,
        };
        if ($kind === null) {
            return;
        }

        $admins = User::where('role', Role::Admin)->where('is_active', true)->pluck('id');
        app(Notifier::class)->toUsers($admins, $kind, ['current' => $current, 'limit' => $limit], '/admin/students');
    }

    /**
     * $inserted is how many of the $adding students are already in the table (inside the caller's transaction, which then rolls back).
     *
     * @throws StudentLimitReached when adding $adding students would pass the limit
     */
    public static function assertRoom(int $adding = 1, int $inserted = 0): void
    {
        $limit = tenant()?->max_students;
        if ($limit === null) {
            return;
        }

        $current = Student::count() - $inserted;
        if ($current + $adding > $limit) {
            throw new StudentLimitReached((int) $limit, $current, max(0, (int) $limit - $current));
        }
    }
}
