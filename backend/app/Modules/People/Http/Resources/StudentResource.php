<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Resources;

use App\Modules\Academic\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Student
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The latest placement (highest academic year) is the current classroom.
        $classroom = $this->enrollments->sortByDesc('academic_year_id')->first()?->classroom;

        return [
            'id' => $this->id,
            'student_number' => $this->student_number,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'gender' => $this->gender,
            'user_id' => $this->user_id,
            'email' => $this->user?->email,
            'must_change_password' => $this->user?->must_change_password,
            'classroom' => $classroom ? ['id' => $classroom->id, 'name' => $classroom->name] : null,
            // The primary parent first: the form edits that one.
            'guardians' => $this->guardians->sortByDesc(fn ($guardian) => (int) $guardian->pivot->is_primary)->map(fn ($guardian) => [
                'id' => $guardian->id,
                'name' => $guardian->name,
                'email' => $guardian->email,
            ])->values(),
        ];
    }
}
