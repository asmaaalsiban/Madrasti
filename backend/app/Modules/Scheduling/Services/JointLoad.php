<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use Illuminate\Support\Facades\DB;

/**
 * A joint lesson is taught once but sits in two sections, each with its own assignment of periods. A teacher's real load is
 * the assigned periods minus the periods counted twice.
 */
class JointLoad
{
    /** @return array<int, int> teacher id => periods counted twice this year */
    public function duplicates(): array
    {
        $year = DB::table('academic_years')->where('is_current', true)->value('id');
        if ($year === null) {
            return [];
        }
        $out = [];
        foreach (DB::table('timetable_entries')->where('academic_year_id', $year)->where('is_joint', true)->get(['teacher_id', 'period_id'])->groupBy(fn ($r) => $r->teacher_id.'-'.$r->period_id) as $group) {
            $out[$group->first()->teacher_id] = ($out[$group->first()->teacher_id] ?? 0) + ($group->count() - 1);
        }

        return $out;
    }
}
