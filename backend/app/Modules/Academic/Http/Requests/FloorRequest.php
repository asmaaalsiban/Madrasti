<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Requests;

use App\Modules\Academic\Models\Floor;
use App\Modules\Academic\Models\GradeLevel;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class FloorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'name' => ['required', 'string', 'max:100'],
            'level' => ['required', 'integer', 'between:-5,60'],
            'gender' => ['nullable', 'in:male,female,mixed'],
            'grade_from_id' => ['nullable', 'integer', 'exists:grade_levels,id'],
            'grade_to_id' => ['nullable', 'integer', 'exists:grade_levels,id'],
            'classrooms' => ['required', 'integer', 'between:0,200'],
            'science_labs' => ['required', 'integer', 'between:0,200'],
            'computer_labs' => ['required', 'integer', 'between:0,200'],
        ];
    }

    /** The grade range has both ends (or none) and runs from the lower grade to the higher; a level is used once per building. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = $this->input('grade_from_id');
            $to = $this->input('grade_to_id');
            if (($from === null) !== ($to === null)) {
                $validator->errors()->add('grade_to_id', __('academic.floor_grade_range_incomplete'));
            } elseif ($from !== null && GradeLevel::findOrFail($from)->sort_order > GradeLevel::findOrFail($to)->sort_order) {
                $validator->errors()->add('grade_to_id', __('academic.floor_grade_range_reversed'));
            }

            $duplicate = Floor::where('building_id', $this->input('building_id'))->where('level', $this->input('level'))
                ->when($this->route('floor'), fn ($q, $id) => $q->whereKeyNot($id))->exists();
            if ($duplicate) {
                $validator->errors()->add('level', __('academic.floor_level_taken'));
            }
        }];
    }
}
