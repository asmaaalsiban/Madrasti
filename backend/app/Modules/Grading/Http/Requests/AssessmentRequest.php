<?php

declare(strict_types=1);

namespace App\Modules\Grading\Http\Requests;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Models\Term;
use App\Modules\Grading\Models\Assessment;
use App\Shared\Enums\Role;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssessmentRequest extends FormRequest
{
    public const TYPES = ['quiz', 'exam', 'assignment', 'project', 'participation'];

    /** A teacher may only grade a subject in a classroom they teach (checked before validating). */
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();
        $assessment = $this->route('assessment');

        if ($assessment instanceof Assessment) {
            return $user->hasRole(Role::Admin) || $assessment->teacher_id === $user->id;
        }

        return $user->hasRole(Role::Admin) || $this->assignment()?->teacher_id === $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'term_id' => ['required', 'integer', 'exists:terms,id'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'max_score' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'held_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function assignment(): ?TeachingAssignment
    {
        $assessment = $this->route('assessment');
        $classroom = $assessment instanceof Assessment ? $assessment->classroom_id : $this->input('classroom_id');
        $subject = $assessment instanceof Assessment ? $assessment->subject_id : $this->input('subject_id');

        return is_numeric($classroom) && is_numeric($subject)
            ? TeachingAssignment::where('classroom_id', $classroom)->where('subject_id', $subject)->first()
            : null;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $assessment = $this->route('assessment');
            $classroom = Classroom::findOrFail($this->integer('classroom_id'));
            $term = Term::findOrFail($this->integer('term_id'));

            if ($term->academic_year_id !== $classroom->academic_year_id) {
                $validator->errors()->add('term_id', __('grading.term_not_of_year'));
            }
            if ($this->assignment() === null) {
                $validator->errors()->add('subject_id', __('grading.no_teacher_assigned'));
            }

            $others = Assessment::where('classroom_id', $classroom->id)->where('subject_id', $this->input('subject_id'))->where('term_id', $term->id)
                ->when($assessment instanceof Assessment, fn ($q) => $q->whereKeyNot($assessment->id))->sum('weight');
            if ($others + (float) $this->input('weight') > 100) {
                $validator->errors()->add('weight', __('grading.weight_over_100', ['used' => rtrim(rtrim(number_format((float) $others, 2, '.', ''), '0'), '.')]));
            }

            if ($assessment instanceof Assessment && (float) $assessment->scores()->max('score') > (float) $this->input('max_score')) {
                $validator->errors()->add('max_score', __('grading.max_below_scores'));
            }
        }];
    }
}
