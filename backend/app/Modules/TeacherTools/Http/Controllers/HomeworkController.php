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
use App\Modules\TeacherTools\Models\HomeworkFile;
use App\Modules\TeacherTools\Models\HomeworkSubmission;
use App\Shared\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Homework: a teacher sends it to a section; the student and their parents read it. */
class HomeworkController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['classroom_id' => ['sometimes', 'integer']]);
        $user = $this->staff($request);

        $rows = HomeworkAssignment::with(['classroom', 'subject', 'teacher', 'files'])
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

        return (new HomeworkResource($homework->load(['classroom', 'subject', 'teacher', 'files'])))->response()->setStatusCode(201);
    }

    public function update(HomeworkRequest $request, HomeworkAssignment $homework): HomeworkResource
    {
        $this->own($request, $homework);
        $homework->update($this->fields($request->validated()));

        return new HomeworkResource($homework->load(['classroom', 'subject', 'teacher', 'files']));
    }

    public function destroy(Request $request, HomeworkAssignment $homework): Response
    {
        $this->own($request, $homework);
        HomeworkFile::forget($homework->files);
        $homework->submissions()->with('files')->get()->each(fn ($sub) => HomeworkFile::forget($sub->files));
        $homework->delete();

        return response()->noContent();
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

        $rows = $classroomId === null ? collect() : HomeworkAssignment::with(['classroom', 'subject', 'teacher', 'files'])
            ->where('classroom_id', $classroomId)->whereNotNull('published_at')->orderByDesc('due_at')->orderByDesc('id')->get();

        $mine = HomeworkSubmission::with('files')->where('student_id', $student->id)->whereIn('homework_assignment_id', $rows->pluck('id'))->get()->keyBy('homework_assignment_id');
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
