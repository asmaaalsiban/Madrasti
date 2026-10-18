<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * The four school roles. The Super Admin is not here: it lives in the
 * central database only (see App\Central\Models\SuperAdmin).
 */
enum Role: string
{
    case Admin = 'admin';
    case Teacher = 'teacher';
    case Student = 'student';
    case Guardian = 'parent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }
}
