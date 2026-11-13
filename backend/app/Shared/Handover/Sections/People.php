<?php

declare(strict_types=1);

namespace App\Shared\Handover\Sections;

use App\Shared\Handover\HandoverSection;
use App\Shared\Handover\HandoverSheet as S;
use Illuminate\Support\Facades\DB;

/** Students with their sections and guardians, and the staff with their contracts. */
class People implements HandoverSection
{
    public function name(): string
    {
        return 'people';
    }

    public function write(string $dir): void
    {
        S::write($dir, 'students', ['student_number', 'name_ar', 'name_en', 'birth_date', 'gender', 'email', 'phone', 'deleted'], S::stream(
            DB::table('students as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')->orderBy('s.id')
                ->select('s.student_number', 's.name_ar', 's.name_en', 's.birth_date', 's.gender', 'u.email', 'u.phone', 's.deleted_at'),
            fn ($r) => [$r->student_number, $r->name_ar, $r->name_en, S::date($r->birth_date), $r->gender, $r->email, $r->phone, $r->deleted_at ? 1 : 0],
        ));

        S::write($dir, 'enrollments', ['student_number', 'name_ar', 'name_en', 'year', 'grade', 'classroom'], S::stream(
            DB::table('enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')->join('academic_years as y', 'y.id', '=', 'e.academic_year_id')
                ->join('classrooms as c', 'c.id', '=', 'e.classroom_id')->join('grade_levels as g', 'g.id', '=', 'c.grade_level_id')->orderBy('e.id')
                ->select('s.student_number', 's.name_ar', 's.name_en', 'y.name as year', 'g.name_ar as g_ar', 'g.name_en as g_en', 'c.name as classroom'),
            fn ($r) => [$r->student_number, $r->name_ar, $r->name_en, $r->year, S::both($r->g_ar, $r->g_en), $r->classroom],
        ));

        S::write($dir, 'guardians', ['guardian', 'email', 'phone', 'relation', 'primary', 'student_number', 'name_ar', 'name_en'], S::stream(
            DB::table('guardian_student as gs')->join('users as u', 'u.id', '=', 'gs.guardian_id')->join('students as s', 's.id', '=', 'gs.student_id')->orderBy('gs.id')
                ->select('u.name', 'u.email', 'u.phone', 'gs.relation', 'gs.is_primary', 's.student_number', 's.name_ar', 's.name_en'),
            fn ($r) => [$r->name, $r->email, $r->phone, $r->relation, $r->is_primary ? 1 : 0, $r->student_number, $r->name_ar, $r->name_en],
        ));

        S::write($dir, 'staff', ['name', 'email', 'phone', 'role', 'active', 'job_title', 'staff_type', 'hired_on', 'weekly_hours'], S::stream(
            DB::table('users as u')->leftJoin('staff_profiles as p', 'p.user_id', '=', 'u.id')->whereNotIn('u.role', ['student', 'parent'])->orderBy('u.id')
                ->select('u.name', 'u.email', 'u.phone', 'u.role', 'u.is_active', 'p.job_title', 'p.staff_type', 'p.hired_on', 'p.weekly_hours'),
            fn ($r) => [$r->name, $r->email, $r->phone, $r->role, $r->is_active ? 1 : 0, $r->job_title, $r->staff_type, S::date($r->hired_on), $r->weekly_hours],
        ));

        S::write($dir, 'staff_contracts', ['staff_member', 'starts_on', 'ends_on', 'base_salary', 'status', 'raise_amount', 'raise_percent', 'note'], S::stream(
            DB::table('staff_contracts as c')->join('users as u', 'u.id', '=', 'c.user_id')->orderBy('c.id')
                ->select('u.name', 'c.starts_on', 'c.ends_on', 'c.base_salary', 'c.status', 'c.raise_amount', 'c.raise_percent', 'c.notes'),
            fn ($r) => [$r->name, S::date($r->starts_on), S::date($r->ends_on), S::money($r->base_salary), $r->status, $r->raise_amount === null ? null : S::money($r->raise_amount), $r->raise_percent === null ? null : S::money($r->raise_percent), $r->notes],
        ));

        S::write($dir, 'staff_contract_items', ['staff_member', 'starts_on', 'kind', 'label', 'amount'], S::stream(
            DB::table('staff_contract_items as i')->join('staff_contracts as c', 'c.id', '=', 'i.staff_contract_id')->join('users as u', 'u.id', '=', 'c.user_id')->orderBy('i.id')
                ->select('u.name', 'c.starts_on', 'i.kind', 'i.label', 'i.amount'),
            fn ($r) => [$r->name, S::date($r->starts_on), $r->kind, $r->label, S::money($r->amount)],
        ));
    }
}
