<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SaveCurriculumRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'periods_per_week' => ['sometimes', 'integer', 'between:5,80'],
            'cells' => ['present', 'array', 'max:2000'],
            'cells.*.grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'],
            'cells.*.subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'cells.*.weekly_periods' => ['nullable', 'integer', 'between:0,40'],
        ];
    }

    /** A subject appears once per grade. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $pairs = collect($this->input('cells'))->map(fn ($c) => $c['grade_level_id'].'-'.$c['subject_id']);
            if ($pairs->count() !== $pairs->unique()->count()) {
                $validator->errors()->add('cells', __('academic.curriculum_duplicate'));
            }
        }];
    }
}
