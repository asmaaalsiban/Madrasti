<?php

declare(strict_types=1);

namespace App\Modules\Grading\Http\Requests;

use App\Modules\Academic\Models\Enrollment;
use App\Modules\Grading\Models\Assessment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SaveScoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller checks the assessment
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scores' => ['required', 'array', 'max:300'],
            'scores.*.student_id' => ['required', 'integer', 'distinct'],
            'scores.*.score' => ['nullable', 'numeric', 'min:0'],
            'scores.*.remark' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Assessment $assessment */
            $assessment = $this->route('assessment');
            $enrolled = Enrollment::where('classroom_id', $assessment->classroom_id)->whereHas('student')->pluck('student_id')->all();

            foreach ($this->input('scores') as $i => $row) {
                if (! in_array((int) $row['student_id'], $enrolled, true)) {
                    $validator->errors()->add("scores.$i.student_id", __('grading.student_not_in_classroom'));
                }
                if (isset($row['score']) && $row['score'] !== '' && (float) $row['score'] > $assessment->max_score) {
                    $validator->errors()->add("scores.$i.score", __('grading.score_above_max', ['max' => $assessment->max_score]));
                }
            }
        }];
    }
}
