<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Academic\Models\Classroom;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;

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
    public function create(array $data): Student
    {
        return DB::transaction(function () use ($data): Student {
            $account = User::create([
                'name' => $data['name_ar'],
                'email' => $data['student_email'],
                'role' => Role::Student,
                'is_active' => true,
                ...$this->defaultCredentials(),
            ]);

            $student = Student::create([...$this->studentFields($data), 'user_id' => $account->id]);

            $this->place($student, $data['classroom_id'] ?? null);
            $this->linkGuardian($student, $data['guardian_email'] ?? null, $data['guardian_name'] ?? null);

            return $this->loaded($student);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Student $student, array $data): Student
    {
        return DB::transaction(function () use ($student, $data): Student {
            $student->update($this->studentFields($data));

            // Name and email follow the student record; the password is never touched here.
            $student->user?->update(['name' => $data['name_ar'], 'email' => $data['student_email']]);

            $this->place($student, $data['classroom_id'] ?? null);
            $this->linkGuardian($student, $data['guardian_email'] ?? null, $data['guardian_name'] ?? null, replacePrimary: true);

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
    private function place(Student $student, ?int $classroomId): void
    {
        if ($classroomId === null) {
            return;
        }

        $classroom = Classroom::findOrFail($classroomId);

        Enrollment::updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $classroom->academic_year_id],
            ['classroom_id' => $classroom->id],
        );
    }

    /**
     * Links the parent account with this email, creating it when it does not exist yet (one parent, many children).
     * The form edits the primary parent: with $replacePrimary a different email replaces the old primary parent's
     * link (their account stays), so fixing a mistyped email does not leave the wrong account with access to the child.
     */
    private function linkGuardian(Student $student, ?string $email, ?string $name, bool $replacePrimary = false): void
    {
        if ($email === null || $email === '') {
            return;
        }

        $guardian = User::where('email', $email)->first() ?? User::create([
            'name' => $name ?: __('people.guardian_of', ['name' => $student->name_ar]),
            'email' => $email,
            'role' => Role::Guardian,
            'is_active' => true,
            ...$this->defaultCredentials(),
        ]);

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
        return $student->load(['user', 'guardians', 'enrollments.classroom']);
    }
}
