<?php

declare(strict_types=1);

namespace App\Modules\People\Import;

use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\People\Actions\SaveStudent;
use App\Modules\People\Http\Requests\StudentRequest;
use Illuminate\Support\Facades\Validator;

/** Students from a spreadsheet: the same checks as the student form, the same account, parent and yearly-fee handling. */
class StudentImporter extends Importer
{
    public function __construct(private readonly SaveStudent $save) {}

    public function kind(): string
    {
        return 'students';
    }

    public function columns(): array
    {
        return [
            'student_number' => ['en' => 'Student number', 'ar' => 'رقم الطالب', 'required' => true, 'example' => '2026001', 'aliases' => ['رقم الطالب', 'الرقم الاكاديمي', 'رقم التسجيل', 'رقم القيد', 'الرقم التعريفي', 'الرقم', 'رقم', 'id', 'student id', 'student no', 'code']],
            'name_ar' => ['en' => 'Name (Arabic)', 'ar' => 'الاسم بالعربي', 'required' => true, 'example' => 'ليلى أحمد', 'aliases' => ['اسم الطالب بالعربي', 'الاسم بالعربي', 'اسم الطالب', 'اسم الطالبه', 'الاسم الكامل', 'arabic name', 'student name']],
            'name_en' => ['en' => 'Name (English)', 'ar' => 'الاسم بالإنجليزي', 'required' => false, 'example' => 'Layla Ahmad', 'aliases' => ['الاسم بالانجليزي', 'الاسم بالانجليزية', 'اسم الطالب بالانجليزي', 'english name', 'name en', 'name english']],
            'birth_date' => ['en' => 'Birth date', 'ar' => 'تاريخ الميلاد', 'required' => false, 'example' => '2015-03-21', 'aliases' => ['تاريخ الميلاد', 'تاريخ الولاده', 'الميلاد', 'birthdate', 'birth date', 'date of birth', 'dob']],
            'gender' => ['en' => 'Gender', 'ar' => 'الجنس', 'required' => false, 'example' => 'female', 'aliases' => ['الجنس', 'النوع', 'gender', 'sex']],
            'classroom' => ['en' => 'Section', 'ar' => 'الشعبة', 'required' => false, 'example' => '5-A', 'aliases' => ['الشعبه', 'الصف', 'الفصل', 'القسم', 'class', 'section', 'classroom', 'grade']],
            'guardian_name' => ['en' => 'Parent name', 'ar' => 'اسم ولي الأمر', 'required' => false, 'example' => 'Ahmad Ali', 'aliases' => ['اسم ولي الامر', 'ولي الامر', 'اسم الاب', 'الاب', 'اسم الام', 'parent name', 'guardian name', 'guardian', 'father name']],
            'guardian_email' => ['en' => 'Parent email', 'ar' => 'بريد ولي الأمر', 'required' => false, 'example' => 'ahmad@example.com', 'aliases' => ['بريد ولي الامر', 'ايميل ولي الامر', 'الايميل', 'البريد الالكتروني', 'البريد', 'parent email', 'guardian email', 'email']],
            'guardian_phone' => ['en' => 'Parent phone', 'ar' => 'هاتف ولي الأمر', 'required' => false, 'example' => '0790000000', 'aliases' => ['هاتف ولي الامر', 'جوال ولي الامر', 'رقم ولي الامر', 'جوال الاب', 'رقم الهاتف', 'الهاتف', 'الجوال', 'موبايل', 'parent phone', 'guardian phone', 'phone', 'mobile']],
        ];
    }

    public function genericName(): array
    {
        return ['keys' => ['name_ar', 'name_en'], 'aliases' => ['الاسم', 'اسم', 'name', 'full name']];
    }

    /** A student needs some name; the number is made up when the file has none. */
    public function missingFields(array $mapped): array
    {
        return array_intersect($mapped, ['name_ar', 'name_en']) === [] ? ['name_ar'] : [];
    }

    private ?int $next = null;

    public function check(array $row): array
    {
        $errors = [];
        // A file with only one (say English) name still gets a name to show; a missing number is generated.
        $row['name_ar'] ??= $row['name_en'] ?? null;
        $row['student_number'] ??= $this->nextNumber();
        $data = array_filter($row, fn ($v) => $v !== null);
        unset($data['classroom']);

        if (isset($row['gender'])) {
            $gender = $this->gender($row['gender']);
            if ($gender === null) {
                $errors[] = __('import.bad_gender');
            }
            $data['gender'] = $gender;
        }
        if (isset($row['classroom'])) {
            $classroom = $this->classroom($row['classroom']);
            if ($classroom === null) {
                $errors[] = __('import.unknown_classroom', ['name' => $row['classroom']]);
            }
            $data['classroom_id'] = $classroom?->id;
        }
        if (isset($data['guardian_email'])) {
            $data['guardian_email'] = mb_strtolower($data['guardian_email']);
        }
        if (isset($data['student_number']) && $this->repeated('student', $data['student_number'])) {
            $errors[] = __('import.duplicate_in_file', ['value' => $data['student_number']]);
        }

        // The very rules of the student form (including the parent email and phone checks).
        $request = StudentRequest::create('/', 'POST', $data);
        $request->setContainer(app());
        $validator = Validator::make($data, $request->rules());
        $errors = [...$errors, ...$this->messages($validator)];

        return [$errors === [] ? $validator->validated() : null, $errors];
    }

    public function create(array $data): array
    {
        $student = $this->save->create($data);

        return ['student_number' => $student->student_number, 'name' => $student->name_ar, 'email' => $student->generated_account['email']];
    }

    /** The next free student number after the highest numeric one (so a school's own numbers are never touched). */
    private function nextNumber(): string
    {
        $this->next ??= (int) \App\Modules\Academic\Models\Student::withTrashed()->pluck('student_number')->filter(fn ($n) => ctype_digit((string) $n))->map(fn ($n) => (int) $n)->max();
        do {
            $number = (string) ++$this->next;
        } while (\App\Modules\Academic\Models\Student::withTrashed()->where('student_number', $number)->exists());

        return $number;
    }

    private function gender(string $value): ?string
    {
        return match (mb_strtolower(trim($value))) {
            'male', 'm', 'ذكر', 'ولد' => 'male',
            'female', 'f', 'أنثى', 'انثى', 'بنت' => 'female',
            default => null,
        };
    }

    /** A section of the current academic year, by its name. */
    private function classroom(string $name): ?Classroom
    {
        $matcher = new \App\Shared\Import\ColumnMatcher();
        $strip = fn (string $v) => str_replace(['الصف', 'شعبه', 'شعبة', 'class', 'grade'], '', $matcher->norm($v));
        $wanted = $strip($name);

        return Classroom::whereIn('academic_year_id', AcademicYear::where('is_current', true)->select('id'))->get()
            ->first(fn (Classroom $c) => $matcher->norm($c->name) === $matcher->norm($name) || $strip($c->name) === $wanted);
    }
}
