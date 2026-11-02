<?php

declare(strict_types=1);

namespace App\Modules\Motivation\Services;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Motivation\Models\Badge;
use App\Modules\Motivation\Models\PointAward;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Points totals, automatic badges and the star of the week. */
class Motivation
{
    public function __construct(private readonly Notifier $notifier) {}

    public function total(int $studentId): int
    {
        return (int) PointAward::where('student_id', $studentId)->sum('points');
    }

    /** Sunday starts the week that contains `$date`. */
    public function weekStart(string $date): string
    {
        return Carbon::parse($date)->startOfWeek(Carbon::SUNDAY)->toDateString();
    }

    /**
     * Total and this-week points for each student.
     *
     * @param  Collection<int, int>  $studentIds
     * @return array<int, array{total: int, week: int}>
     */
    public function totals(Collection $studentIds): array
    {
        $week = $this->weekStart(now()->toDateString());
        $rows = PointAward::whereIn('student_id', $studentIds)->groupBy('student_id')
            ->selectRaw('student_id, SUM(points) as total, SUM(CASE WHEN awarded_at >= ? THEN points ELSE 0 END) as week', [$week])->get()->keyBy('student_id');

        return $studentIds->mapWithKeys(fn ($id) => [$id => ['total' => (int) ($rows[$id]->total ?? 0), 'week' => (int) ($rows[$id]->week ?? 0)]])->all();
    }

    /**
     * Who scored most this week in a section (ties share it; nobody at 0 or less): the suggestion for the star of the week.
     *
     * @return Collection<int, int> student ids
     */
    public function leaders(int $classroomId): Collection
    {
        $year = AcademicYear::where('is_current', true)->first();
        $ids = Enrollment::where('classroom_id', $classroomId)->where('academic_year_id', $year?->id)->pluck('student_id');
        $week = collect($this->totals($ids))->map(fn ($t) => $t['week']);
        $top = $week->max();

        return $top > 0 ? $week->filter(fn ($p) => $p === $top)->keys()->values() : collect();
    }

    /** The student the teacher crowned star of this week in a section, if any. */
    public function star(int $classroomId): ?int
    {
        $id = DB::table('star_of_week')->where('classroom_id', $classroomId)->where('week_starts_on', $this->weekStart(now()->toDateString()))->value('student_id');

        return $id ? (int) $id : null;
    }

    /** Gives badges the student's total has reached and tells the student and parents about new ones. */
    public function awardEarnedBadges(Student $student): void
    {
        $total = $this->total($student->id);
        $owned = DB::table('student_badges')->where('student_id', $student->id)->pluck('badge_id');
        $earned = Badge::where('is_active', true)->whereNotNull('points_required')->where('points_required', '<=', $total)->whereNotIn('id', $owned)->get();
        foreach ($earned as $badge) {
            $this->giveBadge($student, $badge, null);
        }
    }

    public function announceStar(Student $student): void
    {
        $params = ['student_ar' => $student->name_ar, 'student_en' => $student->name_en];
        $this->notifier->toUsers(array_filter([$student->user_id]), 'star_of_week', $params, '/student/motivation');
        $this->notifier->toUsers($student->guardians()->pluck('users.id'), 'star_of_week', $params, "/parent/children/{$student->id}/motivation");
    }

    public function giveBadge(Student $student, Badge $badge, ?int $by): bool
    {
        $created = DB::table('student_badges')->insertOrIgnore(['student_id' => $student->id, 'badge_id' => $badge->id, 'awarded_by' => $by, 'created_at' => now(), 'updated_at' => now(), 'awarded_at' => now()]);
        if ($created) {
            $params = ['student_ar' => $student->name_ar, 'student_en' => $student->name_en, 'badge_ar' => $badge->name_ar, 'badge_en' => $badge->name_en, 'icon' => $badge->icon];
            $this->notifier->toUsers(array_filter([$student->user_id]), 'badge_earned', $params, '/student/motivation');
            $this->notifier->toUsers($student->guardians()->pluck('users.id'), 'badge_earned', $params, "/parent/children/{$student->id}/motivation");
        }

        return (bool) $created;
    }
}
