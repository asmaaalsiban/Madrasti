<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\TeacherTools\Models\HomeworkAssignment;
use App\Modules\TeacherTools\Models\HomeworkSubmission;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A student's answer to a homework (text and/or a photo), and the teacher's list of who answered. */
class HomeworkSubmissionController extends Controller
{
    /** The teacher of the homework: every student of the section, with their answer if they sent one. */
    public function index(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        abort_unless($homework->teacher_id === $request->user()->id, 403);

        $year = AcademicYear::where('is_current', true)->first();
        $students = Student::whereIn('id', Enrollment::where('classroom_id', $homework->classroom_id)->where('academic_year_id', $year?->id)->select('student_id'))->orderBy('name_ar')->get();
        $answers = $homework->submissions()->get()->keyBy('student_id');

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'student' => $s->only(['id', 'name_ar', 'name_en']),
            'submission' => ($a = $answers->get($s->id)) ? $this->shape($a, $homework) : null,
        ])->values()]);
    }

    /** The signed-in student sends (or replaces) their answer. A new photo replaces the old one; remove_image drops it. */
    public function store(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        $student = $this->studentOf($request->user(), $homework);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['sometimes', 'boolean'],
        ]);

        $existing = HomeworkSubmission::where('homework_assignment_id', $homework->id)->where('student_id', $student->id)->first();
        $path = $existing?->file_path;
        if ($request->hasFile('image') || $request->boolean('remove_image')) {
            $path = null;
        }
        if (! $request->hasFile('image') && blank($data['body'] ?? null) && $path === null) {
            throw ValidationException::withMessages(['body' => [__('teacher_tools.empty_answer')]]);
        }
        if ($path !== $existing?->file_path && $existing?->file_path !== null) {
            Storage::delete($existing->file_path);
        }
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('homework-answers');
        }

        $submission = HomeworkSubmission::updateOrCreate(
            ['homework_assignment_id' => $homework->id, 'student_id' => $student->id],
            ['body' => $data['body'] ?? null, 'file_path' => $path, 'submitted_at' => now()],
        );

        return response()->json(['data' => $this->shape($submission, $homework)]);
    }

    public function destroy(Request $request, HomeworkAssignment $homework): Response
    {
        $student = $this->studentOf($request->user(), $homework);
        $submission = HomeworkSubmission::where('homework_assignment_id', $homework->id)->where('student_id', $student->id)->first();
        if ($submission !== null) {
            $submission->file_path && Storage::delete($submission->file_path);
            $submission->delete();
        }

        return response()->noContent();
    }

    /** The photo of an answer: the teacher of the homework, the student, and the student's parents. */
    public function image(Request $request, HomeworkSubmission $submission): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($submission->file_path !== null && Storage::exists($submission->file_path), 404);
        $allowed = match ($user->role) {
            Role::Teacher => $submission->homework->teacher_id === $user->id,
            Role::Student => $user->student?->id === $submission->student_id,
            Role::Guardian => $submission->student->guardians()->whereKey($user->id)->exists(),
            default => false,
        };
        abort_unless($allowed, 403);

        return Storage::response($submission->file_path);
    }

    /** The student record of the signed-in student, who must be in the section the homework went to. */
    private function studentOf(User $user, HomeworkAssignment $homework): Student
    {
        $student = $user->student;
        $year = AcademicYear::where('is_current', true)->first();
        abort_unless($student !== null && $homework->published_at !== null
            && $student->enrollments()->where('academic_year_id', $year?->id)->where('classroom_id', $homework->classroom_id)->exists(), 403);

        return $student;
    }

    /** @return array<string, mixed> */
    private function shape(HomeworkSubmission $s, HomeworkAssignment $homework): array
    {
        return [
            'id' => $s->id, 'body' => $s->body, 'has_image' => $s->file_path !== null,
            'submitted_at' => $s->submitted_at->toIso8601String(), 'late' => $s->submitted_at->gt($homework->due_at),
        ];
    }
}
