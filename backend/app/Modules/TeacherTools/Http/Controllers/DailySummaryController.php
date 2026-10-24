<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Communication\Services\Notifier;
use App\Modules\TeacherTools\Models\DailySummary;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Daily summary: the teacher writes what the lesson covered; the section's students and their parents read it. */
class DailySummaryController extends Controller
{
    private const LIMIT = 90;

    /** The signed-in teacher's own summaries, newest first (optionally one section / subject). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['classroom_id' => ['sometimes', 'integer'], 'subject_id' => ['sometimes', 'integer']]);

        $rows = DailySummary::with(['classroom', 'subject', 'teacher'])->where('teacher_id', $request->user()->id)
            ->when(isset($data['classroom_id']), fn ($q) => $q->where('classroom_id', $data['classroom_id']))
            ->when(isset($data['subject_id']), fn ($q) => $q->where('subject_id', $data['subject_id']))
            ->orderByDesc('date')->orderByDesc('id')->limit(self::LIMIT)->get();

        return response()->json(['data' => $rows->map(fn (DailySummary $s) => $this->shape($s))]);
    }

    /** Writes (or replaces) the summary of a subject in a section for a day; it is published at once. */
    public function save(Request $request, Notifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'summary' => ['required', 'string', 'max:5000'],
        ]);
        $teaches = TeachingAssignment::where('classroom_id', $data['classroom_id'])->where('subject_id', $data['subject_id'])->where('teacher_id', $request->user()->id)->exists();
        if (! $teaches) {
            throw ValidationException::withMessages(['classroom_id' => [__('teacher_tools.not_your_class')]]);
        }

        $summary = DailySummary::updateOrCreate(
            ['classroom_id' => $data['classroom_id'], 'subject_id' => $data['subject_id'], 'date' => $data['date'] ?? now()->toDateString()],
            ['teacher_id' => $request->user()->id, 'summary' => $data['summary'], 'published_at' => now()],
        );

        if ($summary->wasRecentlyCreated) {
            $subject = $summary->subject ?? $summary->load('subject')->subject;
            $notifier->toSection($summary->classroom_id, 'summaries', 'summary', ['subject_ar' => $subject->name_ar, 'subject_en' => $subject->name_en ?: $subject->name_ar]);
        }

        return response()->json(['data' => $this->shape($summary->load(['classroom', 'subject', 'teacher']))]);
    }

    public function destroy(Request $request, DailySummary $summary): Response
    {
        abort_unless($summary->teacher_id === $request->user()->id, 403);
        $summary->delete();

        return response()->noContent();
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

        /** @var Collection<int, DailySummary> $rows */
        $rows = $classroomId === null ? collect() : DailySummary::with(['classroom', 'subject', 'teacher'])
            ->where('classroom_id', $classroomId)->whereNotNull('published_at')->orderByDesc('date')->orderBy('subject_id')->limit(self::LIMIT)->get();

        return response()->json(['data' => $rows->map(fn (DailySummary $s) => $this->shape($s))]);
    }

    /** @return array<string, mixed> */
    private function shape(DailySummary $s): array
    {
        return [
            'id' => $s->id, 'date' => $s->date->format('Y-m-d'), 'summary' => $s->summary,
            'classroom' => $s->classroom->only(['id', 'name']), 'subject' => $s->subject->only(['id', 'name_ar', 'name_en']), 'teacher' => $s->teacher->only(['id', 'name']),
        ];
    }
}
