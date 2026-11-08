<?php

declare(strict_types=1);

namespace App\Modules\Academic\Services;

use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\GradeLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\StudentRenewal;
use App\Modules\Communication\Services\Notifier;
use App\Modules\Finance\Services\FeeBilling;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The yearly re-registration. The school opens it for the next academic year: every enrolled student gets a pending row with
 * the next grade. Renewing a student places them in a section of that grade in the new year (that IS the move to the new grade)
 * and gives them the new year's fees; declining records that they leave.
 */
class RenewalService
{
    public function __construct(private readonly FeeBilling $billing, private readonly Notifier $notifier) {}

    /** The year the school is in now. */
    public function currentYear(): ?AcademicYear
    {
        return AcademicYear::where('is_current', true)->first();
    }

    /** The year to renew for when none is chosen: the first year that starts after the current one. */
    public function nextYear(): ?AcademicYear
    {
        $current = $this->currentYear();

        return $current === null ? null : AcademicYear::where('id', '!=', $current->id)->whereDate('starts_on', '>', $current->starts_on)->orderBy('starts_on')->first();
    }

    /** Opens the renewal for `$to`: a row for every student enrolled this year who has none yet. Returns how many were added. */
    public function open(AcademicYear $to): int
    {
        $from = $this->currentYear();
        if ($from === null || $to->id === $from->id || $to->starts_on->lte($from->starts_on)) {
            throw ValidationException::withMessages(['year_id' => [__('renewal.bad_year')]]);
        }
        $grades = GradeLevel::orderBy('sort_order')->get();
        $rooms = Classroom::whereIn('id', Enrollment::where('academic_year_id', $from->id)->select('classroom_id'))->get()->keyBy('id');
        $added = 0;

        DB::transaction(function () use ($from, $to, $grades, $rooms, &$added): void {
            $enrolled = Enrollment::where('academic_year_id', $from->id)->whereHas('student')->get();
            $already = StudentRenewal::where('to_year_id', $to->id)->pluck('student_id')->flip();
            $placedAlready = Enrollment::where('academic_year_id', $to->id)->pluck('classroom_id', 'student_id');
            foreach ($enrolled as $enrollment) {
                if ($already->has($enrollment->student_id)) {
                    continue;
                }
                $grade = $rooms[$enrollment->classroom_id]?->grade_level_id;
                $next = $grade === null ? null : $this->nextGrade($grades, $grades->firstWhere('id', $grade));
                // Someone already placed in the new year (e.g. by hand) is renewed already.
                $status = $placedAlready->has($enrollment->student_id) ? 'renewed' : ($next === null ? 'graduating' : 'pending');
                StudentRenewal::create([
                    'student_id' => $enrollment->student_id, 'from_year_id' => $from->id, 'to_year_id' => $to->id, 'from_classroom_id' => $enrollment->classroom_id,
                    'from_grade_level_id' => $grade, 'to_grade_level_id' => $next?->id, 'to_classroom_id' => $placedAlready[$enrollment->student_id] ?? null, 'status' => $status,
                ]);
                $added++;
            }
        });

        return $added;
    }

    /**
     * The grade a student goes to next: the level after theirs. When that level has several tracks (health, business...) a student
     * who is in a track stays in the same track, a student with no track gets the first one (the admin can pick another when renewing).
     *
     * @param  \Illuminate\Support\Collection<int, GradeLevel>  $grades  ordered by sort_order
     */
    private function nextGrade($grades, GradeLevel $current): ?GradeLevel
    {
        $level = $grades->first(fn (GradeLevel $g) => $g->sort_order > $current->sort_order)?->sort_order;
        if ($level === null) {
            return null;
        }
        $options = $grades->filter(fn (GradeLevel $g) => $g->sort_order === $level);

        return ($current->track !== null && $current->track !== '' ? $options->first(fn (GradeLevel $g) => $g->track === $current->track) : null) ?? $options->first();
    }

