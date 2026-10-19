<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Modules\Academic\Http\Requests\ClassroomRequest;
use App\Modules\Academic\Http\Resources\ClassroomResource;
use App\Modules\Academic\Models\Classroom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClassroomController extends AcademicController
{
    protected string $model = Classroom::class;

    protected array $referencedBy = ['enrollments' => 'classroom_id', 'student_attendances' => 'classroom_id', 'assessments' => 'classroom_id', 'lesson_plans' => 'classroom_id', 'conversations' => 'classroom_id', 'classroom_subject_teacher' => 'classroom_id', 'timetable_entries' => 'classroom_id', 'exam_sessions' => 'classroom_id', 'curriculum_units' => 'classroom_id', 'daily_summaries' => 'classroom_id', 'homework_assignments' => 'classroom_id', 'star_of_week' => 'classroom_id', 'announcements' => 'classroom_id', 'stories' => 'classroom_id'];

    protected string $resource = ClassroomResource::class;

    protected array $searchable = ['name'];

    protected array $with = ['gradeLevel', 'academicYear', 'homeroomTeacher'];

    protected string $orderBy = 'name';

    public function store(ClassroomRequest $request): JsonResponse
    {
        Gate::authorize('create', Classroom::class);
        $classroom = Classroom::create($request->validated());

        return (new ClassroomResource($classroom->load($this->with)))->response()->setStatusCode(201);
    }

    public function update(ClassroomRequest $request, Classroom $classroom): ClassroomResource
    {
        Gate::authorize('update', $classroom);

        $newYear = (int) $request->validated('academic_year_id');
        if ($newYear !== $classroom->academic_year_id && DB::table('enrollments')->where('classroom_id', $classroom->id)->exists()) {
            throw ValidationException::withMessages(['academic_year_id' => [__('academic.year_locked')]]);
        }
        $classroom->update($request->validated());

        return new ClassroomResource($classroom->load($this->with));
    }

    protected function filter(Builder $query, Request $request): void
    {
        foreach (['grade_level_id', 'academic_year_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, (int) $request->query($column));
            }
        }
    }

    protected function resourceKey(): string
    {
        return 'classroom';
    }
}
