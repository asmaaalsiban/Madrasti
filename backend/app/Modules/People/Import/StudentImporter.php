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
            'student_number' => ['en' => 'Student number', 'ar' => 'رقم الطالب', 'required' => true, 'example' => '2026001'],
            'name_ar' => ['en' => 'Name (Arabic)', 'ar' => 'الاسم بالعربي', 'required' => true, 'example' => 'ليلى أحمد'],
            'name_en' => ['en' => 'Name (English)', 'ar' => 'الاسم بالإنجليزي', 'required' => false, 'example' => 'Layla Ahmad'],
            'birth_date' => ['en' => 'Birth date', 'ar' => 'تاريخ الميلاد', 'required' => false, 'example' => '2015-03-21'],
            'gender' => ['en' => 'Gender', 'ar' => 'الجنس', 'required' => false, 'example' => 'female'],
            'classroom' => ['en' => 'Section', 'ar' => 'الشعبة', 'required' => false, 'example' => '5-A'],
            'guardian_name' => ['en' => 'Parent name', 'ar' => 'اسم ولي الأمر', 'required' => false, 'example' => 'Ahmad Ali'],
            'guardian_email' => ['en' => 'Parent email', 'ar' => 'بريد ولي الأمر', 'required' => false, 'example' => 'ahmad@example.com'],
            'guardian_phone' => ['en' => 'Parent phone', 'ar' => 'هاتف ولي الأمر', 'required' => false, 'example' => '0790000000'],
        ];
    }

    public function check(array $row): array
    {
        $errors = [];
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
        return Classroom::whereIn('academic_year_id', AcademicYear::where('is_current', true)->select('id'))->where('name', trim($name))->first();
    }
}
