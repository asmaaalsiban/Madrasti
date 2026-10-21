<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Scheduling\Services\PeriodSetup;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PeriodSetupRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['integer', 'between:0,6', 'distinct'],
            'periods_per_day' => ['required', 'integer', 'between:1,12'],
            'starts_at' => ['required', 'date_format:H:i'],
            'period_minutes' => ['required', 'integer', 'between:20,120'],
            'gap_minutes' => ['required', 'integer', 'between:0,30'],
            'long_break_after' => ['nullable', 'integer', 'min:1', 'lt:periods_per_day'],
            'long_break_minutes' => ['nullable', 'required_with:long_break_after', 'integer', 'between:5,90'],
        ];
    }

    /** The school day must end the same day (before 22:00). */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && app(PeriodSetup::class)->endOfDay($this->validated()) > 22 * 60) {
                $validator->errors()->add('starts_at', __('scheduling.day_too_long'));
            }
        }];
    }
}
