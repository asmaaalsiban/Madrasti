<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Scheduling\Models\LessonCover;
use App\Modules\Scheduling\Models\Period;
use App\Modules\Scheduling\Models\TimetableEntry;
use App\Shared\Enums\StaffType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * When a teacher is away: the lessons they have that day, and for each one the teachers who are free and could cover it,
 * the best first (teaches the subject, teaches the grade, fewest lessons and covers that day).
 */
class CoverPlanner
{
    /**
     * @return list<array<string, mixed>>
     */
    public function plan(User $absent, string $date): array
    {
        $year = AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            return [];
        }
        $dow = Carbon::parse($date)->dayOfWeek; // 0 = Sunday, like the periods
        $entries = TimetableEntry::where('academic_year_id', $year->id)->where('teacher_id', $absent->id)
            ->whereIn('period_id', Period::where('day_of_week', $dow)->pluck('id'))->with(['classroom.gradeLevel', 'subject'])->get()
            ->sortBy(fn ($e) => Period::find($e->period_id)?->number)->values();
        $periods = Period::whereIn('id', $entries->pluck('period_id'))->get()->keyBy('id');
        $covers = LessonCover::where('date', $date)->get()->keyBy(fn ($c) => $c->period_id.'-'.$c->classroom_id);
        $names = User::whereIn('id', $covers->pluck('cover_teacher_id'))->pluck('name', 'id');
        $pool = $this->pool($absent, $date, $dow, $year->id);

        return $entries->map(function (TimetableEntry $e) use ($periods, $covers, $names, $pool, $date): array {
            $p = $periods[$e->period_id];
            $cover = $covers[$e->period_id.'-'.$e->classroom_id] ?? null;

            return [
                'period' => ['id' => $p->id, 'number' => $p->number, 'starts_at' => substr($p->starts_at, 0, 5), 'ends_at' => substr($p->ends_at, 0, 5)],
                'classroom' => ['id' => $e->classroom_id, 'name' => $e->classroom->name],
                'subject' => $e->subject->only(['id', 'name_ar', 'name_en']),
                'cover' => $cover ? ['id' => $cover->id, 'teacher' => ['id' => $cover->cover_teacher_id, 'name' => $names[$cover->cover_teacher_id] ?? '']] : null,
                'suggestions' => $cover ? [] : $this->suggest($e, $p, $date, $pool),
            ];
        })->all();
    }

    /** Whether this teacher can cover this lesson (free, available, present, not already covering at that time). */
    public function canCover(int $teacherId, int $periodId, string $date, int $absentId): bool
    {
        $year = AcademicYear::where('is_current', true)->value('id');
        $dow = Carbon::parse($date)->dayOfWeek;
        $absent = User::find($absentId);
        if ($absent === null || ! isset($this->pool($absent, $date, $dow, (int) $year)[$teacherId])) {
            return false;
        }

        return ! $this->busyAt($teacherId, $periodId, $date, (int) $year);
    }

    /**
     * Active teaching staff who are present that day, with what they already do.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pool(User $absent, string $date, int $dow, int $yearId): array
    {
        $away = DB::table('staff_attendances')->where('date', $date)->whereIn('status', ['absent', 'leave'])->pluck('user_id')->push($absent->id)->unique()->all();
        $dayPeriods = Period::where('day_of_week', $dow)->pluck('id');
        $lessonsToday = TimetableEntry::where('academic_year_id', $yearId)->whereIn('period_id', $dayPeriods)->get(['teacher_id'])->countBy('teacher_id');
        $coversToday = LessonCover::where('date', $date)->get(['cover_teacher_id'])->countBy('cover_teacher_id');
        $weekly = TimetableEntry::where('academic_year_id', $yearId)->get(['teacher_id'])->countBy('teacher_id');

        return User::where('is_active', true)->whereNotIn('id', $away)->whereHas('staffProfile', fn ($q) => $q->whereIn('staff_type', StaffType::valuesIn('teaching')))
            ->with(['staffProfile.gradeFrom', 'staffProfile.gradeTo', 'staffProfile.classrooms', 'staffProfile.extraSubjects.gradeFrom', 'staffProfile.extraSubjects.gradeTo'])->get()
            ->mapWithKeys(fn (User $u) => [$u->id => ['user' => $u, 'lessons_today' => (int) ($lessonsToday[$u->id] ?? 0), 'covers_today' => (int) ($coversToday[$u->id] ?? 0), 'weekly' => (int) ($weekly[$u->id] ?? 0)]])->all();
    }

    private function busyAt(int $teacherId, int $periodId, string $date, int $yearId): bool
    {
        return TimetableEntry::where('academic_year_id', $yearId)->where('teacher_id', $teacherId)->where('period_id', $periodId)->exists()
            || LessonCover::where('date', $date)->where('period_id', $periodId)->where('cover_teacher_id', $teacherId)->exists()
            || DB::table('teacher_unavailabilities')->where('teacher_id', $teacherId)->where('period_id', $periodId)->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pool
     * @return list<array<string, mixed>>
     */
    private function suggest(TimetableEntry $e, Period $p, string $date, array $pool): array
    {
        $yearId = (int) $e->academic_year_id;
        $room = $e->classroom;
        $out = [];
        foreach ($pool as $id => $row) {
            if ($this->busyAt($id, $p->id, $date, $yearId)) {
                continue;
            }
            /** @var User $u */
            $u = $row['user'];
            $profile = $u->staffProfile;
            $teachesSubject = $profile->canTeachSubject((int) $e->subject_id, (int) $room->gradeLevel->sort_order, $room->gender ?? 'mixed', $room->id, (int) $room->grade_level_id);
            $from = $profile->gradeFrom?->sort_order;
            $to = $profile->gradeTo?->sort_order;
            $teachesGrade = $from === null || $to === null || ($from <= $room->gradeLevel->sort_order && $room->gradeLevel->sort_order <= $to);
            $score = ($teachesSubject ? 100 : 0) + ($teachesGrade ? 40 : 0) - $row['covers_today'] * 8 - $row['lessons_today'] - $row['weekly'] * 0.1;
            $out[] = ['score' => $score, 'teacher' => ['id' => $id, 'name' => $u->name], 'teaches_subject' => $teachesSubject, 'teaches_grade' => $teachesGrade, 'covers_today' => $row['covers_today'], 'lessons_today' => $row['lessons_today']];
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($r) => array_diff_key($r, ['score' => 0]), array_slice($out, 0, 6));
    }
}
