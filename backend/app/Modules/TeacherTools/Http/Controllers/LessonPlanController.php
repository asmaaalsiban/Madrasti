<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\TeacherTools\Models\LessonPlan;
use App\Shared\Enums\Role;
use App\Shared\Enums\StaffType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Weekly lesson plans. The teacher writes a draft and submits it; the head of their department approves it or rejects it with a
 * note (the teacher then fixes and submits again). An approved plan that is shared reaches the section's students and their parents.
 */
class LessonPlanController extends Controller
{
    private const LIMIT = 100;

    /** The signed-in teacher's plans, newest week first. */
    public function index(Request $request): JsonResponse
    {
        $rows = LessonPlan::with(['classroom', 'subject', 'teacher'])->where('teacher_id', $request->user()->id)
            ->orderByDesc('week_starts_on')->orderByDesc('id')->limit(self::LIMIT)->get();

        return response()->json(['data' => $rows->map(fn (LessonPlan $p) => $this->shape($p))]);
    }

    /** Writes (or rewrites) the plan of the week that contains `date`. Changing a sent or approved plan returns it to a draft. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:255'],
            'objectives' => ['nullable', 'string', 'max:5000'],
            'content' => ['nullable', 'string', 'max:20000'],
            'shared_with_parents' => ['sometimes', 'boolean'],
        ]);
        $teacher = $request->user();
        if (! TeachingAssignment::where('classroom_id', $data['classroom_id'])->where('subject_id', $data['subject_id'])->where('teacher_id', $teacher->id)->exists()) {
            throw ValidationException::withMessages(['classroom_id' => [__('teacher_tools.not_your_class')]]);
        }

        $key = ['teacher_id' => $teacher->id, 'classroom_id' => $data['classroom_id'], 'subject_id' => $data['subject_id'], 'week_starts_on' => $this->weekStart($data['date'] ?? now()->toDateString())];
        $plan = LessonPlan::updateOrCreate($key, [
            'title' => $data['title'], 'objectives' => $data['objectives'] ?? null, 'content' => $data['content'] ?? null,
            'shared_with_parents' => $data['shared_with_parents'] ?? true,
            // Changing a plan that was sent or approved takes it back to a draft: it must be submitted (and approved) again.
            'status' => 'draft', 'review_note' => null, 'reviewed_by' => null,
        ]);

        return response()->json(['data' => $this->shape($plan->load(['classroom', 'subject', 'teacher']))]);
    }

    public function submit(Request $request, LessonPlan $plan): JsonResponse
    {
        abort_unless($plan->teacher_id === $request->user()->id, 403);
        if (! $plan->isEditable()) {
            throw ValidationException::withMessages(['status' => [__('teacher_tools.plan_locked')]]);
        }
        // A head of department has nobody above them, and a subject without a head has no reviewer: both are approved at once.
        $profile = $request->user()->staffProfile;
        $selfApproved = $profile?->staff_type === StaffType::HeadOfDepartment || ! $this->headExists($profile?->subject_id);
        $plan->update($selfApproved
            ? ['status' => 'approved', 'review_note' => null, 'reviewed_by' => $request->user()->id]
            : ['status' => 'submitted', 'review_note' => null]);

        return response()->json(['data' => $this->shape($plan->load(['classroom', 'subject', 'teacher']))]);
    }

    /** Takes a submitted plan back before the head has decided, to change it. */
    public function withdraw(Request $request, LessonPlan $plan): JsonResponse
    {
        abort_unless($plan->teacher_id === $request->user()->id, 403);
        if ($plan->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => [__('teacher_tools.plan_not_waiting')]]);
        }
        $plan->update(['status' => 'draft']);

        return response()->json(['data' => $this->shape($plan->load(['classroom', 'subject', 'teacher']))]);
    }

    /** A plan can be deleted by its teacher whatever its status. */
    public function destroy(Request $request, LessonPlan $plan): Response
    {
        abort_unless($plan->teacher_id === $request->user()->id, 403);
        $plan->delete();

        return response()->noContent();
    }

    /** A head of department's list: the plans their department's teachers submitted (or all of one status with ?status=). */
    public function review(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', 'in:draft,submitted,approved,rejected']]);
        $subjectId = $this->departmentOf($request->user());

        $rows = LessonPlan::with(['classroom', 'subject', 'teacher'])->where('status', $data['status'] ?? 'submitted')
            ->whereHas('teacher.staffProfile', fn ($q) => $q->where('subject_id', $subjectId))->where('teacher_id', '!=', $request->user()->id)
            ->orderBy('week_starts_on')->orderBy('id')->limit(self::LIMIT)->get();

        return response()->json(['data' => $rows->map(fn (LessonPlan $p) => $this->shape($p))]);
    }

    /** Approves or rejects (with a note) a plan of a teacher of the head's own department. */
    public function decide(Request $request, LessonPlan $plan): JsonResponse
    {
        $subjectId = $this->departmentOf($request->user());
        abort_unless($plan->teacher_id !== $request->user()->id && $plan->teacher->staffProfile?->subject_id === $subjectId, 403);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['required_if:decision,reject', 'nullable', 'string', 'max:3000'],
        ]);
        if ($plan->status !== 'submitted') {
            throw ValidationException::withMessages(['decision' => [__('teacher_tools.plan_not_submitted')]]);
        }
        $plan->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $request->user()->id, 'review_note' => $data['note'] ?? null]);

        return response()->json(['data' => $this->shape($plan->load(['classroom', 'subject', 'teacher']))]);
    }

    /** The subject of the signed-in head of department (anyone else is refused). */
    private function departmentOf(User $user): int
    {
        $profile = $user->staffProfile;
        abort_unless($profile?->staff_type === StaffType::HeadOfDepartment && $profile->subject_id !== null, 403);

        return $profile->subject_id;
    }

    private function headExists(?int $subjectId): bool
    {
        return $subjectId !== null && User::where('is_active', true)
            ->whereHas('staffProfile', fn ($q) => $q->where('subject_id', $subjectId)->where('staff_type', StaffType::HeadOfDepartment->value))->exists();
    }

    /** The signed-in student's section. */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole(Role::Student) && $user->student !== null, 403);

        return $this->forStudentModel($user->student);
    }

    /** A student's section: the student, their parents and admins (a gate checks). */
    public function forStudent(Student $student): JsonResponse
    {
        Gate::authorize('view-student-homework', $student);

        return $this->forStudentModel($student);
    }

    private function forStudentModel(Student $student): JsonResponse
    {
        $year = AcademicYear::where('is_current', true)->first();
        $classroomId = $year ? $student->enrollments()->where('academic_year_id', $year->id)->value('classroom_id') : null;

        $rows = $classroomId === null ? collect() : LessonPlan::with(['classroom', 'subject', 'teacher'])
            ->where('classroom_id', $classroomId)->where('status', 'approved')->where('shared_with_parents', true)
            ->orderByDesc('week_starts_on')->orderBy('subject_id')->limit(self::LIMIT)->get();

        return response()->json(['data' => $rows->map(fn (LessonPlan $p) => $this->shape($p, false))]);
    }

    /** The Sunday of the week of a date (the school week starts on Sunday). */
    private function weekStart(string $date): string
    {
        return Carbon::parse($date)->startOfWeek(Carbon::SUNDAY)->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function shape(LessonPlan $p, bool $internal = true): array
    {
        return [
            'id' => $p->id, 'week_starts_on' => $p->week_starts_on->format('Y-m-d'), 'title' => $p->title, 'objectives' => $p->objectives, 'content' => $p->content,
            'classroom' => $p->classroom->only(['id', 'name']), 'subject' => $p->subject->only(['id', 'name_ar', 'name_en']), 'teacher' => $p->teacher->only(['id', 'name']),
        ] + ($internal ? ['status' => $p->status, 'review_note' => $p->review_note, 'shared_with_parents' => $p->shared_with_parents] : []);
    }
}
