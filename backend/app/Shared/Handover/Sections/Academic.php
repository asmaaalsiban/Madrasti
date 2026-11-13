<?php

declare(strict_types=1);

namespace App\Shared\Handover\Sections;

use App\Shared\Handover\HandoverSection;
use App\Shared\Handover\HandoverSheet as S;
use Illuminate\Support\Facades\DB;

/** Years and terms, grades by year and term, attendance, behaviour and the timetables. */
class Academic implements HandoverSection
{
    public function name(): string
    {
        return 'academic';
    }

    public function write(string $dir): void
    {
        S::write($dir, 'academic_years', ['name', 'starts_on', 'ends_on', 'is_current'], S::stream(
            DB::table('academic_years')->orderBy('starts_on'),
            fn ($r) => [$r->name, S::date($r->starts_on), S::date($r->ends_on), $r->is_current ? 1 : 0],
        ));
        S::write($dir, 'terms', ['year', 'name', 'starts_on', 'ends_on'], S::stream(
            DB::table('terms as t')->join('academic_years as y', 'y.id', '=', 't.academic_year_id')->orderBy('y.starts_on')->orderBy('t.starts_on')
                ->select('y.name as year', 't.name', 't.starts_on', 't.ends_on'),
            fn ($r) => [$r->year, $r->name, S::date($r->starts_on), S::date($r->ends_on)],
        ));

        S::write($dir, 'grades', ['year', 'term', 'classroom', 'subject', 'assessment', 'type', 'max_score', 'weight', 'held_on', 'student_number', 'name_ar', 'name_en', 'score', 'first_score', 'status', 'remark'], S::stream(
            DB::table('assessment_scores as sc')->join('assessments as a', 'a.id', '=', 'sc.assessment_id')->join('terms as t', 't.id', '=', 'a.term_id')
                ->join('academic_years as y', 'y.id', '=', 't.academic_year_id')->join('classrooms as c', 'c.id', '=', 'a.classroom_id')
                ->join('subjects as sub', 'sub.id', '=', 'a.subject_id')->join('students as s', 's.id', '=', 'sc.student_id')
                ->orderBy('y.starts_on')->orderBy('t.starts_on')->orderBy('a.id')->orderBy('sc.id')
                ->select('y.name as year', 't.name as term', 'c.name as classroom', 'sub.name_ar as sub_ar', 'sub.name_en as sub_en', 'a.title', 'a.type', 'a.max_score', 'a.weight', 'a.held_on', 's.student_number', 's.name_ar', 's.name_en', 'sc.score', 'sc.first_score', 'sc.status', 'sc.remark'),
            fn ($r) => [$r->year, $r->term, $r->classroom, S::both($r->sub_ar, $r->sub_en), $r->title, $r->type, S::money($r->max_score), S::money($r->weight), S::date($r->held_on), $r->student_number, $r->name_ar, $r->name_en,
                $r->score === null ? null : S::money($r->score), $r->first_score === null ? null : S::money($r->first_score), $r->status, $r->remark],
        ));

        S::write($dir, 'student_attendance', ['date', 'student_number', 'name_ar', 'name_en', 'classroom', 'status', 'note'], S::stream(
            DB::table('student_attendances as a')->join('students as s', 's.id', '=', 'a.student_id')->join('classrooms as c', 'c.id', '=', 'a.classroom_id')->orderBy('a.date')->orderBy('a.id')
                ->select('a.date', 's.student_number', 's.name_ar', 's.name_en', 'c.name as classroom', 'a.status', 'a.note'),
            fn ($r) => [S::date($r->date), $r->student_number, $r->name_ar, $r->name_en, $r->classroom, $r->status, $r->note],
        ));
        S::write($dir, 'staff_attendance', ['date', 'staff_member', 'check_in', 'check_out', 'worked_minutes', 'status', 'note'], S::stream(
            DB::table('staff_attendances as a')->join('users as u', 'u.id', '=', 'a.user_id')->orderBy('a.date')->orderBy('a.id')
                ->select('a.date', 'u.name', 'a.check_in', 'a.check_out', 'a.worked_minutes', 'a.status', 'a.note'),
            fn ($r) => [S::date($r->date), $r->name, $r->check_in, $r->check_out, (int) $r->worked_minutes, $r->status, $r->note],
        ));

        S::write($dir, 'behavior_notes', ['date', 'student_number', 'name_ar', 'name_en', 'teacher', 'type', 'note', 'visible_to_parent'], S::stream(
            DB::table('behavior_notes as b')->join('students as s', 's.id', '=', 'b.student_id')->join('users as u', 'u.id', '=', 'b.teacher_id')->orderBy('b.noted_on')->orderBy('b.id')
                ->select('b.noted_on', 's.student_number', 's.name_ar', 's.name_en', 'u.name as teacher', 'b.type', 'b.note', 'b.visible_to_parent'),
            fn ($r) => [S::date($r->noted_on), $r->student_number, $r->name_ar, $r->name_en, $r->teacher, $r->type, $r->note, $r->visible_to_parent ? 1 : 0],
        ));
        S::write($dir, 'behavior_evaluations', ['week_starts_on', 'student_number', 'name_ar', 'name_en', 'teacher', 'criterion', 'score', 'note'], S::stream(
            DB::table('behavior_evaluation_scores as sc')->join('behavior_evaluations as e', 'e.id', '=', 'sc.behavior_evaluation_id')->join('behavior_criteria as c', 'c.id', '=', 'sc.behavior_criterion_id')
                ->join('students as s', 's.id', '=', 'e.student_id')->join('users as u', 'u.id', '=', 'e.teacher_id')->orderBy('e.week_starts_on')->orderBy('sc.id')
                ->select('e.week_starts_on', 's.student_number', 's.name_ar', 's.name_en', 'u.name as teacher', 'c.name_ar as c_ar', 'c.name_en as c_en', 'sc.score', 'e.note'),
            fn ($r) => [S::date($r->week_starts_on), $r->student_number, $r->name_ar, $r->name_en, $r->teacher, S::both($r->c_ar, $r->c_en), (int) $r->score, $r->note],
        ));
        S::write($dir, 'warnings', ['issued_on', 'subject_type', 'student_number', 'name_ar', 'name_en', 'staff_member', 'category', 'level', 'title', 'body', 'status'], S::stream(
            DB::table('warnings as w')->leftJoin('students as s', 's.id', '=', 'w.student_id')->leftJoin('users as u', 'u.id', '=', 'w.user_id')->orderBy('w.issued_on')->orderBy('w.id')
                ->select('w.issued_on', 'w.subject_type', 's.student_number', 's.name_ar', 's.name_en', 'u.name as staff', 'w.category', 'w.level', 'w.title', 'w.body', 'w.status'),
            fn ($r) => [S::date($r->issued_on), $r->subject_type, $r->student_number, $r->name_ar, $r->name_en, $r->staff, $r->category, (int) $r->level, $r->title, $r->body, $r->status],
        ));

        S::write($dir, 'timetable', ['year', 'classroom', 'day', 'period', 'starts_at', 'ends_at', 'subject', 'teacher', 'room'], S::stream(
            DB::table('timetable_entries as t')->join('academic_years as y', 'y.id', '=', 't.academic_year_id')->join('classrooms as c', 'c.id', '=', 't.classroom_id')->join('periods as p', 'p.id', '=', 't.period_id')
                ->leftJoin('subjects as sub', 'sub.id', '=', 't.subject_id')->leftJoin('users as u', 'u.id', '=', 't.teacher_id')->leftJoin('rooms as r', 'r.id', '=', 't.room_id')
                ->orderBy('y.starts_on')->orderBy('c.id')->orderBy('p.day_of_week')->orderBy('p.number')
                ->select('y.name as year', 'c.name as classroom', 'p.day_of_week', 'p.number', 'p.starts_at', 'p.ends_at', 'sub.name_ar as sub_ar', 'sub.name_en as sub_en', 'u.name as teacher', 'r.name as room'),
            fn ($r) => [$r->year, $r->classroom, (int) $r->day_of_week, (int) $r->number, $r->starts_at, $r->ends_at, S::both($r->sub_ar, $r->sub_en), $r->teacher, $r->room],
        ));
        S::write($dir, 'exam_timetable', ['year', 'term', 'classroom', 'subject', 'exam_date', 'starts_at', 'ends_at', 'room'], S::stream(
            DB::table('exam_sessions as x')->join('terms as t', 't.id', '=', 'x.term_id')->join('academic_years as y', 'y.id', '=', 't.academic_year_id')->join('classrooms as c', 'c.id', '=', 'x.classroom_id')
                ->leftJoin('subjects as sub', 'sub.id', '=', 'x.subject_id')->leftJoin('rooms as r', 'r.id', '=', 'x.room_id')->orderBy('x.exam_date')->orderBy('x.starts_at')->orderBy('x.id')
                ->select('y.name as year', 't.name as term', 'c.name as classroom', 'sub.name_ar as sub_ar', 'sub.name_en as sub_en', 'x.exam_date', 'x.starts_at', 'x.ends_at', 'r.name as room'),
            fn ($r) => [$r->year, $r->term, $r->classroom, S::both($r->sub_ar, $r->sub_en), S::date($r->exam_date), $r->starts_at, $r->ends_at, $r->room],
        ));
        S::write($dir, 'duties', ['date', 'staff_member', 'starts_at', 'ends_at', 'location'], S::stream(
            DB::table('duty_assignments as d')->join('users as u', 'u.id', '=', 'd.user_id')->orderBy('d.date')->orderBy('d.starts_at')->orderBy('d.id')
                ->select('d.date', 'u.name', 'd.starts_at', 'd.ends_at', 'd.location'),
            fn ($r) => [S::date($r->date), $r->name, $r->starts_at, $r->ends_at, $r->location],
        ));
    }
}
