<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Resources;

use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\Money;
use App\Shared\Attachments\Attachment;
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
            // Only present right after the student was created: the generated login the admin hands over.
            'account' => $this->when($this->resource->getAttribute('generated_account') !== null, fn () => $this->resource->getAttribute('generated_account')),
            // Only right after creation: the fees the student got (the grade's yearly fee, transport).
            'created_fees' => $this->when($this->resource->getAttribute('created_fees') !== null, fn () => $this->resource->getAttribute('created_fees')),
            // Only right after creation: the first payment taken at registration (one receipt per fee it covered).
            'created_payments' => $this->when($this->resource->getAttribute('created_payments') !== null, fn () => $this->resource->getAttribute('created_payments')),
            // The current transport fee of the student (the form shows it so it can be changed when they move to another area).
            'transport_fee' => $this->whenLoaded('fees', function () {
                $year = $this->fees->max('academic_year_id');
                $cents = (int) $this->fees->where('academic_year_id', $year)->flatMap->items->where('kind', 'transport')->sum(fn ($i) => Money::cents($i->amount));

                return $cents > 0 ? Money::amount($cents) : null;
            }),
            // For the admin only: what the student owes, paid and remaining this year (filled by the list).
            'balance' => $this->when($request->attributes->has('student_balances'), fn () => $request->attributes->get('student_balances')[$this->id] ?? null),
            // For the admin only: the student's file (documents), filled by the list.
            'files' => $this->when($request->attributes->has('student_files'), fn () => Attachment::list($request->attributes->get('student_files')->get($this->id))),
            // The primary parent first: the form edits that one.
            'guardians' => $this->guardians->sortByDesc(fn ($guardian) => (int) $guardian->pivot->is_primary)->map(fn ($guardian) => [
                'id' => $guardian->id,
                'name' => $guardian->name,
                'email' => $guardian->email,
                'phone' => $guardian->phone,
            ])->values(),
        ];
    }
}
