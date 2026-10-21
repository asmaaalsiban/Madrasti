<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class GenerateClassroomsRequest extends FormRequest
{
    public const MAX_PER_GRADE = 26;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'dry_run' => ['sometimes', 'boolean'],
            'plan' => ['required', 'array', 'min:1', 'max:60'],
            'plan.*.grade_level_id' => ['required', 'integer', 'distinct', 'exists:grade_levels,id'],
            'plan.*.male' => ['required', 'integer', 'between:0,'.self::MAX_PER_GRADE],
            'plan.*.female' => ['required', 'integer', 'between:0,'.self::MAX_PER_GRADE],
            'plan.*.mixed' => ['required', 'integer', 'between:0,'.self::MAX_PER_GRADE],
        ];
    }

    /** The sections of one grade get the letters A to Z. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ($this->input('plan') as $i => $row) {
                if ($row['male'] + $row['female'] + $row['mixed'] > self::MAX_PER_GRADE) {
                    $validator->errors()->add("plan.$i", __('academic.too_many_sections', ['max' => self::MAX_PER_GRADE]));
                }
            }
        }];
    }
}
