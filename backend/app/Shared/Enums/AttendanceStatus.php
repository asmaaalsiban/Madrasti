<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/** What the teacher records for a student each day ("excused" exists in the table but the screens do not offer it yet). */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Excused = 'excused';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
