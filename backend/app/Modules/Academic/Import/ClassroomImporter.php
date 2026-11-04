<?php

declare(strict_types=1);

namespace App\Modules\Academic\Import;

use App\Modules\Academic\Http\Requests\ClassroomRequest;
use App\Modules\Academic\Models\AcademicYear;
use App\Modules\Academic\Models\Classroom;
use App\Modules\People\Import\Importer;
use Illuminate\Support\Facades\Validator;

/** The sections (5-A, 5-B...) of a grade, with the same checks as the section form. The grade and the teacher must exist. */
class ClassroomImporter extends Importer
{
    use Lookups;

    public function kind(): string
    {
        return 'classrooms';
    }

    public function columns(): array
    {
        return [
            'name' => ['en' => 'Section', 'ar' => 'الشعبة', 'required' => true, 'example' => '5-A', 'aliases' => ['اسم الشعبه', 'الشعبه', 'الصف الفرعي', 'القسم', 'section', 'class', 'classroom', 'name']],
            'grade_level' => ['en' => 'Grade', 'ar' => 'الصف', 'required' => true, 'example' => 'الصف الخامس', 'aliases' => ['الصف', 'المرحله', 'الصف الدراسي', 'grade', 'grade level', 'level']],
            'homeroom_teacher' => ['en' => 'Homeroom teacher', 'ar' => 'مربي الشعبة', 'required' => false, 'example' => 'teacher@example.com', 'aliases' => ['مربي الشعبه', 'المربي', 'معلم الصف', 'مربي الصف', 'homeroom', 'homeroom teacher', 'teacher']],
            'capacity' => ['en' => 'Capacity', 'ar' => 'السعة', 'required' => false, 'example' => '30', 'aliases' => ['السعه', 'عدد الطلاب', 'capacity', 'seats']],
            'gender' => ['en' => 'Students', 'ar' => 'نوع الطلاب', 'required' => false, 'example' => 'mixed', 'aliases' => ['نوع الطلاب', 'الجنس', 'gender', 'students']],
            'academic_year' => ['en' => 'Academic year', 'ar' => 'السنة الدراسية', 'required' => false, 'example' => '2026-2027', 'aliases' => ['السنه الدراسيه', 'السنه', 'العام الدراسي', 'academic year', 'year']],
        ];
    }

    public function missingFields(array $mapped): array
    {
        return array_values(array_diff(['name', 'grade_level'], $mapped));
    }

    public function check(array $row): array
    {
        $errors = [];
        $data = array_filter($row, fn ($v) => $v !== null);
        unset($data['grade_level'], $data['homeroom_teacher'], $data['academic_year']);

        $year = isset($row['academic_year']) ? $this->academicYearNamed($row['academic_year']) : AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            $errors[] = isset($row['academic_year']) ? __('import.unknown_year', ['name' => $row['academic_year']]) : __('import.no_current_year');
        }
        $data['academic_year_id'] = $year?->id;

        $grade = isset($row['grade_level']) ? $this->gradeLevelNamed($row['grade_level']) : null;
        if (isset($row['grade_level']) && $grade === null) {
            $errors[] = __('import.unknown_grade', ['name' => $row['grade_level']]);
        }
        $data['grade_level_id'] = $grade?->id;

        if (isset($row['homeroom_teacher'])) {
            $teacher = $this->staffNamed($row['homeroom_teacher']);
            if ($teacher === null) {
                $errors[] = __('import.unknown_staff', ['name' => $row['homeroom_teacher']]);
            }
            $data['homeroom_teacher_id'] = $teacher?->id;
        }
        if (isset($data['gender'])) {
            $gender = match (mb_strtolower(trim($data['gender']))) {
                'male', 'boys', 'ذكور', 'ذكر', 'بنين' => 'male',
                'female', 'girls', 'اناث', 'إناث', 'بنات' => 'female',
                'mixed', 'مختلط' => 'mixed',
                default => null,
            };
            if ($gender === null) {
                $errors[] = __('import.bad_class_gender');
            }
            $data['gender'] = $gender;
        }

        if (isset($data['name'], $data['grade_level_id'], $data['academic_year_id'])) {
            if (Classroom::where('name', $data['name'])->where('grade_level_id', $data['grade_level_id'])->where('academic_year_id', $data['academic_year_id'])->exists()) {
                $errors[] = __('import.already_exists', ['value' => $data['name']]);
            } elseif ($this->repeated('classroom', $data['name'].'|'.$data['grade_level_id'].'|'.$data['academic_year_id'])) {
                $errors[] = __('import.duplicate_in_file', ['value' => $data['name']]);
            }
        }

        $request = ClassroomRequest::create('/', 'POST', $data);
        $request->setContainer(app());
        $validator = Validator::make($data, $request->rules());
        $errors = [...$errors, ...$this->messages($validator)];

        return [$errors === [] ? $validator->validated() : null, $errors];
    }

    public function create(array $data): array
    {
        $classroom = Classroom::create($data)->load('gradeLevel', 'homeroomTeacher');

        return ['name' => $classroom->name, 'grade' => $classroom->gradeLevel->name_ar, 'teacher' => $classroom->homeroomTeacher?->name];
    }
}
