<?php

declare(strict_types=1);

namespace App\Modules\Motivation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Motivation\Models\Badge;
use App\Modules\Motivation\Models\PointAward;
use App\Modules\Motivation\Models\PointCategory;
use App\Modules\Motivation\Services\Motivation;
use App\Modules\TeacherTools\Services\BehaviorAccess;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Points, badges and the star of the week. Teachers (and the admin) give points to the students they teach. */
class MotivationController extends Controller
{
    public function __construct(private readonly BehaviorAccess $access, private readonly Motivation $motivation) {}

    /** The students of one section with their points, the star of the week (and this week's leaders), and the categories and badges to give. */
    public function classroom(Request $request): JsonResponse
    {
        $data = $request->validate(['classroom_id' => ['required', 'integer']]);
        abort_unless($this->access->classrooms($request->user())->contains('id', (int) $data['classroom_id']), 403);

        $year = AcademicYear::where('is_current', true)->first();
        $students = Student::whereIn('id', Enrollment::where('classroom_id', $data['classroom_id'])->where('academic_year_id', $year?->id)->select('student_id'))->orderBy('name_ar')->get();
        $totals = $this->motivation->totals($students->pluck('id'));
        $star = $this->motivation->star((int) $data['classroom_id']);
        $leaders = $this->motivation->leaders((int) $data['classroom_id']);

        return response()->json(['data' => [
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en,
                'total' => $totals[$s->id]['total'], 'week' => $totals[$s->id]['week'], 'is_star' => $star === $s->id, 'is_leader' => $leaders->contains($s->id),
            ])->values(),
            'categories' => PointCategory::where('is_active', true)->orderByDesc('points')->get(),
            'badges' => Badge::where('is_active', true)->orderBy('id')->get(),
        ]]);
    }

    /** Gives (or takes, if negative) points to one or more students, by a ready-made category or a free amount and note. */
    public function award(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:100'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
            'point_category_id' => ['nullable', 'integer', 'exists:point_categories,id'],
            'points' => ['nullable', 'integer', 'between:-20,20', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $category = isset($data['point_category_id']) ? PointCategory::findOrFail($data['point_category_id']) : null;
        if (! $category && (blank($data['points'] ?? null) || blank($data['note'] ?? null))) {
            throw ValidationException::withMessages(['point_category_id' => [__('motivation.category_required')]]);
        }
        $students = Student::whereIn('id', $data['student_ids'])->get();
        foreach ($students as $student) {
            abort_unless($this->access->canEvaluate($request->user(), $student), 403);
        }

        $points = $category?->points ?? (int) $data['points'];
        $note = $category ? $category->name_ar.' / '.$category->name_en : $data['note'];
        DB::transaction(function () use ($students, $request, $category, $points, $note): void {
            foreach ($students as $student) {
                PointAward::create(['student_id' => $student->id, 'teacher_id' => $request->user()->id, 'point_category_id' => $category?->id, 'note' => $note, 'points' => $points, 'awarded_at' => now()]);
                $this->motivation->awardEarnedBadges($student);
            }
        });

        return response()->json(['data' => ['students' => $students->count(), 'points' => $points]], 201);
    }

    /** Crowns one student of a section the star of this week (replacing an earlier pick) and tells the family. */
    public function crown(Request $request): JsonResponse
    {
        $data = $request->validate(['classroom_id' => ['required', 'integer'], 'student_id' => ['required', 'integer'], 'reason' => ['nullable', 'string', 'max:255']]);
        abort_unless($this->access->classrooms($request->user())->contains('id', (int) $data['classroom_id']), 403);
        $year = AcademicYear::where('is_current', true)->first();
        abort_unless(Enrollment::where('classroom_id', $data['classroom_id'])->where('academic_year_id', $year?->id)->where('student_id', $data['student_id'])->exists(), 422);

        $week = $this->motivation->weekStart(now()->toDateString());
        $same = $this->motivation->star((int) $data['classroom_id']) === (int) $data['student_id'];
        DB::table('star_of_week')->updateOrInsert(
            ['classroom_id' => $data['classroom_id'], 'week_starts_on' => $week],
            ['student_id' => $data['student_id'], 'selected_by' => $request->user()->id, 'reason' => $data['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()],
        );
        if (! $same) {
            $this->motivation->announceStar(Student::findOrFail($data['student_id']));
        }

        return response()->json(['data' => ['student_id' => (int) $data['student_id']]]);
    }

    /** Takes back an award: the teacher who gave it, or the admin. Badges already earned stay. */
    public function undo(Request $request, PointAward $award): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(Role::Admin) || $award->teacher_id === $user->id, 403);
        $award->delete();

        return response()->noContent();
    }

    /** Hand a badge to a student (also for badges with no points threshold). */
    public function giveBadge(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate(['badge_id' => ['required', 'integer', 'exists:badges,id']]);
        abort_unless($this->access->canEvaluate($request->user(), $student), 403);
        $given = $this->motivation->giveBadge($student, Badge::findOrFail($data['badge_id']), $request->user()->id);
        if (! $given) {
            throw ValidationException::withMessages(['badge_id' => [__('motivation.already_has_badge')]]);
        }

        return response()->json(['data' => ['ok' => true]], 201);
    }

    /** One student's points, recent awards and badges: for them, their parents and the staff who may evaluate them. */
    public function student(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('view-student-motivation', $student);

        $awards = PointAward::with('teacher')->where('student_id', $student->id)->orderByDesc('awarded_at')->orderByDesc('id')->limit(50)->get();
        $totals = $this->motivation->totals(collect([$student->id]))[$student->id];
        $badges = DB::table('student_badges')->join('badges', 'badges.id', '=', 'student_badges.badge_id')->where('student_id', $student->id)
            ->orderBy('awarded_at')->get(['badges.id', 'name_ar', 'name_en', 'icon', 'awarded_at']);
        $classroomId = Enrollment::where('student_id', $student->id)->whereIn('academic_year_id', AcademicYear::where('is_current', true)->select('id'))->value('classroom_id');
        $next = Badge::where('is_active', true)->whereNotNull('points_required')->where('points_required', '>', $totals['total'])->orderBy('points_required')->first(['name_ar', 'name_en', 'icon', 'points_required']);

        return response()->json(['data' => $totals + [
            'is_star' => $classroomId && $this->motivation->star((int) $classroomId) === $student->id,
            'badges' => $badges,
            'next_badge' => $next,
            'awards' => $awards->map(fn (PointAward $a) => [
                'id' => $a->id, 'note' => $a->note, 'points' => $a->points, 'awarded_on' => $a->awarded_at->format('Y-m-d'), 'teacher' => $a->teacher->name,
                'mine' => $a->teacher_id === $request->user()->id,
            ])->values(),
        ]]);
    }
}
