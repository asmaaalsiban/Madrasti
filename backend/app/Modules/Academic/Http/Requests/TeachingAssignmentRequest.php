<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Requests;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\GradeLevelSubject;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Shared\Enums\StaffType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class TeachingAssignmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'teacher_id' => ['required', 'integer'],
            'weekly_periods' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }

    /** One teacher per subject per classroom; the teacher is an active teaching staff member whose subject is this one. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $taken = TeachingAssignment::where('classroom_id', $this->input('classroom_id'))->where('subject_id', $this->input('subject_id'))
                ->when($this->route('teaching_assignment'), fn ($q, $id) => $q->whereKeyNot($id))->exists();
            if ($taken) {
                $validator->errors()->add('subject_id', __('academic.subject_already_taught'));
            }

            // Once a grade has a curriculum, only its subjects can be assigned to its classrooms.
            $gradeId = Classroom::whereKey($this->input('classroom_id'))->value('grade_level_id');
            $curriculum = GradeLevelSubject::where('grade_level_id', $gradeId);
            if ((clone $curriculum)->exists() && ! (clone $curriculum)->where('subject_id', $this->input('subject_id'))->exists()) {
                $validator->errors()->add('subject_id', __('academic.subject_not_in_curriculum'));
            }

            $valid = User::whereKey($this->input('teacher_id'))->where('is_active', true)
                ->whereHas('staffProfile', fn ($q) => $q->where('subject_id', $this->input('subject_id'))->whereIn('staff_type', StaffType::valuesIn('teaching')))
                ->exists();
            if (! $valid) {
                $validator->errors()->add('teacher_id', __('academic.teacher_not_for_subject'));
            } else {
                // The teacher teaches these grades and these students (boys / girls).
                $classroom = Classroom::with('gradeLevel')->find($this->input('classroom_id'));
                $profile = User::find($this->input('teacher_id'))->staffProfile()->with(['gradeFrom', 'gradeTo', 'classrooms'])->first();
                if ($classroom && $profile && ! $profile->canTeach((int) $classroom->gradeLevel->sort_order, $classroom->gender ?? 'mixed', $classroom->id)) {
                    $validator->errors()->add('teacher_id', __('academic.teacher_outside_scope'));
                }
            }
        }];
    }
}
