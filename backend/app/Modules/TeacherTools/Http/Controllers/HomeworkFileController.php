<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\TeacherTools\Http\Resources\HomeworkResource;
use App\Modules\TeacherTools\Models\HomeworkAssignment;
use App\Modules\TeacherTools\Models\HomeworkFile;
use App\Shared\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The private photos of a homework (added by its teacher) and of answers (added by students): add, remove and open. */
class HomeworkFileController extends Controller
{
    /** Adds photos to a homework after the ones it has (the teacher of the homework). */
    public function store(Request $request, HomeworkAssignment $homework): HomeworkResource
    {
        abort_unless($homework->teacher_id === $request->user()->id, 403);
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:'.HomeworkFile::MAX_PER_OWNER],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        if ($homework->files()->count() + count($data['images']) > HomeworkFile::MAX_PER_OWNER) {
            throw ValidationException::withMessages(['images' => [__('teacher_tools.too_many_photos', ['max' => HomeworkFile::MAX_PER_OWNER])]]);
        }

        HomeworkFile::addTo(['homework_assignment_id' => $homework->id], $request->file('images'), 'homework');

        return new HomeworkResource($homework->load(['classroom', 'subject', 'teacher', 'files']));
    }

    /** Removes one photo: of a homework (its teacher) or of an answer (the student who sent it). */
    public function destroy(Request $request, HomeworkFile $file): Response
    {
        /** @var User $user */
        $user = $request->user();
        $allowed = $file->homework_assignment_id !== null
            ? $user->hasRole(Role::Teacher) && $file->homework->teacher_id === $user->id
            : $user->hasRole(Role::Student) && $user->student?->id === $file->submission->student_id;
        abort_unless($allowed, 403);

        HomeworkFile::forget(collect([$file]));
        $file->delete();

        return response()->noContent();
    }

    /** Opens a photo. A homework's: its teacher, the section's students and their parents. An answer's: the homework's teacher, the student, their parents. */
    public function show(Request $request, HomeworkFile $file): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(Storage::exists($file->path), 404);

        $allowed = $file->homework_assignment_id !== null
            ? $this->mayReadHomework($user, $file->homework)
            : match ($user->role) {
                Role::Teacher => $file->submission->homework->teacher_id === $user->id,
                Role::Student => $user->student?->id === $file->submission->student_id,
                Role::Guardian => $file->submission->student->guardians()->whereKey($user->id)->exists(),
                default => false,
            };
        abort_unless($allowed, 403);

        return Storage::response($file->path);
    }

    private function mayReadHomework(User $user, HomeworkAssignment $homework): bool
    {
        if ($user->hasRole(Role::Admin)) {
            return true;
        }
        if ($user->hasRole(Role::Teacher)) {
            return $homework->teacher_id === $user->id;
        }
        if ($homework->published_at === null) {
            return false;
        }
        $students = $user->hasRole(Role::Student) ? collect([$user->student])->filter() : $user->children;
        $year = AcademicYear::where('is_current', true)->first();

        return $year !== null && $students->contains(fn (Student $s) => $s->enrollments()->where('academic_year_id', $year->id)->where('classroom_id', $homework->classroom_id)->exists());
    }
}
