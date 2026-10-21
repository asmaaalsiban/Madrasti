<?php

declare(strict_types=1);

namespace App\Modules\Academic\Http\Requests;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Term;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class TermRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
        ];
    }

    /** The term lies inside its academic year and does not overlap another term of that year. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $year = AcademicYear::findOrFail($this->integer('academic_year_id'));
            $starts = (string) $this->input('starts_on');
            $ends = (string) $this->input('ends_on');

            if ($starts < $year->starts_on->format('Y-m-d')) {
                $validator->errors()->add('starts_on', __('academic.term_outside_year'));
            }
            if ($ends > $year->ends_on->format('Y-m-d')) {
                $validator->errors()->add('ends_on', __('academic.term_outside_year'));
            }

            $overlaps = Term::where('academic_year_id', $year->id)
                ->when($this->route('term'), fn ($q, $id) => $q->whereKeyNot($id))
                ->where('starts_on', '<=', $ends)->where('ends_on', '>=', $starts)->exists();
            if ($overlaps) {
                $validator->errors()->add('starts_on', __('academic.term_overlaps'));
            }
        }];
    }
}