    /**
     * Renews one student: placed in `$classroomId` (or the best section of the next grade, or of `$gradeId` to repeat/skip a grade),
     * and given the fees of the new year. Returns how many fees were created.
     */
    public function renew(StudentRenewal $renewal, User $by, ?int $classroomId = null, ?int $gradeId = null, float $discount = 0, float $transport = 0): int
    {
        if (! in_array($renewal->status, ['pending', 'graduating'], true)) {
            throw ValidationException::withMessages(['renewal' => [__('renewal.not_pending')]]);
        }
        $grade = $gradeId ?? $renewal->to_grade_level_id;
        if ($grade === null) {
            throw ValidationException::withMessages(['grade_level_id' => [__('renewal.graduating')]]);
        }
        $student = $renewal->student;
        $classroom = $classroomId !== null ? Classroom::findOrFail($classroomId) : $this->bestSection($student, $renewal->to_year_id, $grade);
        if ($classroom === null) {
            throw ValidationException::withMessages(['classroom_id' => [__('renewal.no_section', ['student' => $student->name_ar])]]);
        }
        if ($classroom->academic_year_id !== $renewal->to_year_id || $classroom->grade_level_id !== $grade) {
            throw ValidationException::withMessages(['classroom_id' => [__('renewal.wrong_section')]]);
        }

        $created = DB::transaction(function () use ($renewal, $student, $classroom, $grade, $by, $discount, $transport): int {
            Enrollment::updateOrCreate(['student_id' => $student->id, 'academic_year_id' => $renewal->to_year_id], ['classroom_id' => $classroom->id]);
            $fees = $this->billing->onboard($student, $classroom, $discount, $transport);
            $renewal->update(['status' => 'renewed', 'to_grade_level_id' => $grade, 'to_classroom_id' => $classroom->id, 'decline_reason' => null, 'decided_by' => $by->id, 'decided_at' => now()]);

            return count($fees);
        });

        $year = $renewal->toYear;
        $recipients = $student->guardians()->pluck('users.id')->push($student->user_id)->filter()->unique()->values();
        $this->notifier->toUsers($recipients, 'student_renewed', ['student_ar' => $student->name_ar, 'student_en' => $student->name_en ?: $student->name_ar, 'title' => $year->name], '/');

        return $created;
    }

    public function decline(StudentRenewal $renewal, User $by, ?string $reason): void
    {
        if (! in_array($renewal->status, ['pending', 'graduating'], true)) {
            throw ValidationException::withMessages(['renewal' => [__('renewal.not_pending')]]);
        }
        $renewal->update(['status' => 'declined', 'decline_reason' => $reason, 'decided_by' => $by->id, 'decided_at' => now()]);
    }

    /** A declined student who changed their mind goes back to the waiting list. */
    public function reopen(StudentRenewal $renewal): void
    {
        if ($renewal->status !== 'declined') {
            throw ValidationException::withMessages(['renewal' => [__('renewal.not_declined')]]);
        }
        $renewal->update(['status' => $renewal->to_grade_level_id === null ? 'graduating' : 'pending', 'decline_reason' => null, 'decided_by' => null, 'decided_at' => null]);
    }

    /**
     * Renews everyone still waiting, each in the best section of their next grade. Students that cannot be placed (no section, full)
     * are left pending and reported.
     *
     * @return array{renewed: int, failed: list<array{student: string, reason: string}>}
     */
    public function renewAll(AcademicYear $to, User $by): array
    {
        $renewed = 0;
        $failed = [];
        foreach (StudentRenewal::with('student')->where('to_year_id', $to->id)->where('status', 'pending')->get() as $renewal) {
            try {
                $this->renew($renewal, $by);
                $renewed++;
            } catch (ValidationException $e) {
                $failed[] = ['student' => $renewal->student->name_ar, 'reason' => (string) collect($e->errors())->flatten()->first()];
            }
        }

        return ['renewed' => $renewed, 'failed' => $failed];
    }

    /** The section of `$gradeId` in the new year with the most free seats that suits the student's gender. */
    public function bestSection(Student $student, int $yearId, int $gradeId): ?Classroom
    {
        return $this->sections($yearId, $gradeId, $student->gender)->first(fn ($c) => $c['free'] === null || $c['free'] > 0)['model'] ?? null;
    }

    /**
     * The sections of a grade in a year with how many students they hold, the emptiest first.
     *
     * @return Collection<int, array{model: Classroom, id: int, name: string, students: int, capacity: int|null, free: int|null}>
     */
    public function sections(int $yearId, int $gradeId, ?string $gender = null): Collection
    {
        $counts = Enrollment::where('academic_year_id', $yearId)->selectRaw('classroom_id, count(*) as n')->groupBy('classroom_id')->pluck('n', 'classroom_id');

        return Classroom::where('academic_year_id', $yearId)->where('grade_level_id', $gradeId)
            ->when($gender, fn ($q) => $q->where(fn ($w) => $w->whereNull('gender')->orWhere('gender', 'mixed')->orWhere('gender', $gender)))
            ->orderBy('name')->get()
            ->map(fn (Classroom $c) => ['model' => $c, 'id' => $c->id, 'name' => $c->name, 'students' => (int) ($counts[$c->id] ?? 0), 'capacity' => $c->capacity, 'free' => $c->capacity === null ? null : $c->capacity - (int) ($counts[$c->id] ?? 0)])
            ->sortBy(fn ($r) => $r['students'])->values();
    }
}
