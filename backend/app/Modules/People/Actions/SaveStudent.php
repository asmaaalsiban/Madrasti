<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Finance\Models\Money;
use App\Modules\Finance\Services\FeeBilling;
use App\Modules\People\Services\StudentEmail;
use App\Shared\Enums\Role;
use App\Shared\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a student together with the student's login account, the classroom placement
 * and the parent account. Everything happens in one transaction.
 *
 * New accounts get the default password and must change it at the first sign-in.
 */
class SaveStudent
{
    /**
     * @param  array<string, mixed>  $data  validated StudentRequest data
     */
    public function __construct(private readonly StudentEmail $emails, private readonly FeeBilling $billing) {}

    /**
     * The student's login email is generated; it is returned once, with the default password, as the
     * `generated_account` attribute of the result so the admin can hand it over.
     *
     * @param  array<string, mixed>  $data  validated StudentRequest data
     */
    public function create(array $data): Student
    {
        return DB::transaction(function () use ($data): Student {
            $email = $this->emails->generate($data['student_number'], $data['name_en'] ?? null, tenant('id') ?? 'school');

            $account = User::create([
                'name' => $data['name_ar'],
                'email' => $email,
                'role' => Role::Student,
                'is_active' => true,
                ...$this->defaultCredentials(),
            ]);

            $student = Student::create([...$this->studentFields($data), 'user_id' => $account->id]);

            $classroom = $this->place($student, $data['classroom_id'] ?? null);
            $this->linkGuardian($student, $data['guardian_email'] ?? null, $data['guardian_name'] ?? null, $data['guardian_phone'] ?? null);
            // The yearly fee of the grade is applied by itself; the form may add a transport fee and a discount.
            $fees = $classroom ? $this->billing->onboard($student, $classroom, (float) ($data['fee_discount'] ?? 0), (float) ($data['transport_fee'] ?? 0)) : [];

            $payments = (float) ($data['first_payment'] ?? 0) > 0 ? $this->billing->payFirst($fees, (float) $data['first_payment'], $data['first_payment_method'] ?? 'cash', auth()->user(), $data['first_payment_payer'] ?? $data['guardian_name'] ?? null) : [];

            $student = $this->loaded($student);
            $student->setAttribute('created_payments', array_map(fn ($p) => ['receipt' => $p->receiptNumber(), 'amount' => $p->amount], $payments));
            $student->setAttribute('created_fees', array_map(fn ($f) => ['description' => $f->description, 'net' => Money::amount(Money::cents($f->total_amount) - Money::cents($f->discount)), 'installments' => $f->installments->count(), 'items' => $f->items->map(fn ($i) => ['kind' => $i->kind, 'description' => $i->description, 'amount' => $i->amount])->values()->all()], $fees));
            $student->setAttribute('generated_account', ['email' => $email, 'password' => config('accounts.default_password')]);

            return $student;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Student $student, array $data): Student
    {
        return DB::transaction(function () use ($student, $data): Student {
            $student->update($this->studentFields($data));

            // The name follows the student record; the generated email and the password are never touched here.
            $student->user?->update(['name' => $data['name_ar']]);

            $classroom = $this->place($student, $data['classroom_id'] ?? null);
            $this->linkGuardian($student, $data['guardian_email'] ?? null, $data['guardian_name'] ?? null, $data['guardian_phone'] ?? null, replacePrimary: true);
            // A student moved to a grade whose yearly fee they do not have yet gets it (fees they have are never touched).
            if ($classroom) {
                $this->billing->onboard($student, $classroom);
                // The transport fee follows the student when they move to another area (an empty field = no transport).
                if (array_key_exists('transport_fee', $data)) {
                    $this->billing->setTransport($student, $classroom, (float) ($data['transport_fee'] ?? 0));
                }
            }

            return $this->loaded($student);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function studentFields(array $data): array
    {
        return [
            'student_number' => $data['student_number'],
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultCredentials(): array
    {
        return ['password' => config('accounts.default_password'), 'must_change_password' => true];
    }

    /** Places the student in the classroom for that classroom's academic year (replacing the placement of that year). */
    private function place(Student $student, int|string|null $classroomId): ?Classroom
    {
        // The browser sends a <select> value as text ("3"); an empty one means no classroom.
        if ($classroomId === null || $classroomId === '') {
            return null;
        }

        $classroom = Classroom::findOrFail((int) $classroomId);

        Enrollment::updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $classroom->academic_year_id],
            ['classroom_id' => $classroom->id],
        );

        return $classroom;
    }

    /**
     * Links the parent account with this email, creating it when it does not exist yet (one parent, many children).
     * The form edits the primary parent: with $replacePrimary a different email replaces the old primary parent's
     * link (their account stays), so fixing a mistyped email does not leave the wrong account with access to the child.
     */
    public function linkGuardian(Student $student, ?string $email, ?string $name, ?string $phone = null, bool $replacePrimary = false): void
    {
        $email = $email === '' ? null : $email;
        $phone = PhoneNumber::normalize($phone);
        if ($email === null && $phone === null) {
            return;
        }

        // The parent is found by email; a parent registered with a phone number only is found by that number.
        $existing = $email !== null ? User::where('email', $email)->first() : User::findByLogin($phone);
        // A parent who was registered with a phone number only now gets an email: same parent, same account.
        if ($existing === null && $email !== null && $phone !== null) {
            $byPhone = User::findByLogin($phone);
            if ($byPhone !== null && $byPhone->role === Role::Guardian && blank($byPhone->email)) {
                $byPhone->update(['email' => $email]);
                $existing = $byPhone;
            }
        }
        if ($existing !== null && $existing->role !== Role::Guardian) {
            // e.g. the student's own generated email: rolls the whole save back.
            throw ValidationException::withMessages([$email !== null ? 'guardian_email' : 'guardian_phone' => $email !== null ? __('people.guardian_email_conflict') : __('people.guardian_phone_conflict')]);
        }

        $guardian = $existing ?? User::create([
            'name' => $name ?: __('people.guardian_of', ['name' => $student->name_ar]),
            'email' => $email, // may be empty: the parent then signs in with the phone number
            'phone' => $phone,
            'role' => Role::Guardian,
            'is_active' => true,
            ...$this->defaultCredentials(),
        ]);

        // A phone given with the email of an existing parent replaces the old one (a parent found by phone keeps the number as registered).
        if ($email !== null && $phone && $guardian->phone !== $phone) {
            $guardian->update(['phone' => $phone]);
        }

        $linked = $student->guardians()->whereKey($guardian->id)->exists();
        $primary = $student->guardians()->wherePivot('is_primary', true)->first();

        if ($replacePrimary && $primary && $primary->id !== $guardian->id) {
            $student->guardians()->detach($primary->id);
            $primary = null;
        }

        if (! $linked) {
            $student->guardians()->attach($guardian->id, ['is_primary' => $primary === null]);
        } elseif ($primary === null) {
            $student->guardians()->updateExistingPivot($guardian->id, ['is_primary' => true]);
        }
    }

    private function loaded(Student $student): Student
    {
        return $student->load(['user', 'guardians', 'enrollments.classroom', 'fees.items']);
    }
}
