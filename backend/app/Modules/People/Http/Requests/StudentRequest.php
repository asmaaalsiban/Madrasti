<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\Payment;
use App\Shared\Enums\Role;
use App\Shared\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $student = $this->route('student') ? Student::find($this->route('student')) : null;

        return [
            'student_number' => ['required', 'string', 'max:50', Rule::unique('students', 'student_number')->ignore($student?->id)],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            // Only used when the student is created: the transport fee (it differs from student to student) and a discount in money.
            'transport_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'fee_discount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            // Only used when the student is created: money taken at registration.
            'first_payment' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'first_payment_payer' => ['nullable', 'string', 'max:255'],
            'first_payment_method' => ['nullable', Rule::in(Payment::METHODS)],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_email' => ['nullable', 'email', 'max:255', $this->guardianEmailRule()],
            'guardian_phone' => ['nullable', 'string', 'max:30', $this->guardianPhoneRule()],
        ];
    }

    /**
     * The parent phone may only belong to that same parent: users.phone is unique across every account. With no parent
     * email the phone is the parent's login, so it may only be unused or already belong to a parent.
     */
    private function guardianPhoneRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $email = $this->input('guardian_email');
            $owner = User::findByLogin($value);
            if (is_string($email) && $email !== '') {
                $owner = $owner ?? User::withTrashed()->where('phone', PhoneNumber::normalize($value))->first();
                if ($owner && filled($owner->email) && $owner->email !== $email) { // a parent registered by phone only may be given an email
                    $fail(__('people.guardian_phone_conflict'));
                }
            } elseif (($owner && $owner->role !== Role::Guardian) || (! $owner && User::onlyTrashed()->where('phone', PhoneNumber::normalize($value))->exists())) {
                $fail(__('people.guardian_phone_conflict'));
            }
        };
    }

    /** The parent email must be new, or already belong to a parent account (one parent, many children). */
    private function guardianEmailRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $existing = User::withTrashed()->where('email', $value)->first();
            if ($existing && ($existing->trashed() || $existing->role !== Role::Guardian)) {
                $fail(__('people.guardian_email_conflict'));
            }
        };
    }

    /** Emails are compared case-insensitively. */
    protected function prepareForValidation(): void
    {
        $email = $this->input('guardian_email');
        if (is_string($email)) {
            $this->merge(['guardian_email' => mb_strtolower(trim($email))]);
        }
    }
}
