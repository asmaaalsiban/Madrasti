<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/** What the teacher records for a student each day (the table also knows "excused", which the screens do not use yet). */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
