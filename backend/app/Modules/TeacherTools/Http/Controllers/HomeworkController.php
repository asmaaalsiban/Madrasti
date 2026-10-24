<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Student;
use App\Modules\TeacherTools\Http\Requests\HomeworkRequest;
use App\Modules\TeacherTools\Http\Resources\HomeworkResource;
use App\Modules\TeacherTools\Models\HomeworkAssignment;
use App\Modules\TeacherTools\Models\HomeworkSubmission;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Homework: a teacher sends it to a section; the student and their parents read it. */
class HomeworkController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['classroom_id' => ['sometimes', 'integer']]);
        $user = $this->staff($request);

        $rows = HomeworkAssignment::with(['classroom', 'subject', 'teacher'])
            ->where('teacher_id', $user->id)
            ->when(isset($data['classroom_id']), fn ($q) => $q->where('classroom_id', $data['classroom_id']))
            ->orderByDesc('due_at')->orderByDesc('id')->get();

        return HomeworkResource::collection($rows);
    }

    public function store(HomeworkRequest $request): JsonResponse
    {
        $user = $this->staff($request);
        $data = $request->validated();
        $homework = HomeworkAssignment::create($this->fields($data) + ['teacher_id' => $user->id, 'published_at' => now()]);

        return (new HomeworkResource($homework->load(['classroom', 'subject', 'teacher'])))->response()->setStatusCode(201);
    }

    public function update(HomeworkRequest $request, HomeworkAssignment $homework): HomeworkResource
    {
        $this->own($request, $homework);
        $homework->update($this->fields($request->validated()));

        return new HomeworkResource($homework->load(['classroom', 'subject', 'teacher']));
    }

    public function destroy(Request $request, HomeworkAssignment $homework): Response
    {
        $this->own($request, $homework);
        $homework->submissions->each(fn ($sub) => $sub->file_path && Storage::delete($sub->file_path));
        $this->forgetImage($homework);
        $homework->delete();

        return response()->noContent();
    }

    /** Adds or replaces the picture of the homework (a photo of the page or the task). */
    public function storeImage(Request $request, HomeworkAssignment $homework): HomeworkResource
    {
        $this->own($request, $homework);
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        $this->forgetImage($homework);
        $homework->update(['attachment_path' => $request->file('image')->store('homework')]);

        return new HomeworkResource($homework->load(['classroom', 'subject', 'teacher']));
    }

    public function destroyImage(Request $request, HomeworkAssignment $homework): Response
    {
        $this->own($request, $homework);
        $this->forgetImage($homework);
        $homework->update(['attachment_path' => null]);

        return response()->noContent();
    }

    /** The picture: the teacher, admins, and the students of the section and their parents. */
    public function image(Request $request, HomeworkAssignment $homework): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($homework->attachment_path !== null && Storage::exists($homework->attachment_path), 404);
        abort_unless($this->mayRead($user, $homework), 403);

        return Storage::response($homework->attachment_path);
    }

    private function mayRead(User $user, HomeworkAssignment $homework): bool
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

    private function forgetImage(HomeworkAssignment $homework): void
    {
        if ($homework->attachment_path !== null) {
            Storage::delete($homework->attachment_path);
        }
    }

    /** The signed-in student's homework. */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user->hasRole(Role::Student) && $user->student !== null, 403);

        return $this->forStudentModel($user->student);
    }

    /** A student's homework: the student, their parents and admins (a gate checks). */
    public function forStudent(Student $student): AnonymousResourceCollection
    {
        Gate::authorize('view-student-homework', $student);

        return $this->forStudentModel($student);
    }

    private function forStudentModel(Student $student): AnonymousResourceCollection
    {
        $year = AcademicYear::where('is_current', true)->first();
        $classroomId = $year ? $student->enrollments()->where('academic_year_id', $year->id)->value('classroom_id') : null;

        $rows = $classroomId === null ? collect() : HomeworkAssignment::with(['classroom', 'subject', 'teacher'])
            ->where('classroom_id', $classroomId)->whereNotNull('published_at')->orderByDesc('due_at')->orderByDesc('id')->get();

        $mine = HomeworkSubmission::where('student_id', $student->id)->whereIn('homework_assignment_id', $rows->pluck('id'))->get()->keyBy('homework_assignment_id');
        $rows->each(fn ($h) => $h->setRelation('mySubmission', $mine->get($h->id)));

        return HomeworkResource::collection($rows);
    }

    private function staff(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasRole(Role::Teacher), 403);

        return $user;
    }

    private function own(Request $request, HomeworkAssignment $homework): void
    {
        abort_unless($this->staff($request)->id === $homework->teacher_id, 403);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return [
            'classroom_id' => $data['classroom_id'], 'subject_id' => $data['subject_id'], 'title' => $data['title'],
            'description' => $data['description'] ?? null, 'due_at' => $data['due_on'].' 23:59:59',
        ];
    }
}
