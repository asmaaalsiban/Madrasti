<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Controllers;

use App\Modules\Academic\Http\Requests\SubjectRequest;
use App\Modules\Academic\Http\Resources\SubjectResource;
use App\Modules\Academic\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SubjectController extends AcademicController
{
    protected string $model = Subject::class;

    protected array $referencedBy = ['classroom_subject_teacher' => 'subject_id', 'assessments' => 'subject_id', 'timetable_entries' => 'subject_id', 'exam_sessions' => 'subject_id', 'lesson_plans' => 'subject_id', 'curriculum_units' => 'subject_id', 'daily_summaries' => 'subject_id', 'homework_assignments' => 'subject_id'];

    protected string $resource = SubjectResource::class;

    protected array $searchable = ['code', 'name_ar', 'name_en'];

    protected string $orderBy = 'code';

    public function store(SubjectRequest $request): JsonResponse
    {
        Gate::authorize('create', Subject::class);

        return (new SubjectResource(Subject::create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(SubjectRequest $request, Subject $subject): SubjectResource
    {
        Gate::authorize('update', $subject);
        $subject->update($request->validated());

        return new SubjectResource($subject);
    }

    protected function resourceKey(): string
    {
        return 'subject';
    }
}
