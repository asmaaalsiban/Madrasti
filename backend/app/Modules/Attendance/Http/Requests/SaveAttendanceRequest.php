<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Shared\Enums\AttendanceStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Checked before validating, so nobody learns about another classroom's students from the error messages.
        $classroom = is_numeric($this->input('classroom_id')) ? Classroom::find((int) $this->input('classroom_id')) : null;

        return $classroom === null || Gate::allows('take-attendance', $classroom);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'records' => ['required', 'array', 'min:1', 'max:200'],
            'records.*.student_id' => ['required', 'integer', 'distinct'],
            'records.*.status' => ['required', Rule::enum(AttendanceStatus::class)],
            'records.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $classroom = Classroom::with('academicYear')->findOrFail($this->integer('classroom_id'));
            $year = $classroom->academicYear;
            if ($year && ($this->input('date') < $year->starts_on->format('Y-m-d') || $this->input('date') > $year->ends_on->format('Y-m-d'))) {
                $validator->errors()->add('date', __('attendance.date_outside_year'));
            }

            $enrolled = Enrollment::where('classroom_id', $classroom->id)->pluck('student_id')->all();
            foreach ($this->input('records') as $i => $record) {
                if (! in_array((int) $record['student_id'], $enrolled, true)) {
                    $validator->errors()->add("records.$i.student_id", __('attendance.student_outside_classroom'));
                }
            }
        }];
    }
}
