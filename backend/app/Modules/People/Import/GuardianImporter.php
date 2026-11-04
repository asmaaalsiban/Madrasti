<?php

declare(strict_types=1);

namespace App\Modules\People\Import;

use App\Models\User;
use App\Modules\Academic\Models\Student;
use App\Modules\People\Actions\SaveStudent;
use App\Shared\Enums\Role;
use App\Shared\Support\PhoneNumber;
use Illuminate\Support\Collection;

/**
 * Parents and the children they are linked to. One row per parent (a student with two parents has two rows); the
 * children are written by student number, several separated by a comma. A parent who already exists is only linked.
 */
class GuardianImporter extends Importer
{
    /** @var ?Collection<string, Student> */
    private ?Collection $students = null;

    public function __construct(private readonly SaveStudent $save) {}

    public function kind(): string
    {
        return 'guardians';
    }

    public function columns(): array
    {
        return [
            'name' => ['en' => 'Parent name', 'ar' => 'اسم ولي الأمر', 'required' => true, 'example' => 'Ahmad Ali', 'aliases' => ['اسم ولي الامر', 'ولي الامر', 'الاسم', 'اسم الاب', 'اسم الام', 'parent name', 'guardian name', 'name']],
            'phone' => ['en' => 'Phone', 'ar' => 'الهاتف', 'required' => true, 'example' => '0790000000', 'aliases' => ['هاتف ولي الامر', 'جوال ولي الامر', 'رقم الهاتف', 'الهاتف', 'الجوال', 'موبايل', 'رقم الجوال', 'phone', 'mobile', 'parent phone']],
            'email' => ['en' => 'Email', 'ar' => 'الإيميل', 'required' => false, 'example' => 'ahmad@example.com', 'aliases' => ['بريد ولي الامر', 'ايميل ولي الامر', 'الايميل', 'البريد الالكتروني', 'البريد', 'email', 'parent email']],
            'students' => ['en' => 'Student numbers', 'ar' => 'أرقام الطلاب', 'required' => true, 'example' => '1001, 1002', 'aliases' => ['رقم الطالب', 'ارقام الطلاب', 'ارقام الابناء', 'الابناء', 'الطالب', 'student number', 'student numbers', 'children', 'students']],
        ];
    }

    public function missingFields(array $mapped): array
    {
        return array_values(array_diff(['name', 'phone', 'students'], $mapped));
    }

    public function check(array $row): array
    {
        $errors = [];
        $name = trim((string) ($row['name'] ?? ''));
        $name === '' && $errors[] = __('validation.required', ['attribute' => __('import.field_name')]);

        $phone = PhoneNumber::normalize($row['phone'] ?? null);
        $email = filled($row['email'] ?? null) ? mb_strtolower(trim((string) $row['email'])) : null;
        if ($phone === null) {
            $errors[] = __('import.parent_needs_phone');
        }
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('import.bad_email', ['value' => $email]);
        }

        // The phone and the email may only belong to a parent account (or to nobody yet).
        $owner = $phone !== null ? User::findByLogin($phone) : null;
        if ($owner !== null && $owner->role !== Role::Guardian) {
            $errors[] = __('people.guardian_phone_conflict');
        }
        $byEmail = $email !== null ? User::withTrashed()->where('email', $email)->first() : null;
        if ($byEmail !== null && ($byEmail->trashed() || $byEmail->role !== Role::Guardian)) {
            $errors[] = __('people.guardian_email_conflict');
        }
        if ($phone !== null && $this->repeated('guardian', $phone)) {
            $errors[] = __('import.duplicate_in_file', ['value' => $row['phone']]);
        }

        $ids = [];
        $numbers = preg_split('/[,;،؛\/\n\s]+/u', (string) ($row['students'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $numbers === [] && $errors[] = __('import.parent_needs_students');
        foreach ($numbers as $number) {
            $student = $this->student($number);
            $student === null ? $errors[] = __('import.unknown_student', ['value' => $number]) : $ids[] = $student->id;
        }

        return [$errors === [] ? ['name' => $name, 'phone' => $phone, 'email' => $email, 'student_ids' => array_values(array_unique($ids))] : null, $errors];
    }

    public function create(array $data): array
    {
        foreach (Student::whereIn('id', $data['student_ids'])->get() as $student) {
            $this->save->linkGuardian($student, $data['email'], $data['name'], $data['phone']);
        }

        return ['name' => $data['name'], 'login' => $data['email'] ?: $data['phone'], 'children' => count($data['student_ids'])];
    }

    private function student(string $number): ?Student
    {
        $this->students ??= Student::all()->keyBy(fn (Student $s) => mb_strtolower(trim($s->student_number)));

        return $this->students->get(mb_strtolower(trim($number)));
    }
}
