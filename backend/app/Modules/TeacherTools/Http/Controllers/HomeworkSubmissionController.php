<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\TeacherTools\Models\HomeworkAssignment;
use App\Modules\TeacherTools\Models\HomeworkFile;
use App\Modules\TeacherTools\Models\HomeworkSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A student's answer to a homework (text and/or a photo), and the teacher's list of who answered. */
class HomeworkSubmissionController extends Controller
{
    /** The teacher of the homework: every student of the section, with their answer if they sent one. */
    public function index(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        abort_unless($homework->teacher_id === $request->user()->id, 403);

        $year = AcademicYear::where('is_current', true)->first();
        $students = Student::whereIn('id', Enrollment::where('classroom_id', $homework->classroom_id)->where('academic_year_id', $year?->id)->select('student_id'))->orderBy('name_ar')->get();
        $answers = $homework->submissions()->with('files')->get()->keyBy('student_id');

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'student' => $s->only(['id', 'name_ar', 'name_en']),
            'submission' => ($a = $answers->get($s->id)) ? $this->shape($a, $homework) : null,
        ])->values()]);
    }

    /**
     * The signed-in student sends (or changes) their answer: a text and photos of the pages. New photos are added after the
     * ones already sent (never replacing them); remove_file_ids drops chosen photos.
     */
    public function store(Request $request, HomeworkAssignment $homework): JsonResponse
    {
        $student = $this->studentOf($request->user(), $homework);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'images' => ['nullable', 'array', 'max:'.HomeworkFile::MAX_PER_OWNER],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_file_ids' => ['nullable', 'array'],
            'remove_file_ids.*' => ['integer'],
        ]);

        $submission = HomeworkSubmission::with('files')->where('homework_assignment_id', $homework->id)->where('student_id', $student->id)->first();
        $drop = $submission?->files->whereIn('id', $data['remove_file_ids'] ?? []) ?? collect();
        $uploads = $request->file('images', []);
        $remaining = ($submission?->files->count() ?? 0) - $drop->count();

        if ($remaining + count($uploads) > HomeworkFile::MAX_PER_OWNER) {
            throw ValidationException::withMessages(['images' => [__('teacher_tools.too_many_photos', ['max' => HomeworkFile::MAX_PER_OWNER])]]);
        }
        if (blank($data['body'] ?? null) && $remaining + count($uploads) === 0) {
            throw ValidationException::withMessages(['body' => [__('teacher_tools.empty_answer')]]);
        }

        $submission = DB::transaction(function () use ($homework, $student, $data, $submission, $drop, $uploads): HomeworkSubmission {
            $submission = HomeworkSubmission::updateOrCreate(
                ['homework_assignment_id' => $homework->id, 'student_id' => $student->id],
                ['body' => $data['body'] ?? null, 'submitted_at' => now()],
            );
            HomeworkFile::whereIn('id', $drop->pluck('id'))->delete();
            HomeworkFile::addTo(['homework_submission_id' => $submission->id], $uploads, 'homework-answers');

            return $submission;
        });
        HomeworkFile::forget($drop);

        return response()->json(['data' => $this->shape($submission->load('files'), $homework)]);
    }

    public function destroy(Request $request, HomeworkAssignment $homework): Response
    {
        $student = $this->studentOf($request->user(), $homework);
        $submission = HomeworkSubmission::with('files')->where('homework_assignment_id', $homework->id)->where('student_id', $student->id)->first();
        if ($submission !== null) {
            HomeworkFile::forget($submission->files);
            $submission->delete();
        }

        return response()->noContent();
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
            'id' => $s->id, 'body' => $s->body, 'images' => $s->files->pluck('id'),
            'submitted_at' => $s->submitted_at->toIso8601String(), 'late' => $s->submitted_at->gt($homework->due_at),
        ];
    }
}
