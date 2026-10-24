<?php

declare(strict_types=1);

namespace App\Modules\TeacherTools\Http\Requests;

use App\Models\User;
use App\Modules\Academic\Models\TeachingAssignment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class HomeworkRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** The subject must be taught in the section, and only the teacher of the section may send homework. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            /** @var User $user */
            $user = $this->user();
            $assignment = TeachingAssignment::where('classroom_id', $this->input('classroom_id'))->where('subject_id', $this->input('subject_id'))->first();
            if ($assignment === null || $assignment->teacher_id !== $user->id) {
                $validator->errors()->add('classroom_id', __('teacher_tools.not_your_class'));
            }
        }];
    }
}
